<?php
declare(strict_types=1);

namespace PhxVm;

/** Fixed-width 32-bit instructions: OP:8 / A:8 / B:8 / C:8, or OP:8 / A:8 / Bx:16. */
final class Instruction
{
    public static function abc(int $op, int $a = 0, int $b = 0, int $c = 0): int
    {
        foreach ([$op, $a, $b, $c] as $byte) {
            if ($byte < 0 || $byte > 255) {
                throw new \InvalidArgumentException('ABC fields must be unsigned bytes');
            }
        }
        return $op | ($a << 8) | ($b << 16) | ($c << 24);
    }

    public static function abx(int $op, int $a, int $bx): int
    {
        if ($bx < 0 || $bx > 65535) {
            throw new \InvalidArgumentException('ABx operand must fit in 16 bits');
        }
        return self::abc($op, $a, $bx & 255, $bx >> 8);
    }

    public static function op(int $word): int { return $word & 255; }
    public static function a(int $word): int { return ($word >> 8) & 255; }
    public static function b(int $word): int { return ($word >> 16) & 255; }
    public static function c(int $word): int { return ($word >> 24) & 255; }
    public static function bx(int $word): int { return ($word >> 16) & 65535; }
}
