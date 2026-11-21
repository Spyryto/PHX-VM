<?php
declare(strict_types=1);

namespace PhxVm;

/**
 * Trusted, bounded, content-addressed persistent cache for T2 PHP closures.
 *
 * Only source produced by PhpEmitter after Program verification is published.
 * Cached files are NEVER accepted as sandboxed input: the directory must be
 * owned and writable only by the trusted PHP application user.
 *
 * Uses a per-directory flock to serialize publication, validation, requiring
 * and eviction across PHP workers; source files are published atomically.
 *
 * The logical key includes the full verified program, entry, optimization
 * flags, PHP_VERSION_ID and an automatic fingerprint of compiler source files.
 * A sidecar manifest checks the emitted source's full SHA-256 and length.
 */
final class PhpArtifactCache
{
    public const FORMAT_VERSION = 'phx-t2-artifact-cache-v1';

    private readonly string $directory;
    private int $hits = 0;
    private int $misses = 0;
    private int $repairs = 0;
    private int $evictions = 0;
    private ?string $lastPath = null;
    private bool $lastHit = false;

    public function __construct(
        string $directory,
        private readonly int $maxEntries = 256,
        private readonly int $maxBytes = 67108864,
        private readonly int $maxSourceBytes = 4194304,
    ) {
        if ($maxEntries < 1 || $maxBytes < 1 || $maxSourceBytes < 1
            || $maxSourceBytes > $maxBytes) {
            throw new \InvalidArgumentException('Invalid cache limits');
        }
        if (is_link($directory)) {
            throw new \InvalidArgumentException('Cache directory must not be a symbolic link');
        }
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create cache directory');
        }
        if (is_link($directory)) {
            throw new \InvalidArgumentException('Cache directory must not be a symbolic link');
        }
        $resolved = realpath($directory);
        if ($resolved === false || !is_dir($resolved) || !is_writable($resolved)) {
            throw new \RuntimeException('Cache directory must be writable');
        }

