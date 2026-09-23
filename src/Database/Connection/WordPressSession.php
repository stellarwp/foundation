<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Connection;

use Closure;
use Doctrine\DBAL\Driver\Mysqli\Connection;
use mysqli;
use StellarWP\Foundation\Database\Contracts\DatabaseScope;
use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use StellarWP\Foundation\Database\Exceptions\TransactionFailed;
use Throwable;
use wpdb;

/**
 * Pins managed work to one WordPress session and retains terminal failures.
 *
 * @internal
 */
final class WordPressSession
{
	private ?mysqli $owned              = null;
	private ?Throwable $failure         = null;
	private string $prefix              = '';
	private int $site                   = 0;
	private ?AdvisoryLock $advisoryLock = null;

	/**
	 * Retain WordPress's connection source and site scope.
	 */
	public function __construct(
		private readonly wpdb $wpdb,
		private readonly DatabaseScope $scope,
	) {
	}

	/**
	 * Return the current native connection without reconnecting or replaying SQL.
	 *
	 * @throws DatabaseException When WordPress has no mysqli connection.
	 * @throws TransactionFailed When an active operation has failed.
	 */
	public function native(): mysqli {
		$this->check();
		$native = $this->wpdb->__get('dbh');

		if (! $native instanceof mysqli) {
			throw new DatabaseException('WordPress must have an open mysqli connection.');
		}

		return $native;
	}

	/**
	 * Report whether a managed operation owns the session.
	 */
	public function isActive(): bool {
		return $this->owned !== null;
	}

	/**
	 * Own one advisory lock across the complete migration operation, including planning.
	 *
	 * @template T
	 *
	 * @param Closure(): T $operation
	 *
	 * @throws Throwable When acquisition, execution, ownership checks, or release fail.
	 *
	 * @return T
	 */
	public function withAdvisoryLock(string $resource, Closure $operation): mixed {
		if ($this->advisoryLock !== null || $this->isActive()) {
			throw new DatabaseException('Start migrations outside an existing transaction or migration run.');
		}
		$lock = new AdvisoryLock($this->native(), $this->scope, $resource);
		$lock->acquire();
		$this->advisoryLock = $lock;

		try {
			$result = $operation();
			$this->check();
		} catch (Throwable $failure) {
			try {
				$lock->release();
			} catch (Throwable) {
				// Preserve the migration failure when its cleanup also fails.
			}

			throw $failure;
		} finally {
			$this->advisoryLock = null;
		}
		$lock->release();

		return $result;
	}

	/**
	 * Reject ambient work before claiming the session for a managed transaction.
	 *
	 * @throws Throwable When the connection is unavailable or already in a transaction.
	 */
	public function start(): void {
		$native = $this->native();
		$driver = new Connection($native);

		if ((int) $driver->query('SELECT @@session.autocommit')->fetchOne() !== 1) {
			throw new DatabaseException('Managed transactions require autocommit to be enabled.');
		}
		// Both MySQL and MariaDB reject this inside an existing transaction.
		// Unlike START TRANSACTION, it cannot implicitly commit the caller's work.
		$driver->exec('SET TRANSACTION READ WRITE');
		$this->site   = $this->scope->capture();
		$this->prefix = $this->scope->resolveTableName('');
		$this->owned  = $native;
	}

	/**
	 * Execute against the expected session and remember caught database failures.
	 *
	 * @template T
	 *
	 * @param Closure(): T $operation
	 *
	 * @throws Throwable When execution fails or ownership changes.
	 *
	 * @return T
	 */
	public function execute(mysqli $native, Closure $operation): mixed {
		try {
			$this->check();

			if ($this->wpdb->__get('dbh') !== $native) {
				throw new DatabaseException('The WordPress connection changed; start a fresh operation.');
			}

			return $operation();
		} catch (Throwable $failure) {
			$this->advisoryLock?->fail($failure);

			if ($this->isActive()) {
				$this->failure ??= $failure;
			}

			throw $failure;
		}
	}

	/**
	 * Confirm that a normal callback return can still be committed.
	 *
	 * @throws TransactionFailed When a query failed, even if its exception was caught.
	 * @throws Throwable         When the WordPress site or connection changed.
	 */
	public function check(): void {
		$this->advisoryLock?->check($this->wpdb->__get('dbh'));

		if ($this->failure !== null) {
			throw new TransactionFailed('The transaction failed and must be rolled back.', 0, $this->failure);
		}

		if ($this->owned === null) {
			return;
		}

		try {
			$this->scope->assertCurrent($this->site);

			if ($this->scope->resolveTableName('') !== $this->prefix) {
				throw new DatabaseException('The WordPress table prefix changed during a transaction.');
			}

			if ($this->wpdb->__get('dbh') !== $this->owned) {
				throw new DatabaseException('The WordPress connection changed during a transaction.');
			}
		} catch (Throwable $failure) {
			$this->failure = $failure;

			throw $failure;
		}
	}

	/**
	 * Roll back only the owned session; discard it if cleanup cannot be confirmed.
	 *
	 * Cleanup must never replace the exception already escaping the operation.
	 */
	public function rollback(): bool {
		if ($this->owned === null) {
			return true;
		}

		try {
			if ($this->owned->rollback()) {
				return true;
			}
		} catch (Throwable) {
			// A lost connection is already unusable; a live uncertain one is closed below.
		}

		try {
			$this->owned->close();
		} catch (Throwable) {
			// mysqli also throws when the handle has already been closed.
		}

		return false;
	}

	/**
	 * Report whether cleanup is following a terminal infrastructure failure.
	 */
	public function hasFailed(): bool {
		return $this->failure !== null;
	}

	/**
	 * End the invocation's ownership and terminal-failure state.
	 */
	public function finish(): void {
		$this->owned   = null;
		$this->failure = null;
	}
}
