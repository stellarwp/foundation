<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Connection;

use Doctrine\DBAL\Driver\Mysqli\Connection;
use mysqli;
use RuntimeException;
use StellarWP\Foundation\Database\Contracts\DatabaseScope;
use StellarWP\Foundation\Database\Exceptions\MigrationAlreadyRunning;
use StellarWP\Foundation\Database\Exceptions\MigrationInterrupted;
use Throwable;

/**
 * Hold one MySQL advisory lock across migration transactions and implicit DDL commits.
 *
 * @internal Owned by one WordPressSession invocation; never reconnects.
 */
final class AdvisoryLock
{
	private readonly Connection $driver;
	private readonly string $name;
	private readonly int $connectionId;
	private readonly int $site;
	private readonly string $prefix;
	private ?Throwable $failure = null;

	/**
	 * Capture the original connection and scope, naming the lock after its database and resource.
	 *
	 * @throws Throwable When the database session is unavailable.
	 */
	public function __construct(
		private readonly mysqli $native,
		private readonly DatabaseScope $scope,
		string $resource,
	) {
		$this->driver       = new Connection($native);
		$this->connectionId = $native->thread_id;
		$this->site         = $scope->capture();
		$this->prefix       = $scope->resolveTableName('');
		$this->name         = hash('sha256', $this->driver->query('SELECT DATABASE()')->fetchOne() . "\0" . $resource);
	}

	/**
	 * Acquire without waiting, before even initializing migration history.
	 *
	 * @throws MigrationAlreadyRunning When another session owns this resource.
	 * @throws Throwable               When ambient transaction state or the database prevents acquisition.
	 */
	public function acquire(): void {
		if ((int) $this->driver->query('SELECT @@session.autocommit')->fetchOne() !== 1) {
			throw new RuntimeException('Migrations require autocommit to be enabled.');
		}
		// Reject an ambient transaction without implicitly committing it.
		$this->driver->exec('SET TRANSACTION READ WRITE');

		try {
			$result = $this->driver->query("SELECT GET_LOCK('{$this->name}', 0)")->fetchOne();
		} catch (Throwable $failure) {
			// The server may have acquired the lock before its acknowledgement was lost.
			try {
				$this->native->close();
			} catch (Throwable) {
				// A terminated session cannot retain an advisory lock.
			}

			throw $failure;
		}

		if ($result === null || $result === false) {
			throw new MigrationInterrupted('The database could not acquire the migration lock.');
		}

		if ((int) $result !== 1) {
			throw new MigrationAlreadyRunning('Another session is running these migrations; retry after it completes.');
		}
	}

	/**
	 * Verify ownership before SQL, including after a migration catches an earlier exception.
	 *
	 * @throws MigrationInterrupted When work failed or the original scope or lock was lost.
	 */
	public function check(mixed $current): void {
		if ($this->failure !== null) {
			throw new MigrationInterrupted('The migration session failed; start a fresh migration run.', 0, $this->failure);
		}

		try {
			$this->scope->assertCurrent($this->site);

			if ($current !== $this->native || $this->native->thread_id !== $this->connectionId || $this->scope->resolveTableName('') !== $this->prefix) {
				throw new RuntimeException('The migration connection or table prefix changed.');
			}

			if ((int) $this->driver->query("SELECT IS_USED_LOCK('{$this->name}')")->fetchOne() !== $this->connectionId) {
				throw new RuntimeException('The migration session no longer owns its advisory lock.');
			}
		} catch (Throwable $failure) {
			$this->fail($failure);

			throw new MigrationInterrupted('Migration ownership could not be confirmed.', 0, $failure);
		}
	}

	/**
	 * Retain the first SQL failure across inner transaction cleanup and caught exceptions.
	 */
	public function fail(Throwable $failure): void {
		$this->failure ??= $failure;
	}

	/**
	 * Release on the captured session even after a site switch; discard uncertain sessions.
	 *
	 * @throws Throwable When release cannot be acknowledged.
	 */
	public function release(): void {
		try {
			if ($this->native->thread_id !== $this->connectionId) {
				throw new MigrationInterrupted('The original migration connection was lost.');
			}

			if ((int) $this->driver->query("SELECT RELEASE_LOCK('{$this->name}')")->fetchOne() !== 1) {
				throw new MigrationInterrupted('The database did not confirm migration lock release.');
			}
		} catch (Throwable $failure) {
			try {
				$this->native->close();
			} catch (Throwable) {
				// A terminated session already released its server-owned locks.
			}

			throw $failure;
		}
	}
}
