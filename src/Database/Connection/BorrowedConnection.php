<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Connection;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;
use mysqli;
use RuntimeException;

/**
 * Observe native execution failures across Doctrine's query and statement paths.
 *
 * Transaction control returns void. Native false results
 * become exceptions before they can be mistaken for successful completion.
 *
 * @internal
 */
final class BorrowedConnection extends AbstractConnectionMiddleware
{
	/**
	 * Bind the driver adapter to its borrowed native session.
	 */
	public function __construct(
		Connection $driver,
		private readonly mysqli $native,
		private readonly WordPressSession $session,
	) {
		parent::__construct($driver);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws \Throwable When execution fails or the borrowed session is no longer valid.
	 */
	public function prepare(string $sql): Statement {
		return $this->session->execute($this->native, fn (): Statement => new BorrowedStatement(parent::prepare($sql), $this->native, $this->session));
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws \Throwable When execution fails or the borrowed session is no longer valid.
	 */
	public function query(string $sql): Result {
		return $this->session->execute($this->native, fn (): Result => parent::query($sql));
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws \Throwable When execution fails or the borrowed session is no longer valid.
	 */
	public function exec(string $sql): int|string {
		return $this->session->execute($this->native, fn (): int|string => parent::exec($sql));
	}

	/**
	 * Start the native transaction and require a positive acknowledgement.
	 *
	 * @throws RuntimeException When mysqli cannot start the transaction.
	 */
	public function beginTransaction(): void {
		$this->session->execute($this->native, function (): void {
			if (! $this->native->begin_transaction()) {
				throw new RuntimeException('Unable to start the transaction: ' . $this->native->error);
			}
		});
	}

	/**
	 * Commit the native transaction and require a positive acknowledgement.
	 *
	 * @throws RuntimeException When mysqli cannot confirm the commit.
	 */
	public function commit(): void {
		$this->session->execute($this->native, function (): void {
			if (! $this->native->commit()) {
				throw new RuntimeException('The database did not acknowledge the commit.');
			}
		});
	}

	/**
	 * Roll back when DBAL is used outside the managed callback entry point.
	 *
	 * @throws RuntimeException When mysqli cannot confirm rollback.
	 */
	public function rollBack(): void {
		if (! $this->native->rollback()) {
			throw new RuntimeException('The database did not acknowledge rollback.');
		}
	}
}
