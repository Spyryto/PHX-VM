<?php
declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';

use PhxVm\{
    AdaptiveVm, BlockVm, ContinuationLimitExceeded, ExecutionLimitExceeded,
    FunctionDef, Instruction as I, Opcode as O, PhpArtifactCache,
    PhpEmitter, PhpVm, Program, ProgramCodec, TieringCalibration,
    TieringPolicy, Types as T, Vm
};

$checks = 0;
function quotaAssert(bool $ok, string $why): void
{
    global $checks;
    if (!$ok) throw new RuntimeException('Continuation quota: ' . $why);
    ++$checks;
}

/** @return array{string, mixed, int}|array{string,string,string,int} */
function quotaOutcome(object $vm, int $entry, array $args, int $budget, int $frames): array
{
    try {
        return ['return', serialize($vm->run($entry, $args, $budget, $frames)), $vm->lastSteps()];
    } catch (Throwable $error) {
        return ['throw', $error::class, $error->getMessage(), $vm->lastSteps()];
    }
}

/** @return list<object> */
function quotaEngines(Program $program, int $entry): array
{
    $engines = [new Vm($program), new BlockVm($program),
        new BlockVm($program, true)];
    foreach ([[false, false], [false, true], [true, false], [true, true]] as [$inline, $trim]) {
        $path = tempnam(sys_get_temp_dir(), 'phx-quota-');
        if ($path === false) throw new RuntimeException('Could not create temporary PHP');
        try {
            $source = PhpEmitter::source($program, $entry, $inline, $trim);
            if (file_put_contents($path, $source) !== strlen($source)) {
                throw new RuntimeException('Cannot write generated T2 PHP');
            }
            $engines[] = new PhpVm($entry, $path);
        } finally {
            unlink($path);
        }
    }
    return $engines;
}

/**
 * Check the complete outcome for all 7 engines against canonical T0,
 * including class, message, and original instruction count on failure.
 */
function quotaCompare(array $engines, int $entry, array $args, int $budget, int $frames): array
{
    global $checks;
    $expected = quotaOutcome($engines[0], $entry, $args, $budget, $frames);
    foreach (array_slice($engines, 1) as $engine) {
        $actual = quotaOutcome($engine, $entry, $args, $budget, $frames);
        if ($actual !== $expected) {
            throw new RuntimeException('Continuation quota differential mismatch: '
                . var_export(compact('entry', 'args', 'budget', 'frames', 'expected', 'actual'), true));
        }
    }
    ++$checks;
    return $expected;
}

$leaf = new FunctionDef('leaf', [T::INT], 1, T::INT, [
    I::abc(O::RET, 0),
]);
$single = new FunctionDef('single', [T::INT, T::INT], 1, T::INT, [
    I::abc(O::CALL, 1, 0, 0), I::abc(O::RET, 1),
]);
$nested = new FunctionDef('nested', [T::INT, T::INT], 1, T::INT, [
    I::abc(O::CALL, 1, 1, 0), I::abc(O::RET, 1),
]);
$sequential = new FunctionDef('sequential', [T::INT, T::INT, T::INT], 1, T::INT, [
    I::abc(O::CALL, 1, 0, 0), I::abc(O::CALL, 2, 0, 1),
    I::abc(O::RET, 2),
]);
$tail = new FunctionDef('tail', [T::INT], 1, T::INT, [
    I::abc(O::TAILCALL, 0, 0, 0),
]);
$outerTail = new FunctionDef('outerTail', [T::INT, T::INT], 1, T::INT, [
    I::abc(O::CALL, 1, 4, 0), I::abc(O::RET, 1),
]);
$program = new Program([$leaf, $single, $nested, $sequential, $tail, $outerTail]);

$singleEngines = quotaEngines($program, 1);
$nestedEngines = quotaEngines($program, 2);
$sequenceEngines = quotaEngines($program, 3);
$tailEngines = quotaEngines($program, 4);
$outerEngines = quotaEngines($program, 5);

$zero = quotaCompare($singleEngines, 1, [7], 100, 0);
quotaAssert($zero === ['throw', ContinuationLimitExceeded::class,
    'Continuation frame limit exceeded', 1],
    'CALL at quota 0 fails at charged first instruction, even when inlined');
quotaAssert(quotaCompare($singleEngines, 1, [7], 100, 1)[1] === serialize(7),
    'CALL with one available logical continuation succeeds');
quotaAssert(quotaCompare($nestedEngines, 2, [7], 100, 1) === [
    'throw', ContinuationLimitExceeded::class, 'Continuation frame limit exceeded', 2,
], 'second nested CALL fails at step 2');
quotaAssert(quotaCompare($nestedEngines, 2, [7], 100, 2)[1] === serialize(7),
    'two nested CALLs fit quota 2');

for ($limit = 0; $limit <= 3; ++$limit) {
    for ($budget = 1; $budget <= 7; ++$budget) {
        quotaCompare($singleEngines, 1, [5], $budget, $limit);
        quotaCompare($nestedEngines, 2, [5], $budget, $limit);
    }
}
quotaAssert(quotaCompare($sequenceEngines, 3, [9], 100, 1)[1] === serialize(9),
    'successive non-tail CALLs reuse the freed quota');
