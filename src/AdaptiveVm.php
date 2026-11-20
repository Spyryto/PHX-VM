<?php
declare(strict_types=1);

namespace PhxVm;

/**
 * Opt-in execution tier manager: immediate T0, then cached T2 if worthwhile.
 *
 * - Without per-entry calibration it ALWAYS stays on instrumented T0.
 * - Promotion is checked only AFTER enough completed T0 executions exist.
 * - T2 is loaded once per entry and the PhpVm closure is kept in memory.
 * - Compilation/cache failure can safely fall back to T0: no bytecode has
 *   been executed yet. An error from running T2 is NEVER retried in T0;
 *   doing so would duplicate observable effects once future IO is added.
 *
 * The policy uses externally measured costs, NOT timings of profiled T0.
 * This class does not alter Program, ISA, compiler output, or opcode budgets.
 */
final class AdaptiveVm
{
    private readonly ExecutionProfile $profile;
    private readonly Vm $interpreter;
    private readonly TieringPolicy $policy;

    /** @var array<int,TieringCalibration> */
    private array $calibrations = [];
    /** @var array<int,PhpVm> */
    private array $compiled = [];
    /** @var array<int,string> */
    private array $compilationFailures = [];
    /** @var array<int,array<string,mixed>> */
    private array $decisions = [];
    /** @var array<int,int> */
    private array $t0Runs = [];
    /** @var array<int,int> */
    private array $t2Runs = [];
    /** @var array<int,int> */
    private array $compilationAttempts = [];
    private int $lastSteps = 0;
    private ?string $lastBackend = null;

    /**
     * @param array<int,TieringCalibration> $calibrations keyed by entry ID
     */
    public function __construct(
        private readonly Program $program,
        private readonly PhpArtifactCache $cache,
        array $calibrations = [],
        ?TieringPolicy $policy = null,
        private readonly bool $inline = true,
        private readonly bool $trimFrames = true,
        private readonly bool $fallbackOnCompilationFailure = true,
    ) {
        $this->profile = new ExecutionProfile();
        $this->interpreter = new Vm($program, $this->profile);
        $this->policy = $policy ?? new TieringPolicy();
        foreach ($calibrations as $entry => $calibration) {
            if (!is_int($entry) || !$calibration instanceof TieringCalibration) {
                throw new \InvalidArgumentException('Calibrations must be keyed by integer entry IDs');
            }
            $this->setCalibration($entry, $calibration);
        }
    }

    public function lastSteps(): int { return $this->lastSteps; }
    /** Last executed backend, or null when validation/compilation failed before any execution. */
    public function lastBackend(): ?string { return $this->lastBackend; }
    public function profile(): ExecutionProfile { return $this->profile; }

    public function tier(int $functionId): string
    {
        $this->requireEntry($functionId);
        return isset($this->compiled[$functionId]) ? 'T2' : 'T0';
    }

    /**
     * Apply or change the forecast for a particular entry. A failed compiler
     * attempt can be retried after the caller explicitly supplies calibration
     * again. Already promoted entries stay on T2 until demoted.
     */
    public function setCalibration(int $functionId, TieringCalibration $calibration): void
    {
        $this->requireEntry($functionId);
        $this->calibrations[$functionId] = $calibration;
        unset($this->compilationFailures[$functionId], $this->decisions[$functionId]);
    }

    /**
     * Explicitly demote an entry; future valid calls may re-promote if their
     * profile and current calibration justify compiling.
     */
    public function demote(int $functionId): void
    {
        $this->requireEntry($functionId);
        unset($this->compiled[$functionId], $this->compilationFailures[$functionId],
            $this->decisions[$functionId]);
    }

    /**
     * @return array<string,mixed>
     */
    public function stats(int $functionId): array
    {
        $this->requireEntry($functionId);
        return [
            'tier' => $this->tier($functionId),
            't0Runs' => $this->t0Runs[$functionId] ?? 0,
            't2Runs' => $this->t2Runs[$functionId] ?? 0,
            'compilationAttempts' => $this->compilationAttempts[$functionId] ?? 0,
            'compilationFailure' => $this->compilationFailures[$functionId] ?? null,
            'decision' => $this->decisions[$functionId] ?? null,
            'profile' => $this->profile->rootStats($functionId),
        ];
    }

    /** @param list<int|float|bool|string|null> $args */
    public function run(int $functionId, array $args = [], int $maxSteps = 1000000): mixed
    {
        $this->lastSteps = 0;
        $this->lastBackend = null;

        if (isset($this->compiled[$functionId])) {
            return $this->runT2($functionId, $args, $maxSteps);
        }

        $calibration = $this->calibrations[$functionId] ?? null;
        if ($calibration !== null && !isset($this->compilationFailures[$functionId])) {
            // Preserve T0's argument/budget validation precedence and avoid
            // allocating executable PHP artifacts for rejected calls.
            if ($this->validArguments($functionId, $args, $maxSteps)) {
                $decision = $this->policy->assess(
                    $this->profile,
                    $functionId,
                    $calibration->anticipatedRuns,
                    $calibration->t0MsPerStep,
                    $calibration->t2MsPerStep,
                    $calibration->compileAndLoadMs,
                );
                $this->decisions[$functionId] = $decision;

                if ($decision['recommendation'] === 'candidate_t2') {
                    ++$this->compilationAttempts[$functionId];
                    try {
                        // Only compilation/loading is protected by fallback.
                        // Never catch an error from T2 execution and rerun it.
                        $this->compiled[$functionId] = $this->cache->load(
                            $this->program, $functionId, $this->inline, $this->trimFrames
                        );
                    } catch (\Throwable $error) {
                        $this->compilationFailures[$functionId] =
                            $error::class . ': ' . $error->getMessage();
                        if (!$this->fallbackOnCompilationFailure) throw $error;
                    }

                    if (isset($this->compiled[$functionId])) {
                        return $this->runT2($functionId, $args, $maxSteps);
                    }
                }
            }
        }

        // Instrumented T0 supplies counters only, not timing calibration.
        ++$this->t0Runs[$functionId];
        $this->lastBackend = 'T0';
        try {
            return $this->interpreter->run($functionId, $args, $maxSteps);
        } finally {
            $this->lastSteps = $this->interpreter->lastSteps();
        }
    }

    /** @param list<int|float|bool|string|null> $args */
    private function runT2(int $functionId, array $args, int $maxSteps): mixed
    {
        ++$this->t2Runs[$functionId];
        $this->lastBackend = 'T2';
        $compiled = $this->compiled[$functionId];
        try {
            return $compiled->run($functionId, $args, $maxSteps);
        } finally {
            $this->lastSteps = $compiled->lastSteps();
        }
    }

    /**
     * Returns false for invalid arguments so T0 raises *its own* original
     * exception. No samples or compilation should be created by these calls.
     *
     * @param array<mixed> $args
     */
    private function validArguments(int $functionId, array $args, int $maxSteps): bool
    {
        if ($maxSteps < 1 || !isset($this->program->functions[$functionId])
            || !array_is_list($args)) return false;

        $function = $this->program->functions[$functionId];
        if (count($args) !== $function->arity) return false;

        foreach ($args as $i => $arg) {
            try {
                if (Types::of($arg) !== $function->registerTypes[$i]) return false;
            } catch (\InvalidArgumentException) {
                return false;
            }
        }
        return true;
    }

    private function requireEntry(int $functionId): void
    {
        if (!isset($this->program->functions[$functionId])) {
            throw new \InvalidArgumentException('Unknown function ID');
        }
    }
}
