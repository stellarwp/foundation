<?php declare(strict_types=1);

namespace StellarWP\Foundation\WPCli\Exceptions;

use LogicException;

/**
 * Reports an attempt to register one command instance more than once.
 */
final class CommandAlreadyRegistered extends LogicException
{
}
