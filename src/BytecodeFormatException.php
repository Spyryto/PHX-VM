<?php
declare(strict_types=1);

namespace PhxVm;

/** Malformed, unsupported or resource-exceeding serialized PHX program. */
final class BytecodeFormatException extends \InvalidArgumentException
{
}
