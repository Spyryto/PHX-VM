<?php
declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';

use PhxVm\{
    BlockVm, BytecodeFormatException, FunctionDef, Instruction as I,
    Opcode as O, PhpEmitter, PhpVm, Program, ProgramCodec,
    Types as T, Vm
};

$checks = 0;
function codecCheck(bool $okay, string $message): void
{
    global $checks;
    if (!$okay) throw new RuntimeException('ProgramCodec test failed: ' . $message);
    ++$checks;
}
function codecReject(callable $fn, string $message): void
{
    global $checks;
    try {
        $fn();
    } catch (BytecodeFormatException $e) {
        ++$checks;
        return;
    }
    throw new RuntimeException('ProgramCodec accepted malformed input: ' . $message);
}
function codecResult(object $vm, int $entry, array $args, int $budget): array
{
    try {
        return ['return', serialize($vm->run($entry, $args, $budget)), $vm->lastSteps()];
    } catch (Throwable $e) {
        return ['throw', $e::class, $e->getMessage(), $vm->lastSteps()];
    }
}
function codecLoadPhp(Program $program, int $entry): PhpVm
{
    $path = tempnam(sys_get_temp_dir(), 'phx-codec-exec-');
    if ($path === false) throw new RuntimeException('Cannot create temporary PHP');
    try {
        $code = PhpEmitter::source($program, $entry, true, true);
        if (file_put_contents($path, $code) !== strlen($code)) {
            throw new RuntimeException('Cannot write trusted generated PHP');
        }
        return new PhpVm($entry, $path);
    } finally {
        unlink($path);
    }
}
function codecDifferential(Program $program, int $entry, array $args, array $budgets): void
{
    global $checks;
    $encoded = ProgramCodec::encode($program);
    $decoded = ProgramCodec::decode($encoded);
    $engines = [
        new Vm($program),
        new Vm($decoded),
        new BlockVm($decoded, true),
        codecLoadPhp($decoded, $entry),
    ];
    foreach ($budgets as $budget) {
        $expected = codecResult($engines[0], $entry, $args, $budget);
        foreach (array_slice($engines, 1) as $engine) {
            $result = codecResult($engine, $entry, $args, $budget);
            if ($result !== $expected) {
                throw new RuntimeException('Codec engine mismatch: '
                    . var_export([$entry, $args, $budget, $expected, $result], true));
            }
        }
        ++$checks;
    }
}

// Portable golden vector (32 bytes): "id(int)->int", one RET r0 instruction.
$golden = hex2bin('5048580001000000010000000000'
    . '02006964' . '010000010000010000000d000000');
codecCheck($golden !== false && strlen($golden) === 32, 'golden vector byte length');
$id = new Program([
    new FunctionDef('id', [T::INT], 1, T::INT, [I::abc(O::RET, 0)]),
]);
codecCheck(ProgramCodec::encode($id) === $golden, 'portable binary golden encoding');
codecCheck(ProgramCodec::encode(ProgramCodec::decode($golden)) === $golden,
    'decode/encode golden vector identity');
codecDifferential($id, 0, [42], [1, 2, 5]);
codecDifferential($id, 0, [1.0], [1, 2, 5]);
codecDifferential($id, 0, [], [1, 2, 5]);

// Every supported scalar value is represented by an explicit binary tag;
// binary strings are length-prefixed rather than assumed valid UTF-8.
$constants = [
    null, false, true, 0, 1, -1,
    PHP_INT_MAX, PHP_INT_MIN, 3.25, -0.0, INF, -INF, NAN,
    '', "binary\x00\xff\n<php>", str_repeat('a', 512),
];
$functions = [];
foreach ($constants as $idx => $value) {
    $type = T::of($value);
    $functions[] = new FunctionDef('literal-' . $idx, [$type], 0, $type, [
        I::abx(O::CONST_, 0, $idx),
        I::abc(O::RET, 0),
    ]);
}
$program = new Program($functions, $constants);
$binary = ProgramCodec::encode($program);
$decoded = ProgramCodec::decode($binary);
codecCheck($binary === ProgramCodec::encode($decoded), 'all scalar tags deterministic round-trip');
codecCheck(strlen($binary) > 14 && str_starts_with($binary, "PHX\0"), 'v1 envelope');
foreach ($constants as $idx => $value) {
    $actual = $decoded->constants[$idx];
    if (is_float($value) && is_nan($value)) {
        codecCheck(is_float($actual) && is_nan($actual), 'NaN retained');
    } elseif (is_float($value)) {
        codecCheck(is_float($actual) && pack('E', $actual) === pack('E', $value),
            'float64 bits retained: ' . $idx);
    } else {
        codecCheck($actual === $value, 'tag and scalar retained: ' . $idx);
    }
    codecDifferential($program, $idx, [], [1, 2, 3, 100]);
}
codecCheck($decoded->functions[13]->name === 'literal-13', 'function order/name retained');

// Call control flow survives independent binary decoding and must not alter
// instruction charging, including a budget failure inside the callee.
$double = new FunctionDef('double', [T::INT, T::INT], 1, T::INT, [
    I::abc(O::IADD, 1, 0, 0), I::abc(O::RET, 1),
]);
$entry = new FunctionDef('caller', [T::INT, T::INT, T::INT], 1, T::INT, [
    I::abc(O::CALL, 1, 0, 0),
    I::abc(O::IADD, 2, 1, 0),
    I::abc(O::RET, 2),
]);
$tail = new FunctionDef('tail', [T::INT], 1, T::INT, [
    I::abc(O::TAILCALL, 0, 0, 0),
]);
$program = new Program([$double, $entry, $tail]);
codecCheck(ProgramCodec::encode(ProgramCodec::decode(ProgramCodec::encode($program)))
    === ProgramCodec::encode($program), 'direct-call/tail-call round trip');
