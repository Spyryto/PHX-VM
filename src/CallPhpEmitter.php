<?php
declare(strict_types=1);

namespace PhxVm;

/**
 * T2 interprocedural lowering for the verified direct-call ISA.
 *
 * Emits one PHP closure containing all statically reachable functions.
 * Each VM function has its own set of goto labels, but all share one
 * physical register bank and a VM-managed stack of return continuations.
 * CALL saves the caller's registers; TAILCALL replaces the activation.
 *
 * No PHP recursive calls, eval, opcode dispatch or user-controlled PHP code.
 * Optional leaf inlining uses private register banks and a compile-time budget.
 * Optional frame trimming restores only registers live across CALL.
 */
final class CallPhpEmitter
{
    public static function source(Program $program, int $entry, bool $inline = false, bool $trimFrames = false): string
    {
        Verifier::verify($program);
        if (!isset($program->functions[$entry])) {
            throw new \InvalidArgumentException('Unknown function ID');
        }

        // Close over all static call targets. This also covers unreachable
        // bytecode: jumps can never enter a function not emitted here.
        $reachable = [$entry => true];
        $pending = [$entry];
        $maxRegisters = 0;
        for ($i = 0; $i < count($pending); ++$i) {
            $id = $pending[$i];
            $function = $program->functions[$id];
            $maxRegisters = max($maxRegisters, count($function->registerTypes));
            foreach ($function->code as $word) {
                $op = Instruction::op($word);
                if ($op === Opcode::CALL || $op === Opcode::TAILCALL) {
                    $callee = Instruction::b($word);
                    if (!isset($reachable[$callee])) {
                        $reachable[$callee] = true;
                        $pending[] = $callee;
                    }
                }
            }
        }
        $functionIds = array_keys($reachable);
        sort($functionIds, SORT_NUMERIC);

        $entryFunction = $program->functions[$entry];
        $lines = [
            '<?php',
            'declare(strict_types=1);',
            '',
            '// PHX-VM T2: generated from verified, trusted bytecode.',
            '// All CALL continuations are explicit; no recursion into PHP.',
            'return static function (array $args, int $maxSteps, int &$lastSteps, int $maxFrames = PHP_INT_MAX): mixed {',
            '    $lastSteps = 0;',
            '    if ($maxSteps < 1) throw new \InvalidArgumentException(\'maxSteps must be positive\');',
            '    if ($maxFrames < 0) throw new \InvalidArgumentException(\'maxFrames must be non-negative\');',
            '    if (!array_is_list($args)) throw new \InvalidArgumentException(\'Arguments must be a list\');',
            '    if (count($args) !== ' . $entryFunction->arity . ') throw new \InvalidArgumentException(\'Wrong argument count\');',
        ];

        for ($i = 0; $i < $entryFunction->arity; ++$i) {
            $lines[] = '    if (\PhxVm\Types::of($args[' . $i . ']) !== '
                . var_export($entryFunction->registerTypes[$i], true)
                . ') throw new \InvalidArgumentException('
                . var_export("Argument #$i has wrong type", true) . ');';
        }
        for ($r = 0; $r < $maxRegisters; ++$r) {
            $lines[] = '    $r' . $r . ' = '
                . ($r < $entryFunction->arity ? '$args[' . $r . ']' : 'null') . ';';
        }
        $lines[] = '    $steps = 0;';
        $lines[] = '    $frames = [];';
        $lines[] = '    try {';
        $lines[] = '        goto F' . $entry . '_B0;';

        // Bound compile-time growth even for programs with many callsites.
        // Count copied *original* bytecode words, not synthetic PHP lines.
        $remainingInlineOps = $inline ? InlineEmitter::MAX_TOTAL_COPIED_INSTRUCTIONS : 0;

        // T2-only optimization. The canonical program and other executors
        // still use the original register banks. Analyze after verification.
        $acrossCalls = [];
        if ($trimFrames) {
            foreach ($functionIds as $id) {
                $acrossCalls[$id] = RegisterLiveness::acrossCalls($program, $id);
            }
        }

        /** @var list<array{id:int, pc:int, dest:int, saved:list<int>}> $continuations */
        $continuations = [];
        foreach ($functionIds as $id) {
            $function = $program->functions[$id];
            $registerCount = count($function->registerTypes);
            foreach ($function->code as $pc => $word) {
                $op = Instruction::op($word);
                $a = Instruction::a($word);
                $b = Instruction::b($word);
                $c = Instruction::c($word);
                $rA = '$r' . $a;
                $rB = '$r' . $b;
                $rC = '$r' . $c;
                $lines[] = 'F' . $id . '_B' . $pc . ':';
                $lines[] = '        if (++$steps > $maxSteps) throw new \PhxVm\ExecutionLimitExceeded(\'Instruction budget exceeded\');';

                switch ($op) {
                    case Opcode::CONST_:
                        $lines[] = '        ' . $rA . ' = '
                            . var_export($program->constants[Instruction::bx($word)], true) . ';';
                        break;
                    case Opcode::MOVE:
                        $lines[] = '        ' . $rA . ' = ' . $rB . ';';
                        break;
                    case Opcode::IADD:
                    case Opcode::ISUB:
                    case Opcode::IMUL:
                        $operator = match ($op) {
                            Opcode::IADD => '+',
                            Opcode::ISUB => '-',
                            default => '*',
                        };
                        $lines[] = '        $value = ' . $rB . ' ' . $operator . ' ' . $rC . ';';
                        $lines[] = '        if (!is_int($value)) throw new \PhxVm\VmFault('
                            . var_export('Integer overflow in ' . Opcode::name($op), true) . ');';
                        $lines[] = '        ' . $rA . ' = $value;';
                        break;
                    case Opcode::ILT:
                    case Opcode::IEQ:
                        $lines[] = '        ' . $rA . ' = (' . $rB
                            . ($op === Opcode::ILT ? ' < ' : ' === ') . $rC . ');';
                        break;
                    case Opcode::JMP:
                        $lines[] = '        goto F' . $id . '_B' . Instruction::bx($word) . ';';
                        break;
                    case Opcode::BR_TRUE:
                    case Opcode::BR_FALSE:
                        $condition = $op === Opcode::BR_FALSE ? '!' . $rA : $rA;
                        $lines[] = '        if (' . $condition . ') goto F'
                            . $id . '_B' . Instruction::bx($word) . ';';
                        break;
                    case Opcode::CALL:
                    case Opcode::TAILCALL:
                        // This quota counts pending logical CALLs, including
                        // an inlined leaf CALL that needs no physical frame.
                        // Leaf inlinees cannot CALL again, so a transient guard
                        // is enough; TAILCALL never increases pending depth.
                        if ($op === Opcode::CALL) {
                            $lines[] = '        if (count($frames) >= $maxFrames) throw new \\PhxVm\\ContinuationLimitExceeded(\\'Continuation frame limit exceeded\\');';
                        }
                        $callee = $program->functions[$b];
                        if ($remainingInlineOps >= count($callee->code)
                            && InlineEmitter::eligible($callee)) {
                            foreach (InlineEmitter::emit(
                                $program, $callee, $id, $pc, $c, $a,
                                $op === Opcode::TAILCALL
                            ) as $line) {
                                $lines[] = $line;
                            }
                            $remainingInlineOps -= count($callee->code);
                            break;
                        }
                        if ($op === Opcode::CALL) {
                            $continuationId = count($continuations);
                            $registerIds = $trimFrames
                                ? $acrossCalls[$id][$pc]
                                : range(0, $registerCount - 1);
                            $continuations[] = [
                                'id' => $id,
                                'pc' => $pc + 1,
                                'dest' => $a,
                                'saved' => $registerIds,
                            ];
                            $savedRegisters = [];
                            foreach ($registerIds as $r) {
                                $savedRegisters[] = '$r' . $r;
                            }
                            $lines[] = '        $frames[] = [' . $continuationId
                                . ', [' . implode(', ', $savedRegisters) . ']];';
                        }

                        // PHP evaluates the entire RHS before destructuring:
                        // overlapping source/destination register windows and
                        // self-tail-calls therefore preserve simultaneous args.
                        $newValues = [];
                        $targetRegisters = [];
                        for ($r = 0; $r < $maxRegisters; ++$r) {
                            $targetRegisters[] = '$r' . $r;
                            $newValues[] = $r < $callee->arity
                                ? '$r' . ($c + $r)
                                : 'null';
                        }
                        $lines[] = '        [' . implode(', ', $targetRegisters)
                            . '] = [' . implode(', ', $newValues) . '];';
                        $lines[] = '        goto F' . $b . '_B0;';
                        break;
                    case Opcode::RET:
                        $lines[] = '        $returnValue = ' . $rA . ';';
                        $lines[] = '        if ($frames === []) return $returnValue;';
                        $lines[] = '        [$continuation, $saved] = array_pop($frames);';
                        $lines[] = '        goto RESUME;';
                        break;
                    default:
                        throw new UnsupportedCompilationException(
                            'Unsupported T2 opcode ' . Opcode::name($op)
                        );
                }
            }
        }

        // Only a RETURN takes this dispatch. It is never performed for normal
        // instructions, and TAILCALL never allocates a continuation.
        $lines[] = 'RESUME:';
        $lines[] = '        switch ($continuation) {';
        foreach ($continuations as $continuationId => $continuation) {
            $restore = [];
            foreach ($continuation['saved'] as $r) {
                $restore[] = '$r' . $r;
            }
            $lines[] = '            case ' . $continuationId . ':';
            // Empty register sets are valid: the CALL destination alone is
            // the only value observed after returning.
            if ($restore !== []) {
                $lines[] = '                [' . implode(', ', $restore) . '] = $saved;';
            }
            $lines[] = '                $r' . $continuation['dest'] . ' = $returnValue;';
            $lines[] = '                goto F' . $continuation['id'] . '_B' . $continuation['pc'] . ';';
        }
        $lines[] = '        }';
        $lines[] = '        throw new \PhxVm\VmFault(\'Corrupted generated continuation\');';
        $lines[] = '    } finally {';
        $lines[] = '        $lastSteps = $steps;';
        $lines[] = '    }';
        $lines[] = '};';
        return implode("\n", $lines) . "\n";
    }
}
