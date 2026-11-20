# PHX-VM

An experimental **PHP-first register VM**, independent from Nail or any source language.

Status: v0.1 T0 interpreter (PHP 8.2+, no dependencies). Packed 32-bit register bytecode, a mandatory verifier for types, jumps and definite assignment, checked integer arithmetic, direct calls and tail calls, explicit frames and an instruction budget.

Run tests with: php tests/run.php

Run an example with: php examples/sum-tail.php 10000

Future tiers: T1 basic blocks/superinstructions and T2 generated PHP suitable for OPcache. No performance claims until benchmarked.

See docs/ARCHITECTURE.md for the roadmap and bytecode specification. License not yet selected.
