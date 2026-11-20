<?php
declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';

use PhxVm\{BlockVm, FunctionDef, Instruction as I, Opcode as O, PhpEmitter, PhpVm, Program, Types as T, VerificationException, Vm};

$checks = 0;

function checkCalls(bool $condition, string $why): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($why);
    ++$checks;
}

/** Compile and load trusted verified PHP once for a given entry point. */
function makeT2Calls(Program $program, int $entry): PhpVm
{
    $source = PhpEmitter::source($program, $entry);
    $path = tempnam(sys_get_temp_dir(), 'phx-call-');
    if ($path === false) throw new RuntimeException('Cannot allocate temporary T2 file');
    try {
        if (file_put_contents($path, $source) !== strlen($source)) {
            throw new RuntimeException('Cannot persist generated T2 source');
        }
        return new PhpVm($entry, $path);
    } finally {
        unlink($path);
    }
}
function captureCalls(object $vm, int $entry, array $args, int $budget): array
{
    try {
        $value = $vm->run($entry, $args, $budget);
        return ['ok', serialize($value), $vm->lastSteps()];
    } catch (Throwable $e) {
        return ['throw', $e::class, $e->getMessage(), $vm->lastSteps()];
    }
}
function compareCalls(Program $program, int $entry, array $args, array $budgets): array
{
    global $checks;
    $engines = [new Vm($program), new BlockVm($program, true), makeT2Calls($program, $entry)];
    $outcome = [];
    foreach ($budgets as $budget) {
        $outcome = captureCalls($engines[0], $entry, $args, $budget);
        foreach (array_slice($engines, 1) as $engine) {
            $actual = captureCalls($engine, $entry, $args, $budget);
            if ($actual !== $outcome) {
                throw new RuntimeException('T0/T1/T2 mismatch: ' . var_export([
                    'args' => $args, 'budget' => $budget, 'expected' => $outcome, 'actual' => $actual,
                ], true));
            }
        }
        ++$checks;
    }
    return $outcome;
}

$identity = new FunctionDef('id', [T::INT], 1, T::INT, [I::abc(O::RET, 0)]);
$twice = new FunctionDef('twice', [T::INT, T::INT], 1, T::INT, [
    I::abc(O::IADD, 1, 0, 0), I::abc(O::RET, 1),
]);
$wrapper = new FunctionDef('wrapper', [T::INT, T::INT], 1, T::INT, [
    I::abc(O::CALL, 1, 1, 0), I::abc(O::RET, 1),
]);
$p = new Program([$identity, $twice, $wrapper]);
$source = PhpEmitter::source($p, 2);
checkCalls(str_contains($source, 'goto F1_B0;'), 'direct goto into callee');
checkCalls(str_contains($source, 'RESUME:') && str_contains($source, 'switch ($continuation)'), 'explicit return dispatch only');
checkCalls(!str_contains($source, 'switch ($pc)'), 'no interpreter loop or opcode dispatch');
checkCalls($source === PhpEmitter::source($p, 2), 'deterministic code emission');
checkCalls(compareCalls($p, 2, [21], [100])[1] === serialize(42), 'direct CALL value');
compareCalls($p, 2, [PHP_INT_MAX], [1, 2, 3, 4, 100]);
compareCalls($p, 2, [21], range(1, 8));
compareCalls($p, 2, [1.0], [1, 100]);
compareCalls($p, 2, [], [1, 100]);

// Caller values must be saved across a nested call and restored on RETURN.
$addAfterCall = new FunctionDef('addAfterCall', [T::INT, T::INT, T::INT], 1, T::INT, [
    I::abc(O::CALL, 1, 1, 0),
    I::abc(O::IADD, 2, 1, 0),
    I::abc(O::RET, 2),
]);
$p = new Program([$identity, $twice, $addAfterCall]);
checkCalls(compareCalls($p, 2, [14], [100])[1] === serialize(42), 'caller locals restored after return');
compareCalls($p, 2, [PHP_INT_MAX], [100]);
compareCalls($p, 2, [14], range(1, 7));

// Overlapping argument windows: evaluate all callee arguments BEFORE overwriting registers.
$sub = new FunctionDef('sub', [T::INT, T::INT, T::INT], 2, T::INT, [
    I::abc(O::ISUB, 2, 0, 1), I::abc(O::RET, 2),
]);
$overlap = new FunctionDef('overlap', [T::INT, T::INT, T::INT, T::INT], 2, T::INT, [
    I::abc(O::MOVE, 2, 0),
    I::abc(O::CALL, 3, 0, 1),
    I::abc(O::RET, 3),
]);
$p = new Program([$sub, $overlap]);
checkCalls(compareCalls($p, 1, [5, 2], [100])[1] === serialize(-3), 'overlapping call registers');
compareCalls($p, 1, [5, 2], range(1, 6));

// Zero-arity direct calls.
$fortyTwo = new FunctionDef('fortyTwo', [T::INT], 0, T::INT, [
    I::abx(O::CONST_, 0, 0), I::abc(O::RET, 0),
]);
$zeroCaller = new FunctionDef('zeroCaller', [T::INT], 0, T::INT, [
    I::abc(O::CALL, 0, 0, 0), I::abc(O::RET, 0),
]);
$p = new Program([$fortyTwo, $zeroCaller], [42]);
checkCalls(compareCalls($p, 1, [], [100])[1] === serialize(42), 'zero-arity CALL');
compareCalls($p, 1, [], range(1, 6));

