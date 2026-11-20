<?php
declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';

use PhxVm\{FunctionDef, Instruction as I, Opcode as O, PhpEmitter, PhpVm, Program, Types as T, Vm};

/**
 * PHX-VM end-to-end benchmark.
 *
 * The cold T2 path measures verifier/codegen + filesystem write + PHP require
 * + first VM run. The stable path is content-addressed and never rewritten;
 * this allows real OPcache reuse when the host is configured for it.
 *
 * Neither "a second require" nor "a second CLI invocation" is, on its own,
 * evidence of an OPcache hit. Only explicit OPcache telemetry is reported.
 */
function medianE2e(array $measurements): float
{
    sort($measurements, SORT_NUMERIC);
    return $measurements[intdiv(count($measurements), 2)];
}
function elapsedE2e(int $start): float
{
    return (hrtime(true) - $start) / 1e6;
}

/** @return array{0:string,1:bool} [path, alreadyExisted] */
function stableArtifactE2e(string $dir, string $source): array
{
    // Generated only by PhpEmitter from a verified PHX Program. The directory
    // must be controlled by the caller: a PHP artifact is executable code.
    $path = $dir . '/phx-' . hash('sha256', $source) . '.php';
    if (file_exists($path) || is_link($path)) {
        if (!is_file($path) || is_link($path) || hash_file('sha256', $path) !== hash('sha256', $source)) {
            throw new RuntimeException('Existing artifact does not match generated source');
        }
        return [$path, true];
    }

    $tmp = tempnam($dir, '.phx-write-');
    if ($tmp === false) throw new RuntimeException('Cannot create temporary artifact');
    try {
        if (file_put_contents($tmp, $source, LOCK_EX) !== strlen($source)) {
            throw new RuntimeException('Cannot write generated artifact');
        }
        chmod($tmp, 0600);
        // This benchmark expects an exclusive trusted cache directory; the
        // immutable content-hash filename is never intentionally overwritten.
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException('Artifact appeared during publication');
        }
        if (!rename($tmp, $path)) throw new RuntimeException('Cannot publish generated artifact');
    } finally {
        if (is_file($tmp)) unlink($tmp);
    }
    return [$path, false];
}
function runAndVerifyE2e(object $vm, int $entry, array $args, int $budget, int $expected, int &$steps): void
{
    $actual = $vm->run($entry, $args, $budget);
    if ($actual !== $expected) throw new RuntimeException('VM produced incorrect result');
    $steps = $vm->lastSteps();
}
function opcacheProbeE2e(string $path): array
{
    $enabled = function_exists('opcache_get_status')
        && (($status = opcache_get_status(false)) !== false)
        && ($status['opcache_enabled'] ?? false);
    $cached = $enabled && function_exists('opcache_is_script_cached')
        ? opcache_is_script_cached($path)
        : null;
    $details = $enabled ? opcache_get_status(true) : false;
    $script = is_array($details) ? ($details['scripts'][$path] ?? []) : [];
    return [
        'enabled' => $enabled,
        'cached' => $cached,
        'hits' => $script['hits'] ?? null,
        'memoryCacheScripts' => $details['opcache_statistics']['num_cached_scripts'] ?? null,
    ];
}

/**
 * @return array<string,mixed> Measurement details; no timing assertion.
 */
