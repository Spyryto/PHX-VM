<?php
declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';

use PhxVm\{FunctionDef, Instruction as I, Opcode as O, PhpEmitter, PhpVm, Program, Types as T, UnsupportedCompilationException, VerificationException, Vm};

$checks = 0;
function testT2(bool $condition, string $description): void
{
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL: ' . $description);
    ++$checks;
}
function compiledT2(Program $program, int $functionId): PhpVm
{
    $source = PhpEmitter::source($program, $functionId);
    $path = tempnam(sys_get_temp_dir(), 'phx-t2-');
    if ($path === false) throw new RuntimeException('Cannot create temporary file');
    try {
        if (file_put_contents($path, $source) !== strlen($source)) {
            throw new RuntimeException('Cannot write generated PHP');
        }
        return new PhpVm($functionId, $path);
    } finally {
        unlink($path);
    }
}
function outcomeT2(object $vm, int $functionId, array $args, int $budget): array
{
    try {
        $result = $vm->run($functionId, $args, $budget);
        return ['return', serialize($result), $vm->lastSteps()];
    } catch (Throwable $error) {
        return ['throw', $error::class, $error->getMessage(), $vm->lastSteps()];
    }
}
function compareT2(Program $program, int $id, array $args, int $budget = 1000000): array
{
    global $checks;
    $t0 = new Vm($program);
    $t2 = compiledT2($program, $id);
    $expected = outcomeT2($t0, $id, $args, $budget);
    $actual = outcomeT2($t2, $id, $args, $budget);
    if ($expected !== $actual) {
        throw new RuntimeException('T0/T2 mismatch: ' . var_export([$expected, $actual], true));
    }
    ++$checks;
    return $actual;
}

// All supported call-free operations, including a back-edge and an early return.
$loop = new FunctionDef('sum', [T::INT, T::INT, T::INT, T::BOOL, T::INT], 1, T::INT, [
    I::abx(O::CONST_, 1, 0), I::abx(O::CONST_, 2, 1),
    I::abx(O::CONST_, 4, 0), I::abc(O::IEQ, 3, 0, 4),
    I::abx(O::BR_TRUE, 3, 8), I::abc(O::IADD, 1, 1, 0),
    I::abc(O::ISUB, 0, 0, 2), I::abx(O::JMP, 0, 3),
    I::abc(O::RET, 1),
]);
$program = new Program([$loop], [0, 1]);
$source = PhpEmitter::source($program, 0);
testT2($source === PhpEmitter::source($program, 0), 'deterministic emitted source');
testT2(str_contains($source, 'goto B3;') && !str_contains($source, 'switch ($pc)'), 'direct control flow');
testT2(str_contains($source, '$r0 = $args[0]') && !str_contains($source, '$r[$a]'), 'PHP local registers');

foreach ([0, 1, 2, 10, 2000] as $n) compareT2($program, 0, [$n]);
foreach (range(1, 55) as $budget) compareT2($program, 0, [10], $budget);
compareT2($program, 0, [-1], 60);
compareT2($program, 0, [PHP_INT_MAX], 50);

$identity = new FunctionDef('identity', [T::INT], 1, T::INT, [I::abc(O::RET, 0)]);
foreach (['int' => 3, 'float' => -0.0, 'bool' => false, 'string' => "<?php ' \\ \n", 'null' => null] as $type => $value) {
    $fn = new FunctionDef('identity', [$type], 1, $type, [I::abc(O::RET, 0)]);
    compareT2(new Program([$fn]), 0, [$value]);
}
compareT2(new Program([$identity]), 0, [1.0]);
compareT2(new Program([$identity]), 0, []);

foreach ([O::IEQ, O::ILT] as $op) {
    foreach ([O::BR_TRUE, O::BR_FALSE] as $branch) {
        $fn = new FunctionDef('branch', [T::INT, T::INT, T::BOOL, T::INT], 2, T::INT, [
            I::abc($op, 2, 0, 1), I::abx($branch, 2, 4),
            I::abx(O::CONST_, 3, 0), I::abc(O::RET, 3),
            I::abx(O::CONST_, 3, 1), I::abc(O::RET, 3),
        ]);
        $p = new Program([$fn], [11, 22]);
        foreach ([[1, 1], [1, 2], [2, 1]] as $args) {
            compareT2($p, 0, $args);
            foreach (range(1, 6) as $budget) compareT2($p, 0, $args, $budget);
        }
    }
}

foreach ([O::IADD, O::ISUB, O::IMUL] as $op) {
    $f = new FunctionDef('arithmetic', [T::INT, T::INT], 2, T::INT, [
        I::abc($op, 0, 0, 1), I::abc(O::RET, 0),
    ]);
    foreach ([[PHP_INT_MAX, 2], [PHP_INT_MIN, -1], [4, 2], [-1, 0]] as $args) {
        compareT2(new Program([$f]), 0, $args);
        compareT2(new Program([$f]), 0, $args, 1);
    }
}

$pool = array_fill(0, 300, 0);
$pool[299] = 193;
$f = new FunctionDef('largeConstant', [T::INT], 0, T::INT, [
    I::abx(O::CONST_, 0, 299), I::abc(O::RET, 0),
]);
testT2(compareT2(new Program([$f], $pool), 0, [])[0] === 'return', 'large Bx constant');

// CALL and TAILCALL now lower into a single stack-safe PHP closure.
$callee = new FunctionDef('identity', [T::INT], 1, T::INT, [I::abc(O::RET, 0)]);
foreach ([O::CALL, O::TAILCALL] as $callOpcode) {
    $body = $callOpcode === O::CALL
        ? [I::abc(O::CALL, 1, 0, 0), I::abc(O::RET, 1)]
        : [I::abc(O::TAILCALL, 0, 0, 0)];
    $calling = new FunctionDef('calling', [T::INT, T::INT], 1, T::INT, $body);
    $programWithCalls = new Program([$callee, $calling]);
    testT2(compareT2($programWithCalls, 1, [42])[0] === 'return', 'direct call support');
    for ($budget = 1; $budget <= 5; ++$budget) {
        compareT2($programWithCalls, 1, [42], $budget);
    }
}
try {
    PhpEmitter::source(new Program([new FunctionDef('bad', [T::INT], 0, T::INT, [I::abc(O::RET, 0)])]), 0);
    throw new RuntimeException('Failed to reject malformed image');
} catch (VerificationException $expected) {
    testT2(true, 'verifier remains mandatory');
}

// Reproducible randomized straight-line programs, including checked overflow.
mt_srand(6611);
for ($case = 0; $case < 150; ++$case) {
    $code = [I::abx(O::CONST_, 2, 0)];
    for ($i = 0; $i < 6; ++$i) {
        $op = [O::MOVE, O::IADD, O::ISUB, O::IMUL][mt_rand(0, 3)];
        $a = mt_rand(0, 2);
        $b = mt_rand(0, 2);
        $c = mt_rand(0, 2);
        $code[] = I::abc($op, $a, $b, $op === O::MOVE ? 0 : $c);
    }
    $code[] = I::abc(O::RET, mt_rand(0, 2));
    $p = new Program([new FunctionDef('random', [T::INT, T::INT, T::INT], 2, T::INT, $code)], [mt_rand(-10, 10)]);
    compareT2($p, 0, [mt_rand(-7, 7), mt_rand(-7, 7)]);
}

echo "T2 differential suite: $checks checks passed\n";
