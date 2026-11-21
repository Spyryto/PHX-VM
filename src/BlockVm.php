<?php
declare(strict_types=1);

namespace PhxVm;

/**
 * T1 prototype: dispatches at basic-block boundaries and executes predecoded
 * instructions. There is still an opcode switch *inside* each block, so this
 * is not yet a superinstruction engine or native PHP compiler.
 *
 * Every original bytecode instruction retains its own budget charge and
 * failure point. CALL and TAILCALL are handled without PHP recursion.
 */
final class BlockVm
{
    /** @var list<array<int, list<list<int>>>> */
    private readonly array $blocks;
    private int $lastSteps = 0;

    public function __construct(private readonly Program $program, bool $fuse = false)
    {
        $this->blocks = BlockCompiler::prepare($program, $fuse);
    }

    public function lastSteps(): int { return $this->lastSteps; }

    /** @return list<int> Original bytecode PCs at which blocks begin. */
    public function blockStarts(int $functionId): array
    {
        if (!isset($this->blocks[$functionId])) {
            throw new \InvalidArgumentException('Unknown function ID');
        }
        return array_keys($this->blocks[$functionId]);
    }

    /** Count synthetic instructions; useful to prove the fast path was selected. */
    public function superinstructionCount(int $functionId): int
    {
        if (!isset($this->blocks[$functionId])) {
            throw new \InvalidArgumentException('Unknown function ID');
        }
        $count = 0;
        foreach ($this->blocks[$functionId] as $operations) {
            foreach ($operations as $instruction) {
                if ($instruction[0] < 0) ++$count;
            }
        }
        return $count;
    }

    /** @param list<int|float|bool|string|null> $args */
    public function run(int $functionId, array $args = [], int $maxSteps = 1000000, int $maxFrames = PHP_INT_MAX): mixed
    {
        $this->lastSteps = 0;
        if ($maxSteps < 1) throw new \InvalidArgumentException('maxSteps must be positive');
        if ($maxFrames < 0) throw new \InvalidArgumentException('maxFrames must be non-negative');
        if (!isset($this->program->functions[$functionId])) throw new \InvalidArgumentException('Unknown function ID');
        if (!array_is_list($args)) throw new \InvalidArgumentException('Arguments must be a list');

        $functions = $this->program->functions;
        $constants = $this->program->constants;
        $currentId = $functionId;
        $function = $functions[$currentId];
        if (count($args) !== $function->arity) throw new \InvalidArgumentException('Wrong argument count');
        foreach ($args as $i => $arg) {
            if (Types::of($arg) !== $function->registerTypes[$i]) {
                throw new \InvalidArgumentException("Argument #$i has wrong type");
            }
        }

        $r = array_fill(0, count($function->registerTypes), null);
        foreach ($args as $i => $arg) $r[$i] = $arg;
        $pc = 0;
        // Each saved frame is [function ID, return PC, registers, destination].
        $frames = [];
        $steps = 0;
        try {
            while (true) {
                // All possible PCs are verified block leaders: entry, branch
                // target, fallthrough or the instruction following a CALL.
                foreach ($this->blocks[$currentId][$pc] as $instruction) {
                    if (++$steps > $maxSteps) {
                        throw new ExecutionLimitExceeded('Instruction budget exceeded');
                    }

                    [$op, $a, $b, $c] = $instruction;
                    ++$pc; // Keep the original instruction-index PC.
                    switch ($op) {
                        case Superopcode::IADD_ISUB:
                            // First bytecode instruction. Preserve its exception
                            // point before charging or executing the second.
                            $value = $r[$b] + $r[$c];
                            if (!is_int($value)) throw new VmFault('Integer overflow in IADD');
                            $r[$a] = $value;
                            if (++$steps > $maxSteps) {
                                throw new ExecutionLimitExceeded('Instruction budget exceeded');
                            }
                            ++$pc;
                            $value = $r[$instruction[5]] - $r[$instruction[6]];
                            if (!is_int($value)) throw new VmFault('Integer overflow in ISUB');
                            $r[$instruction[4]] = $value;
                            break;
                        case Superopcode::IEQ_BR_TRUE:
                        case Superopcode::IEQ_BR_FALSE:
                        case Superopcode::ILT_BR_TRUE:
                        case Superopcode::ILT_BR_FALSE:
                            // Comparison writes its boolean register before the
                            // branch; both original instructions charge one step.
                            $isEquality = $op === Superopcode::IEQ_BR_TRUE
                                || $op === Superopcode::IEQ_BR_FALSE;
                            $r[$a] = $isEquality
                                ? ($r[$b] === $r[$c])
                                : ($r[$b] < $r[$c]);
                            if (++$steps > $maxSteps) {
                                throw new ExecutionLimitExceeded('Instruction budget exceeded');
                            }
                            ++$pc;
                            $branchesOnTrue = $op === Superopcode::IEQ_BR_TRUE
                                || $op === Superopcode::ILT_BR_TRUE;
                            if ($r[$a] === $branchesOnTrue) {
                                $pc = $instruction[4];
                            }
                            break 2;
                        case Opcode::CONST_:
                            $r[$a] = $constants[$b | ($c << 8)];
                            break;
                        case Opcode::MOVE:
                            $r[$a] = $r[$b];
                            break;
                        case Opcode::IADD:
                            $value = $r[$b] + $r[$c];
                            if (!is_int($value)) throw new VmFault('Integer overflow in IADD');
                            $r[$a] = $value;
                            break;
                        case Opcode::ISUB:
                            $value = $r[$b] - $r[$c];
                            if (!is_int($value)) throw new VmFault('Integer overflow in ISUB');
                            $r[$a] = $value;
                            break;
                        case Opcode::IMUL:
                            $value = $r[$b] * $r[$c];
                            if (!is_int($value)) throw new VmFault('Integer overflow in IMUL');
                            $r[$a] = $value;
                            break;
                        case Opcode::ILT:
                            $r[$a] = $r[$b] < $r[$c];
                            break;
                        case Opcode::IEQ:
                            $r[$a] = $r[$b] === $r[$c];
                            break;
                        case Opcode::JMP:
                            $pc = $b | ($c << 8);
                            break 2; // Leave the opcode switch and this block.
                        case Opcode::BR_TRUE:
                            if ($r[$a]) $pc = $b | ($c << 8);
                            break 2;
                        case Opcode::BR_FALSE:
                            if (!$r[$a]) $pc = $b | ($c << 8);
                            break 2;
                        case Opcode::CALL:
                        case Opcode::TAILCALL:
                            if ($op === Opcode::CALL && count($frames) >= $maxFrames) {
                                throw new ContinuationLimitExceeded('Continuation frame limit exceeded');
                            }
                            $callee = $functions[$b];
                            $nextRegisters = array_fill(0, count($callee->registerTypes), null);
                            for ($i = 0; $i < $callee->arity; ++$i) {
                                $nextRegisters[$i] = $r[$c + $i];
                            }
                            if ($op === Opcode::CALL) {
                                $frames[] = [$currentId, $pc, $r, $a];
                            }
                            $currentId = $b;
                            $r = $nextRegisters;
                            $pc = 0;
                            break 2;
                        case Opcode::RET:
                            $result = $r[$a];
                            if ($frames === []) return $result;
                            [$currentId, $pc, $r, $destination] = array_pop($frames);
                            $r[$destination] = $result;
                            break 2;
                        default:
                            // Inaccessible for verified bytecode.
                            throw new VmFault('Corrupted verified program');
                    }
                }
                // Straight-line fallthrough also reaches the next block here.
            }
        } finally {
            $this->lastSteps = $steps;
        }
    }
}
