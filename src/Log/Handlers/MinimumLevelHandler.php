<?php declare(strict_types=1);

namespace StellarWP\Foundation\Log\Handlers;

use Monolog\Handler\HandlerInterface;
use Monolog\Handler\HandlerWrapper;

/**
 * Apply the configured logging threshold without changing a custom handler's delivery policy.
 */
final class MinimumLevelHandler extends HandlerWrapper
{
	public function __construct(
		HandlerInterface $handler,
		private readonly int $minimumLevel
	) {
		parent::__construct($handler);
	}

	/**
	 * Accept Monolog 2's array or Monolog 3's LogRecord at the installed handler boundary.
	 *
	 * @param mixed $record The installed Monolog version's record representation.
	 */
	public function isHandling($record): bool {
		return $record['level'] >= $this->minimumLevel && parent::isHandling($record);
	}

	/**
	 * Preserve the wrapped handler's bubbling decision for accepted records.
	 *
	 * @param mixed $record The installed Monolog version's record representation.
	 */
	public function handle($record): bool {
		if ($record['level'] < $this->minimumLevel) {
			return false;
		}

		return parent::handle($record);
	}

	/**
	 * Preserve batch delivery while excluding records below the application threshold.
	 */
	public function handleBatch(array $records): void {
		$accepted = [];

		foreach ($records as $record) {
			if ($record['level'] >= $this->minimumLevel) {
				$accepted[] = $record;
			}
		}

		if ($accepted === []) {
			return;
		}

		parent::handleBatch($accepted);
	}
}
