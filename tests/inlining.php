<?php
declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';

use PhxVm\{FunctionDef, Instruction as I, Opcode as O, InlineEmitter, PhpEmitter, PhpVm, Program, Types as T, Vm, VerificationException};

$checks = 0;
function checkInline(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    ++$checks;
}
function loadInline(Program $program, int $entry, bool $inline): array
{
    $source = PhpEmitter::source($program, $entry, $inline);
    $path = tempnam(sys_get_temp_dir(), 'phx-inl-');
    if ($path === false) throw new RuntimeException('No temporary file available');
    try {
        if (file_put_contents($path, $source) !== strlen($source)) {
            throw new RuntimeException('Failed to write PHP source');
        }
        return [new PhpVm($entry, $path), $source];
    } finally {
        unlink($path);
    }
}
function outcomeInline(object $vm, int $entry, array $args, int $budget): array
{
    try {
        return ['ok', serialize($vm->run($entry, $args, $budget)), $vm->lastSteps()];
    } catch (Throwable $e) {
        return ['throw', $e::class, $e->getMessage(), $vm->lastSteps()];
    }
}
function compareInline(Program $program, int $entry, array $args, array $budgets): array
{
    global $checks;
    $t0 = new Vm($program);
    [$old] = loadInline($program, $entry, false);
    [$inlined] = loadInline($program, $entry, true);
    $result = [];
    foreach ($budgets as $budget) {
        $expected = outcomeInline($t0, $entry, $args, $budget);
        foreach ([$old, $inlined] as $engine) {
            $actual = outcomeInline($engine, $entry, $args, $budget);
            if ($actual !== $expected) {
                throw new RuntimeException('T0/T2 mismatch: ' . var_export([
                    'args' => $args,
                    'budget' => $budget,
                    'reference' => $expected,
                    'compiled' => $actual,
                ], true));
            }
        }
        $result = $expected;
        ++$checks;
    }
    return $result;
}

$double = new FunctionDef('double', [T::INT, T::INT], 1, T::INT, [
    I::abc(O::IADD, 1, 0, 0), I::abc(O::RET, 1),
]);
$twice = new FunctionDef('twoCalls', [T::INT, T::INT, T::INT], 1, T::INT, [
    I::abc(O::CALL, 1, 0, 0),
    I::abc(O::CALL, 2, 0, 1),
    I::abc(O::RET, 2),
]);
$p = new Program([$double, $twice]);
$normalSource = PhpEmitter::source($p, 1);
[$inlined, $inlineSource] = loadInline($p, 1, true);
checkInline(substr_count($inlineSource, 'PHX_INLINE_BEGIN') === 2, 'two leaf CALL sites inlined');
checkInline(!str_contains($normalSource, 'PHX_INLINE_BEGIN'), 'default T2 remains unchanged');
checkInline($inlineSource === PhpEmitter::source($p, 1, true), 'inlined PHP is deterministic');
checkInline(str_contains($inlineSource, '$__phx_in_1_0_r0')
    && str_contains($inlineSource, '$__phx_in_1_1_r0'), 'isolated callsite register banks');
checkInline(compareInline($p, 1, [11], [100])[1] === serialize(44), 'two-call value');
compareInline($p, 1, [0], range(1, 8));
compareInline($p, 1, [PHP_INT_MAX], range(1, 8));
compareInline($p, 1, [1.0], [1, 10]);
compareInline($p, 1, [], [1, 10]);

// A leaf called after a branch; entry at the following label must not skip
// or re-enter a partially inlined callee.
$branch = new FunctionDef('branchCaller', [T::INT, T::INT, T::BOOL, T::INT], 2, T::INT, [
    I::abc(O::IEQ, 2, 0, 1),
    I::abx(O::BR_TRUE, 2, 4),
    I::abc(O::CALL, 3, 0, 0),
    I::abc(O::RET, 3),
    I::abc(O::CALL, 3, 0, 1),
    I::abc(O::RET, 3),
]);
$p = new Program([$double, $branch]);
foreach ([[10, 10], [10, 3]] as $args) {
    compareInline($p, 1, $args, range(1, 8));
    compareInline($p, 1, $args, [100]);
}

// CALL arguments may overlap the destination; snapshots are simultaneous.
$sub = new FunctionDef('sub', [T::INT, T::INT, T::INT], 2, T::INT, [
    I::abc(O::ISUB, 2, 0, 1), I::abc(O::RET, 2),
]);
$overlap = new FunctionDef('overlap', [T::INT, T::INT, T::INT, T::INT], 2, T::INT, [
    I::abc(O::MOVE, 2, 0),
    I::abc(O::CALL, 3, 0, 1),
    I::abc(O::RET, 3),
]);
$p = new Program([$sub, $overlap]);
checkInline(compareInline($p, 1, [5, 2], [100])[1] === serialize(-3),
    'overlapping two-arg call');
compareInline($p, 1, [5, 2], range(1, 7));

// Zero-arity and non-int constants, including a PHP source-looking string.
foreach ([42, -0.0, true, false, "Hello \n <?php ' \\ ", null] as $constant) {
    $type = T::of($constant);
    $leaf = new FunctionDef('constant', [$type], 0, $type, [
        I::abx(O::CONST_, 0, 0), I::abc(O::RET, 0),
    ]);
    $caller = new FunctionDef('caller', [$type], 0, $type, [
        I::abc(O::CALL, 0, 0, 0), I::abc(O::RET, 0),
    ]);
    $p = new Program([$leaf, $caller], [$constant]);
    checkInline(compareInline($p, 1, [], [100])[1] === serialize($constant),
        'zero-arity typed constant ' . $type);
    compareInline($p, 1, [], range(1, 5));
}

