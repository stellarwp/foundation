<?php declare(strict_types=1);

namespace StellarWP\Foundation\Log\Handlers;

use Monolog\Handler\AbstractHandler;

/**
 * Black hole.
 *
 * Any record it can handle will be thrown away.
 */
final class NullHandler extends AbstractHandler
{
	public function handle(array $record): bool {
		return $record['level'] >= $this->level;
	}
}
