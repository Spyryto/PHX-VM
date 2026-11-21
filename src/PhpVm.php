<?php
declare(strict_types=1);

namespace PhxVm;

/** Runs a trusted PhpEmitter-generated file; PHP opcode loading is the host's. */
final class PhpVm
{
    private readonly \Closure $runner;
    private int $lastSteps = 0;

    public function __construct(private readonly int $functionId, string $filePath)
    {
        $runner = require $filePath;
        if (!$runner instanceof \Closure) {
            throw new \InvalidArgumentException('T2 file must return a closure');
        }
        // Old 3-argument generated PHP silently ignores extra arguments on
        // PHP userland closures. Reject it instead of pretending it enforces
        // the continuation quota. All current T2 compilers emit this ABI.
        if ((new \ReflectionFunction($runner))->getNumberOfParameters() < 4) {
            throw new \InvalidArgumentException('T2 artifact lacks continuation quota ABI');
        }
        $this->runner = $runner;
    }

    public function lastSteps(): int { return $this->lastSteps; }

    /** @param list<int|float|bool|string|null> $args */
    public function run(int $functionId, array $args = [], int $maxSteps = 1000000, int $maxFrames = PHP_INT_MAX): mixed
    {
        $this->lastSteps = 0;
        if ($maxSteps < 1) throw new \InvalidArgumentException('maxSteps must be positive');
        if ($maxFrames < 0) throw new \InvalidArgumentException('maxFrames must be non-negative');
        if ($functionId !== $this->functionId) throw new \InvalidArgumentException('Unknown function ID');
        return ($this->runner)($args, $maxSteps, $this->lastSteps, $maxFrames);
    }
}
