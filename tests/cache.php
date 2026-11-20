<?php
declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';

use PhxVm\{ExecutionLimitExceeded, FunctionDef, Instruction as I, Opcode as O, PhpArtifactCache, Program, Types as T, VerificationException, Vm, VmFault};

$checks = 0;
function assertCache(bool $ok, string $why): void
{
    global $checks;
    if (!$ok) throw new RuntimeException('Cache test failed: ' . $why);
    ++$checks;
}
function makeCacheTemp(): string
{
    $path = tempnam(sys_get_temp_dir(), 'phx-artifact-test-');
    if ($path === false) throw new RuntimeException('Failed to allocate test directory');
    unlink($path);
    if (!mkdir($path, 0700)) throw new RuntimeException('Failed to create test directory');
    return $path;
}
function cleanCacheTemp(string $dir): void
{
    foreach (glob($dir . '/*') ?: [] as $file) {
        if (is_file($file) || is_link($file)) unlink($file);
    }
    foreach (glob($dir . '/.*') ?: [] as $file) {
        if (basename($file) !== '.' && basename($file) !== '..'
            && (is_file($file) || is_link($file))) unlink($file);
    }
    rmdir($dir);
}
function captureCache(object $vm, int $entry, array $args, int $budget): array
{
    try {
        return ['ok', serialize($vm->run($entry, $args, $budget)), $vm->lastSteps()];
    } catch (Throwable $e) {
        return ['throw', $e::class, $e->getMessage(), $vm->lastSteps()];
    }
}
function equivalentCache(PhpArtifactCache $cache, Program $program, int $entry, array $args, array $budgets,
    bool $inline = false, bool $trim = false): void
{
    global $checks;
    $reference = new Vm($program);
    $compiled = $cache->load($program, $entry, $inline, $trim);
    foreach ($budgets as $budget) {
        $expected = captureCache($reference, $entry, $args, $budget);
        $actual = captureCache($compiled, $entry, $args, $budget);
        if ($expected !== $actual) throw new RuntimeException('Cached T2 differs from T0: '
            . var_export([$args, $budget, $expected, $actual], true));
        ++$checks;
    }
}

$identity = new FunctionDef('identity', [T::INT], 1, T::INT, [I::abc(O::RET, 0)]);
$double = new FunctionDef('double', [T::INT, T::INT], 1, T::INT, [
    I::abc(O::IADD, 1, 0, 0), I::abc(O::RET, 1),
]);
$caller = new FunctionDef('caller', [T::INT, T::INT, T::INT], 1, T::INT, [
    I::abc(O::CALL, 1, 1, 0),
    I::abc(O::IADD, 2, 0, 1), I::abc(O::RET, 2),
]);
$program = new Program([$identity, $double, $caller]);

