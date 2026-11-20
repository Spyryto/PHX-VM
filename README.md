# PHX-VM

Experimental **PHP-first register VM**, independent of Nail or any specific source language.

**Status:** v0.1 T0 plus a prototype T1 predecoded basic-block executor. PHP 8.2+, no runtime dependencies, no framework. Neither JIT nor a PHP source emitter is implemented.

## Run

~~~sh
php tests/run.php
php tests/t1.php
php examples/sum-tail.php 10000
php bench/compare.php 30000
~~~

- **T0: PhxVm\Vm** executes compact verified 32-bit register bytecode.
- **T1 prototype: PhxVm\BlockVm** verifies the same program, decodes its instructions once, identifies basic-block leaders and executes predecoded blocks. It still dispatches each opcode with a switch; superinstructions and emitted PHP are future work.
- **Both** check instruction budgets per original instruction, preserve strict types/checked integer overflow, handle CALL with VM-owned frames and reuse frames for TAILCALL.
- **Differential tests** compare T0 and T1 values, exceptions/messages, exact lastSteps(), loops, calls, recursion, budget boundaries and reproducible generated programs.
- **Benchmarks** print elapsed-time medians for identical bytecode. No speedup is asserted; T1 may be slower on some PHP versions/workloads.

The next experiments are profiling, superinstructions and eventually T2 generated PHP compatible with OPcache. Changes must not compromise semantic equivalence or the verifier.

See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for the bytecode ISA, correctness rules and planned tiers.

**License:** not selected yet.
