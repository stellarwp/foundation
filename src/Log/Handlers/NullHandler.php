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
	/**
	 * Discard matching records and stop their delivery to subsequent handlers.
	 *
	 * Accept the installed Monolog version's record; the parent owns level comparison.
	 */
	public function handle($record): bool {
		return $this->isHandling($record);
	}
}