// Self-recursive tail call: no PHP recursion and no VM continuation allocation.
$sum = new FunctionDef('sum', [
    T::INT, T::INT, T::INT, T::BOOL, T::INT, T::INT, T::INT,
], 2, T::INT, [
    I::abx(O::CONST_, 2, 0), I::abc(O::IEQ, 3, 0, 2),
    I::abx(O::BR_TRUE, 3, 9), I::abx(O::CONST_, 4, 1),
    I::abc(O::ISUB, 5, 0, 4), I::abc(O::IADD, 6, 1, 0),
    I::abc(O::MOVE, 0, 5), I::abc(O::MOVE, 1, 6),
    I::abc(O::TAILCALL, 0, 0, 0), I::abc(O::RET, 1),
]);
$sumWrapper = new FunctionDef('sumWrapper', [T::INT, T::INT, T::INT], 2, T::INT, [
    I::abc(O::CALL, 2, 0, 0), I::abc(O::RET, 2),
]);
$p = new Program([$sum, $sumWrapper], [0, 1]);
checkCalls(compareCalls($p, 1, [10000, 0], [100000])[1] === serialize(50005000),
    '10000 self-tail-recursive calls under ordinary caller');
compareCalls($p, 1, [7, 0], range(1, 65));
compareCalls($p, 1, [PHP_INT_MAX, 0], [100]);
compareCalls($p, 1, [4, PHP_INT_MAX], [100]);

// A deep non-tail recursion must not grow the PHP call stack.
$depth = new FunctionDef('depth', [T::INT, T::INT, T::BOOL, T::INT, T::INT], 1, T::INT, [
    I::abx(O::CONST_, 1, 0), I::abc(O::IEQ, 2, 0, 1),
    I::abx(O::BR_TRUE, 2, 7), I::abx(O::CONST_, 3, 1),
    I::abc(O::ISUB, 3, 0, 3), I::abc(O::CALL, 4, 0, 3),
    I::abc(O::RET, 4), I::abc(O::RET, 0),
]);
$p = new Program([$depth], [0, 1]);
checkCalls(compareCalls($p, 0, [5000], [35020])[1] === serialize(0),
    '5000 non-tail calls with explicit continuations');
compareCalls($p, 0, [10], range(1, 60));

// Mutual tail recursion: two functions, each pointing to the other.
$mutual = [];
for ($id = 0; $id < 2; ++$id) {
    $mutual[] = new FunctionDef('parity' . $id, [T::INT, T::INT, T::BOOL, T::INT, T::BOOL], 1, T::BOOL, [
        I::abx(O::CONST_, 1, 0), I::abc(O::IEQ, 2, 0, 1),
        I::abx(O::BR_TRUE, 2, 6), I::abx(O::CONST_, 3, 1),
        I::abc(O::ISUB, 0, 0, 3), I::abc(O::TAILCALL, 0, 1 - $id, 0),
        I::abx(O::CONST_, 4, $id === 0 ? 2 : 3), I::abc(O::RET, 4),
    ]);
}
$p = new Program($mutual, [0, 1, true, false]);
checkCalls(compareCalls($p, 0, [60000], [370000])[1] === serialize(true), 'mutual tail calls');
checkCalls(compareCalls($p, 1, [60001], [370000])[1] === serialize(true), 'mutual tail calls other entry');
compareCalls($p, 0, [5], range(1, 45));

// Mutual NON-tail recursion: returns must route to the right caller and destination.
$mutualNonTail = [];
for ($id = 0; $id < 2; ++$id) {
    $mutualNonTail[] = new FunctionDef('mutualCall' . $id,
        [T::INT, T::INT, T::BOOL, T::INT, T::BOOL], 1, T::BOOL, [
            I::abx(O::CONST_, 1, 0), I::abc(O::IEQ, 2, 0, 1),
            I::abx(O::BR_TRUE, 2, 7), I::abx(O::CONST_, 3, 1),
            I::abc(O::ISUB, 0, 0, 3), I::abc(O::CALL, 4, 1 - $id, 0),
            I::abc(O::RET, 4),
            I::abx(O::CONST_, 4, $id === 0 ? 2 : 3), I::abc(O::RET, 4),
        ]);
}
$p = new Program($mutualNonTail, [0, 1, true, false]);
checkCalls(compareCalls($p, 0, [2000], [15000])[1] === serialize(true), 'mutual non-tail recursion');
compareCalls($p, 1, [5], range(1, 42));

// Invalid bytecode still must be rejected before PHP is emitted.
try {
    PhpEmitter::source(new Program([new FunctionDef('bad', [T::INT], 0, T::INT, [
        I::abc(O::RET, 0),
    ])]), 0);
    throw new RuntimeException('Verifier unexpectedly bypassed');
} catch (VerificationException $expected) {
    checkCalls(true, 'verification precedes code generation');
}

echo "T2 interprocedural differential suite: $checks checks passed\n";
