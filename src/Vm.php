<?php
declare(strict_types=1);

namespace PhxVm;

/** PHP-first, register-based T0 interpreter; bytecode calls never recurse into PHP. */
final class Vm
{
    private int $lastSteps = 0;

    public function __construct(private readonly Program $program, private readonly ?ExecutionProfile $profile = null)
    {
        Verifier::verify($program);
    }

    public function lastSteps(): int { return $this->lastSteps; }

    /** @param list<int|float|bool|string|null> $args */
    public function run(int $functionId, array $args = [], int $maxSteps = 1000000): mixed
    {
        $this->lastSteps = 0;
        if ($maxSteps < 1) throw new \InvalidArgumentException('maxSteps must be positive');
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
        $code = $function->code;
        $pc = 0;
        // Saved [function ID, return pc, register bank, destination register].
        $frames = [];
        $steps = 0;
        $completed = false;
        $profile = $this->profile;
        if ($profile !== null) {
            $profile->startRun($functionId);
            $profile->enterFunction($functionId);
        }
        try {
            while (true) {
                if (++$steps > $maxSteps) {
                    if ($profile !== null) $profile->rejectedByBudget($currentId);
                    throw new ExecutionLimitExceeded('Instruction budget exceeded');
                }
                $word = $code[$pc++]; // verified: pc always valid
                $op = $word & 255;
                if ($profile !== null) $profile->charge($currentId, $op);
                $a = ($word >> 8) & 255;
                $b = ($word >> 16) & 255;
                $c = ($word >> 24) & 255;
                switch ($op) {
                    case Opcode::CONST_:
                        $r[$a] = $constants[$b | ($c << 8)];
                        break;
                    case Opcode::MOVE:
                        $r[$a] = $r[$b];
                        break;
                    case Opcode::IADD:
                        $v = $r[$b] + $r[$c];
                        if (!is_int($v)) throw new VmFault('Integer overflow in IADD');
                        $r[$a] = $v;
                        break;
                    case Opcode::ISUB:
                        $v = $r[$b] - $r[$c];
                        if (!is_int($v)) throw new VmFault('Integer overflow in ISUB');
                        $r[$a] = $v;
                        break;
                    case Opcode::IMUL:
                        $v = $r[$b] * $r[$c];
                        if (!is_int($v)) throw new VmFault('Integer overflow in IMUL');
                        $r[$a] = $v;
                        break;
                    case Opcode::ILT:
                        $r[$a] = $r[$b] < $r[$c];
                        break;
                    case Opcode::IEQ:
                        $r[$a] = $r[$b] === $r[$c];
                        break;
                    case Opcode::JMP:
                        $pc = $b | ($c << 8);
                        break;
                    case Opcode::BR_TRUE:
                        if ($r[$a]) $pc = $b | ($c << 8);
                        break;
                    case Opcode::BR_FALSE:
                        if (!$r[$a]) $pc = $b | ($c << 8);
                        break;
                    case Opcode::CALL:
                    case Opcode::TAILCALL:
                        $callee = $functions[$b];
                        $nextRegisters = array_fill(0, count($callee->registerTypes), null);
                        for ($i = 0; $i < $callee->arity; $i++) {
                            $nextRegisters[$i] = $r[$c + $i];
                        }
                        if ($op === Opcode::CALL) $frames[] = [$currentId, $pc, $r, $a];
                        if ($profile !== null) {
                            $profile->transfer($currentId, $op === Opcode::TAILCALL, count($frames));
                            $profile->enterFunction($b);
                        }
                        $currentId = $b;
                        $code = $callee->code;
                        $r = $nextRegisters;
                        $pc = 0;
                        break;
                    case Opcode::RET:
                        $result = $r[$a];
                        if ($profile !== null) $profile->returned($currentId);
                        if ($frames === []) {
                            $completed = true;
                            return $result;
                        }
                        [$currentId, $pc, $r, $destination] = array_pop($frames);
                        $r[$destination] = $result;
                        $code = $functions[$currentId]->code;
                        break;
                    default:
                        throw new VmFault('Corrupted verified program');
                }
            }
        } finally {
            $this->lastSteps = $steps;
            if ($profile !== null) $profile->finishRun($functionId, $steps, $completed);
        }
    }
}
