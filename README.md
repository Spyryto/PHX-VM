# PHX-VM

Experimental **PHP-first register VM**, independent of Nail or any specific source language.

**Status:** T0 register interpreter, T1 predecoded/fused interpreter, and experimental T2 PHP emitter with direct calls and recursion. PHP 8.2+, no runtime dependencies, no framework. No JIT is implemented; the T2 emitter outputs ordinary PHP source.

## Run

~~~sh
php tests/run.php
php tests/t1.php
php tests/fusion.php
php tests/t2.php
php tests/t2-calls.php
php tests/inlining.php
php tests/liveness.php
php tests/tiering.php
php tests/cache.php
php tests/adaptive.php
php tests/codec.php
php examples/sum-tail.php 10000
php examples/bytecode.php 500
php bench/compare.php 30000
php bench/calls.php 10000
php bench/inline.php 20000
php bench/frames.php 2000
php bench/end-to-end.php 2000 7
php bench/end-to-end.php 2000 7 --json
php bench/tiering.php 2000 100
php bench/cache.php 2000 7
php bench/adaptive.php 2000 100
~~~

- **T0: PhxVm\Vm** executes compact verified 32-bit register bytecode.
- **T1: PhxVm\BlockVm** verifies and predecodes the same program into basic blocks. By default, it still dispatches individual opcodes. Pass `true` as the second constructor argument to enable optional, runtime-only superinstruction fusion (`IEQ`/`ILT` + conditional branch and `IADD` + `ISUB`). No canonical bytecode changes.
- **Both** check instruction budgets per original instruction, preserve strict types/checked integer overflow, handle CALL with VM-owned frames and reuse frames for TAILCALL.
- **Differential tests** compare T0, ordinary T1 and fused T1 values, errors, `lastSteps()`, loops, calls, deep recursion, budget boundaries, jump targets and reproducible generated programs.
- **Benchmarks** print elapsed-time medians for identical bytecode. No speedup is asserted; T1 may be slower on some PHP versions/workloads.

**T2:** `PhpEmitter::source($program, $functionId)` verifies the complete program and emits a PHP closure using local registers and direct goto control-flow edges. For a call-free entry function it preserves the original fast path. When the entry contains direct calls, it instead emits *all statically reachable functions* into one PHP closure. `CALL` saves an explicit VM continuation (return label, destination, caller registers); `TAILCALL` transfers directly without growing the continuation stack. Non-tail and mutual recursion never recurse into PHP. Execution preserves checked integer overflow and per-original-instruction budgets. Save generated source to a **trusted PHP file** and load it with `new PhpVm($functionId, $path)`.

**T2 opt-in leaf inlining:** `PhpEmitter::source($program, $entry, true)` directly incorporates small straight-line leaf callees (at most 8 original bytecode instructions, final RET, no branch or nested CALL) into the generated PHP. Each callsite has its own private register variables. The original CALL charge and every callee instruction charge remain observable, including exception ordering and `lastSteps()`. An inlined CALL does not allocate a continuation; an inlined TAILCALL returns through its existing outer continuation. Code growth is capped at 128 duplicated original instructions per compiled module. **Disabled by default**; recursive, branched and larger functions retain the ordinary T2 path. No change to the PHX bytecode ISA.

**T2 opt-in live-register continuations:** `PhpEmitter::source($program, $entry, false, true)` enables backward register liveness analysis to save only the caller registers actually needed after each non-inlined `CALL`. The return destination is excluded because the callee overwrites it. Control-flow joins, loops and branches are analyzed to a fixed point. `TAILCALL` still adds no continuation. It can also be combined with inlining via `PhpEmitter::source($program, $entry, true, true)`. The default frame representation is **unchanged**. Each original instruction still consumes one unit of the execution budget and has the same exception order as T0.

**T0 profiler (opt-in):** pass `new ExecutionProfile()` as the second argument to `new Vm($program, $profile)`. The profiler accumulates deterministic charged-opcode counts, function entries, CALL/TAILCALL/RET counts, logical continuation depth and per-entry successful/failed run statistics. It also records budget-rejected attempts separately; `lastSteps()` continues to include the rejected attempt exactly as before. Use `$profile->snapshot()` for a sorted report and `$profile->reset()` to clear it. Without a profile object, T0 keeps its usual execution path.

