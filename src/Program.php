<?php
declare(strict_types=1);

namespace PhxVm;

final class Program
{
    /** @param list<FunctionDef> $functions @param list<int|float|bool|string|null> $constants */
    public function __construct(
        public readonly array $functions,
        public readonly array $constants = [],
    ) {}
}
