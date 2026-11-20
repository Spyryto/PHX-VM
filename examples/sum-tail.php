<?php
declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';

use PhxVm\{FunctionDef, Instruction as I, Opcode as O, Program, Types as T, Vm};

// sum(n, acc): if n == 0 return acc, else tailcall sum(n-1, acc+n).
$sum = new FunctionDef('sum', [T::INT, T::INT, T::INT, T::BOOL, T::INT, T::INT, T::INT], 2, T::INT, [
    I::abx(O::CONST_, 2, 0),
    I::abc(O::IEQ, 3, 0, 2),
    I::abx(O::BR_TRUE, 3, 9),
    I::abx(O::CONST_, 4, 1),
    I::abc(O::ISUB, 5, 0, 4),
    I::abc(O::IADD, 6, 1, 0),
    I::abc(O::MOVE, 0, 5),
    I::abc(O::MOVE, 1, 6),
    I::abc(O::TAILCALL, 0, 0, 0),
    I::abc(O::RET, 1),
]);

$vm = new Vm(new Program([$sum], [0, 1]));
$n = isset($argv[1]) ? (int) $argv[1] : 10000;
if ($n < 0) throw new InvalidArgumentException('n must be nonnegative');
$result = $vm->run(0, [$n, 0], maxSteps: max(100, $n * 10 + 10));
echo "sum(1..$n) = $result, VM instructions = {$vm->lastSteps()}\n";
