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
php examples/sum-tail.php 10000
php bench/compare.php 30000
php bench/calls.php 10000
php bench/inline.php 20000
~~~

- **T0: PhxVm\Vm** executes compact verified 32-bit register bytecode.
- **T1: PhxVm\BlockVm** verifies and predecodes the same program into basic blocks. By default, it still dispatches individual opcodes. Pass `true` as the second constructor argument to enable optional, runtime-only superinstruction fusion (`IEQ`/`ILT` + conditional branch and `IADD` + `ISUB`). No canonical bytecode changes.
- **Both** check instruction budgets per original instruction, preserve strict types/checked integer overflow, handle CALL with VM-owned frames and reuse frames for TAILCALL.
- **Differential tests** compare T0, ordinary T1 and fused T1 values, errors, `lastSteps()`, loops, calls, deep recursion, budget boundaries, jump targets and reproducible generated programs.
- **Benchmarks** print elapsed-time medians for identical bytecode. No speedup is asserted; T1 may be slower on some PHP versions/workloads.

**T2:** `PhpEmitter::source($program, $functionId)` verifies the complete program and emits a PHP closure using local registers and direct goto control-flow edges. For a call-free entry function it preserves the original fast path. When the entry contains direct calls, it instead emits *all statically reachable functions* into one PHP closure. `CALL` saves an explicit VM continuation (return label, destination, caller registers); `TAILCALL` transfers directly without growing the continuation stack. Non-tail and mutual recursion never recurse into PHP. Execution preserves checked integer overflow and per-original-instruction budgets. Save generated source to a **trusted PHP file** and load it with `new PhpVm($functionId, $path)`.

**T2 opt-in leaf inlining:** `PhpEmitter::source($program, $entry, true)` directly incorporates small straight-line leaf callees (at most 8 original bytecode instructions, final RET, no branch or nested CALL) into the generated PHP. Each callsite has its own private register variables. The original CALL charge and every callee instruction charge remain observable, including exception ordering and `lastSteps()`. An inlined CALL does not allocate a continuation; an inlined TAILCALL returns through its existing outer continuation. Code growth is capped at 128 duplicated original instructions per compiled module. **Disabled by default**; recursive, branched and larger functions retain the ordinary T2 path. No change to the PHX bytecode ISA.

**Trust boundary:** generated PHP is **host code**, not a sandbox. Do not load externally supplied PHP files or untrusted source. The generated source may benefit from OPcache on stable filenames. Benchmarks exclude source generation and loading costs; the interprocedural path may be slower on small or cold programs. No JIT, closure values, indirect calls, or automatic transformation of non-tail recursion into a constant-memory algorithm is implemented.

Benchmarks: `bench/calls.php` compares tail and non-tail recursive workloads using T0, fused T1 and interprocedural T2; compilation and file loading times are printed separately from runtime medians. These quick measurements are not full OPcache/JIT studies.

Next experiments: benchmark more workloads and end-to-end compilation/OPcache costs, then consider control-flow-aware inlining and selective frame trimming. Changes must not compromise semantic equivalence or the verifier.

See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for the bytecode ISA, correctness rules and planned tiers.

**License:** not selected yet.
