<?php declare(strict_types=1);

namespace StellarWP\Foundation\Container\Exceptions;

use Psr\Container\ContainerExceptionInterface;
use RuntimeException;

/**
 * Reports a failure while registering or resolving a container entry.
 */
class ContainerException extends RuntimeException implements ContainerExceptionInterface
{
}
