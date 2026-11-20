<?php
declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';

use PhxVm\{BlockVm, FunctionDef, Instruction as I, Opcode as O, PhpEmitter, PhpVm, Program, Types as T, Vm};

// No performance threshold: CI runners and PHP versions differ.
// Compare exactly the same verified program, args, budget and result.
$n = (int) ($argv[1] ?? 10000);
if ($n < 1 || $n > 100000) throw new InvalidArgumentException('n must be between 1 and 100000');

function medianCalls(array $measurements): float
{
    sort($measurements, SORT_NUMERIC);
    return $measurements[intdiv(count($measurements), 2)];
}

function runCallsBenchmark(string $name, Program $program, int $entry, array $args, int $budget, mixed $expected): void
{
    $t0 = new Vm($program);
    $t1 = new BlockVm($program, true);

    $start = hrtime(true);
    $source = PhpEmitter::source($program, $entry);
    $generationMs = (hrtime(true) - $start) / 1e6;

    $path = tempnam(sys_get_temp_dir(), 'phx-bench-calls-');
    if ($path === false) throw new RuntimeException('tempnam failed');
    try {
        $start = hrtime(true);
        if (file_put_contents($path, $source) !== strlen($source)) {
            throw new RuntimeException('Cannot write generated PHP');
        }
        $t2 = new PhpVm($entry, $path);
        $loadMs = (hrtime(true) - $start) / 1e6;
    } finally {
        unlink($path);
    }

    $results = [];
    foreach (['T0' => $t0, 'T1-fused' => $t1, 'T2-PHP' => $t2] as $engine => $vm) {
        if ($vm->run($entry, $args, $budget) !== $expected) {
            throw new RuntimeException($engine . ' unexpected warmup result');
        }
        $ms = [];
        for ($i = 0; $i < 7; ++$i) {
            $start = hrtime(true);
            $result = $vm->run($entry, $args, $budget);
            $ms[] = (hrtime(true) - $start) / 1e6;
            if ($result !== $expected) throw new RuntimeException($engine . ' wrong result');
        }
        $results[$engine] = [medianCalls($ms), $vm->lastSteps()];
    }
    if (count(array_unique(array_column($results, 1))) !== 1) {
        throw new RuntimeException('Instruction accounting mismatch');
    }

    printf(
        "%s: compile %.3f ms, write+load %.3f ms, generated %d bytes\n",
        $name, $generationMs, $loadMs, strlen($source)
    );
    foreach ($results as $engine => [$elapsed, $steps]) {
        printf("  %-9s: %.3f ms (%d original instructions)\n", $engine, $elapsed, $steps);
    }
    printf("  T0/T2 run-time ratio: %.3fx\n", $results['T0'][0] / $results['T2-PHP'][0]);
}

$sum = new FunctionDef('sum', [T::INT, T::INT, T::INT, T::BOOL, T::INT, T::INT, T::INT], 2, T::INT, [
    I::abx(O::CONST_, 2, 0), I::abc(O::IEQ, 3, 0, 2),
    I::abx(O::BR_TRUE, 3, 9), I::abx(O::CONST_, 4, 1),
    I::abc(O::ISUB, 5, 0, 4), I::abc(O::IADD, 6, 1, 0),
    I::abc(O::MOVE, 0, 5), I::abc(O::MOVE, 1, 6),
    I::abc(O::TAILCALL, 0, 0, 0), I::abc(O::RET, 1),
]);
$sumWrapper = new FunctionDef('sumWrapper', [T::INT, T::INT, T::INT], 2, T::INT, [
    I::abc(O::CALL, 2, 0, 0), I::abc(O::RET, 2),
]);
$depth = new FunctionDef('depth', [T::INT, T::INT, T::BOOL, T::INT, T::INT], 1, T::INT, [
    I::abx(O::CONST_, 1, 0), I::abc(O::IEQ, 2, 0, 1),
    I::abx(O::BR_TRUE, 2, 7), I::abx(O::CONST_, 3, 1),
    I::abc(O::ISUB, 3, 0, 3), I::abc(O::CALL, 4, 0, 3),
    I::abc(O::RET, 4), I::abc(O::RET, 0),
]);

echo 'PHP ' . PHP_VERSION . ' / ' . PHP_OS_FAMILY . PHP_EOL;
echo 'OPcache CLI=' . ini_get('opcache.enable_cli') . ', JIT=' . ini_get('opcache.jit') . PHP_EOL;
echo "7 timed runs after one warmup, same verified bytecode and step budget\n";
runCallsBenchmark('Tail sum (' . $n . ')', new Program([$sum, $sumWrapper], [0, 1]),
    1, [$n, 0], 10 * $n + 20, intdiv($n * ($n + 1), 2));
$depthN = min($n, 2000);
runCallsBenchmark('Non-tail depth (' . $depthN . ')', new Program([$depth], [0, 1]),
    0, [$depthN], 10 * $depthN + 20, 0);
echo "Preparation times and measured runs are separate; no general speedup claim.\n";
