<?php
declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';

use PhxVm\{BlockVm, FunctionDef, Instruction as I, Opcode as O, PhpEmitter, PhpVm, Program, RegisterLiveness, Types as T, VerificationException, Verifier, Vm};

$checks = 0;

function liveCheck(bool $condition, string $why): void
{
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL: ' . $why);
    ++$checks;
}

function t2WithFrames(Program $program, int $entry, bool $inline, bool $trim): array
{
    $source = PhpEmitter::source($program, $entry, $inline, $trim);
    $path = tempnam(sys_get_temp_dir(), 'phx-live-');
    if ($path === false) throw new RuntimeException('tempnam failed');
    try {
        if (file_put_contents($path, $source) !== strlen($source)) {
            throw new RuntimeException('Cannot write generated PHP');
        }
        return [new PhpVm($entry, $path), $source];
    } finally {
        unlink($path);
    }
}

function frameOutcome(object $vm, int $entry, array $args, int $budget): array
{
    try {
        return ['return', serialize($vm->run($entry, $args, $budget)), $vm->lastSteps()];
    } catch (Throwable $error) {
        return ['throw', $error::class, $error->getMessage(), $vm->lastSteps()];
    }
}

/** Compares exact result/error/message and the *original* opcode count. */
function compareFrames(Program $program, int $entry, array $args, array $budgets): array
{
    global $checks;
    $engines = [
        new Vm($program),
        new BlockVm($program, true),
        t2WithFrames($program, $entry, false, false)[0],
        t2WithFrames($program, $entry, false, true)[0],
        t2WithFrames($program, $entry, true, true)[0],
    ];
    $last = [];
    foreach ($budgets as $budget) {
        $last = frameOutcome($engines[0], $entry, $args, $budget);
        foreach (array_slice($engines, 1) as $engine) {
            $actual = frameOutcome($engine, $entry, $args, $budget);
            if ($actual !== $last) {
                throw new RuntimeException('Frame trimming mismatch: ' . var_export([
                    'entry' => $entry, 'args' => $args, 'budget' => $budget,
                    'reference' => $last, 'actual' => $actual,
                ], true));
            }
        }
        ++$checks;
    }
    return $last;
}

$identity = new FunctionDef('identity', [T::INT], 1, T::INT, [I::abc(O::RET, 0)]);
$oneLive = new FunctionDef('oneLive', [T::INT, T::INT, T::INT, T::INT], 1, T::INT, [
    I::abc(O::CALL, 3, 0, 0),
    I::abc(O::IADD, 2, 0, 3),
    I::abc(O::RET, 2),
]);
$p = new Program([$identity, $oneLive]);
Verifier::verify($p);
liveCheck(RegisterLiveness::acrossCalls($p, 1) === [0 => [0]],
    'only r0 remains live across CALL, not the return destination');
[$trimmed, $trimSource] = t2WithFrames($p, 1, false, true);
[$full, $fullSource] = t2WithFrames($p, 1, false, false);
liveCheck(str_contains($trimSource, '$frames[] = [0, [$r0]];'),
    'the trimmed continuation saves exactly one register');
liveCheck(str_contains($trimSource, '[$r0] = $saved;'), 'resumes into only live registers');
liveCheck(str_contains($fullSource, '$frames[] = [0, [$r0, $r1, $r2, $r3]];'),
    'the default T2 continuation remains unchanged');
liveCheck(PhpEmitter::source($p, 1, false, true) === $trimSource,
    'optimized source emission is deterministic');
liveCheck(compareFrames($p, 1, [22], [100])[1] === serialize(44),
    'one preserved live argument');
compareFrames($p, 1, [22], range(1, 8));
compareFrames($p, 1, [PHP_INT_MAX], [2, 3, 4, 8]);
compareFrames($p, 1, [1.0], [1, 20]);
compareFrames($p, 1, [], [1, 20]);

// Empty continuation frame: no old register matters after the return.
$noLive = new FunctionDef('noLive', [T::INT, T::INT], 1, T::INT, [
    I::abc(O::CALL, 1, 0, 0), I::abc(O::RET, 1),
]);
$p = new Program([$identity, $noLive]);
Verifier::verify($p);
liveCheck(RegisterLiveness::acrossCalls($p, 1) === [0 => []],
    'CALL result overwrites its destination; nothing needs saving');
[, $source] = t2WithFrames($p, 1, false, true);
liveCheck(str_contains($source, '$frames[] = [0, []];'),
    'empty continuation saved values are supported');
liveCheck(!str_contains($source, '[] = $saved;'),
    'must not emit illegal empty PHP array destructuring');
compareFrames($p, 1, [7], range(1, 6));
compareFrames($p, 1, [PHP_INT_MAX], [20]);

// Condition selects two distinct successors: r0 and r1 live across the call.
$branch = new FunctionDef('branch', [T::INT, T::BOOL, T::INT, T::INT, T::INT], 2, T::INT, [
    I::abc(O::CALL, 2, 0, 0),
    I::abx(O::BR_TRUE, 1, 4),
    I::abc(O::IADD, 3, 0, 2),
    I::abc(O::RET, 3),
    I::abc(O::ISUB, 4, 0, 2),
    I::abc(O::RET, 4),
]);
$p = new Program([$identity, $branch]);
Verifier::verify($p);
liveCheck(RegisterLiveness::acrossCalls($p, 1) === [0 => [0, 1]],
    'liveness must union both branch successors');