foreach ([0, 1, 500, PHP_INT_MAX] as $n) {
    codecDifferential($program, 1, [$n], range(1, 8));
    codecDifferential($program, 2, [$n], range(1, 5));
}
codecDifferential($program, 1, [5.0], [1, 4, 100]);

// File reader is bounded and does not eval PHP or trust malformed source.
$temp = tempnam(sys_get_temp_dir(), 'phx-bytecode-file-');
if ($temp === false) throw new RuntimeException('No temporary binary file');
try {
    file_put_contents($temp, ProgramCodec::encode($program));
    codecCheck(ProgramCodec::encode(ProgramCodec::decodeFile($temp))
        === ProgramCodec::encode($program), 'decodeFile round trip');
    file_put_contents($temp, "<?php echo 'DO NOT EXECUTE';");
    codecReject(static fn() => ProgramCodec::decodeFile($temp),
        'PHP source is not bytecode');
    file_put_contents($temp, str_repeat('x', ProgramCodec::MAX_BYTES + 1));
    codecReject(static fn() => ProgramCodec::decodeFile($temp),
        'oversized binary file rejected');
} finally {
    unlink($temp);
}
codecReject(static fn() => ProgramCodec::decodeFile('/this/path/does/not/exist.phx'),
    'nonexistent file rejected');
codecReject(static fn() => ProgramCodec::decode(str_repeat('x', ProgramCodec::MAX_BYTES + 1)),
    'oversized input rejected');

// Every truncated prefix of the golden vector is invalid, with no warnings,
// partial Programs or deferred execution.
for ($i = 0; $i < strlen($golden); ++$i) {
    codecReject(static fn() => ProgramCodec::decode(substr($golden, 0, $i)),
        'truncation at byte ' . $i);
}

$mutations = [
    'magic' => [0, 'Z'],
    'unsupported version' => [4, "\x02"],
    'nonzero reserved bits' => [6, "\x01"],
    'no functions' => [8, "\x00"],
    'too many functions' => [8, "\x01\x01"],
    'too many constants' => [10, pack('V', 65537)],
    'oversized name' => [14, pack('v', 256)],
    'oversized arity' => [18, pack('v', 2)],
    'unknown return type' => [20, "\xfe"],
    'zero registers' => [21, pack('v', 0)],
    'oversized registers' => [21, pack('v', 257)],
    'unknown register type' => [23, "\xfe"],
    'zero instructions' => [24, pack('V', 0)],
    'oversized instructions' => [24, pack('V', 65537)],
    'invalid opcode' => [28, "\xfe"],
    'read uninitialized' => [28, chr(O::IADD)],
];
foreach ($mutations as $name => [$offset, $newBytes]) {
    $changed = substr_replace($golden, $newBytes, $offset, strlen($newBytes));
    codecReject(static fn() => ProgramCodec::decode($changed), $name);
}
codecReject(static fn() => ProgramCodec::decode($golden . "\0"),
    'trailing junk rejected');

// Constant pool tags and lengths are strictly checked before allocation.
$withConstant = ProgramCodec::encode(new Program([
    new FunctionDef('ret', [T::INT], 0, T::INT, [
        I::abx(O::CONST_, 0, 0), I::abc(O::RET, 0),
    ]),
], [42]));
// First constant tag immediately follows 14-byte header.
codecReject(static fn() => ProgramCodec::decode(
    substr_replace($withConstant, "\xff", 14, 1)),
    'unknown constant tag');
$stringConstant = ProgramCodec::encode(new Program([
    new FunctionDef('text', [T::STRING], 0, T::STRING, [
        I::abx(O::CONST_, 0, 0), I::abc(O::RET, 0),
    ]),
], ['abc']));
codecReject(static fn() => ProgramCodec::decode(
    substr_replace($stringConstant, pack('V', ProgramCodec::MAX_STRING_BYTES + 1), 15, 4)),
    'length-prefixed string larger than limit');
$oversized = new Program([
    new FunctionDef('value', [T::STRING], 0, T::STRING, [
        I::abx(O::CONST_, 0, 0), I::abc(O::RET, 0),
    ]),
], [str_repeat('s', ProgramCodec::MAX_STRING_BYTES + 1)]);
codecReject(static fn() => ProgramCodec::encode($oversized),
    'encode rejects oversized constant');
$longName = new Program([
    new FunctionDef(str_repeat('n', ProgramCodec::MAX_NAME_BYTES + 1),
        [T::INT], 1, T::INT, [I::abc(O::RET, 0)]),
]);
codecReject(static fn() => ProgramCodec::encode($longName), 'encode rejects oversized name');

// Deterministic byte mutations are either rejected or produce a different,
// still fully verified Program (never unchecked code).
mt_srand(1126);
for ($i = 0; $i < 200; ++$i) {
    $offset = mt_rand(0, strlen($golden) - 1);
    $byte = chr(mt_rand(0, 255));
    $changed = substr_replace($golden, $byte, $offset, 1);
    try {
        $decoded = ProgramCodec::decode($changed);
        codecCheck(ProgramCodec::encode($decoded) === $changed,
            'valid mutated vector remains canonical');
    } catch (BytecodeFormatException $expected) {
        codecCheck(true, 'random malformed binary rejected');
    }
}

echo "PHX binary bytecode codec suite: $checks checks passed\n";
