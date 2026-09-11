<?php declare(strict_types=1);

namespace StellarWP\Foundation\Container\Exceptions;

use Psr\Container\NotFoundExceptionInterface;

/**
 * Reports that the container cannot resolve a requested entry.
 */
final class NotFoundException extends ContainerException implements NotFoundExceptionInterface
{
}
