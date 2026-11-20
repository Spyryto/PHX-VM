<?php
declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';

use PhxVm\{FunctionDef, Instruction as I, Opcode as O, PhpArtifactCache, Program, Types as T};

// Run multiple independent CLI processes against the same private cache dir.
// Every worker compiles or loads the EXACT same verified program and checks the
// output and instruction budget; the directory lock serializes publication.
$directory = $argv[1] ?? null;
if (!is_string($directory) || $directory === '') {
    throw new InvalidArgumentException('Pass a private cache directory');
}
$program = new Program([
    new FunctionDef('double', [T::INT, T::INT], 1, T::INT, [
        I::abc(O::IADD, 1, 0, 0), I::abc(O::RET, 1),
    ]),
    new FunctionDef('caller', [T::INT, T::INT, T::INT], 1, T::INT, [
        I::abc(O::CALL, 1, 0, 0),
        I::abc(O::IADD, 2, 0, 1),
        I::abc(O::RET, 2),
    ]),
]);
$cache = new PhpArtifactCache($directory);
for ($i = 0; $i < 12; ++$i) {
    $vm = $cache->load($program, 1, true, true);
    $n = 5 + $i;
    if ($vm->run(1, [$n]) !== $n * 3 || $vm->lastSteps() !== 5) {
        throw new RuntimeException('Cache worker returned different semantics');
    }
}
echo "Cache concurrency worker OK\n";
