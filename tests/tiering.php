<?php
declare(strict_types=1);

require dirname(__DIR__) . '/autoload.php';

use PhxVm\{ExecutionLimitExceeded, ExecutionProfile, FunctionDef, Instruction as I, Opcode as O, Program, TieringPolicy, Types as T, Vm, VmFault};

$checks = 0;
function profileCheck(bool $condition, string $why): void
{
    global $checks;
    if (!$condition) throw new RuntimeException('Profiler failure: ' . $why);
    ++$checks;
}
function captureProfile(object $vm, int $entry, array $args, int $budget): array
{
    try {
        $value = $vm->run($entry, $args, $budget);
        return ['return', serialize($value), $vm->lastSteps()];
    } catch (Throwable $e) {
        return ['throw', $e::class, $e->getMessage(), $vm->lastSteps()];
    }
}
function equalProfileRuns(Vm $baseline, Vm $instrumented, int $entry, array $args, int $budget): array
{
    global $checks;
    $reference = captureProfile($baseline, $entry, $args, $budget);
    $observed = captureProfile($instrumented, $entry, $args, $budget);
    if ($reference !== $observed) {
        throw new RuntimeException('Profiler changes T0 semantics: ' . var_export([
            $reference, $observed, $entry, $args, $budget,
        ], true));
    }
    ++$checks;
    return $reference;
}
function chargedProfileInstructions(ExecutionProfile $profile): int
{
    $sum = 0;
    foreach ($profile->snapshot()['functions'] as $row) {
        $sum += $row['chargedInstructions'];
        profileCheck(array_sum($row['opcodes']) === $row['chargedInstructions'],
            'per-function opcode histogram adds up');
    }
    return $sum;
}

// Call-free loop: the charged count must match every original bytecode step.
$loop = new FunctionDef('sum', [T::INT, T::INT, T::INT, T::BOOL, T::INT], 1, T::INT, [
    I::abx(O::CONST_, 1, 0), I::abx(O::CONST_, 2, 1),
    I::abx(O::CONST_, 4, 0), I::abc(O::IEQ, 3, 0, 4),
    I::abx(O::BR_TRUE, 3, 8), I::abc(O::IADD, 1, 1, 0),
    I::abc(O::ISUB, 0, 0, 2), I::abx(O::JMP, 0, 3), I::abc(O::RET, 1),
]);
$program = new Program([$loop], [0, 1]);
$p = new ExecutionProfile();
$regular = new Vm($program);
$tracked = new Vm($program, $p);
for ($i = 0; $i < 3; ++$i) {
    profileCheck(equalProfileRuns($regular, $tracked, 0, [10], 1000)[1] === serialize(55),
        'loop result');
}
$s = $p->snapshot();
profileCheck($p->rootStats(0) === [
    'started' => 3, 'completed' => 3, 'failed' => 0,
    'successfulSteps' => 168, 'attemptedSteps' => 168,
], 'root statistics accumulate across runs');
profileCheck($s['functions'][0]['chargedInstructions'] === 168, 'loop charged instructions');
profileCheck($s['functions'][0]['entries'] === 3 && $s['functions'][0]['returns'] === 3,
    'entry and return counters');
profileCheck($s['functions'][0]['opcodes'][O::IADD] === 30
    && $s['functions'][0]['opcodes'][O::JMP] === 30, 'per-opcode counters');
profileCheck($s['maxContinuationDepth'] === 0, 'no call frames in loop');

$policy = new TieringPolicy(3, 1, 1.5);
$before = $p->snapshot();
$stay = $policy->assess($p, 0, 100, 0.001, 0.0001, 10.0);
$candidate = $policy->assess($p, 0, 1000, 0.001, 0.0001, 10.0);
profileCheck($stay['recommendation'] === 'stay_t0'
    && $stay['reason'] === 'compilation_not_amortized', 'short horizon stays on T0');