$dir = makeCacheTemp();
try {
    $cache = new PhpArtifactCache($dir, maxEntries: 5);
    $first = $cache->load($program, 2);
    $path = $cache->lastArtifactPath();
    assertCache($path !== null && is_file($path), 'first cache load produces an artifact');
    assertCache(!$cache->lastWasHit(), 'first load was a cold miss');
    assertCache($first->run(2, [7]) === 21, 'first compiled execution');
    $second = $cache->load($program, 2);
    assertCache($cache->lastWasHit(), 'second load hits compiled source cache');
    assertCache($cache->lastArtifactPath() === $path, 'content hash yields stable path');
    assertCache($second->run(2, [7]) === 21, 'cached compiled result');
    assertCache($cache->stats()['hits'] === 1 && $cache->stats()['misses'] === 1,
        'hit/miss metrics');
    assertCache(count(glob($dir . '/phx-*.php') ?: []) === 1,
        'cache hit does not create a second generated PHP file');

    // A different object and a different cache instance simulate a subsequent
    // request in the same process, without requiring new compilation.
    $differentInstance = new PhpArtifactCache($dir);
    $third = $differentInstance->load($program, 2);
    assertCache($differentInstance->lastWasHit() && $third->run(2, [9]) === 27,
        'separate cache instance reuses persisted artifact');
    foreach ([[3], [PHP_INT_MAX], [1.0], []] as $args) {
        equivalentCache($cache, $program, 2, $args, range(1, 9));
    }
    assertCache(captureCache(new Vm($program), 2, [PHP_INT_MAX], 100)[0] === 'throw',
        'overflow remains testable from T0 baseline');
    equivalentCache($cache, $program, 2, [PHP_INT_MAX], [100]);

    // Optimization flags are part of the key and never alias one another.
    $flags = [];
    foreach ([[false, false], [true, false], [false, true], [true, true]] as [$inline, $trim]) {
        $vm = $cache->load($program, 2, $inline, $trim);
        $flags[] = $cache->lastArtifactPath();
        assertCache($vm->run(2, [5]) === 15, 'compiled optimization flags work');
    }
    assertCache(count(array_unique($flags)) === 4, 'four T2 configurations have distinct keys');

    // Any change to a verified Program must invalidate the cache key.
    $changed = new Program([
        $identity,
        new FunctionDef('triple', [T::INT, T::INT], 1, T::INT, [
            I::abc(O::IADD, 1, 0, 0),
            I::abc(O::IADD, 1, 1, 0),
            I::abc(O::RET, 1),
        ]),
        $caller,
    ]);
    $cache->load($changed, 2);
    assertCache(!$cache->lastWasHit(), 'bytecode modification changes cache key');
    $changedKey = $cache->lastArtifactPath();
    $cache->load($program, 1);
    assertCache(!$cache->lastWasHit(), 'entry ID changes cache key');
    assertCache($changedKey !== $cache->lastArtifactPath(), 'entry and program keys separate');

    // A damaged sidecar cannot turn a cache hit into an unchecked require.
    $cache->load($program, 2, true, true);
    $path = $cache->lastArtifactPath();
    $meta = substr($path, 0, -4) . '.meta.json';
    assertCache(is_file($meta), 'sidecar integrity metadata exists');
    $previousRepairs = $cache->stats()['repairs'];
    file_put_contents($meta, '{"corrupt":true}');
    $corrected = $cache->load($program, 2, true, true);
    assertCache(!$cache->lastWasHit() && $cache->stats()['repairs'] === $previousRepairs + 1,
        'damaged metadata forces re-generation');
    assertCache($corrected->run(2, [6]) === 18, 'repaired metadata yields correct code');

    // A changed PHP file must be rejected even if it still parses.
    $correctSource = file_get_contents($path);
    assertCache($correctSource !== false, 'can inspect cached artifact');
    file_put_contents($path, $correctSource . "\n// unintended modification\n");
    $previousRepairs = $cache->stats()['repairs'];
    $repaired = $cache->load($program, 2, true, true);
    assertCache(!$cache->lastWasHit() && $cache->stats()['repairs'] === $previousRepairs + 1,
        'mutated PHP artifact is regenerated after hash mismatch');
    assertCache(hash_file('sha256', $path) === hash('sha256', $correctSource),
        'regenerated artifact recovers byte-for-byte original source');
    assertCache($repaired->run(2, [6]) === 18, 'recovered artifact executes');

    // A symlink at the expected artifact location must never be required.
    if (function_exists('symlink') && DIRECTORY_SEPARATOR === '/') {
        unlink($meta);
        unlink($path);
        $outside = tempnam(sys_get_temp_dir(), 'phx-untrusted-');
        assertCache($outside !== false, 'created outside test payload');
        file_put_contents($outside, "<?php throw new RuntimeException('UNTRUSTED');\n");
        try {
            $linked = @symlink($outside, $path);
            if ($linked) {
                $safe = $cache->load($program, 2, true, true);
                assertCache($safe->run(2, [6]) === 18,
                    'symlink artifact not executed; regenerated trusted source');
                assertCache(!is_link($path), 'cache symlink replaced by regular file');
            }
        } finally {
            unlink($outside);
        }
    }

    $foreign = $dir . '/keep-me.txt';
    file_put_contents($foreign, 'not a PHX cache artifact');
    $cache->clear();
    assertCache(is_file($foreign) && file_get_contents($foreign) === 'not a PHX cache artifact',
        'clear() preserves unrelated files');
    assertCache(count(glob($dir . '/phx-*.php') ?: []) === 0,
        'clear removes managed PHP sources');
    assertCache(count(glob($dir . '/phx-*.meta.json') ?: []) === 0,
        'clear removes managed manifests');
    assertCache($cache->lastArtifactPath() === null,
        'clear resets artifact state');

    // Eviction applies to managed immutable artifacts only.
    $small = new PhpArtifactCache($dir, maxEntries: 2);
    $small->load($program, 2, false, false);
    $small->load($program, 2, true, false);
    $small->load($program, 2, false, true);
    assertCache(count(glob($dir . '/phx-*.php') ?: []) === 2,
        'entry budget evicts an old managed source');
    assertCache($small->stats()['evictions'] >= 1, 'evictions are reported');
    assertCache(is_file($foreign), 'eviction does not touch unrelated files');
    $small->clear();

    // Strict byte limits reject generation, not truncate emitted PHP.
    $tiny = new PhpArtifactCache($dir, maxEntries: 2, maxBytes: 1024, maxSourceBytes: 500);
    try {
        $tiny->load($program, 2);
        throw new RuntimeException('Oversized generated PHP unexpectedly cached');
    } catch (RuntimeException $e) {
        assertCache($e->getMessage() === 'Generated PHP exceeds cache byte limits',
            'source byte limit enforced');
    }
    assertCache(count(glob($dir . '/phx-*.php') ?: []) === 0,
        'oversized PHP was not published');

    // Verification must occur EVEN for a key already in the cache.
    $invalid = new Program([new FunctionDef('bad', [T::INT], 0, T::INT, [
        I::abc(O::RET, 0),
    ])]);
    try {
        $cache->load($invalid, 0);
        throw new RuntimeException('Invalid program was accepted');
    } catch (VerificationException $expected) {
        assertCache(true, 'invalid bytecode never reaches a cache lookup');
    }

    // Reject public directories on POSIX (untrusted executable PHP boundary).
    if (DIRECTORY_SEPARATOR === '/') {
        $public = makeCacheTemp();
        try {
            chmod($public, 0777);
            try {
                new PhpArtifactCache($public);
                throw new RuntimeException('Public cache directory unexpectedly accepted');
            } catch (InvalidArgumentException $expected) {
                assertCache(true, 'world-writable executable cache directory rejected');
            }
        } finally {
            chmod($public, 0700);
            cleanCacheTemp($public);
        }
    }

    assertCache($cache->stats()['repairs'] >= 2, 'both corruption variants recorded');
    echo "T2 compiled artifact cache suite: $checks checks passed\n";
} finally {
    cleanCacheTemp($dir);
}
