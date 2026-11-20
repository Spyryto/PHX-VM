<?php
declare(strict_types=1);

namespace PhxVm;

/**
 * T1-only synthetic operations. Negative IDs cannot collide with the
 * canonical unsigned 8-bit opcode space. They are never serialized.
 */
final class Superopcode
{
    public const IADD_ISUB = -1;
    public const IEQ_BR_TRUE = -2;
    public const IEQ_BR_FALSE = -3;
    public const ILT_BR_TRUE = -4;
    public const ILT_BR_FALSE = -5;
}