profileCheck($candidate['recommendation'] === 'candidate_t2'
    && $candidate['reason'] === 'estimated_amortization'
    && $candidate['breakEvenRuns'] === 298, 'costed large horizon qualifies T2');
profileCheck($p->snapshot() === $before, 'advisory policy must not mutate profile');

$defaultPolicy = new TieringPolicy();
profileCheck($defaultPolicy->assess($p, 0, 2000, 0.001, 0.0001, 1)['reason']
    === 'workload_too_small', 'default minimum work prevents premature tiering');
profileCheck($policy->assess($p, 0, 1000, 0.0001, 0.001, 1)['reason']
    === 'no_observed_per_step_advantage', 'slower T2 rejected');
profileCheck($policy->assess($p, 0, 0, 0.001, 0.0001, 0)['reason']
    === 'no_future_runs', 'zero future use should not compile');
profileCheck($policy->assess($p, 0, 1000, 0.001, 0.0001, 0)['recommendation']
    === 'candidate_t2', 'zero compile cost with positive reuse qualifies');
profileCheck($policy->assess($p, 1, 1000, 0.001, 0.0001, 0)['recommendation']
    === 'collect_more', 'unseen entry must first be profiled');

for ($budget = 1; $budget <= 70; ++$budget) {
    equalProfileRuns($regular, $tracked, 0, [10], $budget);
}
$s = $p->snapshot();
$charged = chargedProfileInstructions($p);
profileCheck($charged + $s['budgetRejects'] === $p->rootStats(0)['attemptedSteps'],
    'failed budget attempts are counted separately from charged instructions');
profileCheck($s['budgetRejects'] > 0, 'budget attempts observed');

$p->reset();
profileCheck($p->snapshot()['functions'] === [] && $p->snapshot()['roots'] === []
    && $p->snapshot()['budgetRejects'] === 0, 'reset clears all accumulated counters');
profileCheck($p->rootStats(0)['completed'] === 0, 'unknown entry has empty stats');

// Direct CALL followed by a TAILCALL; only CALL grows the continuation stack.
$double = new FunctionDef('double', [T::INT, T::INT], 1, T::INT, [
    I::abc(O::IADD, 1, 0, 0), I::abc(O::RET, 1),
]);
$tail = new FunctionDef('tail', [T::INT], 1, T::INT, [
    I::abc(O::TAILCALL, 0, 0, 0),
]);
$caller = new FunctionDef('caller', [T::INT, T::INT, T::INT, T::INT], 1, T::INT, [
    I::abc(O::CALL, 1, 1, 0),
    I::abc(O::CALL, 2, 0, 1),
    I::abc(O::IADD, 3, 2, 0), I::abc(O::RET, 3),
]);
$program = new Program([$double, $tail, $caller]);
$p = new ExecutionProfile();
$regular = new Vm($program);
$tracked = new Vm($program, $p);
profileCheck(equalProfileRuns($regular, $tracked, 2, [3], 100)[1] === serialize(15),
    'non-tail + tail call result');
$s = $p->snapshot();
profileCheck($s['totalCalls'] === 2 && $s['totalTailCalls'] === 1,
    'separate CALL and TAILCALL counters');
profileCheck($s['totalReturns'] === 3 && $s['maxContinuationDepth'] === 1,
    'logical continuation depth, not PHP stack depth');
profileCheck($s['functions'][0]['entries'] === 2 && $s['functions'][0]['chargedInstructions'] === 4,
    'callee instruction counts include multiple entries');
profileCheck($s['functions'][1]['entries'] === 1 && $s['functions'][1]['tailCalls'] === 1,
    'tailcall entry counted');
profileCheck($s['functions'][2]['chargedInstructions'] === 4,
    'caller instructions counted');
profileCheck($p->rootStats(2)['attemptedSteps'] === 9, 'original charged steps equal T0');

