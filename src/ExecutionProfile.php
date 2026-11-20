<?php
declare(strict_types=1);

namespace PhxVm;

/**
 * Opt-in, cumulative execution counters for T0. No automatic tier switching.
 *
 * Counters are deterministic, not wall-clock samples: instrumentation changes
 * the interpreter's speed, so timings measured here must NOT be used to
 * calibrate a native T0-vs-T2 speedup.
 *
 * A charged instruction is recorded even if its execution raises VmFault.
 * A rejected budget attempt increments budgetRejects (but no opcode counter).
 * Vm::lastSteps() includes this rejected attempt, as in unprofiled T0.
 */
final class ExecutionProfile
{
    /** @var array<int, array<string, mixed>> */
    private array $functions = [];

    /** @var array<int, array{started:int, completed:int, failed:int, successfulSteps:int, attemptedSteps:int}> */
    private array $roots = [];

    private int $maxContinuationDepth = 0;
    private int $totalCalls = 0;
    private int $totalTailCalls = 0;
    private int $totalReturns = 0;
    private int $budgetRejects = 0;

    public function startRun(int $rootId): void
    {
        $this->ensureRoot($rootId);
        ++$this->roots[$rootId]['started'];
    }

    public function enterFunction(int $functionId): void
    {
        $this->ensureFunction($functionId);
        ++$this->functions[$functionId]['entries'];
    }

    public function charge(int $functionId, int $opcode): void
    {
        $this->ensureFunction($functionId);
        ++$this->functions[$functionId]['chargedInstructions'];
        $this->functions[$functionId]['opcodes'][$opcode] =
            ($this->functions[$functionId]['opcodes'][$opcode] ?? 0) + 1;
    }

    public function transfer(int $callerId, bool $tail, int $continuationDepth): void
    {
        $this->ensureFunction($callerId);
        if ($tail) {
            ++$this->totalTailCalls;
            ++$this->functions[$callerId]['tailCalls'];
        } else {
            ++$this->totalCalls;
            ++$this->functions[$callerId]['calls'];
        }
        $this->maxContinuationDepth = max($this->maxContinuationDepth, $continuationDepth);
    }

    public function returned(int $functionId): void
    {
        $this->ensureFunction($functionId);
        ++$this->functions[$functionId]['returns'];
        ++$this->totalReturns;
    }

    public function rejectedByBudget(int $functionId): void
    {
        $this->ensureFunction($functionId);
        ++$this->functions[$functionId]['budgetRejects'];
        ++$this->budgetRejects;
    }

    public function finishRun(int $rootId, int $attemptedSteps, bool $completed): void
    {
        $this->ensureRoot($rootId);
        $this->roots[$rootId]['attemptedSteps'] += $attemptedSteps;
        if ($completed) {
            ++$this->roots[$rootId]['completed'];
            $this->roots[$rootId]['successfulSteps'] += $attemptedSteps;
        } else {
            ++$this->roots[$rootId]['failed'];
        }
    }

    /**
     * @return array{started:int, completed:int, failed:int, successfulSteps:int, attemptedSteps:int}
     */
    public function rootStats(int $rootId): array
    {
        return $this->roots[$rootId] ?? self::emptyRoot();
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $roots = $this->roots;
        $functions = $this->functions;
        ksort($roots, SORT_NUMERIC);
        ksort($functions, SORT_NUMERIC);
        foreach ($functions as &$row) {
            ksort($row['opcodes'], SORT_NUMERIC);
        }
        unset($row);
        return [
            'roots' => $roots,
            'functions' => $functions,
            'totalCalls' => $this->totalCalls,
            'totalTailCalls' => $this->totalTailCalls,
            'totalReturns' => $this->totalReturns,
            'budgetRejects' => $this->budgetRejects,
            'maxContinuationDepth' => $this->maxContinuationDepth,
        ];
    }

    public function reset(): void
    {
        $this->functions = [];
        $this->roots = [];
        $this->maxContinuationDepth = 0;
        $this->totalCalls = 0;
        $this->totalTailCalls = 0;
        $this->totalReturns = 0;
        $this->budgetRejects = 0;
    }

    private function ensureFunction(int $id): void
    {
        if (!isset($this->functions[$id])) {
            $this->functions[$id] = [
                'entries' => 0,
                'chargedInstructions' => 0,
                'calls' => 0,
                'tailCalls' => 0,
                'returns' => 0,
                'budgetRejects' => 0,
                'opcodes' => [],
            ];
        }
    }

    private function ensureRoot(int $id): void
    {
        if (!isset($this->roots[$id])) $this->roots[$id] = self::emptyRoot();
    }

    /** @return array{started:int, completed:int, failed:int, successfulSteps:int, attemptedSteps:int} */
    private static function emptyRoot(): array
    {
        return [
            'started' => 0,
            'completed' => 0,
            'failed' => 0,
            'successfulSteps' => 0,
            'attemptedSteps' => 0,
        ];
    }
}
