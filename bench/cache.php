<?php
declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';

use PhxVm\{FunctionDef, Instruction as I, Opcode as O, PhpArtifactCache, Program, Types as T, Vm};

/**
 * Separates T0 verify+run, T2 cache miss compile+write+require+run,
 * T2 cache hit verify+hash+require+run, and T2 already-loaded run.
 *
 * Timing samples are observational. No "warm speedup" assertion is used.
 */
$n = isset($argv[1]) ? (int) $argv[1] : 2000;
$samples = isset($argv[2]) ? (int) $argv[2] : 7;
$providedDir = $argv[3] ?? null;
if ($n < 1 || $n > 100000 || $samples < 1 || $samples > 30) {
    throw new InvalidArgumentException('Usage: php bench/cache.php [n:1..100000] [samples:1..30] [privateCacheDir]');
}

$owned = $providedDir === null;
if ($owned) {
    $dir = tempnam(sys_get_temp_dir(), 'phx-cache-bench-');
    if ($dir === false) throw new RuntimeException('Cannot allocate directory');
    unlink($dir);
    if (!mkdir($dir, 0700)) throw new RuntimeException('Cannot make cache directory');
} else {
    $dir = $providedDir;
}
$cache = new PhpArtifactCache($dir);

$double = new FunctionDef('double', [T::INT, T::INT], 1, T::INT, [
    I::abc(O::IADD, 1, 0, 0), I::abc(O::RET, 1),
]);
$sumDoubles = new FunctionDef('sumDoubles', [T::INT, T::INT, T::INT, T::BOOL, T::INT, T::INT], 1, T::INT, [
    I::abx(O::CONST_, 1, 0),
    I::abx(O::CONST_, 2, 1),
    I::abx(O::CONST_, 5, 0),
    I::abc(O::IEQ, 3, 0, 5),
    I::abx(O::BR_TRUE, 3, 9),
    I::abc(O::CALL, 4, 0, 0),
    I::abc(O::IADD, 1, 1, 4),
    I::abc(O::ISUB, 0, 0, 2),
    I::abx(O::JMP, 0, 3),
    I::abc(O::RET, 1),
]);
$program = new Program([$double, $sumDoubles], [0, 1]);
$expected = $n * ($n + 1);
$budget = 10 * $n + 40;

function medianCache(array $times): float
{
    sort($times, SORT_NUMERIC);
    return $times[intdiv(count($times), 2)];
}
function runCheckedCache(object $vm, int $n, int $budget, int $expected, ?int &$stepCount): void
{
    if ($vm->run(1, [$n], $budget) !== $expected) throw new RuntimeException('Incorrect result');
    $steps = $vm->lastSteps();
    if ($stepCount !== null && $steps !== $stepCount) {
        throw new RuntimeException('Original PHX instruction budget mismatch');
    }
    $stepCount = $steps;
}
try {
    $stepCount = null;
    $t0VerifyRun = [];
    for ($i = 0; $i < $samples; ++$i) {
        $started = hrtime(true);
        $vm = new Vm($program);
        runCheckedCache($vm, $n, $budget, $expected, $stepCount);
        $t0VerifyRun[] = (hrtime(true) - $started) / 1e6;
    }

    $started = hrtime(true);
    $first = $cache->load($program, 1, true, true);
    $firstWasHit = $cache->lastWasHit();
    runCheckedCache($first, $n, $budget, $expected, $stepCount);
    $firstLoadAndRunMs = (hrtime(true) - $started) / 1e6;

    $cacheHitLoadAndRun = [];
    for ($i = 0; $i < $samples; ++$i) {
        $started = hrtime(true);
        $vm = $cache->load($program, 1, true, true);
        if (!$cache->lastWasHit()) {
            throw new RuntimeException('Expected compiled artifact cache hit');
        }
        runCheckedCache($vm, $n, $budget, $expected, $stepCount);
        $cacheHitLoadAndRun[] = (hrtime(true) - $started) / 1e6;
    }

    $alreadyLoaded = [];
    for ($i = 0; $i < $samples; ++$i) {
        $started = hrtime(true);
        runCheckedCache($first, $n, $budget, $expected, $stepCount);
        $alreadyLoaded[] = (hrtime(true) - $started) / 1e6;
    }

    printf("PHX-VM T2 persistent artifact cache / PHP %s / iterations %d / trials %d\n",
        PHP_VERSION, $n, $samples);
    printf("OPcache CLI=%s, JIT=%s\n", ini_get('opcache.enable_cli'), ini_get('opcache.jit'));
    printf("Original VM instructions per run: %d\n", $stepCount);
    printf("T0 new verify+run median: %.3f ms\n", medianCache($t0VerifyRun));
    printf("T2 first cache load+run: %.3f ms (%s)\n",
        $firstLoadAndRunMs, $firstWasHit ? 'existing artifact' : 'compiled and published');
    printf("T2 repeated cache load+run median: %.3f ms (verified cache hits)\n",
        medianCache($cacheHitLoadAndRun));
    printf("T2 already-loaded closure run median: %.3f ms\n", medianCache($alreadyLoaded));
    printf("Cache: hits=%d, misses=%d, repairs=%d, evictions=%d\n",
        $cache->stats()['hits'], $cache->stats()['misses'],
        $cache->stats()['repairs'], $cache->stats()['evictions']);
    echo "Cache-hit timings still include Program verification, fingerprinting, file integrity and require.\n";
    echo "Neither CLI startup nor HTTP/FPM worker overhead is included; no speed threshold.\n";
} finally {
    if ($owned) {
        $cache->clear();
        $lock = $dir . '/.phx-cache.lock';
        if (is_file($lock)) unlink($lock);
        rmdir($dir);
    }
}
