<?php
declare(strict_types=1);

namespace PhxVm;

/**
 * T2 compiler: emits a PHP closure with local registers and direct goto edges.
 * No eval or writable bytecode. The call-free path generates PHP locals
 * and direct branches; direct CALL/TAILCALL uses CallPhpEmitter.
 */
final class PhpEmitter
{
    public static function source(Program $program, int $functionId, bool $inline = false): string
    {
        Verifier::verify($program);
        if (!isset($program->functions[$functionId])) {
            throw new \InvalidArgumentException('Unknown function ID');
        }
        $function = $program->functions[$functionId];
        $code = $function->code;
        $leaders = [0 => true];
        foreach ($code as $word) {
            $op = Instruction::op($word);
            if ($op === Opcode::CALL || $op === Opcode::TAILCALL) {
                // Preserve the original call-free fast path. Program-level
                // direct calls use one generated PHP closure and an explicit
                // stack of return continuations instead of PHP recursion.
                return CallPhpEmitter::source($program, $functionId, $inline);
            }
            if (in_array($op, [Opcode::JMP, Opcode::BR_TRUE, Opcode::BR_FALSE], true)) {
                $leaders[Instruction::bx($word)] = true;
            }
        }
        $line = [
            '<?php',
            'declare(strict_types=1);',
            '',
            '// PHX-VM T2 generated PHP (trusted, verified input).',
            '// Same integer overflow, step counting, and branch semantics as T0.',
            'return static function (array $args, int $maxSteps, int &$lastSteps): mixed {',
            '    $lastSteps = 0;',
            '    if ($maxSteps < 1) throw new \\InvalidArgumentException(\'maxSteps must be positive\');',
            '    if (!array_is_list($args)) throw new \\InvalidArgumentException(\'Arguments must be a list\');',
            '    if (count($args) !== ' . $function->arity . ') throw new \\InvalidArgumentException(\'Wrong argument count\');',
        ];
        for ($i = 0; $i < $function->arity; $i++) {
            $line[] = '    if (\\PhxVm\\Types::of($args[' . $i . ']) !== '
                . var_export($function->registerTypes[$i], true)
                . ') throw new \\InvalidArgumentException(' . var_export("Argument #$i has wrong type", true) . ');';
        }
        for ($i = 0; $i < $function->arity; $i++) {
            $line[] = '    $r' . $i . ' = $args[' . $i . '];';
        }
        $line[] = '    $steps = 0;';
        $line[] = '    try {';
        foreach ($code as $pc => $word) {
            $op = Instruction::op($word);
            $a = Instruction::a($word);
            $b = Instruction::b($word);
            $c = Instruction::c($word);
            if (isset($leaders[$pc])) $line[] = 'B' . $pc . ':';
            $line[] = '        if (++$steps > $maxSteps) throw new \\PhxVm\\ExecutionLimitExceeded(\'Instruction budget exceeded\');';
            $rA = '$r' . $a;
            $rB = '$r' . $b;
            $rC = '$r' . $c;
            switch ($op) {
                case Opcode::CONST_:
                    $line[] = '        ' . $rA . ' = ' . var_export($program->constants[Instruction::bx($word)], true) . ';';
                    break;
                case Opcode::MOVE:
                    $line[] = '        ' . $rA . ' = ' . $rB . ';';
                    break;
                case Opcode::IADD:
                case Opcode::ISUB:
                case Opcode::IMUL:
                    $operator = match ($op) {
                        Opcode::IADD => '+', Opcode::ISUB => '-', default => '*',
                    };
                    $line[] = '        $value = ' . $rB . ' ' . $operator . ' ' . $rC . ';';
                    $line[] = '        if (!is_int($value)) throw new \\PhxVm\\VmFault('
                        . var_export('Integer overflow in ' . Opcode::name($op), true) . ');';
                    $line[] = '        ' . $rA . ' = $value;';
                    break;
                case Opcode::ILT:
                case Opcode::IEQ:
                    $operator = $op === Opcode::ILT ? '<' : '===';
                    $line[] = '        ' . $rA . ' = (' . $rB . ' ' . $operator . ' ' . $rC . ');';
                    break;
                case Opcode::JMP:
                    $line[] = '        goto B' . Instruction::bx($word) . ';';
                    break;
                case Opcode::BR_TRUE:
                case Opcode::BR_FALSE:
                    $condition = $op === Opcode::BR_FALSE ? '!' . $rA : $rA;
                    $line[] = '        if (' . $condition . ') goto B' . Instruction::bx($word) . ';';
                    break;
                case Opcode::RET:
                    $line[] = '        return ' . $rA . ';';
                    break;
                default:
                    // The verifier accepts only known opcodes. Keep an explicit
                    // fail-closed path if a future opcode is added to the ISA.
                    throw new UnsupportedCompilationException('Unsupported T2 opcode ' . Opcode::name($op));
            }
        }
        $line[] = '    } finally {';
        $line[] = '        $lastSteps = $steps;';
        $line[] = '    }';
        $line[] = '};';
        return implode("\n", $line) . "\n";
    }
}
