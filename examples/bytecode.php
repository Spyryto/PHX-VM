<?php
declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';

use PhxVm\{
    FunctionDef, Instruction as I, Opcode as O,
    Program, ProgramCodec, Types as T, Vm
};

// A compiler/frontend would produce a Program or the documented PHX v1
// binary format. This example writes verified *data* only, not executable PHP.
$n = (int) ($argv[1] ?? 30);
if ($n < 0 || $n > 10000) throw new InvalidArgumentException('n must be 0..10000');

$sum = new FunctionDef('sum', [T::INT, T::INT, T::INT, T::BOOL, T::INT], 1, T::INT, [
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
$original = new Program([$sum], [0, 1]);
$binary = ProgramCodec::encode($original);

$path = tempnam(sys_get_temp_dir(), 'phx-example-');
if ($path === false) throw new RuntimeException('Cannot create temporary file');
try {
    if (file_put_contents($path, $binary) !== strlen($binary)) {
        throw new RuntimeException('Cannot write PHX bytecode file');
    }
    // Import re-checks size, framing, instruction types, definite assignment,
    // jumps and all signatures before constructing the executable Program.
    $imported = ProgramCodec::decodeFile($path);
} finally {
    unlink($path);
}

$vm = new Vm($imported);
$result = $vm->run(0, [$n], 10 * $n + 20);
$expected = intdiv($n * ($n + 1), 2);
if ($result !== $expected) throw new RuntimeException('Incorrect imported program');
printf("PHX bytecode v%d: %d bytes, SHA-256 %s\n",
    ProgramCodec::VERSION, strlen($binary), hash('sha256', $binary));
printf("sum(1..%d) = %d; %d canonical instructions executed\n",
    $n, $result, $vm->lastSteps());
