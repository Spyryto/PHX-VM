<?php
declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';

use PhxVm\{BlockVm, FunctionDef, Instruction as I, Opcode as O, Program, Types as T, Vm};

// Same verified bytecode for both interpreters. This benchmark is not a
// correctness test and must not be used as a CI speed threshold.
$n = isset($argv[1]) ? (int) $argv[1] : 30000;
if ($n < 1 || $n > 1000000) {
    throw new InvalidArgumentException('Specify n in [1, 1000000]');
}
$loops = new FunctionDef('loopSum',
    [T::INT, T::INT, T::INT, T::BOOL, T::INT], 1, T::INT, [
        I::abx(O::CONST_, 1, 0),
        I::abx(O::CONST_, 2, 1),
        I::abx(O::CONST_, 4, 0),
        I::abc(O::IEQ, 3, 0, 4),
        I::abx(O::BR_TRUE, 3, 8),
        I::abc(O::IADD, 1, 1, 0),
        I::abc(O::ISUB, 0, 0, 2),
        I::abx(O::JMP, 0, 3),
        I::abc(O::RET, 1),
    ]
);
$program = new Program([$loops], [0, 1]);
$expected = intdiv($n * ($n + 1), 2);
$maxSteps = 10 * $n + 20;

function median(array $numbers): float
{
    sort($numbers, SORT_NUMERIC);
    return $numbers[intdiv(count($numbers), 2)];
}
function measure(string $name, object $vm, int $n, int $budget, int $expected): array
{
    $result = $vm->run(0, [$n], $budget); // warm up VM and PHP opcodes
    if ($result !== $expected) throw new RuntimeException("$name wrong result");

    $samples = [];
    for ($i = 0; $i < 7; ++$i) {
        $started = hrtime(true);
        $value = $vm->run(0, [$n], $budget);
        $samples[] = (hrtime(true) - $started) / 1e6;
        if ($value !== $expected) throw new RuntimeException("$name result mismatch");
    }
    return ['name' => $name, 'medianMs' => median($samples), 'steps' => $vm->lastSteps()];
}

$t0 = new Vm($program);
$t1 = new BlockVm($program);

echo 'PHP ' . PHP_VERSION . ' / ' . PHP_OS_FAMILY . PHP_EOL;
echo 'OPcache CLI=' . ini_get('opcache.enable_cli') . ', JIT=' . ini_get('opcache.jit') . PHP_EOL;
echo "Input n=$n; seven timed runs after one warm-up per engine\n";
$a = measure('T0', $t0, $n, $maxSteps, $expected);
$b = measure('T1', $t1, $n, $maxSteps, $expected);
if ($a['steps'] !== $b['steps']) throw new RuntimeException('Budget accounting mismatch');
printf("T0: %.3f ms (%d instructions)\n", $a['medianMs'], $a['steps']);
printf("T1: %.3f ms (%d instructions)\n", $b['medianMs'], $b['steps']);
printf("T1/T0 elapsed-time ratio: %.3f (lower is better)\n", $b['medianMs'] / $a['medianMs']);
echo "Results reflect only this PHP installation and one workload; no general speedup claim.\n";
