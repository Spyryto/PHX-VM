<?php
declare(strict_types=1);

namespace PhxVm;

/**
 * Validates encoding, control flow, register types, call signatures and definite
 * assignment on all reachable paths (including loops and merge points).
 */
final class Verifier
{
    public static function verify(Program $program): void
    {
        if (!array_is_list($program->functions) || count($program->functions) < 1 || count($program->functions) > 256) {
            throw new VerificationException('Program needs 1..256 indexed functions');
        }
        if (!array_is_list($program->constants) || count($program->constants) > 65536) {
            throw new VerificationException('Constant pool must be a list of at most 65536 values');
        }
        foreach ($program->constants as $value) {
            try { Types::of($value); }
            catch (\InvalidArgumentException $e) { throw new VerificationException($e->getMessage(), 0, $e); }
        }
        foreach ($program->functions as $id => $function) {
            if (!$function instanceof FunctionDef) {
                throw new VerificationException("Function #$id is not a FunctionDef");
            }
            self::verifyFunction($program, $function);
        }
    }

    private static function fail(FunctionDef $function, int $pc, string $message): never
    {
        throw new VerificationException("{$function->name}: pc=$pc: $message");
    }

    private static function verifyFunction(Program $program, FunctionDef $f): void
    {
        $regCount = count($f->registerTypes);
        $length = count($f->code);
        if ($regCount < 1 || $regCount > 256 || !array_is_list($f->registerTypes)) {
            throw new VerificationException("{$f->name}: register types must be a list of 1..256 entries");
        }
        if ($f->arity < 0 || $f->arity > $regCount || !Types::valid($f->returnType)) {
            throw new VerificationException("{$f->name}: invalid function signature");
        }
        foreach ($f->registerTypes as $type) {
            if (!is_string($type) || !Types::valid($type)) {
                throw new VerificationException("{$f->name}: invalid register type");
            }
        }
        if (!array_is_list($f->code) || $length < 1 || $length > 65536) {
            throw new VerificationException("{$f->name}: code must be a list of 1..65536 words");
        }

        $reads = $writes = $edges = [];
        foreach ($f->code as $pc => $word) {
            if (!is_int($word) || $word < 0 || $word > 0xFFFFFFFF) {
                self::fail($f, $pc, 'invalid unsigned 32-bit word');
            }
            $op = Instruction::op($word);
            $a = Instruction::a($word);
            $b = Instruction::b($word);
            $c = Instruction::c($word);
            $bx = Instruction::bx($word);
            $rs = $ws = [];
            $next = $pc + 1;
            $successors = [];

            $assertRegister = static function (int $r, ?string $type = null) use ($f, $regCount, $pc): void {
                if ($r >= $regCount) self::fail($f, $pc, "r$r out of bounds");
                if ($type !== null && $f->registerTypes[$r] !== $type) {
                    self::fail($f, $pc, "r$r must be $type, got {$f->registerTypes[$r]}");
                }
            };
            $target = static function (int $t) use ($f, $length, $pc): int {
                if ($t >= $length) self::fail($f, $pc, "invalid jump target $t");
                return $t;
            };
            switch ($op) {
                case Opcode::CONST_:
                    $assertRegister($a);
                    if ($bx >= count($program->constants)) self::fail($f, $pc, "constant #$bx out of bounds");
                    if (Types::of($program->constants[$bx]) !== $f->registerTypes[$a]) {
                        self::fail($f, $pc, "constant #$bx does not match r$a type");
                    }
                    $ws = [$a];
                    break;
                case Opcode::MOVE:
                    if ($c !== 0) self::fail($f, $pc, 'MOVE has nonzero reserved operand');
                    $assertRegister($a);
                    $assertRegister($b, $f->registerTypes[$a]);
                    $rs = [$b]; $ws = [$a];
                    break;
                case Opcode::IADD: case Opcode::ISUB: case Opcode::IMUL:
                    $assertRegister($a, Types::INT);
                    $assertRegister($b, Types::INT);
                    $assertRegister($c, Types::INT);
                    $rs = [$b, $c]; $ws = [$a];
                    break;
                case Opcode::ILT: case Opcode::IEQ:
                    $assertRegister($a, Types::BOOL);
                    $assertRegister($b, Types::INT);
                    $assertRegister($c, Types::INT);
                    $rs = [$b, $c]; $ws = [$a];
                    break;
                case Opcode::JMP:
                    if ($a !== 0) self::fail($f, $pc, 'JMP has nonzero reserved operand');
                    $successors = [$target($bx)];
                    break;
                case Opcode::BR_TRUE: case Opcode::BR_FALSE:
                    $assertRegister($a, Types::BOOL);
                    $rs = [$a];
                    $successors = [$target($bx), $next];
                    break;
                case Opcode::CALL: case Opcode::TAILCALL:
                    if ($op === Opcode::TAILCALL && $a !== 0) self::fail($f, $pc, 'TAILCALL has nonzero reserved operand');
                    if (!isset($program->functions[$b])) self::fail($f, $pc, "callee #$b not found");
                    $callee = $program->functions[$b];
                    if (!$callee instanceof FunctionDef) self::fail($f, $pc, 'callee is not a function');
                    if ($c + $callee->arity > $regCount) self::fail($f, $pc, 'argument window out of bounds');
                    for ($i = 0; $i < $callee->arity; $i++) {
                        $assertRegister($c + $i);
                        if (!isset($callee->registerTypes[$i]) || $f->registerTypes[$c + $i] !== $callee->registerTypes[$i]) {
                            self::fail($f, $pc, "argument #$i type mismatch");
                        }
                        $rs[] = $c + $i;
                    }
                    if ($op === Opcode::CALL) {
                        $assertRegister($a, $callee->returnType);
                        $ws = [$a];
                    } elseif ($callee->returnType !== $f->returnType) {
                        self::fail($f, $pc, 'tail call return type mismatch');
                    }
                    break;
                case Opcode::RET:
                    if ($b !== 0 || $c !== 0) self::fail($f, $pc, 'RET has nonzero reserved operands');
                    $assertRegister($a, $f->returnType);
                    $rs = [$a];
                    break;
                default:
                    self::fail($f, $pc, 'unknown opcode ' . Opcode::name($op));
            }

            if ($successors === [] && $op !== Opcode::JMP && $op !== Opcode::RET && $op !== Opcode::TAILCALL) {
                $successors = [$next];
            }
            foreach ($successors as $to) {
                if ($to >= $length) self::fail($f, $pc, 'fallthrough past function end');
            }
            $reads[$pc] = $rs;
            $writes[$pc] = $ws;
            $edges[$pc] = $successors;
        }

        // Must-assignment fixed point: joins intersect assigned register sets.
        $initial = array_fill(0, $regCount, false);
        for ($i = 0; $i < $f->arity; $i++) $initial[$i] = true;
        $incoming = [0 => $initial];
        $queue = [0]; $head = 0;
        while ($head < count($queue)) {
            $pc = $queue[$head++];
            $out = $incoming[$pc];
            foreach ($writes[$pc] as $r) $out[$r] = true;
            foreach ($edges[$pc] as $to) {
                if (!array_key_exists($to, $incoming)) {
                    $incoming[$to] = $out;
                    $queue[] = $to;
                    continue;
                }
                $joined = $incoming[$to];
                $changed = false;
                for ($r = 0; $r < $regCount; $r++) {
                    if ($joined[$r] && !$out[$r]) { $joined[$r] = false; $changed = true; }
                }
                if ($changed) { $incoming[$to] = $joined; $queue[] = $to; }
            }
        }
        foreach ($incoming as $pc => $assigned) {
            foreach ($reads[$pc] as $r) {
                if (!$assigned[$r]) self::fail($f, $pc, "read of uninitialized r$r");
            }
        }
    }
}
