<?php
declare(strict_types=1);

namespace PhxVm;

/**
 * Backward register liveness for the verified PHX bytecode CFG.
 *
 * The public result is indexed by CALL instruction PC and contains exactly the
 * caller registers needed *after* that CALL returns. The destination register
 * is excluded: the returned value overwrites it before the continuation runs.
 *
 * This is a pure analysis; callers must first verify the whole Program.
 * Compact PHP string bitsets keep the memory cost bounded for 256 registers.
 */
final class RegisterLiveness
{
    /**
     * @return array<int, list<int>> CALL pc => register indices to preserve
     */
    public static function acrossCalls(Program $program, int $functionId): array
    {
        $function = $program->functions[$functionId];
        $code = $function->code;
        $count = count($code);
        $registerCount = count($function->registerTypes);
        $byteCount = intdiv($registerCount + 7, 8);
        $empty = str_repeat("\0", $byteCount);
        $use = $def = $successors = [];
        $predecessors = array_fill(0, $count, []);

        foreach ($code as $pc => $word) {
            $op = Instruction::op($word);
            $a = Instruction::a($word);
            $b = Instruction::b($word);
            $c = Instruction::c($word);
            $reads = $writes = [];

            switch ($op) {
                case Opcode::CONST_:
                    $writes[] = $a;
                    break;
                case Opcode::MOVE:
                    $reads[] = $b;
                    $writes[] = $a;
                    break;
                case Opcode::IADD:
                case Opcode::ISUB:
                case Opcode::IMUL:
                case Opcode::ILT:
                case Opcode::IEQ:
                    $reads = [$b, $c];
                    $writes[] = $a;
                    break;
                case Opcode::BR_TRUE:
                case Opcode::BR_FALSE:
                case Opcode::RET:
                    $reads[] = $a;
                    break;
                case Opcode::CALL:
                case Opcode::TAILCALL:
                    $arity = $program->functions[$b]->arity;
                    for ($i = 0; $i < $arity; ++$i) {
                        $reads[] = $c + $i;
                    }
                    if ($op === Opcode::CALL) $writes[] = $a;
                    break;
                case Opcode::JMP:
                    break;
                default:
                    throw new \LogicException('Expected verified PHX opcode');
            }

            $next = $pc + 1;
            $succ = match ($op) {
                Opcode::JMP => [Instruction::bx($word)],
                Opcode::BR_TRUE, Opcode::BR_FALSE => [Instruction::bx($word), $next],
                Opcode::RET, Opcode::TAILCALL => [],
                default => [$next],
            };
            $successors[$pc] = $succ;
            foreach ($succ as $to) {
                $predecessors[$to][] = $pc;
            }
            $use[$pc] = self::mask($reads, $byteCount);
            $def[$pc] = self::mask($writes, $byteCount);
        }

        // Monotone dataflow: liveIn = uses U (liveOut - defs),
        // liveOut = union(liveIn(successors)). A worklist propagates changes
        // through arbitrary loops and joins until the least fixed point.
        $liveIn = array_fill(0, $count, $empty);
        $worklist = range(0, $count - 1);
        $queued = array_fill(0, $count, true);

        while ($worklist !== []) {
            $pc = array_pop($worklist);
            $queued[$pc] = false;
            $out = $empty;
            foreach ($successors[$pc] as $to) {
                $out = $out | $liveIn[$to];
            }
            $nextIn = $use[$pc] | ($out & ~$def[$pc]);
            if ($nextIn === $liveIn[$pc]) continue;

            $liveIn[$pc] = $nextIn;
            foreach ($predecessors[$pc] as $pred) {
                if (!$queued[$pred]) {
                    $worklist[] = $pred;
                    $queued[$pred] = true;
                }
            }
        }

        $savedAt = [];
        foreach ($code as $pc => $word) {
            if (Instruction::op($word) !== Opcode::CALL) continue;

            // The verifier guarantees that CALL has one fallthrough successor,
            // pc+1. The return destination replaces its old value.
            $live = $liveIn[$pc + 1];
            $destination = Instruction::a($word);
            $saved = [];
            for ($r = 0; $r < $registerCount; ++$r) {
                if ($r === $destination) continue;
                if ((ord($live[intdiv($r, 8)]) & (1 << ($r & 7))) !== 0) {
                    $saved[] = $r;
                }
            }
            $savedAt[$pc] = $saved;
        }
        return $savedAt;
    }

    /** @param list<int> $registers */
    private static function mask(array $registers, int $byteCount): string
    {
        $mask = str_repeat("\0", $byteCount);
        foreach ($registers as $r) {
            $byte = intdiv($r, 8);
            $mask[$byte] = chr(ord($mask[$byte]) | (1 << ($r & 7)));
        }
        return $mask;
    }
}
