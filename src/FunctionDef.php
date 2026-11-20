<?php
declare(strict_types=1);

namespace PhxVm;

/** Arguments occupy registers r0..r(arity-1). */
final class FunctionDef
{
    /** @param list<string> $registerTypes @param list<int> $code */
    public function __construct(
        public readonly string $name,
        public readonly array $registerTypes,
        public readonly int $arity,
        public readonly string $returnType,
        public readonly array $code,
    ) {}
}