quotaAssert(quotaCompare($tailEngines, 4, [11], 100, 0)[1] === serialize(11),
    'TAILCALL needs no continuation frame');
quotaAssert(quotaCompare($outerEngines, 5, [11], 100, 1)[1] === serialize(11),
    'inlined TAILCALL returns to existing one-level continuation');
for ($frames = 0; $frames <= 2; ++$frames) {
    for ($budget = 1; $budget <= 8; ++$budget) {
        quotaCompare($sequenceEngines, 3, [9], $budget, $frames);
        quotaCompare($tailEngines, 4, [11], $budget, $frames);
        quotaCompare($outerEngines, 5, [11], $budget, $frames);
    }
}
// The quota is checked after an opcode's step charge. Budget exhaustion
// takes precedence when insufficient steps remain for that CALL.
quotaAssert(quotaCompare($singleEngines, 1, [7], 0, 0) === [
    'throw', InvalidArgumentException::class, 'maxSteps must be positive', 0,
], 'invalid budget validation precedes both quotas');
quotaAssert(quotaCompare($singleEngines, 1, [7], 100, -1) === [
    'throw', InvalidArgumentException::class, 'maxFrames must be non-negative', 0,
], 'invalid frame quota validation is identical across engines');
quotaCompare($singleEngines, 1, [], 100, 0);
quotaCompare($singleEngines, 1, [1.0], 100, 0);

$preCall = new FunctionDef('preCall', [T::INT, T::INT], 1, T::INT, [
    I::abc(O::MOVE, 1, 0),
    I::abc(O::CALL, 1, 0, 0),
    I::abc(O::RET, 1),
]);
$preEngines = quotaEngines(new Program([$leaf, $preCall]), 1);
quotaAssert(quotaCompare($preEngines, 1, [3], 1, 0) === [
    'throw', ExecutionLimitExceeded::class, 'Instruction budget exceeded', 2,
], 'budget takes precedence over continuation quota on CALL step');
quotaAssert(quotaCompare($preEngines, 1, [3], 2, 0) === [
    'throw', ContinuationLimitExceeded::class, 'Continuation frame limit exceeded', 2,
], 'CALL quota fails only when instruction itself is affordable');

$add = new FunctionDef('add', [T::INT, T::INT], 1, T::INT, [
    I::abc(O::IADD, 1, 0, 0), I::abc(O::RET, 1),
]);
$overflowCaller = new FunctionDef('overflow', [T::INT, T::INT], 1, T::INT, [
    I::abc(O::CALL, 1, 0, 0), I::abc(O::RET, 1),
]);
$overflowEngines = quotaEngines(new Program([$add, $overflowCaller]), 1);
quotaAssert(quotaCompare($overflowEngines, 1, [PHP_INT_MAX], 100, 0)[1]
    === ContinuationLimitExceeded::class, 'quota error precedes arithmetic overflow');
quotaAssert(quotaCompare($overflowEngines, 1, [PHP_INT_MAX], 100, 1)[1]
    === \PhxVm\VmFault::class, 'arithmetic overflow remains unchanged at adequate quota');

// Deep recursion is bounded even when a very large original instruction
// budget is supplied. Every recursive CALL creates one pending continuation.
$depth = new FunctionDef('depth', [T::INT, T::INT, T::BOOL, T::INT, T::INT], 1, T::INT, [
    I::abx(O::CONST_, 1, 0),
    I::abc(O::IEQ, 2, 0, 1),
    I::abx(O::BR_TRUE, 2, 7),
    I::abx(O::CONST_, 3, 1),
    I::abc(O::ISUB, 3, 0, 3),
    I::abc(O::CALL, 4, 0, 3),
    I::abc(O::RET, 4),
    I::abc(O::RET, 0),
]);
$recursiveProgram = new Program([$depth], [0, 1]);
$recursiveEngines = quotaEngines($recursiveProgram, 0);
foreach ([0, 1, 2, 8, 63] as $limit) {
    $out = quotaCompare($recursiveEngines, 0, [1000], 10000, $limit);
    quotaAssert($out === [
        'throw', ContinuationLimitExceeded::class, 'Continuation frame limit exceeded',
        6 * ($limit + 1),
    ], 'recursive CALL charged at exact depth ' . $limit);
}
quotaAssert(quotaCompare($recursiveEngines, 0, [0], 1000, 0)[1] === serialize(0),
    'base recursion terminates without any pending CALL');
quotaAssert(quotaCompare($recursiveEngines, 0, [50], 1000, 50)[1] === serialize(0),
    'exact recursive continuation quota permits the call tree');
quotaAssert(quotaCompare($recursiveEngines, 0, [600], 10000, PHP_INT_MAX)[1] === serialize(0),
    'default unbounded execution remains compatible with deep recursion');
