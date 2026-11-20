<?php
declare(strict_types=1);

namespace PhxVm;

final class Opcode
{
    public const CONST_ = 1;
    public const MOVE = 2;
    public const IADD = 3;
    public const ISUB = 4;
    public const IMUL = 5;
    public const ILT = 6;
    public const IEQ = 7;
    public const JMP = 8;
    public const BR_TRUE = 9;
    public const BR_FALSE = 10;
    public const CALL = 11;
    public const TAILCALL = 12;
    public const RET = 13;

    public static function name(int $op): string
    {
        return match ($op) {
            self::CONST_ => 'CONST', self::MOVE => 'MOVE',
            self::IADD => 'IADD', self::ISUB => 'ISUB', self::IMUL => 'IMUL',
            self::ILT => 'ILT', self::IEQ => 'IEQ', self::JMP => 'JMP',
            self::BR_TRUE => 'BR_TRUE', self::BR_FALSE => 'BR_FALSE',
            self::CALL => 'CALL', self::TAILCALL => 'TAILCALL',
            self::RET => 'RET', default => 'UNKNOWN(' . $op . ')',
        };
    }
}