        // POSIX trust boundary: nobody except the current owner should be
        // permitted to inspect or mutate executable PHP artifacts.
        if (DIRECTORY_SEPARATOR === '/'
            && ((fileperms($resolved) & 0077) !== 0)) {
            throw new \InvalidArgumentException('Cache directory must have private permissions (0700)');
        }
        $this->directory = $resolved;
    }

    public function directory(): string { return $this->directory; }
    public function lastArtifactPath(): ?string { return $this->lastPath; }
    public function lastWasHit(): bool { return $this->lastHit; }

    /** @return array{hits:int,misses:int,repairs:int,evictions:int,lastHit:bool} */
    public function stats(): array
    {
        return [
            'hits' => $this->hits,
            'misses' => $this->misses,
            'repairs' => $this->repairs,
            'evictions' => $this->evictions,
            'lastHit' => $this->lastHit,
        ];
    }

    public function load(
        Program $program,
        int $functionId,
        bool $inline = false,
        bool $trimFrames = false,
    ): PhpVm {
        // Always verify before accepting a cache hit; the cache does not
        // bypass bytecode verification or its input-validation contract.
        Verifier::verify($program);
        if (!isset($program->functions[$functionId])) {
            throw new \InvalidArgumentException('Unknown function ID');
        }

        $key = self::programKey($program, $functionId, $inline, $trimFrames);
        $path = $this->sourcePath($key);
        $manifestPath = $this->manifestPath($key);
        $this->lastPath = null;
        $this->lastHit = false;

        $lock = $this->lock();
        try {
            $manifest = $this->validManifest($key, $path, $manifestPath);
            if ($manifest !== null) {
                ++$this->hits;
                $this->lastHit = true;
                // Update LRU on a metadata file rather than mutating the
                // immutable source; use timestamps only for eviction order.
                touch($manifestPath);
            } else {
                ++$this->misses;
                if (file_exists($path) || is_link($path)
                    || file_exists($manifestPath) || is_link($manifestPath)) {
                    ++$this->repairs;
                    $this->deletePair($path, $manifestPath);
                }

                // Reverify in emitter, generate once, enforce size before
                // publishing. This host PHP source is executable and trusted.
                $source = PhpEmitter::source($program, $functionId, $inline, $trimFrames);
                $bytes = strlen($source);
                if ($bytes > $this->maxSourceBytes || $bytes > $this->maxBytes) {
                    throw new \RuntimeException('Generated PHP exceeds cache byte limits');
                }
                $manifest = [
                    'format' => self::FORMAT_VERSION,
                    'key' => $key,
                    'sourceSha256' => hash('sha256', $source),
                    'bytes' => $bytes,
                ];
                // Write PHP first, then manifest; absent manifest means the
                // artifact cannot be loaded and is repaired next time.
                $this->publish($path, $source);
                $this->publish($manifestPath, json_encode($manifest, JSON_THROW_ON_ERROR) . "\n");
            }

            // Source was hash-validated under the same exclusive lock; keep
            // the lock while requiring to avoid a concurrent pruning race.
            $vm = new PhpVm($functionId, $path);
            $this->lastPath = $path;
            $this->prune($key);
            return $vm;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Clean only files belonging to this cache, never unrelated files. */
    public function clear(): void
    {
        $lock = $this->lock();
        try {
            foreach ($this->artifactKeys() as $key) {
                $this->deletePair($this->sourcePath($key), $this->manifestPath($key));
            }
            $this->lastPath = null;
            $this->lastHit = false;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Full fingerprint without generating PHP. Verified-program-only caller. */
    public static function programKey(
        Program $program,
        int $functionId,
        bool $inline = false,
        bool $trimFrames = false,
    ): string {
        $functions = [];
        foreach ($program->functions as $function) {
            $functions[] = [
                $function->name,
                $function->registerTypes,
                $function->arity,
                $function->returnType,
                $function->code,
            ];
        }
        return hash('sha256', serialize([
            self::FORMAT_VERSION,
            PHP_VERSION_ID,
            self::compilerSignature(),
            $functionId,
            $inline,
            $trimFrames,
            $functions,
            $program->constants,
        ]));
    }

    /**
     * Automatically invalidates when emitted PHP, its ABI, the verifier, or
     * the source-level VM semantics change. Recomputed per PHP process.
     */
    private static function compilerSignature(): string
    {
        static $fingerprint = null;
        if ($fingerprint === null) {
            $files = [
                'CallPhpEmitter.php', 'ContinuationLimitExceeded.php',
                'ExecutionLimitExceeded.php',
                'FunctionDef.php', 'InlineEmitter.php', 'Instruction.php',
                'Opcode.php', 'PhpEmitter.php', 'PhpVm.php', 'Program.php',
                'RegisterLiveness.php', 'Types.php', 'VerificationException.php',
                'Verifier.php', 'VmFault.php',
            ];
            $hashes = [];
            foreach ($files as $file) {
                $digest = hash_file('sha256', __DIR__ . '/' . $file);
                if ($digest === false) throw new \RuntimeException('Cannot fingerprint compiler file');
                $hashes[] = [$file, $digest];
            }
            $fingerprint = hash('sha256', serialize($hashes));
        }
        return $fingerprint;
    }

    /** @return resource */
    private function lock()
    {
        $path = $this->directory . '/.phx-cache.lock';
        if (is_link($path)) throw new \RuntimeException('Cache lock must not be a symlink');
        $lock = fopen($path, 'c+');
        if ($lock === false) throw new \RuntimeException('Cannot open cache lock');
        if (!flock($lock, LOCK_EX)) {
            fclose($lock);
            throw new \RuntimeException('Cannot acquire cache lock');
        }
        return $lock;
    }

    private function sourcePath(string $key): string
    {
        return $this->directory . '/phx-' . $key . '.php';
    }
    private function manifestPath(string $key): string
    {
        return $this->directory . '/phx-' . $key . '.meta.json';
    }

    /** @return array<string,mixed>|null */
    private function validManifest(string $key, string $path, string $manifestPath): ?array
    {
        if (is_link($path) || is_link($manifestPath)
            || !is_file($path) || !is_file($manifestPath)) return null;
        $raw = file_get_contents($manifestPath);
        if ($raw === false || strlen($raw) > 4096) return null;
        try {
            $data = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($data)
            || ($data['format'] ?? null) !== self::FORMAT_VERSION
            || ($data['key'] ?? null) !== $key
            || !is_string($data['sourceSha256'] ?? null)
            || preg_match('/^[0-9a-f]{64}$/D', $data['sourceSha256']) !== 1
            || !is_int($data['bytes'] ?? null)
            || $data['bytes'] < 1 || $data['bytes'] > $this->maxSourceBytes
            || $data['bytes'] > $this->maxBytes) return null;
        $size = filesize($path);
        if ($size === false || $size !== $data['bytes']) return null;
        $digest = hash_file('sha256', $path);
        return $digest !== false && hash_equals($data['sourceSha256'], $digest)
            ? $data
            : null;
    }

    private function publish(string $path, string $content): void
    {
        // Only the owning, locked writer may create files inside this private
        // directory. tempnam uses an unpredictable private staging path.
        $temp = tempnam($this->directory, '.phx-stage-');
        if ($temp === false) throw new \RuntimeException('Cannot create cache staging file');
        try {
            if (file_put_contents($temp, $content, LOCK_EX) !== strlen($content)) {
                throw new \RuntimeException('Cannot write cache staging file');
            }
            chmod($temp, 0600);
            if (!rename($temp, $path)) {
                throw new \RuntimeException('Cannot publish cache artifact');
            }
        } finally {
            if (is_file($temp)) unlink($temp);
        }
    }

    private function deletePair(string $source, string $manifest): void
    {
        if (function_exists('opcache_invalidate') && is_file($source) && !is_link($source)) {
            @opcache_invalidate($source, true);
        }
        foreach ([$manifest, $source] as $file) {
            if (is_file($file) || is_link($file)) {
                if (!unlink($file)) throw new \RuntimeException('Cannot remove cache artifact');
            }
        }
    }

    /** @return list<string> */
    private function artifactKeys(): array
    {
        $keys = [];
        foreach (scandir($this->directory) ?: [] as $filename) {
            if (preg_match('/^phx-([0-9a-f]{64})\.(?:meta\.json|php)$/D', $filename, $m) === 1) {
                $keys[$m[1]] = true;
            }
        }
        return array_keys($keys);
    }

    /** Evict oldest managed pairs to meet both entry and source-byte caps. */
    private function prune(string $protectedKey): void
    {
        $rows = [];
        $totalBytes = 0;
        foreach ($this->artifactKeys() as $key) {
            $source = $this->sourcePath($key);
            $manifest = $this->manifestPath($key);
            $size = is_file($source) && !is_link($source) ? filesize($source) : 0;
            $size = $size === false ? 0 : $size;
            $time = is_file($manifest) && !is_link($manifest) ? filemtime($manifest) : 0;
            $time = $time === false ? 0 : $time;
            $rows[] = [$key, $size, $time];
            $totalBytes += $size;
        }
        usort($rows, static fn(array $a, array $b): int =>
            ($a[2] <=> $b[2]) ?: strcmp($a[0], $b[0]));

        $entries = count($rows);
        foreach ($rows as [$key, $size]) {
            if ($entries <= $this->maxEntries && $totalBytes <= $this->maxBytes) break;
            if ($key === $protectedKey) continue;
            $this->deletePair($this->sourcePath($key), $this->manifestPath($key));
            --$entries;
            $totalBytes -= $size;
            ++$this->evictions;
        }
    }
}
