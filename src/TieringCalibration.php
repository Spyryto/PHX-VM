<?php
declare(strict_types=1);

namespace PhxVm;

/**
 * Host-specific cost estimates supplied by the caller, never sampled from
 * the slower, instrumented T0 interpreter.
 *
 * anticipatedRuns is the predicted number of *future* uses of this entry
 * after enough successful T0 samples have been collected. A value of zero
 * disables promotion; it is not decremented automatically.
 */
final class TieringCalibration
{
    public function __construct(
        public readonly int $anticipatedRuns,
        public readonly float $t0MsPerStep,
        public readonly float $t2MsPerStep,
        public readonly float $compileAndLoadMs,
    ) {
        if ($anticipatedRuns < 0
            || !is_finite($t0MsPerStep) || $t0MsPerStep < 0.0
            || !is_finite($t2MsPerStep) || $t2MsPerStep < 0.0
            || !is_finite($compileAndLoadMs) || $compileAndLoadMs < 0.0) {
            throw new \InvalidArgumentException('Invalid tiering calibration');
        }
    }
}
