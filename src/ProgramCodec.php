<?php
declare(strict_types=1);

namespace PhxVm;

/**
 * PHX bytecode wire format v1 (experimental; not a promised pre-1.0 ABI).
 *
 * Deterministic, little-endian, explicitly tagged, no PHP serialization,
 * class instantiation, eval or executable-file loading. Decoding verifies
 * both structural framing and the complete original register bytecode.
 *
 * Header: "PHX\0", u16 version=1, u16 reserved=0,
 *         u16 function count, u32 constant count.
 * Constants: u8 tag (0 null, 1 false, 2 true, 3 i64 LE,
 *            4 f64 BE, 5 byte-string [u32 length, bytes]).
 * Functions: u16 name byte-length, name bytes, u16 arity,
 *            u8 return type, u16 register count, register type bytes,
 *            u32 instruction count, u32 LE instruction words.
 *
 * Float64 is IEEE 754 binary64 big-endian (including signed zero, infinities
 * and NaN). String values and function names are opaque byte sequences.
 * Integer constants require 64-bit PHP and are signed two's-complement.
 */
final class ProgramCodec
{
    public const VERSION = 1;
    public const MAX_BYTES = 8388608;      // 8 MiB input/output
    public const MAX_WORDS = 200000;       // across all functions
    public const MAX_STRING_BYTES = 1048576;
    public const MAX_NAME_BYTES = 255;

    /** Encode a verified Program to canonical PHX v1 bytes. */
    public static function encode(Program $program): string
    {
        self::require64BitHost();
        Verifier::verify($program);

        $parts = [];
        $size = 0;
        $push = static function (string $chunk) use (&$parts, &$size): void {
            $size += strlen($chunk);
            if ($size > self::MAX_BYTES) {
                throw new BytecodeFormatException('PHX bytecode exceeds maximum size');
            }
            $parts[] = $chunk;
        };

        $push("PHX\0" . pack('vvvV', self::VERSION, 0,
            count($program->functions), count($program->constants)));

        foreach ($program->constants as $constant) {
            switch (Types::of($constant)) {
                case Types::NULL:
                    $push("\x00");
                    break;
                case Types::BOOL:
                    $push($constant ? "\x02" : "\x01");
                    break;
                case Types::INT:
                    $push("\x03" . pack('P', $constant));
                    break;
                case Types::FLOAT:
                    $push("\x04" . pack('E', $constant));
                    break;
                case Types::STRING:
                    $length = strlen($constant);
                    if ($length > self::MAX_STRING_BYTES) {
                        throw new BytecodeFormatException('PHX string constant exceeds limit');
                    }
                    $push("\x05" . pack('V', $length) . $constant);
                    break;
            }
        }

        $totalWords = 0;
        foreach ($program->functions as $function) {
            $nameLength = strlen($function->name);
            if ($nameLength > self::MAX_NAME_BYTES) {
                throw new BytecodeFormatException('PHX function name exceeds limit');
            }
            $wordCount = count($function->code);
            $totalWords += $wordCount;
            if ($totalWords > self::MAX_WORDS) {
                throw new BytecodeFormatException('PHX total instruction count exceeds limit');
            }
            $push(pack('v', $nameLength) . $function->name);
            $push(pack('vCv', $function->arity,
                self::typeTag($function->returnType), count($function->registerTypes)));
            $tags = '';
            foreach ($function->registerTypes as $type) $tags .= chr(self::typeTag($type));
            $push($tags . pack('V', $wordCount));
            foreach ($function->code as $word) {
                $push(pack('V', $word));
            }
        }

        return implode('', $parts);
    }

