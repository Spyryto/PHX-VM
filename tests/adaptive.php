<?php
declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';

use PhxVm\{
    AdaptiveVm, ExecutionLimitExceeded, FunctionDef, Instruction as I,
    Opcode as O, PhpArtifactCache, Program, TieringCalibration,
    TieringPolicy, Types as T, VerificationException, Vm, VmFault
};

$checks = 0;
function adaptiveCheck(bool $condition, string $why): void
{
    global $checks;
    if (!$condition) throw new RuntimeException('AdaptiveVm test failed: ' . $why);
    ++$checks;
}
function adaptiveDir(): string
{
    $dir = tempnam(sys_get_temp_dir(), 'phx-adaptive-');
    if ($dir === false) throw new RuntimeException('Cannot allocate test directory');
    unlink($dir);
    if (!mkdir($dir, 0700)) throw new RuntimeException('Cannot create private directory');
    return $dir;
}
function adaptiveCleanup(string $dir): void
{
    foreach (scandir($dir) ?: [] as $filename) {
        if ($filename === '.' || $filename === '..') continue;
        $path = $dir . '/' . $filename;
        if (is_file($path) || is_link($path)) unlink($path);
    }
    rmdir($dir);
}
function adaptiveOutcome(object $vm, int $entry, array $args, int $budget): array
{
    try {
        return ['ok', serialize($vm->run($entry, $args, $budget)), $vm->lastSteps()];
    } catch (Throwable $error) {
        return ['throw', $error::class, $error->getMessage(), $vm->lastSteps()];
    }
}
function compareAdaptive(Vm $reference, AdaptiveVm $adaptive, int $entry, array $args, int $budget): array
{
    global $checks;
    $expected = adaptiveOutcome($reference, $entry, $args, $budget);
    $actual = adaptiveOutcome($adaptive, $entry, $args, $budget);
    if ($expected !== $actual) {
        throw new RuntimeException('T0/AdaptiveVm mismatch: ' . var_export([
            'entry' => $entry, 'args' => $args, 'budget' => $budget,
            'expected' => $expected, 'actual' => $actual,
        ], true));
    }
    ++$checks;
    return $actual;
}

