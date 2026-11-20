<?php
declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';

use PhxVm\{FunctionDef, Instruction as I, Opcode as O, PhpEmitter, PhpVm, Program, RegisterLiveness, Types as T, Vm};

$n = (int) ($argv[1] ?? 2000);
if ($n < 1 || $n > 4000) {
    throw new InvalidArgumentException('depth must be between 1 and 4000');
}

// Deliberately wide register bank: only r0 survives the recursive CALL.
// The original T2 stores all 64 registers in every continuation; the optimized
// T2 stores only one, independent of the actual recursion depth.
$types = array_fill(0, 64, T::INT);
$types[2] = T::BOOL;
$depth = new FunctionDef('depthWide', $types, 1, T::INT, [
    I::abx(O::CONST_, 1, 0),
    I::abx(O::CONST_, 5, 1),
    I::abc(O::IEQ, 2, 0, 1),
    I::abx(O::BR_TRUE, 2, 8),
    I::abc(O::ISUB, 3, 0, 5),
    I::abc(O::CALL, 4, 0, 3),
    I::abc(O::IADD, 6, 0, 4),
    I::abc(O::RET, 6),
    I::abc(O::RET, 1),
]);
$program = new Program([$depth], [0, 1]);
$expected = intdiv($n * ($n + 1), 2);
$budget = 10 * $n + 40;

function timeMedian(array $numbers): float
{
    sort($numbers, SORT_NUMERIC);
    return $numbers[intdiv(count($numbers), 2)];
}
function generateFrameVm(Program $program, bool $trim): array
{
    $started = hrtime(true);
    $source = PhpEmitter::source($program, 0, false, $trim);
    $compileMs = (hrtime(true) - $started) / 1e6;

    $path = tempnam(sys_get_temp_dir(), 'phx-frames-bench-');
    if ($path === false) throw new RuntimeException('No temporary file');
    try {
        $started = hrtime(true);
        if (file_put_contents($path, $source) !== strlen($source)) {
            throw new RuntimeException('Cannot write generated PHP');
        }
        $vm = new PhpVm(0, $path);
        $loadMs = (hrtime(true) - $started) / 1e6;
    } finally {
        unlink($path);
    }
    return [$vm, $compileMs, $loadMs, strlen($source)];
}
function timeExecution(object $vm, int $n, int $budget, int $expected): array
{
    if ($vm->run(0, [$n], $budget) !== $expected) {
        throw new RuntimeException('Unexpected warmup result');
    }
    $values = [];
    for ($i = 0; $i < 7; ++$i) {
        $started = hrtime(true);
        $result = $vm->run(0, [$n], $budget);
        $values[] = (hrtime(true) - $started) / 1e6;
        if ($result !== $expected) throw new RuntimeException('Wrong result');
    }
    return [timeMedian($values), $vm->lastSteps()];
}

$started = hrtime(true);
$t0 = new Vm($program);
$t0VerifyMs = (hrtime(true) - $started) / 1e6;
[$full, $fullCompileMs, $fullLoadMs, $fullBytes] = generateFrameVm($program, false);
[$trim, $trimCompileMs, $trimLoadMs, $trimBytes] = generateFrameVm($program, true);

$baseline = timeExecution($t0, $n, $budget, $expected);
$original = timeExecution($full, $n, $budget, $expected);
$optimized = timeExecution($trim, $n, $budget, $expected);
if ($baseline[1] !== $original[1] || $baseline[1] !== $optimized[1]) {
    throw new RuntimeException('Instruction accounting mismatch');
}

$live = RegisterLiveness::acrossCalls($program, 0);
printf("PHP %s / %s\n", PHP_VERSION, PHP_OS_FAMILY);
printf("OPcache CLI=%s, JIT=%s\n", ini_get('opcache.enable_cli'), ini_get('opcache.jit'));
printf("Wide non-tail recursion, depth=%d, register slots per frame=%d / %d (full / trimmed)\n",
    $n, count($types), count($live[5]));
echo "Seven timed runs after warm-up; same bytecode, returned value and instruction budget\n";
printf("T0:       %.3f ms, %d steps, verifier %.3f ms\n",
    $baseline[0], $baseline[1], $t0VerifyMs);
printf("T2 full:  %.3f ms, %d steps, compile %.3f ms, load %.3f ms, source %d bytes\n",
    $original[0], $original[1], $fullCompileMs, $fullLoadMs, $fullBytes);
printf("T2 live:  %.3f ms, %d steps, compile %.3f ms, load %.3f ms, source %d bytes\n",
    $optimized[0], $optimized[1], $trimCompileMs, $trimLoadMs, $trimBytes);
printf("T2 full/live execution ratio: %.3fx (higher means faster)\n",
    $original[0] / $optimized[0]);
echo "Slot counts describe the continuation payload, not measured process memory.\n";
echo "Compilation, file loading and execution times are separate; no speedup assertion.\n";