foreach ([[5, true], [5, false], [PHP_INT_MAX, false]] as $args) {
    compareFrames($p, 1, $args, range(1, 11));
}

// Loop backedges keep values alive even when the next use is in a later iteration.
$loop = new FunctionDef('loop', [
    T::INT, T::INT, T::INT, T::INT, T::BOOL, T::INT,
], 1, T::INT, [
    I::abx(O::CONST_, 1, 0), I::abx(O::CONST_, 2, 1),
    I::abx(O::CONST_, 5, 0), I::abc(O::IEQ, 4, 0, 5),
    I::abx(O::BR_TRUE, 4, 9), I::abc(O::CALL, 3, 0, 0),
    I::abc(O::IADD, 1, 1, 3), I::abc(O::ISUB, 0, 0, 2),
    I::abx(O::JMP, 0, 3), I::abc(O::RET, 1),
]);
$p = new Program([$identity, $loop], [0, 1]);
Verifier::verify($p);
liveCheck(RegisterLiveness::acrossCalls($p, 1) === [5 => [0, 1, 2, 5]],
    'liveness fixed point must preserve loop-carried values');
liveCheck(compareFrames($p, 1, [100], [2000])[1] === serialize(5050),
    'loop returns correct sum with narrow frames');
compareFrames($p, 1, [5], range(1, 75));
compareFrames($p, 1, [PHP_INT_MAX], [30]);

// Deep non-tail recursion with many otherwise dead registers. Only the
// argument r0 survives the recursive call: the 63 other slots are dead.
$types = array_fill(0, 64, T::INT);
$types[2] = T::BOOL;
$depth = new FunctionDef('depth', $types, 1, T::INT, [
    I::abx(O::CONST_, 1, 0), I::abx(O::CONST_, 5, 1),
    I::abc(O::IEQ, 2, 0, 1), I::abx(O::BR_TRUE, 2, 8),
    I::abc(O::ISUB, 3, 0, 5),
    I::abc(O::CALL, 4, 0, 3),
    I::abc(O::IADD, 6, 0, 4), I::abc(O::RET, 6),
    I::abc(O::RET, 1),
]);
$p = new Program([$depth], [0, 1]);
Verifier::verify($p);
liveCheck(RegisterLiveness::acrossCalls($p, 0) === [5 => [0]],
    'recursive activation requires only r0');
liveCheck(compareFrames($p, 0, [2000], [20000])[1] === serialize(2001000),
    'deep recursion without PHP call-stack growth');
compareFrames($p, 0, [9], range(1, 85));
compareFrames($p, 0, [PHP_INT_MAX], [30]);

// CALL inside a loop followed by another CALL; earlier callsites may need
// their result later, but the destination is never captured before overwrite.
$twice = new FunctionDef('double', [T::INT, T::INT], 1, T::INT, [
    I::abc(O::IADD, 1, 0, 0), I::abc(O::RET, 1),
]);
$chain = new FunctionDef('chain', [T::INT, T::INT, T::INT, T::INT], 1, T::INT, [
    I::abc(O::CALL, 1, 0, 0),
    I::abc(O::CALL, 2, 0, 1),
    I::abc(O::IADD, 3, 0, 2),
    I::abc(O::RET, 3),
]);
$p = new Program([$twice, $chain]);
Verifier::verify($p);
liveCheck(RegisterLiveness::acrossCalls($p, 1) === [0 => [0], 1 => [0]],
    'successive calls preserve only the needed original argument');
compareFrames($p, 1, [10], range(1, 12));
compareFrames($p, 1, [PHP_INT_MAX], [20]);

// TAILCALL does not create a continuation; its caller's continuation must
// still restore the correct registers after an indirect tail chain.
$tail = new FunctionDef('tail', [T::INT], 1, T::INT, [
    I::abc(O::TAILCALL, 0, 0, 0),
]);
$outer = new FunctionDef('outer', [T::INT, T::INT, T::INT], 1, T::INT, [
    I::abc(O::CALL, 1, 1, 0), I::abc(O::IADD, 2, 1, 0),
    I::abc(O::RET, 2),
]);
$p = new Program([$twice, $tail, $outer]);
Verifier::verify($p);
liveCheck(RegisterLiveness::acrossCalls($p, 2) === [0 => [0]],
    'outer caller must preserve its argument across TAILCALL');
liveCheck(compareFrames($p, 2, [7], [100])[1] === serialize(21),
    'tail chain returns into trimmed outer frame');
compareFrames($p, 2, [7], range(1, 12));

// No changes to call-free T2 even when both optimization flags are set.
$noCalls = new Program([new FunctionDef('constant', [T::INT], 1, T::INT, [
    I::abc(O::RET, 0),
])]);
liveCheck(PhpEmitter::source($noCalls, 0) === PhpEmitter::source($noCalls, 0, true, true),
    'call-free PHP source remains unchanged');

// Reject incorrect programs before analyzing them in the emitter.
try {
    PhpEmitter::source(new Program([new FunctionDef('bad', [T::INT], 0, T::INT, [
        I::abc(O::RET, 0),
    ])]), 0, false, true);
    throw new RuntimeException('Verifier bypassed in trimmed compilation');
} catch (VerificationException $expected) {
    liveCheck(true, 'verifier still mandatory');
}

echo "T2 liveness/frame differential suite: $checks checks passed\n";
