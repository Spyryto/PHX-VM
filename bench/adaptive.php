<?php
declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';

use PhxVm\{
    AdaptiveVm, FunctionDef, Instruction as I, Opcode as O,
    PhpArtifactCache, PhpEmitter, PhpVm, Program,
    TieringCalibration, Types as T, Vm
};

/**
 * Observational warmup / tier-switch demonstration. Calibration comes from
 * an uninstrumented T0 and separately prepared T2. Neither profile timings
 * nor synthetic CI speed thresholds drive the tiering policy.
 */
$n = (int) ($argv[1] ?? 1000);
$forecast = (int) ($argv[2] ?? 100);
if ($n < 1 || $n > 100000 || $forecast < 0) {
    throw new InvalidArgumentException('Usage: php bench/adaptive.php [n:1..100000] [anticipatedRuns>=0]');
}
$sum = new FunctionDef('sum', [T::INT, T::INT, T::INT, T::BOOL, T::INT], 1, T::INT, [
    I::abx(O::CONST_, 1, 0), I::abx(O::CONST_, 2, 1),
    I::abx(O::CONST_, 4, 0), I::abc(O::IEQ, 3, 0, 4),
    I::abx(O::BR_TRUE, 3, 8), I::abc(O::IADD, 1, 1, 0),
    I::abc(O::ISUB, 0, 0, 2), I::abx(O::JMP, 0, 3), I::abc(O::RET, 1),
]);
$program = new Program([$sum], [0, 1]);
$expected = intdiv($n * ($n + 1), 2);
$budget = 6 * $n + 40;

function medianAdaptive(array $values): float
{
    sort($values, SORT_NUMERIC);
    return $values[intdiv(count($values), 2)];
}
function benchAdaptiveEngine(object $vm, int $n, int $budget, int $expected): array
{
    if ($vm->run(0, [$n], $budget) !== $expected) throw new RuntimeException('Incorrect warmup result');
    $values = [];
    for ($i = 0; $i < 7; ++$i) {
        $start = hrtime(true);
        $value = $vm->run(0, [$n], $budget);
        $values[] = (hrtime(true) - $start) / 1e6;
        if ($value !== $expected) throw new RuntimeException('Incorrect value');
    }
    return [medianAdaptive($values), $vm->lastSteps()];
}

$t0 = new Vm($program);
[$t0Median, $steps] = benchAdaptiveEngine($t0, $n, $budget, $expected);

$sourceStart = hrtime(true);
$source = PhpEmitter::source($program, 0, true, true);
$sourceMs = (hrtime(true) - $sourceStart) / 1e6;

$temporarySource = tempnam(sys_get_temp_dir(), 'phx-adaptive-bench-');
if ($temporarySource === false) throw new RuntimeException('Cannot make temporary artifact');
try {
    $start = hrtime(true);
    if (file_put_contents($temporarySource, $source) !== strlen($source)) {
        throw new RuntimeException('Cannot persist compiled PHP');
    }
    $t2 = new PhpVm(0, $temporarySource);
    $loadMs = (hrtime(true) - $start) / 1e6;
} finally {
    unlink($temporarySource);
}
[$t2Median, $t2Steps] = benchAdaptiveEngine($t2, $n, $budget, $expected);
if ($steps !== $t2Steps) throw new RuntimeException('Calibration bytecode mismatch');

$cacheDir = tempnam(sys_get_temp_dir(), 'phx-adaptive-cache-');
if ($cacheDir === false) throw new RuntimeException('Cannot allocate cache directory');
unlink($cacheDir);
if (!mkdir($cacheDir, 0700)) throw new RuntimeException('Cannot create cache directory');
$cache = new PhpArtifactCache($cacheDir);

try {
    $calibration = new TieringCalibration(
        $forecast, $t0Median / $steps, $t2Median / $steps,
        $sourceMs + $loadMs
    );
    $adaptive = new AdaptiveVm($program, $cache, [0 => $calibration]);
    $timeline = [];
    for ($i = 0; $i < 9; ++$i) {
        $start = hrtime(true);
        $value = $adaptive->run(0, [$n], $budget);
        $elapsed = (hrtime(true) - $start) / 1e6;
        if ($value !== $expected || $adaptive->lastSteps() !== $steps) {
            throw new RuntimeException('AdaptiveVm result or step budget mismatch');
        }
        $timeline[] = [$i + 1, $adaptive->lastBackend(), $elapsed, $adaptive->tier(0)];
    }

    printf("PHX-VM AdaptiveVm tiering demo / PHP %s / n=%d / futureRuns=%d\n",
        PHP_VERSION, $n, $forecast);
    printf("Independent calibration: T0 %.3f ms, T2 %.3f ms, generation+load %.3f ms; %d virtual instructions\n",
        $t0Median, $t2Median, $sourceMs + $loadMs, $steps);
    foreach ($timeline as [$run, $engine, $ms, $tier]) {
        printf("  run %2d: %-2s in %.3f ms, resident tier %-2s\n", $run, $engine, $ms, $tier);
    }
    $status = $adaptive->stats(0);
    printf("Final: %s, T0 calls=%d, T2 calls=%d, compilation attempts=%d, cache hits=%d, misses=%d\n",
        $status['tier'], $status['t0Runs'], $status['t2Runs'],
        $status['compilationAttempts'], $cache->stats()['hits'], $cache->stats()['misses']);
    echo "Measurements from one process are illustrative and not a universal switch threshold.\n";
    echo "Time spent profiling T0 is deliberately NOT used for calibration.\n";
} finally {
    $cache->clear();
    $lock = $cacheDir . '/.phx-cache.lock';
    if (is_file($lock)) unlink($lock);
    rmdir($cacheDir);
}
