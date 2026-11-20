<?php
declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';

use PhxVm\{BlockVm, FunctionDef, Instruction as I, Opcode as O, Program, Types as T, Vm, VerificationException};

$checks = 0;

function assertSameOutcome(Program $program, int $functionId, array $args, int $budget = 1000000): array
{
    global $checks;

    $t0 = new Vm($program);
    $t1 = new BlockVm($program);

    $capture = static function (object $vm) use ($functionId, $args, $budget): array {
        try {
            $value = $vm->run($functionId, $args, $budget);
            return ['returned', $value, $vm->lastSteps()];
        } catch (\Throwable $error) {
            return ['threw', $error::class, $error->getMessage(), $vm->lastSteps()];
        }
    };

    $reference = $capture($t0);
    $prepared = $capture($t1);
    if ($reference !== $prepared) {
        throw new \RuntimeException('T0/T1 mismatch: '
            . json_encode([$reference, $prepared], JSON_THROW_ON_ERROR));
    }
    ++$checks;
    return $reference;
}

function assertCondition(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new \RuntimeException($message);
    ++$checks;
}

$addition = new FunctionDef('add', [T::INT, T::INT, T::INT], 2, T::INT, [
    I::abc(O::IADD, 2, 0, 1),
    I::abc(O::RET, 2),
]);
$calling = new FunctionDef('caller', [T::INT, T::INT, T::INT], 2, T::INT, [
    I::abc(O::CALL, 2, 0, 0),
    I::abc(O::IADD, 2, 2, 1),
    I::abc(O::RET, 2),
]);
$program = new Program([$addition, $calling]);

assertCondition((new BlockVm($program))->blockStarts(1) === [0, 1], 'CALL resume must be a block leader');
assertCondition(assertSameOutcome($program, 0, [20, 22])[1] === 42, 'addition');
assertCondition(assertSameOutcome($program, 1, [20, 22])[1] === 64, 'CALL resumes at the next bytecode PC');
assertSameOutcome($program, 1, [PHP_INT_MAX, 1]); // overflow after returning from CALL
assertSameOutcome($program, 0, [PHP_INT_MAX, 1]); // overflow in callee

$loop = new FunctionDef('loopSum', [
    T::INT, T::INT, T::INT, T::BOOL, T::INT,
], 1, T::INT, [
    I::abx(O::CONST_, 1, 0),
    I::abx(O::CONST_, 2, 1),
    I::abx(O::CONST_, 4, 0),
    I::abc(O::IEQ, 3, 0, 4),
    I::abx(O::BR_TRUE, 3, 8),
    I::abc(O::IADD, 1, 1, 0),
    I::abc(O::ISUB, 0, 0, 2),
    I::abx(O::JMP, 0, 3),
    I::abc(O::RET, 1),
]);
$program = new Program([$loop], [0, 1]);

assertCondition((new BlockVm($program))->blockStarts(0) === [0, 3, 5, 8],
    'branch targets and fallthroughs are distinct blocks');
assertCondition(assertSameOutcome($program, 0, [100])[1] === 5050, 'loop with forward and backward edges');
assertSameOutcome($program, 0, [0]);
assertSameOutcome($program, 0, [-1], 40); // deterministic budget exhaustion

for ($budget = 1; $budget <= 30; ++$budget) {
    assertSameOutcome($program, 0, [4], $budget);
}
assertCondition(assertSameOutcome($program, 0, [4], 100)[1] === 10,
    'budget admits a complete loop');

$sum = new FunctionDef('tailSum', [
    T::INT, T::INT, T::INT, T::BOOL, T::INT, T::INT, T::INT,
], 2, T::INT, [
    I::abx(O::CONST_, 2, 0), I::abc(O::IEQ, 3, 0, 2),
    I::abx(O::BR_TRUE, 3, 9), I::abx(O::CONST_, 4, 1),
    I::abc(O::ISUB, 5, 0, 4), I::abc(O::IADD, 6, 1, 0),
    I::abc(O::MOVE, 0, 5), I::abc(O::MOVE, 1, 6),
    I::abc(O::TAILCALL, 0, 0, 0), I::abc(O::RET, 1),
]);
$wrapper = new FunctionDef('wrapper', [T::INT, T::INT, T::INT], 2, T::INT, [
    I::abc(O::CALL, 2, 0, 0), I::abc(O::RET, 2),
]);
$program = new Program([$sum, $wrapper], [0, 1]);
assertCondition(assertSameOutcome($program, 1, [10000, 0], 100000)[1] === 50005000,
    'tail calls under a non-tail caller');
for ($budget = 1; $budget <= 16; ++$budget) {
    assertSameOutcome($program, 1, [5, 0], $budget);
}

$depth = new FunctionDef('depth', [T::INT, T::INT, T::BOOL, T::INT, T::INT], 1, T::INT, [
    I::abx(O::CONST_, 1, 0), I::abc(O::IEQ, 2, 0, 1),
    I::abx(O::BR_TRUE, 2, 7), I::abx(O::CONST_, 3, 1),
    I::abc(O::ISUB, 3, 0, 3), I::abc(O::CALL, 4, 0, 3),
    I::abc(O::RET, 4), I::abc(O::RET, 0),
]);
$program = new Program([$depth], [0, 1]);
assertCondition(assertSameOutcome($program, 0, [2000], 15000)[1] === 0,
    'non-tail recursion uses explicit frames');
assertSameOutcome($program, 0, [2000], 50);

// Primitive value pass-through, including NULL (different from an unset register).
foreach ([0, 1.5, true, false, 'hi', null] as $value) {
    $type = T::of($value);
    $identity = new FunctionDef('id', [$type], 1, $type, [I::abc(O::RET, 0)]);
    assertCondition(assertSameOutcome(new Program([$identity]), 0, [$value])[1] === $value,
        'pass through ' . $type);
}

$invalid = new Program([new FunctionDef('invalid', [T::INT], 0, T::INT, [
    I::abc(O::RET, 0),
])]);
foreach ([Vm::class, BlockVm::class] as $class) {
    try {
        new $class($invalid);
        throw new \RuntimeException("Verifier bypassed in $class");
    } catch (VerificationException $expected) {
        assertCondition(true, 'invalid bytecode rejected by ' . $class);
    }
}

// Reproducible small, type-correct straight-line programs. These include
// overflow cases; both engines must agree on the error and its bytecode cost.
mt_srand(41021);
for ($case = 0; $case < 180; ++$case) {
    $code = [I::abx(O::CONST_, 2, 0), I::abx(O::CONST_, 3, 1)];
    $count = 3 + mt_rand(0, 12);
    for ($j = 0; $j < $count; ++$j) {
        $dest = mt_rand(2, 3);
        $b = mt_rand(0, 3);
        $c = mt_rand(0, 3);
        $op = [O::MOVE, O::IADD, O::ISUB, O::IMUL][mt_rand(0, 3)];
        $code[] = $op === O::MOVE
            ? I::abc($op, $dest, $b)
            : I::abc($op, $dest, $b, $c);
    }
    $code[] = I::abc(O::RET, mt_rand(2, 3));
    $program = new Program([
        new FunctionDef('generated', [T::INT, T::INT, T::INT, T::INT], 2, T::INT, $code),
    ], [mt_rand(-10, 10), mt_rand(-10, 10)]);
    assertSameOutcome($program, 0, [mt_rand(-8, 8), mt_rand(-8, 8)]);
}

echo "T1 differential suite: $checks checks passed\n";
