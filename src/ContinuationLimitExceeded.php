<?php
declare(strict_types=1);

namespace PhxVm;

/** A CALL would exceed the caller's pending non-tail continuation quota. */
final class ContinuationLimitExceeded extends VmFault {}
