<?php
declare(strict_types=1);

namespace PhxVm;

/**
 * Advisory T0/T2 tiering policy. This does NOT compile, load, cache or execute
 * T2: it only evaluates externally supplied calibration numbers.
 *
 * Instrumented T0 is slower than normal T0. Never derive t0MsPerStep directly
 * from timings of the instrumented interpreter; calibrate using unprofiled T0
 * and compiled T2 on representative workloads and the same PHP host.
 *
 * Cost model: predicted gross benefit =
 *   anticipatedRuns * meanSuccessfulBytecodeSteps * (T0cost - T2cost)
 * Require that benefit exceeds preparationCost * safetyFactor.
 *
 * Not a guarantee: per-op costs vary by opcode mix, recursion, cache state,
 * PHP version and OPcache/JIT configuration. PR #10 will own tier switching.
 */
final class TieringPolicy
{
    public function __construct(
        private readonly int $minCompletedSamples = 3,
        private readonly int $minAverageSteps = 100,
        private readonly float $safetyFactor = 1.5,
    ) {
        if ($minCompletedSamples < 1 || $minAverageSteps < 1
            || !is_finite($safetyFactor) || $safetyFactor < 1.0) {
            throw new \InvalidArgumentException('Invalid tiering policy thresholds');
        }
    }

    /**
     * @return array{
     *   recommendation:string, reason:string, completedSamples:int,
     *   averageSteps:float, anticipatedRuns:int, grossSavingsMs:float,
     *   adjustedPreparationMs:float, projectedNetMs:float, breakEvenRuns:?int
     * }
     */
    public function assess(
        ExecutionProfile $profile,
        int $entry,
        int $anticipatedRuns,
        float $t0MsPerStep,
        float $t2MsPerStep,
        float $compileAndLoadMs,
    ): array {
        if ($entry < 0 || $anticipatedRuns < 0
            || !is_finite($t0MsPerStep) || !is_finite($t2MsPerStep)
            || !is_finite($compileAndLoadMs)
            || $t0MsPerStep < 0.0 || $t2MsPerStep < 0.0
            || $compileAndLoadMs < 0.0) {
            throw new \InvalidArgumentException('Tiering inputs must be finite and non-negative');
        }

        $root = $profile->rootStats($entry);
        $samples = $root['completed'];
        $averageSteps = $samples > 0 ? $root['successfulSteps'] / $samples : 0.0;
        $perStepAdvantage = $t0MsPerStep - $t2MsPerStep;
        $gross = $anticipatedRuns * $averageSteps * max(0.0, $perStepAdvantage);
        $preparation = $compileAndLoadMs * $this->safetyFactor;
        if (!is_finite($gross) || !is_finite($preparation)) {
            throw new \InvalidArgumentException('Projected tiering costs exceed finite range');
        }
        $breakEven = null;
        $decision = 'stay_t0';
        $reason = 'insufficient_future_runs';

        if ($samples < $this->minCompletedSamples) {
            $decision = 'collect_more';
            $reason = 'insufficient_completed_samples';
        } elseif ($averageSteps < $this->minAverageSteps) {
            $reason = 'workload_too_small';
        } elseif ($perStepAdvantage <= 0.0) {
            $reason = 'no_observed_per_step_advantage';
        } elseif ($anticipatedRuns === 0) {
            $reason = 'no_future_runs';
        } else {
            $savedPerRun = $averageSteps * $perStepAdvantage;
            // The policy is advisory; unbounded required runs are represented
            // by null instead of silently casting INF to an int.
            $threshold = $preparation / $savedPerRun;
            if (is_finite($threshold) && $threshold <= PHP_INT_MAX) {
                $breakEven = (int) ceil($threshold);
            }
            if ($gross >= $preparation) {
                $decision = 'candidate_t2';
                $reason = 'estimated_amortization';
            } else {
                $reason = 'compilation_not_amortized';
            }
        }

        return [
            'recommendation' => $decision,
            'reason' => $reason,
            'completedSamples' => $samples,
            'averageSteps' => $averageSteps,
            'anticipatedRuns' => $anticipatedRuns,
            'grossSavingsMs' => $gross,
            'adjustedPreparationMs' => $preparation,
            'projectedNetMs' => $gross - $preparation,
            'breakEvenRuns' => $breakEven,
        ];
    }
}