for ($frames = 0; $frames <= 5; ++$frames) {
    for ($budget = 1; $budget <= 45; $budget += 2) {
        quotaCompare($recursiveEngines, 0, [14], $budget, $frames);
    }
}

// TAILCALL recursion does not accumulate suspended frames.
$tailRecursive = new FunctionDef('sumTail', [
    T::INT, T::INT, T::INT, T::BOOL, T::INT, T::INT, T::INT,
], 2, T::INT, [
    I::abx(O::CONST_, 2, 0), I::abc(O::IEQ, 3, 0, 2),
    I::abx(O::BR_TRUE, 3, 9), I::abx(O::CONST_, 4, 1),
    I::abc(O::ISUB, 5, 0, 4), I::abc(O::IADD, 6, 1, 0),
    I::abc(O::MOVE, 0, 5), I::abc(O::MOVE, 1, 6),
    I::abc(O::TAILCALL, 0, 0, 0), I::abc(O::RET, 1),
]);
$tailProgram = new Program([$tailRecursive], [0, 1]);
$tailRecursiveEngines = quotaEngines($tailProgram, 0);
quotaAssert(quotaCompare($tailRecursiveEngines, 0, [300, 0], 4000, 0)[1]
    === serialize(45150), 'tail-recursive sum at zero continuation quota');
for ($limit = 0; $limit <= 2; ++$limit) {
    for ($budget = 1; $budget <= 33; $budget += 2) {
        quotaCompare($tailRecursiveEngines, 0, [13, 0], $budget, $limit);
    }
}

// Serialization is quota-independent: quotas are caller execution options,
// not embedded state in Program or the wire-format ABI.
$wire = ProgramCodec::encode($recursiveProgram);
quotaAssert($wire === ProgramCodec::encode(ProgramCodec::decode($wire)),
    'frame quota does not alter canonical binary program representation');
quotaAssert(quotaOutcome(new Vm(ProgramCodec::decode($wire)), 0, [10], 1000, 1)[1]
    === ContinuationLimitExceeded::class, 'decoded program still obeys runtime quota');

// AdaptiveVm must carry the same quota through both tiers and avoid compiling
// merely to reject a negative limit. A cached resident T2 run is not replayed.
$dir = tempnam(sys_get_temp_dir(), 'phx-quota-cache-');
if ($dir === false) throw new RuntimeException('Cannot allocate cache dir');
unlink($dir);
if (!mkdir($dir, 0700)) throw new RuntimeException('Cannot create cache dir');
try {
    $cache = new PhpArtifactCache($dir);
    $policy = new TieringPolicy(2, 1, 1.0);
    $calibration = new TieringCalibration(1000, 0.01, 0.001, 0.0);
    $adaptive = new AdaptiveVm($recursiveProgram, $cache, [0 => $calibration], $policy);

    quotaAssert(quotaOutcome($adaptive, 0, [3], 1000, -1) ===
        quotaOutcome(new Vm($recursiveProgram), 0, [3], 1000, -1),
        'invalid negative maxFrames never triggers promotion');
    quotaAssert($adaptive->profile()->rootStats(0)['started'] === 0
        && $adaptive->stats(0)['compilationAttempts'] === 0,
        'invalid quota does not create profile sample or load T2');

    for ($i = 0; $i < 2; ++$i) {
        $a = quotaOutcome($adaptive, 0, [5], 1000, 5);
        $b = quotaOutcome(new Vm($recursiveProgram), 0, [5], 1000, 5);
        quotaAssert($a === $b && $adaptive->lastBackend() === 'T0',
            'profile samples run in T0 at exact permitted depth');
    }
    quotaAssert($adaptive->tier(0) === 'T0'
        && $adaptive->profile()->rootStats(0)['completed'] === 2,
        'two completed T0 samples qualify the entry');
    $failure = quotaOutcome($adaptive, 0, [5], 1000, 0);
    quotaAssert($failure === quotaOutcome(new Vm($recursiveProgram), 0, [5], 1000, 0)
        && $adaptive->lastBackend() === 'T2' && $adaptive->tier(0) === 'T2',
        'promoted T2 preserves immediate quota fault, no T0 replay');
    $before = $adaptive->stats(0)['t0Runs'];
    foreach ([0, 1, 3, 5] as $frames) {
        for ($budget = 1; $budget <= 45; $budget += 2) {
            $reference = quotaOutcome(new Vm($recursiveProgram), 0, [5], $budget, $frames);
            $result = quotaOutcome($adaptive, 0, [5], $budget, $frames);
            quotaAssert($result === $reference && $adaptive->lastBackend() === 'T2',
                'compiled AdaptiveVm frame/budget semantics');
        }
    }
    quotaAssert($adaptive->stats(0)['t0Runs'] === $before
        && $adaptive->stats(0)['compilationAttempts'] === 1,
        'T2 errors never rerun T0 or recompile resident closures');
} finally {
    $cache->clear();
    $lock = $dir . '/.phx-cache.lock';
    if (is_file($lock)) unlink($lock);
    rmdir($dir);
}

echo "Continuation quota T0/T1/T2/AdaptiveVm differential suite: $checks checks passed\n";