// Inlined TAILCALL returns to the *outer* continuation, not to its tail
// caller. Both the top-level and an ordinary caller are exercised.
$tail = new FunctionDef('tailCaller', [T::INT], 1, T::INT, [
    I::abc(O::TAILCALL, 0, 0, 0),
]);
$outer = new FunctionDef('outer', [T::INT, T::INT], 1, T::INT, [
    I::abc(O::CALL, 1, 1, 0), I::abc(O::RET, 1),
]);
$p = new Program([$double, $tail, $outer]);
checkInline(compareInline($p, 1, [21], [100])[1] === serialize(42),
    'top-level tailcall');
checkInline(compareInline($p, 2, [21], [100])[1] === serialize(42),
    'tailcall under outer CALL');
compareInline($p, 1, [21], range(1, 6));
compareInline($p, 2, [21], range(1, 8));

// Checked overflow and budget boundary between arithmetic and RET.
$overflowLeaf = new FunctionDef('overflowLeaf', [T::INT, T::INT, T::INT, T::INT], 2, T::INT, [
    I::abc(O::IADD, 2, 0, 1),
    I::abc(O::ISUB, 3, 2, 1),
    I::abc(O::RET, 3),
]);
$overflowCaller = new FunctionDef('overflowCaller', [T::INT, T::INT, T::INT], 2, T::INT, [
    I::abc(O::CALL, 2, 0, 0), I::abc(O::RET, 2),
]);
$p = new Program([$overflowLeaf, $overflowCaller]);
foreach ([[20, 10], [PHP_INT_MAX, 1], [PHP_INT_MIN, -1]] as $args) {
    compareInline($p, 1, $args, range(1, 7));
}

// Ineligible control flow / recursion must be left on the safe T2 path.
$branchLeaf = new FunctionDef('branchLeaf', [T::INT, T::INT, T::BOOL], 1, T::INT, [
    I::abc(O::IEQ, 2, 0, 1), I::abx(O::BR_TRUE, 2, 4),
    I::abc(O::RET, 0), I::abc(O::RET, 0), I::abc(O::RET, 1),
]);
$branchCaller = new FunctionDef('branchCaller', [T::INT, T::INT, T::INT, T::INT], 2, T::INT, [
    I::abc(O::CALL, 2, 0, 0), I::abc(O::RET, 2),
]);
$p = new Program([$branchLeaf, $branchCaller], [0]);
checkInline(substr_count(PhpEmitter::source($p, 1, true), 'PHX_INLINE_BEGIN') === 0,
    'branch-containing callee must not be inlined');
compareInline($p, 1, [0, 99], [100]);
compareInline($p, 1, [2, 99], [100]);

$recursive = new FunctionDef('recursive', [T::INT, T::INT], 1, T::INT, [
    I::abc(O::CALL, 1, 0, 0), I::abc(O::RET, 1),
]);
$p = new Program([$recursive]);
checkInline(substr_count(PhpEmitter::source($p, 0, true), 'PHX_INLINE_BEGIN') === 0,
    'recursive callees never inlined');
compareInline($p, 0, [1], [1, 2, 3, 20]);

// Limit total code duplication to 128 original instructions per module.
$many = array_fill(0, 80, I::abc(O::CALL, 1, 0, 0));
$many[] = I::abc(O::RET, 1);
$p = new Program([
    $double,
    new FunctionDef('manyCalls', [T::INT, T::INT], 1, T::INT, $many),
]);
$source = PhpEmitter::source($p, 1, true);
checkInline(substr_count($source, 'PHX_INLINE_BEGIN') ===
    intdiv(InlineEmitter::MAX_TOTAL_COPIED_INSTRUCTIONS, count($double->code)),
    'bounded inlining budget stops duplicating code');
checkInline(compareInline($p, 1, [9], [1000])[1] === serialize(18),
    'mix of inlined and non-inlined CALL sites');

// Reproducible small leaf programs, including aliases and arithmetic overflow.
mt_srand(1218);
for ($case = 0; $case < 80; ++$case) {
    $op1 = [O::IADD, O::ISUB, O::IMUL][mt_rand(0, 2)];
    $op2 = [O::IADD, O::ISUB, O::IMUL][mt_rand(0, 2)];
    $leaf = new FunctionDef('randomLeaf', [T::INT, T::INT, T::INT], 2, T::INT, [
        I::abc($op1, 2, 0, 1),
        I::abc($op2, 2, 2, mt_rand(0, 1)),
        I::abc(O::RET, 2),
    ]);
    $caller = new FunctionDef('randomCaller', [T::INT, T::INT, T::INT], 2, T::INT, [
        I::abc(O::CALL, 2, 0, 0), I::abc(O::RET, 2),
    ]);
    $p = new Program([$leaf, $caller]);
    $args = [mt_rand(-20, 20), mt_rand(-20, 20)];
    compareInline($p, 1, $args, [1, 2, 3, 4, 5, 100]);
}

try {
    PhpEmitter::source(new Program([new FunctionDef('invalid', [T::INT], 0, T::INT, [
        I::abc(O::RET, 0),
    ])]), 0, true);
    throw new RuntimeException('Verifier bypassed');
} catch (VerificationException $expected) {
    checkInline(true, 'inlined T2 still verifies all bytecode');
}

echo "T2 inlining differential suite: $checks checks passed\n";
