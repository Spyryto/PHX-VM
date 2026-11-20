<?php
declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';

use PhxVm\{FunctionDef, Instruction as I, Opcode as O, PhpEmitter, PhpVm, Program, Types as T, Vm};

$n = (int) ($argv[1] ?? 20000);
if ($n < 1 || $n > 500000) {
    throw new InvalidArgumentException('n must be 1..500000');
}

// A hot, small leaf function called from a loop. Same verified bytecode and
// original instruction accounting for every engine.
$double = new FunctionDef('double', [T::INT, T::INT], 1, T::INT, [
    I::abc(O::IADD, 1, 0, 0), I::abc(O::RET, 1),
]);
$sumDoubles = new FunctionDef('sumDoubles', [
    T::INT, T::INT, T::INT, T::BOOL, T::INT, T::INT,
], 1, T::INT, [
    I::abx(O::CONST_, 1, 0), // total = 0
    I::abx(O::CONST_, 2, 1), // decrement = 1
    I::abx(O::CONST_, 5, 0), // zero = 0
    I::abc(O::IEQ, 3, 0, 5),
    I::abx(O::BR_TRUE, 3, 9),
    I::abc(O::CALL, 4, 0, 0), // doubled = double(n)
    I::abc(O::IADD, 1, 1, 4),
    I::abc(O::ISUB, 0, 0, 2),
    I::abx(O::JMP, 0, 3),
    I::abc(O::RET, 1),
]);
$program = new Program([$double, $sumDoubles], [0, 1]);
$entry = 1;
$budget = 10 * $n + 100;
$expected = $n * ($n + 1);

function benchMedian(array $numbers): float
{
    sort($numbers, SORT_NUMERIC);
    return $numbers[intdiv(count($numbers), 2)];
}
function buildT2(Program $program, int $entry, bool $inline): array
{
    $start = hrtime(true);
    $source = PhpEmitter::source($program, $entry, $inline);
    $generationMs = (hrtime(true) - $start) / 1e6;
    $path = tempnam(sys_get_temp_dir(), 'phx-inline-bench-');
    if ($path === false) throw new RuntimeException('tempnam failed');
    try {
        $start = hrtime(true);
        if (file_put_contents($path, $source) !== strlen($source)) {
            throw new RuntimeException('Cannot write generated source');
        }
        $vm = new PhpVm($entry, $path);
        $loadMs = (hrtime(true) - $start) / 1e6;
    } finally {
        unlink($path);
    }
    return [$vm, $generationMs, $loadMs, strlen($source),
        substr_count($source, 'PHX_INLINE_BEGIN')];
}
function benchRun(object $vm, int $entry, int $n, int $budget, int $expected): array
{
    if ($vm->run($entry, [$n], $budget) !== $expected) {
        throw new RuntimeException('Incorrect warm-up result');
    }
    $samples = [];
    for ($i = 0; $i < 7; ++$i) {
        $start = hrtime(true);
        $result = $vm->run($entry, [$n], $budget);
        $samples[] = (hrtime(true) - $start) / 1e6;
        if ($result !== $expected) throw new RuntimeException('Incorrect sample result');
    }
    return [benchMedian($samples), $vm->lastSteps()];
}

$start = hrtime(true);
$t0 = new Vm($program);
$t0Init = (hrtime(true) - $start) / 1e6;
[$t2, $t2Compile, $t2Load, $t2Bytes] = buildT2($program, $entry, false);
[$t2Inline, $inlineCompile, $inlineLoad, $inlineBytes, $siteCount] =
    buildT2($program, $entry, true);
if ($siteCount !== 1) throw new RuntimeException('Expected exactly one inlined callsite');

$a = benchRun($t0, $entry, $n, $budget, $expected);
$b = benchRun($t2, $entry, $n, $budget, $expected);
$c = benchRun($t2Inline, $entry, $n, $budget, $expected);
if ($a[1] !== $b[1] || $a[1] !== $c[1]) {
    throw new RuntimeException('Original instruction budget mismatch');
}

echo 'PHP ' . PHP_VERSION . ' / ' . PHP_OS_FAMILY . PHP_EOL;
echo 'OPcache CLI=' . ini_get('opcache.enable_cli')
    . ', JIT=' . ini_get('opcache.jit') . PHP_EOL;
echo "Sum of double(1..$n), seven measured runs after warm-up\n";
printf("T0:       %.3f ms (%d instructions), verifier %.3f ms\n", $a[0], $a[1], $t0Init);
printf("T2:       %.3f ms (%d instructions), emit %.3f ms, load %.3f ms, %d bytes\n",
    $b[0], $b[1], $t2Compile, $t2Load, $t2Bytes);
printf("T2 inline: %.3f ms (%d instructions), emit %.3f ms, load %.3f ms, %d bytes\n",
    $c[0], $c[1], $inlineCompile, $inlineLoad, $inlineBytes);
printf("Inline vs T2 runtime ratio: %.3fx (higher means faster)\n", $b[0] / $c[0]);
echo "Compilation, file I/O and loading are separately measured; no speed threshold in CI.\n";