function caseE2e(
    string $name, Program $program, int $entry, array $args,
    int $budget, int $expected, int $samples, string $dir,
    bool $inline = false, bool $trim = false
): array {
    $baselineBuild = [];
    $baselineWarm = [];
    $baselineSteps = null;
    $steps = 0;
    for ($i = 0; $i < $samples; ++$i) {
        $start = hrtime(true);
        $vm = new Vm($program);
        runAndVerifyE2e($vm, $entry, $args, $budget, $expected, $steps);
        $baselineBuild[] = elapsedE2e($start);
        $baselineSteps = $steps;
    }
    $baseline = new Vm($program);
    runAndVerifyE2e($baseline, $entry, $args, $budget, $expected, $steps);
    for ($i = 0; $i < $samples; ++$i) {
        $start = hrtime(true);
        runAndVerifyE2e($baseline, $entry, $args, $budget, $expected, $steps);
        $baselineWarm[] = elapsedE2e($start);
    }

    // Cold: fresh filename for every iteration, even with OPcache active.
    // All temporary paths are deleted after the require+run cycle.
    $coldTotal = $coldGenerate = $coldWrite = $coldLoad = $coldRun = [];
    for ($i = 0; $i < $samples; ++$i) {
        $totalStart = hrtime(true);
        $start = hrtime(true);
        $source = PhpEmitter::source($program, $entry, $inline, $trim);
        $coldGenerate[] = elapsedE2e($start);

        $start = hrtime(true);
        $temp = tempnam($dir, '.phx-cold-');
        if ($temp === false) throw new RuntimeException('Could not allocate cold artifact');
        try {
            if (file_put_contents($temp, $source) !== strlen($source)) {
                throw new RuntimeException('Could not write cold artifact');
            }
            $coldWrite[] = elapsedE2e($start);

            $start = hrtime(true);
            $coldVm = new PhpVm($entry, $temp);
            $coldLoad[] = elapsedE2e($start);

            $start = hrtime(true);
            runAndVerifyE2e($coldVm, $entry, $args, $budget, $expected, $steps);
            $coldRun[] = elapsedE2e($start);
            if ($steps !== $baselineSteps) {
                throw new RuntimeException('T2 cold instruction accounting differs from T0');
            }
            $coldTotal[] = elapsedE2e($totalStart);
        } finally {
            unlink($temp);
        }
    }

    // Stable content-addressed path: repeated require is a valid proxy for
    // reload-per-request *inside this CLI process*, NOT for an HTTP/FPM request.
    $start = hrtime(true);
    $source = PhpEmitter::source($program, $entry, $inline, $trim);
    $generateMs = elapsedE2e($start);

    $start = hrtime(true);
    [$path, $alreadyExisted] = stableArtifactE2e($dir, $source);
    $publishMs = elapsedE2e($start);
    $cacheBefore = opcacheProbeE2e($path);

    $start = hrtime(true);
    $stableVm = new PhpVm($entry, $path);
    $firstLoadMs = elapsedE2e($start);
    $start = hrtime(true);
    runAndVerifyE2e($stableVm, $entry, $args, $budget, $expected, $steps);
    $firstRunMs = elapsedE2e($start);
    if ($steps !== $baselineSteps) {
        throw new RuntimeException('Stable T2 instruction accounting differs from T0');
    }

    $warm = $reload = [];
    for ($i = 0; $i < $samples; ++$i) {
        $start = hrtime(true);
        runAndVerifyE2e($stableVm, $entry, $args, $budget, $expected, $steps);
        $warm[] = elapsedE2e($start);

        $start = hrtime(true);
        $newVm = new PhpVm($entry, $path);
        runAndVerifyE2e($newVm, $entry, $args, $budget, $expected, $steps);
        $reload[] = elapsedE2e($start);
    }
    $cacheAfter = opcacheProbeE2e($path);

    return [
        'workload' => $name,
        'input' => $args,
        'originalInstructions' => $baselineSteps,
        'generatedBytes' => strlen($source),
        'artifactHash' => hash('sha256', $source),
        'stableArtifactReused' => $alreadyExisted,
        't0ColdVerifyRunMedianMs' => medianE2e($baselineBuild),
        't0WarmRunMedianMs' => medianE2e($baselineWarm),
        't2ColdGenerateMedianMs' => medianE2e($coldGenerate),
        't2ColdWriteMedianMs' => medianE2e($coldWrite),
        't2ColdRequireMedianMs' => medianE2e($coldLoad),
        't2ColdRunMedianMs' => medianE2e($coldRun),
        't2ColdTotalMedianMs' => medianE2e($coldTotal),
        't2StableGenerateMs' => $generateMs,
        't2StablePublishMs' => $publishMs,
        't2StableRequireMs' => $firstLoadMs,
        't2StableFirstRunMs' => $firstRunMs,
        't2WarmRunMedianMs' => medianE2e($warm),
        't2StableReloadAndRunMedianMs' => medianE2e($reload),
        'opcacheBefore' => $cacheBefore,
        'opcacheAfter' => $cacheAfter,
    ];
}

$n = isset($argv[1]) ? (int) $argv[1] : 2000;
$samples = isset($argv[2]) ? (int) $argv[2] : 7;
$explicitDir = $argv[3] ?? null;
$jsonOutput = in_array('--json', $argv, true);
if ($n < 1 || $n > 100000) throw new InvalidArgumentException('n must be 1..100000');
if ($samples < 1 || $samples > 30) throw new InvalidArgumentException('samples must be 1..30');
if ($explicitDir === '--json') $explicitDir = null;
elseif ($explicitDir !== null && str_starts_with($explicitDir, '--')) {
    throw new InvalidArgumentException('Unknown benchmark option');
}
foreach (array_slice($argv, 4) as $option) {
    if ($option !== '--json') throw new InvalidArgumentException('Unknown benchmark option');
}

$ownedDirectory = $explicitDir === null;
$dir = $explicitDir ?? (sys_get_temp_dir() . '/phx-e2e-' . bin2hex(random_bytes(8)));
if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
    throw new RuntimeException('Cannot create benchmark cache directory');
}
$dir = realpath($dir);
if ($dir === false || !is_writable($dir)) throw new RuntimeException('Cache directory is not writable');