**Tiering policy (advisory, no automatic compilation):** `TieringPolicy::assess($profile, $entry, $futureRuns, $t0MsPerStep, $t2MsPerStep, $compileAndLoadMs)` returns `collect_more`, `stay_t0` or `candidate_t2`. A candidate means only that the supplied calibration estimates that sufficient future work will amortize T2 preparation, after an adjustable safety factor. Default gates require at least 3 completed samples and 100 instructions per successful execution. Supply *externally measured* T0/T2 costs from equivalent workloads and the same host. **Do not calibrate using time measurements from the instrumented T0:** counter collection adds overhead. The command `php bench/tiering.php 2000 100` demonstrates independent calibration using uninstrumented T0 and precompiled/warm T2; it never switches the running VM. Estimates are not universal guarantees, and the policy has no persistent cache awareness yet.

**T2 compiled artifact cache (PR #9):** `PhpArtifactCache` persists verified, generated PHP on disk and loads it on subsequent calls **without repeating PHP source generation**. Cache keys are built from the complete Program representation, function entry, inlining/liveness flags, `PHP_VERSION_ID`, a cache-format version and SHA-256 fingerprints of the compiler and relevant runtime sources. Rebuilding/upgrading the compiler therefore changes the key automatically after the PHP application process restarts. A sidecar manifest records the SHA-256 and byte length of the emitted PHP; every hit checks integrity before `require`. The cache uses a private 0700 directory, an interprocess `flock`, atomic rename publication and size/entry limits with least-recently-used-by-filetime eviction. Invalid or truncated pairs are repaired, and `opcache_invalidate` is used when removing stale source files where supported. The default `Vm` and `PhpEmitter` APIs remain unchanged; nothing is compiled automatically.

Example:

~~~php
$cache = new \PhxVm\PhpArtifactCache(__DIR__ . '/private-t2-cache');
$vm = $cache->load($program, $entry, inline: true, trimFrames: true);
$result = $vm->run($entry, $arguments);
$stats = $cache->stats();
~~~

The cache accepts only Program objects that pass bytecode verification. **The directory must be trusted, writable only by the host application owner and not a symlink. PHP generated by the compiler is still executable host code, not a sandbox.** Do not mount this directory as untrusted shared storage or expose it to external users. Use the default `maxEntries=256`, `maxBytes=64 MiB` (PHP source only) and `maxSourceBytes=4 MiB` limits or tighten them for your host; `clear()` removes only managed artifacts. Large applications will need coordinated cache-lifetime and worker-restart policies. `bench/cache.php` compares the first cache load (including compilation and first run), subsequent verified cache hits (including file hashing and require), and repeated execution of an already-loaded closure.

**Opt-in AdaptiveVm (PR #10):** `AdaptiveVm` brings the T0 profiler, `TieringPolicy`, and `PhpArtifactCache` together. The first completed invocations of each entry run on instrumented T0; after enough successful samples, a policy based on **externally calibrated** uninstrumented T0/T2 costs can promote the entry to T2. A promoted entry holds its `PhpVm` closure in memory: subsequent calls do not re-verify, hash or load the cache artifact. Multiple entries can independently use T0 and T2. Without a calibration for an entry **no automatic compilation occurs**. The cache lookup always verifies bytecode and generated PHP integrity.

~~~php
$cache = new \PhxVm\PhpArtifactCache(__DIR__ . '/private-t2-cache');
$calibration = new \PhxVm\TieringCalibration(
    anticipatedRuns: 200,
    t0MsPerStep: 0.00008,  // EXAMPLE values: measure on your host!
    t2MsPerStep: 0.00002,
    compileAndLoadMs: 0.75,
);
$vm = new \PhxVm\AdaptiveVm(
    $program, $cache, calibrations: [$entry => $calibration]
);
$result = $vm->run($entry, $arguments);
$backend = $vm->lastBackend(); // 'T0' or 'T2'
$status = $vm->stats($entry);
~~~

AdaptiveVm uses **lightweight T0 profiling by default**: it records completed/failed entry executions and original instruction counts *after* each run, avoiding the per-opcode instrumentation overhead. The `$vm->profile()->rootStats($entry)` counters remain accurate, while the detailed per-function opcode histogram is empty. Pass `detailedProfiling: true` to the AdaptiveVm constructor if you explicitly need call, opcode and continuation counters; this is slower. Full `ExecutionProfile` instrumentation remains independently available through `new Vm($program, $profile)`.

The policy is evaluated immediately before a **valid** entry invocation once enough successful T0 samples are available; thus default thresholds permit promotion on the fourth successful invocation. Compiler/cache load failures are caught before any bytecode has executed and can safely fall back to T0 (configurable); they are recorded and not retried until the caller explicitly resets calibration or demotes the entry. Exceptions thrown *during T2 execution* (including VM faults and instruction budgets) are **never** retried in T0. `lastSteps()` preserves the canonical instruction count on both tiers. `profile()` exposes T0-only observations, while `tier($entry)`, `lastBackend()`, `stats($entry)`, `setCalibration()` and `demote()` give explicit control and diagnostics.

`TieringCalibration` values are not measured by the instrumented interpreter: the profiling overhead would bias the decision. Use `php bench/tiering.php 2000 100` to calibrate uninstrumented T0 vs warmed T2 and `php bench/adaptive.php 2000 100` to observe actual tier transitions. The policy currently uses a simplified mean-cost-per-bytecode-instruction model and a caller-supplied future invocation forecast, **not** a universal or self-calibrating predictor. A cold one-shot program may still be faster under T0.

**Experimental binary PHX v1 interchange format (M4):** `ProgramCodec::encode($program)` produces deterministic, explicitly tagged binary bytecode, and `ProgramCodec::decode($bytes)` or `ProgramCodec::decodeFile($path)` loads only structurally valid, **fully verified** Programs. The format is independent of PHP serialization, eval or generated PHP files. It stores functions, typed registers, 32-bit instructions, exact scalar constants and their order; signed 64-bit integers, IEEE-754 floats and arbitrary byte strings are supported. The envelope includes a magic value, format version and reserved fields; unsupported versions, malformed data, overlarge inputs and invalid bytecode are rejected. The v1 importer limits total encoded bytes (8 MiB), aggregate instructions (200,000), string constant size (1 MiB), and the verifier's existing per-function limits. **Requires 64-bit PHP.** This format is experimental: no pre-1.0 backward compatibility is promised.

~~~php
$binary = \PhxVm\ProgramCodec::encode($program);
$loaded = \PhxVm\ProgramCodec::decode($binary);
$result = (new \PhxVm\Vm($loaded))->run($entry, $args);
~~~

See [docs/BYTECODE.md](docs/BYTECODE.md) for the exact field layout and a 32-byte golden binary vector, and `php examples/bytecode.php 500` for a working import/export example. This is a **program interchange format**, not a sandbox or a license to execute arbitrary host PHP: callers still need sensible instruction, memory and time budgets.

**Trust boundary:** generated PHP is **host code**, not a sandbox. Do not load externally supplied PHP files or untrusted source. The generated source may benefit from OPcache on stable filenames. Benchmarks exclude source generation and loading costs; the interprocedural path may be slower on small or cold programs. No JIT, closure values, indirect calls, or automatic transformation of non-tail recursion into a constant-memory algorithm is implemented.

**End-to-end and OPcache experiment:** `php bench/end-to-end.php 2000 7` compares T0 construction+run, T0 warm run, T2 cold (verified generation + write + require + first execution), T2 warm closure execution, and T2 repeated require+run from a stable content-addressed artifact. The benchmark includes a call-free loop and a leaf-call loop, with/without inlining. Correctness and original bytecode instruction budgets are checked on every run. Reports are observational; there are no performance thresholds in CI.

For an OPcache experiment that spans two independent CLI processes, enable CLI OPcache explicitly and **reuse both directories**:

~~~sh
mkdir -p /tmp/phx-vm-artifacts /tmp/phx-vm-opcache
php -d opcache.enable_cli=1 -d opcache.file_cache=/tmp/phx-vm-opcache \
  -d opcache.file_update_protection=0 bench/end-to-end.php 2000 7 /tmp/phx-vm-artifacts
php -d opcache.enable_cli=1 -d opcache.file_cache=/tmp/phx-vm-opcache \
  -d opcache.file_update_protection=0 bench/end-to-end.php 2000 7 /tmp/phx-vm-artifacts
~~~

Use a **private, trusted cache directory**. Content-hashed filenames are immutable and checked before being required; never load generated PHP from untrusted locations. Disabling the newly-written-file protection is appropriate only in this controlled benchmark for immutable files; do not copy that setting blindly to a production environment.

`opcache_is_script_cached()` and script hit counters are reported when available; the report also distinguishes configured JIT settings from an actually active JIT. Reusing an artifact path does *not* itself prove that the second CLI process got a disk-cache hit. OPcache's shared-memory behavior in a long-lived FPM server is not reproduced by independent CLI processes. The main benchmark also excludes process startup and network/request handling. Other benchmarks (`bench/calls.php`, `bench/frames.php`) continue to isolate runtime from generation/load costs.

Next M4 work: runtime resource quotas, strict public API contracts and packaging/release checks. Longer-term benchmarks should cover persistent PHP-FPM workers and representative data-structure/network workloads. Any frontend integration remains independent of Nail. `bench/frames.php` measures wide-register non-tail recursion with all-register versus live-register continuations, separately reporting compilation and loading costs. Changes must not compromise semantic equivalence or the verifier.

See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for the bytecode ISA, correctness rules and planned tiers.

**License:** not selected yet.