$previous = $p->rootStats(2);
equalProfileRuns($regular, $tracked, 2, [3], 3);
$s = $p->snapshot();
profileCheck($p->rootStats(2)['failed'] === $previous['failed'] + 1
    && $p->rootStats(2)['attemptedSteps'] === 13,
    'budget exhaustion includes rejected fourth attempted step');
profileCheck($s['budgetRejects'] === 1 && $s['functions'][0]['budgetRejects'] === 1,
    'budget failure attributed to callee');
profileCheck(chargedProfileInstructions($p) + $s['budgetRejects']
    === $p->rootStats(2)['attemptedSteps'], 'charge invariant over success and budget failure');

$before = $p->snapshot();
equalProfileRuns($regular, $tracked, 2, [1.0], 100);
equalProfileRuns($regular, $tracked, 2, [], 100);
equalProfileRuns($regular, $tracked, 2, [3], 0);
profileCheck($p->snapshot() === $before,
    'rejected arguments and invalid budget do not start profiling runs');

equalProfileRuns($regular, $tracked, 2, [PHP_INT_MAX], 100);
profileCheck($p->rootStats(2)['failed'] === 2, 'VM overflow counts as failed started run');
profileCheck($p->snapshot()['budgetRejects'] === 1,
    'overflow must not masquerade as instruction budget failure');

// Deep non-tail recursion must count logical frames without PHP recursion.
$depth = new FunctionDef('depth', [T::INT, T::INT, T::BOOL, T::INT, T::INT], 1, T::INT, [
    I::abx(O::CONST_, 1, 0), I::abc(O::IEQ, 2, 0, 1),
    I::abx(O::BR_TRUE, 2, 7), I::abx(O::CONST_, 3, 1),
    I::abc(O::ISUB, 3, 0, 3), I::abc(O::CALL, 4, 0, 3),
    I::abc(O::RET, 4), I::abc(O::RET, 0),
]);
$program = new Program([$depth], [0, 1]);
$p = new ExecutionProfile();
$regular = new Vm($program);
$tracked = new Vm($program, $p);
profileCheck(equalProfileRuns($regular, $tracked, 0, [2000], 15000)[1] === serialize(0),
    'deep non-tail recursion result');
profileCheck($p->snapshot()['maxContinuationDepth'] === 2000
    && $p->snapshot()['totalCalls'] === 2000, 'deep continuation counters');
profileCheck($p->snapshot()['functions'][0]['entries'] === 2001,
    'deep recursive function entries');
for ($budget = 1; $budget <= 20; ++$budget) {
    equalProfileRuns($regular, $tracked, 0, [8], $budget);
}
$s = $p->snapshot();
profileCheck(chargedProfileInstructions($p) + $s['budgetRejects']
    === $p->rootStats(0)['attemptedSteps'], 'deep recursion charge invariant');

// Explicit validation of non-finite and negative calibration input.
foreach ([NAN, INF, -INF, -1.0] as $invalid) {
    try {
        $policy->assess($p, 0, 100, 0.001, 0.0001, $invalid);
        throw new RuntimeException('Invalid calibration unexpectedly accepted');
    } catch (InvalidArgumentException $expected) {
        profileCheck(true, 'invalid calibration rejected');
    }
}
foreach ([[-1, 10], [0, -1]] as [$entry, $future]) {
    try {
        $policy->assess($p, $entry, $future, 0.001, 0.0001, 1);
        throw new RuntimeException('Invalid entry/future unexpectedly accepted');
    } catch (InvalidArgumentException $expected) {
        profileCheck(true, 'invalid tier horizon rejected');
    }
}
foreach ([[0, 100, 1.5], [3, 0, 1.5], [3, 100, INF], [3, 100, 0.5]] as $cfg) {
    try {
        new TieringPolicy(...$cfg);
        throw new RuntimeException('Invalid policy unexpectedly accepted');
    } catch (InvalidArgumentException $expected) {
        profileCheck(true, 'policy constructor rejected invalid thresholds');
    }
}

echo "T0 profiler and tiering policy differential suite: $checks checks passed\n";
