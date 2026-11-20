<?php
declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';

use PhxVm\{ExecutionLimitExceeded, FunctionDef, Instruction as I, Opcode as O, Program, Types as T, VerificationException, Vm, VmFault};

$passed = 0;
function check(bool $condition, string $name): void {
    global $passed;
    if (!$condition) throw new RuntimeException("FAIL: $name");
    $passed++;
    echo ".";
}
function rejects(callable $call, string $expected, string $name): void {
    try { $call(); }
    catch (Throwable $e) {
        check($e instanceof $expected, "$name (got " . get_class($e) . ')');
        return;
    }
    throw new RuntimeException("FAIL: $name (no exception)");
}
function makeFn(string $name, array $types, int $arity, string $return, array $code): FunctionDef {
    return new FunctionDef($name, $types, $arity, $return, $code);
}

$add = makeFn('add', [T::INT, T::INT, T::INT], 2, T::INT, [
    I::abc(O::IADD, 2, 0, 1), I::abc(O::RET, 2),
]);
$caller = makeFn('caller', [T::INT, T::INT, T::INT], 2, T::INT, [
    I::abc(O::CALL, 2, 0, 0), I::abc(O::RET, 2),
]);
$vm = new Vm(new Program([$add, $caller]));
check($vm->run(0, [4, 5]) === 9, 'integer addition');
check($vm->run(1, [12, 30]) === 42, 'direct CALL and return');
check($vm->lastSteps() === 4, 'count CALL instructions');
rejects(fn() => $vm->run(0, [4, 5.0]), InvalidArgumentException::class, 'strict argument types');
rejects(fn() => $vm->run(0, [1]), InvalidArgumentException::class, 'arity');
rejects(fn() => $vm->run(0, [PHP_INT_MAX, 1]), VmFault::class, 'integer overflow');

$sum = makeFn('sum', [T::INT, T::INT, T::INT, T::BOOL, T::INT, T::INT, T::INT], 2, T::INT, [
    I::abx(O::CONST_, 2, 0), I::abc(O::IEQ, 3, 0, 2), I::abx(O::BR_TRUE, 3, 9),
    I::abx(O::CONST_, 4, 1), I::abc(O::ISUB, 5, 0, 4), I::abc(O::IADD, 6, 1, 0),
    I::abc(O::MOVE, 0, 5), I::abc(O::MOVE, 1, 6), I::abc(O::TAILCALL, 0, 0, 0), I::abc(O::RET, 1),
]);
$vm = new Vm(new Program([$sum], [0, 1]));
check($vm->run(0, [0, 0]) === 0, 'tail-call base case');
check($vm->run(0, [100000, 0]) === 5000050000, '100k tail calls without PHP recursion');
rejects(fn() => $vm->run(0, [100, 0], 10), ExecutionLimitExceeded::class, 'instruction budget');

$fact = makeFn('factorial', [T::INT, T::INT, T::BOOL, T::INT, T::INT, T::INT, T::INT], 1, T::INT, [
    I::abx(O::CONST_, 1, 1), I::abc(O::ILT, 2, 0, 1), I::abx(O::BR_TRUE, 2, 8),
    I::abc(O::ISUB, 3, 0, 1), I::abc(O::MOVE, 4, 3),
    I::abc(O::CALL, 5, 0, 4), I::abc(O::IMUL, 6, 0, 5), I::abc(O::RET, 6),
    I::abc(O::RET, 1),
]);
$vm = new Vm(new Program([$fact], [0, 1]));
check($vm->run(0, [5]) === 120, 'non-tail recursive CALL');
check($vm->run(0, [0]) === 1, 'factorial base case');
rejects(fn() => $vm->run(0, [22]), VmFault::class, 'recursive overflow');

$loop = makeFn('loop', [T::INT], 1, T::INT, [I::abx(O::JMP, 0, 0)]);
$vm = new Vm(new Program([$loop]));
rejects(fn() => $vm->run(0, [1], 17), ExecutionLimitExceeded::class, 'budget on backward jumps');
check($vm->lastSteps() === 18, 'budget counts rejected instruction');

$branch = makeFn('branch', [T::BOOL, T::INT], 1, T::INT, [
    I::abx(O::BR_FALSE, 0, 3), I::abx(O::CONST_, 1, 0),
    I::abc(O::RET, 1), I::abx(O::CONST_, 1, 1), I::abc(O::RET, 1),
]);
$vm = new Vm(new Program([$branch], [7, 9]));
check($vm->run(0, [true]) === 7 && $vm->run(0, [false]) === 9, 'conditional branches');

rejects(fn() => new Vm(new Program([makeFn('bad_jump', [T::INT], 0, T::INT, [I::abx(O::JMP, 0, 9)])])), VerificationException::class, 'invalid jump');
rejects(fn() => new Vm(new Program([makeFn('bad_opcode', [T::INT], 0, T::INT, [I::abc(254)])])), VerificationException::class, 'unknown opcode');
rejects(fn() => new Vm(new Program([makeFn('uninit', [T::INT], 0, T::INT, [I::abc(O::RET, 0)])])), VerificationException::class, 'uninitialized register');
rejects(fn() => new Vm(new Program([makeFn('bad_join', [T::BOOL, T::INT], 1, T::INT, [
    I::abx(O::BR_TRUE, 0, 2), I::abx(O::CONST_, 1, 0), I::abc(O::RET, 1),
])], [7])), VerificationException::class, 'definite assignment at CFG join');
rejects(fn() => new Vm(new Program([makeFn('wrong_type', [T::BOOL], 0, T::BOOL, [
    I::abx(O::CONST_, 0, 0), I::abc(O::RET, 0),
])], [3])), VerificationException::class, 'constant type mismatch');
rejects(fn() => new Vm(new Program([makeFn('bad_reserved', [T::INT], 1, T::INT, [
    I::abc(O::RET, 0, 1),
])])), VerificationException::class, 'noncanonical reserved fields');
rejects(fn() => new Vm(new Program([makeFn('bad_fallthrough', [T::INT], 1, T::INT, [
    I::abc(O::MOVE, 0, 0),
])])), VerificationException::class, 'fallthrough past end');

// Non-tail self calls use explicit VM frames, not host recursion.
$depth = makeFn('depth', [T::INT, T::INT, T::BOOL, T::INT, T::INT], 1, T::INT, [
    I::abx(O::CONST_, 1, 0), I::abc(O::IEQ, 2, 0, 1), I::abx(O::BR_TRUE, 2, 7),
    I::abx(O::CONST_, 3, 1), I::abc(O::ISUB, 3, 0, 3), I::abc(O::CALL, 4, 0, 3),
    I::abc(O::RET, 4), I::abc(O::RET, 0),
]);
$vm = new Vm(new Program([$depth], [0, 1]));
check($vm->run(0, [2000]) === 0, '2000 non-tail calls on explicit VM frame stack');

echo "\n$passed checks passed\n";
