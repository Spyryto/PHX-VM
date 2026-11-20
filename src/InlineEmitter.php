<?php
declare(strict_types=1);

namespace PhxVm;

/**
 * Conservative, opt-in T2 inlining of small, straight-line leaf functions.
 *
 * An inlined CALL does not save/restore the caller's registers or create a
 * continuation: the callee uses a private, callsite-scoped register bank.
 * Inlined TAILCALL returns to the *existing* continuation (if any).
 *
 * All original bytecode instructions, including CALL, callee RET and every
 * arithmetic operation, are still charged independently.
 */
final class InlineEmitter
{
    public const MAX_INSTRUCTIONS = 8;
    public const MAX_TOTAL_COPIED_INSTRUCTIONS = 128;

    public static function eligible(FunctionDef $callee): bool
    {
        $count = count($callee->code);
        if ($count < 1 || $count > self::MAX_INSTRUCTIONS) {
            return false;
        }

        foreach ($callee->code as $pc => $word) {
            $op = Instruction::op($word);
            if ($pc === $count - 1) {
                if ($op !== Opcode::RET) return false;
                continue;
            }
            // No control flow, calls, early RET or other effects in the body.
            if (!in_array($op, [
                Opcode::CONST_, Opcode::MOVE,
                Opcode::IADD, Opcode::ISUB, Opcode::IMUL,
                Opcode::ILT, Opcode::IEQ,
            ], true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Emits a fully self-contained, straight-line inlined callee at a verified
     * callsite. Must be used only after eligible() and whole-program verification.
     *
     * @return list<string> PHP source lines
     */
    public static function emit(
        Program $program,
        FunctionDef $callee,
        int $callerId,
        int $pc,
        int $argumentBase,
        int $destination,
        bool $tail,
    ): array {
        if (!self::eligible($callee)) {
            throw new \InvalidArgumentException('Callee is not eligible for inlining');
        }

        // Scoped by caller+PC to prevent collisions between separate callsites.
        $prefix = '$__phx_in_' . $callerId . '_' . $pc . '_r';
        $registerCount = count($callee->registerTypes);
        $target = [];
        $sources = [];
        for ($r = 0; $r < $registerCount; ++$r) {
            $target[] = $prefix . $r;
            $sources[] = $r < $callee->arity
                ? '$r' . ($argumentBase + $r)
                : 'null';
        }

        $lines = [
            '        // PHX_INLINE_BEGIN F' . $callerId . '_B' . $pc,
            // Evaluate all arguments before writing *any* private register.
            '        [' . implode(', ', $target) . '] = [' . implode(', ', $sources) . '];',
        ];

        foreach ($callee->code as $word) {
            $op = Instruction::op($word);
            $a = Instruction::a($word);
            $b = Instruction::b($word);
            $c = Instruction::c($word);
            $regA = $prefix . $a;
            $regB = $prefix . $b;
            $regC = $prefix . $c;

            $lines[] = '        if (++$steps > $maxSteps) throw new \PhxVm\ExecutionLimitExceeded(\'Instruction budget exceeded\');';
            switch ($op) {
                case Opcode::CONST_:
                    $lines[] = '        ' . $regA . ' = '
                        . var_export($program->constants[Instruction::bx($word)], true) . ';';
                    break;
                case Opcode::MOVE:
                    $lines[] = '        ' . $regA . ' = ' . $regB . ';';
                    break;
                case Opcode::IADD:
                case Opcode::ISUB:
                case Opcode::IMUL:
                    $operator = match ($op) {
                        Opcode::IADD => '+',
                        Opcode::ISUB => '-',
                        default => '*',
                    };
                    $temp = '$__phx_in_' . $callerId . '_' . $pc . '_value';
                    $lines[] = '        ' . $temp . ' = ' . $regB . ' ' . $operator . ' ' . $regC . ';';
                    $lines[] = '        if (!is_int(' . $temp . ')) throw new \PhxVm\VmFault('
                        . var_export('Integer overflow in ' . Opcode::name($op), true) . ');';
                    $lines[] = '        ' . $regA . ' = ' . $temp . ';';
                    break;
                case Opcode::ILT:
                case Opcode::IEQ:
                    $lines[] = '        ' . $regA . ' = (' . $regB
                        . ($op === Opcode::ILT ? ' < ' : ' === ') . $regC . ');';
                    break;
                case Opcode::RET:
                    if ($tail) {
                        // In a tailcall, return to the *previous* activation;
                        // do not add a new frame or resume the tail caller.
                        $lines[] = '        $returnValue = ' . $regA . ';';
                        $lines[] = '        if ($frames === []) return $returnValue;';
                        $lines[] = '        [$continuation, $saved] = array_pop($frames);';
                        $lines[] = '        goto RESUME;';
                    } else {
                        $lines[] = '        $r' . $destination . ' = ' . $regA . ';';
                    }
                    break;
                default:
                    // This path is unreachable because eligible() accepted
                    // only a straight-line sequence ending in one RET.
                    throw new \LogicException('Unsupported inlined instruction');
            }
        }

        $lines[] = '        // PHX_INLINE_END F' . $callerId . '_B' . $pc;
        return $lines;
    }
}
