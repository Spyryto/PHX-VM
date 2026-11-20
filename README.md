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
php examples/sum-tail.php 10000
php bench/compare.php 30000
php bench/calls.php 10000
php bench/inline.php 20000
php bench/frames.php 2000
php bench/end-to-end.php 2000 7
~~~

- **T0: PhxVm\Vm** executes compact verified 32-bit register bytecode.
- **T1: PhxVm\BlockVm** verifies and predecodes the same program into basic blocks. By default, it still dispatches individual opcodes. Pass `true` as the second constructor argument to enable optional, runtime-only superinstruction fusion (`IEQ`/`ILT` + conditional branch and `IADD` + `ISUB`). No canonical bytecode changes.
- **Both** check instruction budgets per original instruction, preserve strict types/checked integer overflow, handle CALL with VM-owned frames and reuse frames for TAILCALL.
- **Differential tests** compare T0, ordinary T1 and fused T1 values, errors, `lastSteps()`, loops, calls, deep recursion, budget boundaries, jump targets and reproducible generated programs.
- **Benchmarks** print elapsed-time medians for identical bytecode. No speedup is asserted; T1 may be slower on some PHP versions/workloads.

**T2:** `PhpEmitter::source($program, $functionId)` verifies the complete program and emits a PHP closure using local registers and direct goto control-flow edges. For a call-free entry function it preserves the original fast path. When the entry contains direct calls, it instead emits *all statically reachable functions* into one PHP closure. `CALL` saves an explicit VM continuation (return label, destination, caller registers); `TAILCALL` transfers directly without growing the continuation stack. Non-tail and mutual recursion never recurse into PHP. Execution preserves checked integer overflow and per-original-instruction budgets. Save generated source to a **trusted PHP file** and load it with `new PhpVm($functionId, $path)`.

**T2 opt-in leaf inlining:** `PhpEmitter::source($program, $entry, true)` directly incorporates small straight-line leaf callees (at most 8 original bytecode instructions, final RET, no branch or nested CALL) into the generated PHP. Each callsite has its own private register variables. The original CALL charge and every callee instruction charge remain observable, including exception ordering and `lastSteps()`. An inlined CALL does not allocate a continuation; an inlined TAILCALL returns through its existing outer continuation. Code growth is capped at 128 duplicated original instructions per compiled module. **Disabled by default**; recursive, branched and larger functions retain the ordinary T2 path. No change to the PHX bytecode ISA.

**T2 opt-in live-register continuations:** `PhpEmitter::source($program, $entry, false, true)` enables backward register liveness analysis to save only the caller registers actually needed after each non-inlined `CALL`. The return destination is excluded because the callee overwrites it. Control-flow joins, loops and branches are analyzed to a fixed point. `TAILCALL` still adds no continuation. It can also be combined with inlining via `PhpEmitter::source($program, $entry, true, true)`. The default frame representation is **unchanged**. Each original instruction still consumes one unit of the execution budget and has the same exception order as T0.

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

`opcache_is_script_cached()` and script hit counters are reported when available. Reusing an artifact path does *not* itself prove that the second CLI process got a disk-cache hit. OPcache's shared-memory behavior in a long-lived FPM server is not reproduced by independent CLI processes. The main benchmark also excludes process startup and network/request handling. Other benchmarks (`bench/calls.php`, `bench/frames.php`) continue to isolate runtime from generation/load costs.

Next experiments: long-lived PHP-FPM/worker measurement, cold and warm OPcache with realistic request routing, and control-flow-aware inlining. `bench/frames.php` measures wide-register non-tail recursion with all-register versus live-register continuations, separately reporting compilation and loading costs. Changes must not compromise semantic equivalence or the verifier.

See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for the bytecode ISA, correctness rules and planned tiers.

**License:** not selected yet.
