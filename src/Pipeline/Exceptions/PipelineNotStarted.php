<?php declare(strict_types=1);

namespace StellarWP\Foundation\Pipeline\Exceptions;

use LogicException;

/**
 * Thrown when a pipeline is executed before receiving a passable value.
 */
final class PipelineNotStarted extends LogicException
{
}
