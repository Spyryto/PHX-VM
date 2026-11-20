# PHX-VM

Experimental, PHP-first register virtual machine, independent from Nail or any other source language.

The project starts with a verified 32-bit register bytecode interpreter (T0). Later tiers may lower hot paths into basic blocks and specialized PHP suitable for OPcache, always preserving correctness.

**Status:** early prototype, PHP 8.2+, no external runtime dependencies. See the source, tests and architecture notes in subsequent commits.

No license has been selected yet.
