<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Connection;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware;

/**
 * Adapt Doctrine's MySQL driver to the configured WordPress session.
 *
 * @internal
 */
final readonly class WordPressMiddleware implements Middleware
{
	/**
	 * Supply the connection's shared ownership state.
	 */
	public function __construct(
		public WordPressSession $session,
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function wrap(Driver $driver): Driver {
		return new WordPressDriver($driver, $this->session);
	}
}
