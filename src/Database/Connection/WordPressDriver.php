<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Connection;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Mysqli\Connection;

/**
 * Borrow mysqli instead of opening a second database session.
 *
 * @internal
 */
final class WordPressDriver extends AbstractDriverMiddleware
{
	/**
	 * Preserve Doctrine's platform and exception conversion behavior.
	 */
	public function __construct(
		Driver $driver,
		public readonly WordPressSession $session,
	) {
		parent::__construct($driver);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws \Throwable When execution fails or the borrowed session is no longer valid.
	 */
	public function connect(array $params): BorrowedConnection {
		$native = $this->session->native();

		return new BorrowedConnection(new Connection($native), $native, $this->session);
	}
}
