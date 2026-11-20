<?php
declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';

use PhxVm\{BlockVm, FunctionDef, Instruction as I, Opcode as O, Program, Types as T, Vm};

$checks = 0;

function checkFusion(bool $condition, string $reason): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($reason);
    ++$checks;
}

/** Compare all observable T0, prepared T1 and fused T1 outcomes. */
function compareFusion(Program $program, int $functionId, array $args, int $budget = 1000000): array
{
    global $checks;
    $engines = [new Vm($program), new BlockVm($program), new BlockVm($program, true)];
    $outcomes = [];

    foreach ($engines as $engine) {
        try {
            $value = $engine->run($functionId, $args, $budget);
            $outcomes[] = ['return', $value, $engine->lastSteps()];
        } catch (Throwable $error) {
            $outcomes[] = ['throw', $error::class, $error->getMessage(), $engine->lastSteps()];
        }
    }
    if ($outcomes[0] !== $outcomes[1] || $outcomes[0] !== $outcomes[2]) {
        throw new RuntimeException('T0/T1/fused mismatch: ' . var_export($outcomes, true));
    }
    ++$checks;
    return $outcomes[0];
}

// Compare-and-branch fusion, all 4 combinations, taken and not taken.
foreach ([O::IEQ, O::ILT] as $comparison) {
    foreach ([O::BR_TRUE, O::BR_FALSE] as $branch) {
        $function = new FunctionDef('compareBranch', [T::INT, T::INT, T::BOOL, T::INT], 2, T::INT, [
            I::abc($comparison, 2, 0, 1),
            I::abx($branch, 2, 4),
            I::abx(O::CONST_, 3, 0),
            I::abc(O::RET, 3),
            I::abx(O::CONST_, 3, 1),
            I::abc(O::RET, 3),
        ]);
        $program = new Program([$function], [11, 22]);
        checkFusion((new BlockVm($program, true))->superinstructionCount(0) === 1, 'compare+branch must fuse');
        checkFusion((new BlockVm($program))->superinstructionCount(0) === 0, 'default blocks must remain unfused');
        foreach ([[1, 1], [1, 2], [2, 1]] as $args) {
            compareFusion($program, 0, $args);
            for ($limit = 1; $limit <= 6; ++$limit) {
                compareFusion($program, 0, $args, $limit);
            }
        }
    }
}

// A branch into the second instruction must prevent fusion.
$unfusible = new FunctionDef('branchTarget', [T::INT, T::INT, T::BOOL], 3, T::INT, [
    I::abx(O::JMP, 0, 2),
    I::abc(O::IEQ, 2, 0, 1),
    I::abx(O::BR_TRUE, 2, 4),
    I::abc(O::RET, 0),
    I::abc(O::RET, 1),
]);
$program = new Program([$unfusible]);
checkFusion((new BlockVm($program, true))->superinstructionCount(0) === 0,
    'do not fuse across a branch-target leader');
compareFusion($program, 0, [10, 20, true]);
compareFusion($program, 0, [10, 20, false]);

// Fused IADD + ISUB must preserve source order and aliasing.
$pair = new FunctionDef('arithPair', [T::INT, T::INT, T::INT, T::INT], 2, T::INT, [
    I::abc(O::IADD, 2, 0, 1),
    I::abc(O::ISUB, 3, 1, 2),
    I::abc(O::RET, 3),
]);
$program = new Program([$pair]);
checkFusion((new BlockVm($program, true))->superinstructionCount(0) === 1, 'arithmetic pair must fuse');
foreach ([[5, 2], [0, 0], [-7, -13], [PHP_INT_MAX, 1], [PHP_INT_MIN, 1]] as $args) {
    compareFusion($program, 0, $args);
    for ($limit = 1; $limit <= 4; ++$limit) compareFusion($program, 0, $args, $limit);
}

// In this program the sub instruction aliases the add destination.
$alias = new FunctionDef('alias', [T::INT, T::INT, T::INT], 2, T::INT, [
    I::abc(O::IADD, 2, 0, 1),
    I::abc(O::ISUB, 2, 2, 1),
    I::abc(O::RET, 2),
]);
compareFusion(new Program([$alias]), 0, [10, 3]);

// Loop: two fused pairs per iteration, with exact original-bytecode budgets.
$loop = new FunctionDef('loop', [T::INT, T::INT, T::INT, T::BOOL, T::INT], 1, T::INT, [
    I::abx(O::CONST_, 1, 0), I::abx(O::CONST_, 2, 1),
    I::abx(O::CONST_, 4, 0), I::abc(O::IEQ, 3, 0, 4),
    I::abx(O::BR_TRUE, 3, 8), I::abc(O::IADD, 1, 1, 0),
    I::abc(O::ISUB, 0, 0, 2), I::abx(O::JMP, 0, 3),
    I::abc(O::RET, 1),
]);
$program = new Program([$loop], [0, 1]);
checkFusion((new BlockVm($program, true))->superinstructionCount(0) === 2, 'loop fuses comparison and arithmetic pair');
checkFusion(compareFusion($program, 0, [100])[1] === 5050, 'fused loop result');
for ($budget = 1; $budget <= 60; ++$budget) compareFusion($program, 0, [5], $budget);
compareFusion($program, 0, [-1], 80);

// Tail recursion with a fused comparison, and non-tail CALL resume.
$sum = new FunctionDef('sum', [T::INT, T::INT, T::INT, T::BOOL, T::INT, T::INT, T::INT], 2, T::INT, [
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
checkFusion(compareFusion($program, 1, [10000, 0], 100000)[1] === 50005000, 'recursive sum result');
for ($budget = 1; $budget <= 22; ++$budget) compareFusion($program, 1, [3, 0], $budget);

// Repeatable arithmetic variations include both successful calculations and
// checked overflow. Source register overlap must behave identically.
mt_srand(31415);
for ($case = 0; $case < 100; ++$case) {
    $code = [];
    for ($i = 0; $i < 3; ++$i) {
        $code[] = I::abc(O::IADD, 2, mt_rand(0, 2), mt_rand(0, 2));
        $code[] = I::abc(O::ISUB, 2, mt_rand(0, 2), mt_rand(0, 2));
    }
    $code[] = I::abc(O::RET, 2);
    $function = new FunctionDef('generatedPair', [T::INT, T::INT, T::INT], 2, T::INT, $code);
    // r2 must be written by IADD before any read inside the program.
    $program = new Program([$function]);
    compareFusion($program, 0, [mt_rand(-100, 100), mt_rand(-100, 100)]);
}

echo "T1 superinstruction differential suite: $checks checks passed\n";
