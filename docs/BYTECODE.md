# PHX binary bytecode v1 — experimental interchange format

This describes the **current** PHX VM binary interchange format. It is an explicit
data encoding for independent frontends. It is **not a stable ABI commitment
before PHX-VM 1.0**. The implementation is `PhxVm\ProgramCodec`.

A PHX binary file is **not PHP code**. Import uses no `eval`, `unserialize`,
autoloaded application objects, PHP reflection or source execution.

## Entry points

~~~php
$binary = \PhxVm\ProgramCodec::encode($program);  // verifies before encoding
$program = \PhxVm\ProgramCodec::decode($binary);  // validates and verifies
$program = \PhxVm\ProgramCodec::decodeFile($path); // reads at most 8 MiB+1
$vm = new \PhxVm\Vm($program);
$result = $vm->run(0, [30], 100000);
~~~

The binary stores **all functions and constants** in a Program; it does not
select an entry point. The executing application selects the entry by integer
function ID. No VM capabilities or host functions are serialized.

## Encoding

All lengths and counts are unsigned. `u16` and `u32` are **little-endian**;
`i64` is signed two's-complement **little-endian**, on a **64-bit PHP** host;
`f64` is IEEE 754 binary64 **big-endian**. All instructions are exactly
4 bytes (`u32` little-endian), preserving the canonical OP/A/B/C or OP/A/Bx
register instruction.

| Field | Width | Description |
|---|---:|---|
| Magic | 4 bytes | `50 48 58 00` (`PHX\0`) |
| Version | u16 | `1` |
| Reserved | u16 | must be `0` |
| Function count | u16 | 1..256 |
| Constant count | u32 | 0..65536 |
| Constants | variable | exactly constant-count entries, in order |
| Functions | variable | exactly function-count entries, in order |
| End | 0 bytes | trailing bytes are rejected |

### Constants

Each constant begins with a one-byte *tag*:

| Tag | Value | Payload |
|---:|---|---|
| `0` | `null` | none |
| `1` | `false` | none |
| `2` | `true` | none |
| `3` | integer | i64 (8 bytes LE, signed) |
| `4` | float | f64 (8 bytes BE) |
| `5` | string | u32 byte-length, then raw bytes |

The wire format preserves `-0.0`, infinities and NaN (with usual PHP floating-point
limitations). Strings may include NUL and arbitrary binary bytes; they are
not required to be UTF-8.

### Functions

Each function, in numeric function-ID order, contains:

| Field | Width | Description |
|---|---:|---|
| Name byte-length | u16 | 0..255 |
| Name | variable | raw bytes, no terminator |
| Arity | u16 | 0..number of registers |
| Return type | u8 | type tag below |
| Register count | u16 | 1..256 |
| Register types | register-count × u8 | one type tag per register |
| Instruction count | u32 | 1..65536 |
| Instructions | instruction-count × u32 | 32-bit little-endian words |

Register type tags are `0 Int, 1 Float, 2 Bool, 3 String, 4 Null`.
Argument registers occupy `r0..r(arity-1)`, as in PHX-VM's verified ISA.
Type signatures, operand encoding and control-flow rules are defined in
[ARCHITECTURE.md](ARCHITECTURE.md) and enforced by `Verifier`.

### Independent golden test vector

One function named `id`, arity 1, one Int register, returns it with
`RET r0`. Zero constants. Hexadecimal, concatenated:

~~~text
50 48 58 00  01 00  00 00  01 00  00 00 00 00
02 00  69 64  01 00  00  01 00  00
01 00 00 00  0d 00 00 00
~~~

It is **32 bytes** long. `tests/codec.php` verifies exact bytes and round-trips it
on PHP 8.2–8.5.

## Import requirements and bounds

Malformed data throws `BytecodeFormatException`, including unknown tags,
unsupported version, truncated payload, oversized lengths, trailing junk or
a bytecode program rejected by the original verifier. `encode` performs
verification before writing the binary representation and may also throw
`VerificationException` for an invalid in-memory Program.

Limits are deliberately conservative:

| Resource | v1 bound |
|---|---:|
| Whole encoded file or binary input | 8 MiB |
| Functions | 256 |
| Constants | 65,536 |
| Encoded string constant | 1 MiB |
| Function name | 255 bytes |
| Registers per function | 256 |
| Instructions per function | 65,536 |
| Total instructions across functions | 200,000 |

The decoder **checks lengths before allocating instruction vectors** and
always runs `Verifier::verify()` after decoding. No incomplete Program is returned.

These are limits on imported representation, not a complete runtime sandbox.
Execution still needs a caller-supplied instruction budget (`maxSteps`) and
appropriate process memory/time limits. A T2 compiler produces **trusted PHP
host code**: only use it when that trust model is appropriate. Untrusted inputs
are not an authorization to execute arbitrary PHP or to bypass normal host
resource controls.

## ABI evolution

Version `1` is a **wire-format version**, not PHX-VM's semantic version.
Future incompatible changes must change the version or introduce a new
container, with explicit conversion tools if needed. Unknown versions are
rejected rather than interpreted as an older format. There is no implied
backwards-compatibility obligation before the language/runtime reaches 1.0.

Implementation intentionally stays PHP-only and independent of Nail. A frontend
in Haxe, PHP, JavaScript or another language can generate this deterministic
representation without needing to embed PHP serialization.