$double = new FunctionDef('double', [T::INT, T::INT], 1, T::INT, [
    I::abc(O::IADD, 1, 0, 0), I::abc(O::RET, 1),
]);
$caller = new FunctionDef('caller', [T::INT, T::INT, T::INT], 1, T::INT, [
    I::abc(O::CALL, 1, 0, 0),
    I::abc(O::IADD, 2, 0, 1), I::abc(O::RET, 2),
]);
$program = new Program([$double, $caller]);
$reference = new Vm($program);
$policy = new TieringPolicy(3, 1, 1.5);
$calibration = new TieringCalibration(1000, 0.010, 0.001, 1.0);
$dir = adaptiveDir();
try {
    $cache = new PhpArtifactCache($dir);

    // Without an explicit calibration the adaptive engine must never compile.
    $unconfigured = new AdaptiveVm($program, $cache, policy: $policy);
    for ($i = 0; $i < 5; ++$i) {
        adaptiveCheck(compareAdaptive($reference, $unconfigured, 1, [7], 100)[1] === serialize(21),
            'uncalibrated engine preserves result');
        adaptiveCheck($unconfigured->lastBackend() === 'T0', 'without calibration stays on T0');
    }
    adaptiveCheck($unconfigured->tier(1) === 'T0'
        && $unconfigured->stats(1)['compilationAttempts'] === 0
        && $cache->stats()['misses'] === 0, 'no implicit compilation');

    // Explicitly calibrate ONLY entry 1; three successful samples are required.
    $adaptive = new AdaptiveVm(
        $program, $cache, [1 => $calibration], $policy,
        inline: true, trimFrames: true
    );
    for ($i = 1; $i <= 3; ++$i) {
        compareAdaptive($reference, $adaptive, 1, [$i], 100);
        adaptiveCheck($adaptive->lastBackend() === 'T0' && $adaptive->tier(1) === 'T0',
            'profiling samples must execute T0');
    }
    adaptiveCheck($adaptive->profile()->rootStats(1)['completed'] === 3,
        'profile accumulates exactly three successful T0 samples');
    adaptiveCheck($adaptive->profile()->snapshot()['functions'] === [],
        'default lightweight T0 observations avoid per-instruction opcode profiling');
    adaptiveCheck($adaptive->stats(1)['compilationAttempts'] === 0,
        'no compilation during sampling');
    adaptiveCheck(compareAdaptive($reference, $adaptive, 1, [4], 100)[1] === serialize(12),
        'fourth run promotes and executes T2');
    adaptiveCheck($adaptive->tier(1) === 'T2' && $adaptive->lastBackend() === 'T2',
        'T2 promoted after policy recommendation');
    adaptiveCheck($adaptive->stats(1)['decision']['recommendation'] === 'candidate_t2',
        'policy decision exposed to caller');
    adaptiveCheck($adaptive->stats(1)['compilationAttempts'] === 1
        && $cache->stats()['misses'] === 1, 'one compilation attempt');
    adaptiveCheck($adaptive->profile()->rootStats(1)['completed'] === 3,
        'T2 runs do not accidentally increase T0 profiling samples');

    // All future valid runs reuse the loaded PhpVm closure, not even the
    // verified disk cache lookup is repeated.
    $cacheSnapshot = $cache->stats();
    for ($i = 0; $i < 12; ++$i) {
        compareAdaptive($reference, $adaptive, 1, [5 + $i], 100);
        adaptiveCheck($adaptive->lastBackend() === 'T2', 'warm call uses T2');
    }
    adaptiveCheck($cache->stats() === $cacheSnapshot,
        'hot T2 closure avoids subsequent disk loads and hashing');
    adaptiveCheck($adaptive->stats(1)['t2Runs'] === 13, 'compiled run count');
    adaptiveCheck($adaptive->stats(1)['t0Runs'] === 3, 'T0 sample count');
    adaptiveCheck($adaptive->tier(0) === 'T0'
        && $adaptive->stats(0)['compilationAttempts'] === 0,
        'per-entry tiers are independent');

    // Errors from executing T2 must be surfaced once, NOT retried in T0.
    $rootBefore = $adaptive->profile()->rootStats(1);
    $t0Before = $adaptive->stats(1)['t0Runs'];
    compareAdaptive($reference, $adaptive, 1, [PHP_INT_MAX], 100);
    adaptiveCheck($adaptive->lastBackend() === 'T2'
        && $adaptive->stats(1)['t0Runs'] === $t0Before
        && $adaptive->profile()->rootStats(1) === $rootBefore,
        'T2 overflow not replayed through T0');
    adaptiveCheck($adaptive->tier(1) === 'T2', 'runtime exception must not demote T2');

    for ($budget = 1; $budget <= 8; ++$budget) {
        compareAdaptive($reference, $adaptive, 1, [10], $budget);
        adaptiveCheck($adaptive->lastBackend() === 'T2',
            'exhausting budget does not trigger retry');
    }

    // Input errors have exact classes, messages, and lastSteps=0 on T2.
    foreach ([[1.0], [], [1, 2], ['wrong'], [['bad']]] as $args) {
        compareAdaptive($reference, $adaptive, 1, $args, 100);
    }
    compareAdaptive($reference, $adaptive, 1, [5], 0);
    compareAdaptive($reference, $adaptive, 3, [5], 100);

    // Manual demotion drops loaded closure but retains samples. A subsequent
    // qualified invocation may restore T2 via a VERIFIED persistent cache hit.
    $hitsBefore = $cache->stats()['hits'];
    $adaptive->demote(1);
    adaptiveCheck($adaptive->tier(1) === 'T0', 'manual demotion');
    compareAdaptive($reference, $adaptive, 1, [7], 100);
    adaptiveCheck($adaptive->tier(1) === 'T2' && $cache->stats()['hits'] === $hitsBefore + 1,
        're-promotion reuses persistent cache rather than generating source');

    // Separate AdaptiveVm instance starts with its own T0 samples but can
    // obtain the previously compiled artifact after becoming qualified.
    $another = new AdaptiveVm($program, new PhpArtifactCache($dir),
        [1 => $calibration], $policy);
    for ($i = 0; $i < 4; ++$i) {
        compareAdaptive($reference, $another, 1, [11], 100);
    }
    adaptiveCheck($another->tier(1) === 'T2' && $another->stats(1)['compilationAttempts'] === 1,
        'second instance tiering is independent');
    adaptiveCheck($another->stats(1)['t0Runs'] === 3,
        'second instance collects independent samples');
    adaptiveCheck($another->stats(1)['t2Runs'] === 1,
        'second instance switches on fourth run');

    // Invalid calls before promotion must not publish PHP, count as samples,
    // or change the instruction budget error behavior.
    $noisy = new AdaptiveVm($program, $cache, [0 => $calibration], $policy);
    foreach ([[1.5], [], [1, 2], [new stdClass()]] as $args) {
        compareAdaptive($reference, $noisy, 0, $args, 100);
    }
    compareAdaptive($reference, $noisy, 0, [1], 0);
    adaptiveCheck($noisy->stats(0)['compilationAttempts'] === 0
        && $noisy->profile()->rootStats(0)['started'] === 0,
        'input validation failures never add T0 profiler samples');
    for ($budget = 1; $budget <= 2; ++$budget) {
        compareAdaptive($reference, $noisy, 0, [9], $budget);
    }
    adaptiveCheck($noisy->stats(0)['compilationAttempts'] === 0
        && $noisy->profile()->rootStats(0)['completed'] < 3,
        'budget failures do not satisfy sample threshold');

    // No forecast, or forecast without a positive advantage, means no tiering
    // regardless of the number of completed profiling samples.
    $zeroForecast = new AdaptiveVm($program, $cache,
        [0 => new TieringCalibration(0, 0.01, 0.001, 0)], $policy);
    $slowT2 = new AdaptiveVm($program, $cache,
        [0 => new TieringCalibration(1000, 0.001, 0.01, 0)], $policy);
    foreach ([$zeroForecast, $slowT2] as $notQualified) {
        for ($i = 0; $i < 7; ++$i) compareAdaptive($reference, $notQualified, 0, [$i], 100);
        adaptiveCheck($notQualified->tier(0) === 'T0', 'policy keeps T0 when not worthwhile');
        adaptiveCheck($notQualified->stats(0)['compilationAttempts'] === 0,
            'no compilation when decision is stay_t0');
    }

    // An unusably small source cap forces a compiler/cache error: the current
    // invocation safely falls back to T0 because no bytecode ran in T2.
    $tiny = new PhpArtifactCache($dir . '/tiny', maxEntries: 2,
        maxBytes: 16, maxSourceBytes: 1);
    $fallback = new AdaptiveVm($program, $tiny,
        [0 => $calibration], $policy);
    for ($i = 0; $i < 3; ++$i) compareAdaptive($reference, $fallback, 0, [9], 100);
    adaptiveCheck(compareAdaptive($reference, $fallback, 0, [9], 100)[1] === serialize(18),
        'compiler failure falls back to identical T0 result');
    adaptiveCheck($fallback->lastBackend() === 'T0' && $fallback->tier(0) === 'T0',
        'no T2 activation when compilation failed');
    adaptiveCheck($fallback->stats(0)['compilationAttempts'] === 1
        && str_contains($fallback->stats(0)['compilationFailure'], 'cache byte limits'),
        'compilation failure is recorded');
    for ($i = 0; $i < 3; ++$i) compareAdaptive($reference, $fallback, 0, [9], 100);
    adaptiveCheck($fallback->stats(0)['compilationAttempts'] === 1,
        'do not retry failing compilation every execution');
    $fallback->setCalibration(0, $calibration);
    compareAdaptive($reference, $fallback, 0, [9], 100);
    adaptiveCheck($fallback->stats(0)['compilationAttempts'] === 2,
        'explicit calibration update allows a new attempt');

    $strictFailure = new AdaptiveVm($program, $tiny,
        [0 => $calibration], $policy, fallbackOnCompilationFailure: false);
    for ($i = 0; $i < 3; ++$i) compareAdaptive($reference, $strictFailure, 0, [7], 100);
    $before = $strictFailure->profile()->rootStats(0);
    try {
        $strictFailure->run(0, [7], 100);
        throw new RuntimeException('Expected compiler error');
    } catch (RuntimeException $expected) {
        adaptiveCheck($expected->getMessage() === 'Generated PHP exceeds cache byte limits',
            'strict mode exposes compilation failure');
    }
    adaptiveCheck($strictFailure->lastBackend() === null && $strictFailure->lastSteps() === 0,
        'strict compiler error occurred before VM execution');
    adaptiveCheck($strictFailure->profile()->rootStats(0) === $before,
        'failed strict compilation has no T0 profiling side effects');
    compareAdaptive($reference, $strictFailure, 0, [7], 100);
    adaptiveCheck($strictFailure->lastBackend() === 'T0',
        'subsequent strict invocation can continue safely in T0');

    // Deep recursive CALL exercises the T2 interprocedural/trimming backend.
    $depth = new FunctionDef('depth', [T::INT, T::INT, T::BOOL, T::INT, T::INT], 1, T::INT, [
        I::abx(O::CONST_, 1, 0), I::abc(O::IEQ, 2, 0, 1),
        I::abx(O::BR_TRUE, 2, 7), I::abx(O::CONST_, 3, 1),
        I::abc(O::ISUB, 3, 0, 3), I::abc(O::CALL, 4, 0, 3),
        I::abc(O::RET, 4), I::abc(O::RET, 0),
    ]);
    $recursiveProgram = new Program([$depth], [0, 1]);
    $recursiveReference = new Vm($recursiveProgram);
    $recursive = new AdaptiveVm($recursiveProgram, $cache, [0 => $calibration], $policy);
    for ($i = 0; $i < 3; ++$i) compareAdaptive($recursiveReference, $recursive, 0, [500], 4000);
    adaptiveCheck($recursive->tier(0) === 'T0', 'recursive profile samples on T0');
    adaptiveCheck(compareAdaptive($recursiveReference, $recursive, 0, [500], 4000)[1] === serialize(0)
        && $recursive->tier(0) === 'T2',
        'fourth deep recursion switches to stack-safe T2');
    for ($budget = 1; $budget <= 35; ++$budget) {
        compareAdaptive($recursiveReference, $recursive, 0, [7], $budget);
    }
    adaptiveCheck($recursive->lastBackend() === 'T2', 'deep compiled tier stays active');

    // Full instrumentation remains opt-in and must not change semantics or
    // the completed-run threshold used for tier promotion.
    $detailed = new AdaptiveVm(
        $program, $cache, [1 => $calibration], $policy,
        detailedProfiling: true
    );
    for ($i = 0; $i < 3; ++$i) {
        compareAdaptive($reference, $detailed, 1, [12], 100);
    }
    $detailedSnapshot = $detailed->profile()->snapshot();
    adaptiveCheck($detailedSnapshot['functions'][0]['entries'] === 3
        && $detailedSnapshot['functions'][1]['entries'] === 3,
        'opt-in detailed mode counts both caller and callee function entries');
    adaptiveCheck($detailedSnapshot['functions'][0]['opcodes'][O::IADD] === 3
        && $detailedSnapshot['functions'][1]['opcodes'][O::CALL] === 3,
        'opt-in detailed mode records actual bytecode opcode counts');
    adaptiveCheck($detailed->profile()->rootStats(1)['completed'] === 3,
        'detailed profile still uses exact completed-root counts');
    compareAdaptive($reference, $detailed, 1, [12], 100);
    adaptiveCheck($detailed->lastBackend() === 'T2' && $detailed->tier(1) === 'T2',
        'detailed profile promotes at same completion threshold');

    // Construction and configuration must remain strict.
    try {
        new AdaptiveVm(new Program([new FunctionDef('invalid', [T::INT], 0, T::INT, [
            I::abc(O::RET, 0),
        ])]), $cache);
        throw new RuntimeException('Verifier bypassed');
    } catch (VerificationException $expected) {
        adaptiveCheck(true, 'AdaptiveVm verifies program on construction');
    }
    foreach ([[1, -1.0, 0.0, 1.0], [-1, 1.0, 0.0, 1.0],
              [1, INF, 0.0, 1.0], [1, 0.0, NAN, 1.0]] as $args) {
        try {
            new TieringCalibration(...$args);
            throw new RuntimeException('Invalid calibration accepted');
        } catch (InvalidArgumentException $expected) {
            adaptiveCheck(true, 'invalid calibration rejected');
        }
    }
    try {
        $adaptive->setCalibration(8, $calibration);
        throw new RuntimeException('Unknown entry accepted');
    } catch (InvalidArgumentException $expected) {
        adaptiveCheck(true, 'unknown calibrated entry rejected');
    }
    try {
        new AdaptiveVm($program, $cache, [0 => new stdClass()], $policy);
        throw new RuntimeException('Non-calibration object accepted');
    } catch (InvalidArgumentException $expected) {
        adaptiveCheck(true, 'non-calibration object rejected');
    }

    echo "AdaptiveVm T0/T2 differential suite: $checks checks passed\n";
} finally {
    adaptiveCleanup($dir . '/tiny');
    adaptiveCleanup($dir);
}
