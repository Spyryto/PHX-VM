<?php
declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';

use PhxVm\{ExecutionProfile, FunctionDef, Instruction as I, Opcode as O, PhpEmitter, PhpVm, Program, TieringPolicy, Types as T, Vm};

/**
 * Demonstrates the advisory tiering policy with local, real measurements.
 * This does NOT switch T0 to T2: it only prints a recommendation.
 * Uninstrumented T0 and warmed T2 are used for cost calibration; the
 * instrumented interpreter supplies ONLY logical instruction counts.
 */
$n = (int) ($argv[1] ?? 2000);
$anticipatedRuns = (int) ($argv[2] ?? 100);
if ($n < 1 || $n > 100000 || $anticipatedRuns < 0) {
    throw new InvalidArgumentException('Usage: php bench/tiering.php [n:1..100000] [futureRuns>=0]');
}

$function = new FunctionDef('sum', [
    T::INT, T::INT, T::INT, T::BOOL, T::INT,
], 1, T::INT, [
    I::abx(O::CONST_, 1, 0), I::abx(O::CONST_, 2, 1),
    I::abx(O::CONST_, 4, 0), I::abc(O::IEQ, 3, 0, 4),
    I::abx(O::BR_TRUE, 3, 8), I::abc(O::IADD, 1, 1, 0),
    I::abc(O::ISUB, 0, 0, 2), I::abx(O::JMP, 0, 3), I::abc(O::RET, 1),
]);
$program = new Program([$function], [0, 1]);
$expected = intdiv($n * ($n + 1), 2);
$budget = $n * 6 + 30;
$samples = 7;

function medianTiering(array $numbers): float
{
    sort($numbers, SORT_NUMERIC);
    return $numbers[intdiv(count($numbers), 2)];
}
function measureTiering(object $vm, int $n, int $budget, int $expected, int $samples): array
{
    if ($vm->run(0, [$n], $budget) !== $expected) {
        throw new RuntimeException('Incorrect warmup result');
    }
    $times = [];
    for ($i = 0; $i < $samples; ++$i) {
        $started = hrtime(true);
        $actual = $vm->run(0, [$n], $budget);
        $times[] = (hrtime(true) - $started) / 1e6;
        if ($actual !== $expected) throw new RuntimeException('Incorrect timed result');
    }
    return [medianTiering($times), $vm->lastSteps()];
}

$profile = new ExecutionProfile();
$instrumented = new Vm($program, $profile);
for ($i = 0; $i < $samples; ++$i) {
    if ($instrumented->run(0, [$n], $budget) !== $expected) {
        throw new RuntimeException('Incorrect profiled result');
    }
}
$stats = $profile->rootStats(0);
$average = $stats['successfulSteps'] / $stats['completed'];

$normalT0 = new Vm($program);
[$t0Ms, $t0Steps] = measureTiering($normalT0, $n, $budget, $expected, $samples);

$started = hrtime(true);
$source = PhpEmitter::source($program, 0);
$generateMs = (hrtime(true) - $started) / 1e6;
$path = tempnam(sys_get_temp_dir(), 'phx-tier-bench-');
if ($path === false) throw new RuntimeException('Cannot allocate generated PHP path');
try {
    $started = hrtime(true);
    if (file_put_contents($path, $source) !== strlen($source)) {
        throw new RuntimeException('Failed to write generated PHP');
    }
    $compiledT2 = new PhpVm(0, $path);
    $writeLoadMs = (hrtime(true) - $started) / 1e6;
} finally {
    unlink($path);
}
[$t2Ms, $t2Steps] = measureTiering($compiledT2, $n, $budget, $expected, $samples);
if ($t0Steps !== $t2Steps || $average !== $t0Steps) {
    throw new RuntimeException('Original bytecode step count mismatch');
}

$policy = new TieringPolicy();
$decision = $policy->assess(
    $profile, 0, $anticipatedRuns,
    $t0Ms / $t0Steps,
    $t2Ms / $t2Steps,
    $generateMs + $writeLoadMs,
);

printf("PHX-VM tiering observation / PHP %s / n=%d / anticipated runs=%d\n",
    PHP_VERSION, $n, $anticipatedRuns);
printf("Profile: %d completed samples, %.0f mean original instructions/run; max frame depth=%d\n",
    $stats['completed'], $average, $profile->snapshot()['maxContinuationDepth']);
printf("Uninstrumented T0 median: %.4f ms / %d original steps\n", $t0Ms, $t0Steps);
printf("Loaded T2 median:        %.4f ms / %d original steps\n", $t2Ms, $t2Steps);
printf("T2 preparation:          %.4f ms generate + %.4f ms write/load\n", $generateMs, $writeLoadMs);
printf("Policy: %s (%s), break-even estimate=%s runs, projected net=%.4f ms\n",
    $decision['recommendation'], $decision['reason'],
    $decision['breakEvenRuns'] === null ? 'unavailable' : (string) $decision['breakEvenRuns'],
    $decision['projectedNetMs']);
echo "Advisory only: no automatic compilation or engine switch. One workload, no OPcache persistence.\n";
echo "Instrumentation timings are intentionally NOT used to calibrate T0 performance.\n";
