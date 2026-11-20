# PHX-VM

Experimental **PHP-first register VM**, independent of Nail or any specific source language.

**Status:** v0.1 T0 plus experimental T1 predecoded and fused basic-block executors. PHP 8.2+, no runtime dependencies, no framework. Neither JIT nor a PHP source emitter is implemented.

## Run

~~~sh
php tests/run.php
php tests/t1.php
php tests/fusion.php
php examples/sum-tail.php 10000
php bench/compare.php 30000
~~~

- **T0: PhxVm\Vm** executes compact verified 32-bit register bytecode.
- **T1: PhxVm\BlockVm** verifies and predecodes the same program into basic blocks. By default, it still dispatches individual opcodes. Pass `true` as the second constructor argument to enable optional, runtime-only superinstruction fusion (`IEQ`/`ILT` + conditional branch and `IADD` + `ISUB`). No canonical bytecode changes.
- **Both** check instruction budgets per original instruction, preserve strict types/checked integer overflow, handle CALL with VM-owned frames and reuse frames for TAILCALL.
- **Differential tests** compare T0, ordinary T1 and fused T1 values, errors, `lastSteps()`, loops, calls, deep recursion, budget boundaries, jump targets and reproducible generated programs.
- **Benchmarks** print elapsed-time medians for identical bytecode. No speedup is asserted; T1 may be slower on some PHP versions/workloads.

Next experiments: profiling and adding carefully selected superinstructions, followed by T2 PHP generation compatible with OPcache. Changes must not compromise semantic equivalence or the verifier.

See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for the bytecode ISA, correctness rules and planned tiers.

**License:** not selected yet.
