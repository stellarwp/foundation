<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Connection;

use Doctrine\DBAL\Driver\Middleware\AbstractStatementMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use mysqli;

/**
 * Retain prepared-statement failures even when application code catches them.
 *
 * @internal
 */
final class BorrowedStatement extends AbstractStatementMiddleware
{
	/**
	 * Retain the session against which the statement was prepared.
	 */
	public function __construct(
		Statement $statement,
		private readonly mysqli $native,
		private readonly WordPressSession $session,
	) {
		parent::__construct($statement);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws \Throwable When execution fails or the borrowed session is no longer valid.
	 */
	public function execute(): Result {
		return $this->session->execute($this->native, fn (): Result => parent::execute());
	}
}