$sum = new FunctionDef('sum', [T::INT, T::INT, T::INT, T::BOOL, T::INT], 1, T::INT, [
    I::abx(O::CONST_, 1, 0), I::abx(O::CONST_, 2, 1),
    I::abx(O::CONST_, 4, 0), I::abc(O::IEQ, 3, 0, 4),
    I::abx(O::BR_TRUE, 3, 8), I::abc(O::IADD, 1, 1, 0),
    I::abc(O::ISUB, 0, 0, 2), I::abx(O::JMP, 0, 3), I::abc(O::RET, 1),
]);
$double = new FunctionDef('double', [T::INT, T::INT], 1, T::INT, [
    I::abc(O::IADD, 1, 0, 0), I::abc(O::RET, 1),
]);
$sumDoubles = new FunctionDef('sumDoubles', [T::INT, T::INT, T::INT, T::BOOL, T::INT, T::INT], 1, T::INT, [
    I::abx(O::CONST_, 1, 0), I::abx(O::CONST_, 2, 1),
    I::abx(O::CONST_, 5, 0), I::abc(O::IEQ, 3, 0, 5),
    I::abx(O::BR_TRUE, 3, 9), I::abc(O::CALL, 4, 0, 0),
    I::abc(O::IADD, 1, 1, 4), I::abc(O::ISUB, 0, 0, 2),
    I::abx(O::JMP, 0, 3), I::abc(O::RET, 1),
]);

try {
    $measurements = [
        caseE2e('loop_no_calls', new Program([$sum], [0, 1]), 0,
            [$n], $n * 10 + 30, intdiv($n * ($n + 1), 2), $samples, $dir),
        caseE2e('loop_with_leaf_inlined', new Program([$double, $sumDoubles], [0, 1]), 1,
            [$n], $n * 12 + 30, $n * ($n + 1), $samples, $dir, true, true),
        caseE2e('loop_with_leaf_not_inlined', new Program([$double, $sumDoubles], [0, 1]), 1,
            [$n], $n * 12 + 30, $n * ($n + 1), $samples, $dir, false, true),
    ];
    $opcacheStatus = function_exists('opcache_get_status')
        ? opcache_get_status(false)
        : false;
    $environment = [
        'php' => PHP_VERSION,
        'os' => PHP_OS_FAMILY,
        'samples' => $samples,
        'opcacheEnableCli' => ini_get('opcache.enable_cli'),
        'opcacheFileCacheConfigured' => ini_get('opcache.file_cache') !== '',
        'opcacheFileCacheOnly' => ini_get('opcache.file_cache_only'),
        'opcacheFileUpdateProtection' => ini_get('opcache.file_update_protection'),
        'opcacheJitConfigured' => ini_get('opcache.jit'),
        'opcacheJitBufferSize' => ini_get('opcache.jit_buffer_size'),
        'opcacheJitActive' => is_array($opcacheStatus)
            ? ($opcacheStatus['jit']['on'] ?? null)
            : null,
        'artifactDirPersistent' => !$ownedDirectory,
    ];
    if ($jsonOutput) {
        echo json_encode(['environment' => $environment, 'measurements' => $measurements],
            JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    } else {
        printf("PHX-VM T2 end-to-end / PHP %s (%s), n=%d, trials=%d\n", PHP_VERSION, PHP_OS_FAMILY, $n, $samples);
        printf("OPcache CLI=%s, file_cache=%s, file_update_protection=%s, JIT config=%s, JIT active=%s\n",
            $environment['opcacheEnableCli'], $environment['opcacheFileCacheConfigured'] ? 'configured' : 'unset',
            $environment['opcacheFileUpdateProtection'], $environment['opcacheJitConfigured'],
            var_export($environment['opcacheJitActive'], true));
        foreach ($measurements as $m) {
            printf("\n%s (%d bytecode instructions)\n", $m['workload'], $m['originalInstructions']);
            printf("  T0: verify+run %.3f ms; warm run %.3f ms\n",
                $m['t0ColdVerifyRunMedianMs'], $m['t0WarmRunMedianMs']);
            printf("  T2: cold full pipeline %.3f ms [generate %.3f, write %.3f, require %.3f, execute %.3f]\n",
                $m['t2ColdTotalMedianMs'], $m['t2ColdGenerateMedianMs'],
                $m['t2ColdWriteMedianMs'], $m['t2ColdRequireMedianMs'], $m['t2ColdRunMedianMs']);
            printf("  T2: warm closure %.3f ms; stable require+run %.3f ms\n",
                $m['t2WarmRunMedianMs'], $m['t2StableReloadAndRunMedianMs']);
            printf("  Stable artifact: %s, %d bytes; memory-cache before=%s, after=%s, hits=%s\n",
                $m['stableArtifactReused'] ? 'reused' : 'new', $m['generatedBytes'],
                var_export($m['opcacheBefore']['cached'], true),
                var_export($m['opcacheAfter']['cached'], true),
                var_export($m['opcacheAfter']['hits'], true));
        }
        echo "\nCold-path totals include source generation and filesystem work. ";
        echo "Warm closure runs exclude require. Reload+run is same-process reuse, NOT an HTTP/FPM measurement.\n";
        echo "Second CLI invocation with a shared artifact directory does NOT prove an OPcache disk hit.\n";
        echo "No general performance claim; timing ratios are intentionally not CI gates.\n";
    }
} finally {
    // Private temporary artifacts are safe to remove. Explicitly selected
    // cache directories are left intact for a second-process experiment.
    if ($ownedDirectory) {
        foreach (glob($dir . '/*') ?: [] as $artifact) {
            if (is_file($artifact) && !is_link($artifact)) unlink($artifact);
        }
        rmdir($dir);
    }
}
