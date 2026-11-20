# PHX-VM

Experimental **PHP-first register VM**, independent of Nail or any specific source language.

**Status:** T0 register interpreter, T1 predecoded/fused interpreter, and experimental T2 PHP emitter (call-free subset). PHP 8.2+, no runtime dependencies, no framework. No JIT is implemented; the T2 emitter outputs ordinary PHP source.

## Run

~~~sh
php tests/run.php
php tests/t1.php
php tests/fusion.php
php tests/t2.php
php examples/sum-tail.php 10000
php bench/compare.php 30000
~~~

- **T0: PhxVm\Vm** executes compact verified 32-bit register bytecode.
- **T1: PhxVm\BlockVm** verifies and predecodes the same program into basic blocks. By default, it still dispatches individual opcodes. Pass `true` as the second constructor argument to enable optional, runtime-only superinstruction fusion (`IEQ`/`ILT` + conditional branch and `IADD` + `ISUB`). No canonical bytecode changes.
- **Both** check instruction budgets per original instruction, preserve strict types/checked integer overflow, handle CALL with VM-owned frames and reuse frames for TAILCALL.
- **Differential tests** compare T0, ordinary T1 and fused T1 values, errors, `lastSteps()`, loops, calls, deep recursion, budget boundaries, jump targets and reproducible generated programs.
- **Benchmarks** print elapsed-time medians for identical bytecode. No speedup is asserted; T1 may be slower on some PHP versions/workloads.

**T2:** PhpEmitter::source($program, $functionId) verifies a program then produces a PHP closure using local variables and direct goto control-flow edges, with per-instruction budget checks. Save that source to a trusted PHP file, then load it using `new PhpVm($functionId, $path)`. The emitted code is intended for PHP/OPcache, but loading generated PHP executes host code and must never be treated as sandboxing. The first T2 milestone supports only call-free bytecode, including loops and branches. `CALL` and `TAILCALL` raise UnsupportedCompilationException at compilation time; use T0/T1 for them. Tests compare T0 and T2 results, exception types/messages and instruction counts. Benchmark numbers exclude code generation and file loading costs.

Next experiments: extend the PHP emitter to interprocedural calls, specialize more patterns and measure warm/cold OPcache. Changes must not compromise semantic equivalence or the verifier.

See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for the bytecode ISA, correctness rules and planned tiers.

**License:** not selected yet.
