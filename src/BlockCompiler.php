<?php
declare(strict_types=1);

namespace PhxVm;

/**
 * One-time lowering of verified 32-bit bytecode to basic blocks.
 *
 * Each block is a list of decoded [opcode, A, B, C] integer tuples, keyed by
 * its original instruction index. This format is *runtime-only*: programs
 * still persist and link using the canonical 32-bit instruction stream.
 *
 * Leaders include branch targets, conditional fallthroughs and every
 * instruction following a control transfer (including CALL resume points).
 * We keep unreachable blocks so their original PC mapping remains intact.
 */
final class BlockCompiler
{
    /**
     * @return list<array<int, list<array{int, int, int, int}>>>
     */
    public static function prepare(Program $program): array
    {
        // A caller must not be able to bypass verification by using T1.
        Verifier::verify($program);

        $result = [];
        foreach ($program->functions as $function) {
            $code = $function->code;
            $count = count($code);
            $leaders = [0 => true];

            foreach ($code as $pc => $word) {
                $op = Instruction::op($word);
                if ($op === Opcode::JMP || $op === Opcode::BR_TRUE || $op === Opcode::BR_FALSE) {
                    $leaders[Instruction::bx($word)] = true;
                }
                if (in_array($op, [
                    Opcode::JMP, Opcode::BR_TRUE, Opcode::BR_FALSE,
                    Opcode::CALL, Opcode::TAILCALL, Opcode::RET,
                ], true) && $pc + 1 < $count) {
                    $leaders[$pc + 1] = true;
                }
            }

            $starts = array_keys($leaders);
            sort($starts, SORT_NUMERIC);
            $blocks = [];
            foreach ($starts as $index => $start) {
                $end = $starts[$index + 1] ?? $count;
                $operations = [];
                for ($pc = $start; $pc < $end; ++$pc) {
                    $word = $code[$pc];
                    $operations[] = [
                        Instruction::op($word), Instruction::a($word),
                        Instruction::b($word), Instruction::c($word),
                    ];
                }
                $blocks[$start] = $operations;
            }
            $result[] = $blocks;
        }
        return $result;
    }
}