    /** Decode and verify a PHX v1 Program. Never executes imported bytes. */
    public static function decode(string $binary): Program
    {
        self::require64BitHost();
        $length = strlen($binary);
        if ($length > self::MAX_BYTES) {
            throw new BytecodeFormatException('PHX bytecode exceeds maximum size');
        }

        $offset = 0;
        if (self::take($binary, $offset, 4) !== "PHX\0") {
            throw new BytecodeFormatException('Invalid PHX bytecode magic');
        }
        $version = self::u16($binary, $offset);
        if ($version !== self::VERSION) {
            throw new BytecodeFormatException('Unsupported PHX bytecode version ' . $version);
        }
        if (self::u16($binary, $offset) !== 0) {
            throw new BytecodeFormatException('Reserved PHX header bits must be zero');
        }

        $functionCount = self::u16($binary, $offset);
        $constantCount = self::u32($binary, $offset);
        if ($functionCount < 1 || $functionCount > 256 || $constantCount > 65536) {
            throw new BytecodeFormatException('PHX function or constant count exceeds limit');
        }

        $constants = [];
        for ($i = 0; $i < $constantCount; ++$i) {
            $tag = self::u8($binary, $offset);
            switch ($tag) {
                case 0:
                    $constants[] = null;
                    break;
                case 1:
                    $constants[] = false;
                    break;
                case 2:
                    $constants[] = true;
                    break;
                case 3:
                    $constants[] = unpack('P', self::take($binary, $offset, 8))[1];
                    break;
                case 4:
                    $constants[] = unpack('E', self::take($binary, $offset, 8))[1];
                    break;
                case 5:
                    $bytes = self::u32($binary, $offset);
                    if ($bytes > self::MAX_STRING_BYTES) {
                        throw new BytecodeFormatException('PHX string constant exceeds limit');
                    }
                    $constants[] = self::take($binary, $offset, $bytes);
                    break;
                default:
                    throw new BytecodeFormatException('Unknown PHX constant tag ' . $tag);
            }
        }

        $functions = [];
        $totalWords = 0;
        for ($id = 0; $id < $functionCount; ++$id) {
            $nameLength = self::u16($binary, $offset);
            if ($nameLength > self::MAX_NAME_BYTES) {
                throw new BytecodeFormatException('PHX function name exceeds limit');
            }
            $name = self::take($binary, $offset, $nameLength);
            $arity = self::u16($binary, $offset);
            $return = self::tagType(self::u8($binary, $offset));
            $registerCount = self::u16($binary, $offset);
            if ($registerCount < 1 || $registerCount > 256 || $arity > $registerCount) {
                throw new BytecodeFormatException('PHX function signature exceeds register limits');
            }
            $types = [];
            for ($r = 0; $r < $registerCount; ++$r) {
                $types[] = self::tagType(self::u8($binary, $offset));
            }
            $wordCount = self::u32($binary, $offset);
            $totalWords += $wordCount;
            if ($wordCount < 1 || $wordCount > 65536 || $totalWords > self::MAX_WORDS) {
                throw new BytecodeFormatException('PHX instruction count exceeds limit');
            }
            // Fail before allocating a list of instruction words.
            if ($wordCount * 4 > strlen($binary) - $offset) {
                throw new BytecodeFormatException('Truncated PHX instruction payload');
            }
            $code = [];
            for ($pc = 0; $pc < $wordCount; ++$pc) {
                $code[] = self::u32($binary, $offset);
            }
            $functions[] = new FunctionDef($name, $types, $arity, $return, $code);
        }

        if ($offset !== $length) {
            throw new BytecodeFormatException('Trailing data after PHX program');
        }
        $program = new Program($functions, $constants);
        try {
            Verifier::verify($program);
        } catch (VerificationException $e) {
            throw new BytecodeFormatException('Invalid PHX bytecode: ' . $e->getMessage(), 0, $e);
        }
        return $program;
    }

    /**
     * Read at most MAX_BYTES+1 bytes, then decode and verify.
     * Rejects oversized files before attempting to decode them.
     */
    public static function decodeFile(string $path): Program
    {
        $binary = @file_get_contents($path, false, null, 0, self::MAX_BYTES + 1);
        if ($binary === false) {
            throw new BytecodeFormatException('Cannot read PHX bytecode file');
        }
        return self::decode($binary);
    }

    private static function require64BitHost(): void
    {
        if (PHP_INT_SIZE < 8) {
            throw new BytecodeFormatException('PHX bytecode v1 requires 64-bit PHP');
        }
    }

    private static function typeTag(string $type): int
    {
        return match ($type) {
            Types::INT => 0,
            Types::FLOAT => 1,
            Types::BOOL => 2,
            Types::STRING => 3,
            Types::NULL => 4,
            default => throw new BytecodeFormatException('Unknown PHX register type'),
        };
    }

    private static function tagType(int $tag): string
    {
        return match ($tag) {
            0 => Types::INT,
            1 => Types::FLOAT,
            2 => Types::BOOL,
            3 => Types::STRING,
            4 => Types::NULL,
            default => throw new BytecodeFormatException('Unknown PHX register type tag ' . $tag),
        };
    }

    private static function take(string $binary, int &$offset, int $count): string
    {
        if ($count < 0 || $count > strlen($binary) - $offset) {
            throw new BytecodeFormatException('Truncated PHX bytecode');
        }
        $chunk = substr($binary, $offset, $count);
        $offset += $count;
        return $chunk;
    }

    private static function u8(string $binary, int &$offset): int
    {
        return ord(self::take($binary, $offset, 1));
    }

    private static function u16(string $binary, int &$offset): int
    {
        return unpack('v', self::take($binary, $offset, 2))[1];
    }

    private static function u32(string $binary, int &$offset): int
    {
        return unpack('V', self::take($binary, $offset, 4))[1];
    }
}
