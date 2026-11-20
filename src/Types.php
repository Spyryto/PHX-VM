<?php
declare(strict_types=1);

namespace PhxVm;

final class Types
{
    public const INT = 'int';
    public const FLOAT = 'float';
    public const BOOL = 'bool';
    public const STRING = 'string';
    public const NULL = 'null';

    public static function valid(string $type): bool
    {
        return in_array($type, [self::INT, self::FLOAT, self::BOOL, self::STRING, self::NULL], true);
    }

    public static function of(mixed $value): string
    {
        return match (true) {
            is_int($value) => self::INT,
            is_float($value) => self::FLOAT,
            is_bool($value) => self::BOOL,
            is_string($value) => self::STRING,
            $value === null => self::NULL,
            default => throw new \InvalidArgumentException('Unsupported VM value: ' . get_debug_type($value)),
        };
    }
}
