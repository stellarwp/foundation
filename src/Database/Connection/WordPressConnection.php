<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Connection;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use LogicException;
use mysqli;
use StellarWP\Foundation\Database\Exceptions\CommitOutcomeUnknown;
use StellarWP\Foundation\Database\Exceptions\DatabaseException;
use Throwable;

/**
 * Provide Doctrine transactions with completion-or-exception semantics.
 *
 * Transaction control returns void, following DBAL 4. Only
 * transactional() returns an application result; callers must not test a
 * transaction-control return value for boolean success.
 *
 * @internal
 */
final class WordPressConnection extends Connection
{
	private ?mysqli $borrowed = null;

	/**
	 * Refresh an idle handle after WordPress reconnects, without replaying work.
	 *
	 * @throws Throwable When the session is unavailable or managed ownership changed.
	 */
	protected function connect(): DriverConnection {
		$native = $this->session()->native();

		if ($this->borrowed !== null && $native !== $this->borrowed) {
			parent::close();
		}
		$driver         = parent::connect();
		$this->borrowed = $native;

		return $driver;
	}

	/**
	 * Commit successful work, or preserve the escaping error through cleanup.
	 *
	 * @template T
	 *
	 * @param Closure(Connection): T $func
	 *
	 * @throws Throwable When work fails, ownership changes, or commit is uncertain.
	 *
	 * @return T
	 */
	public function transactional(Closure $func): mixed {
		$level = $this->getTransactionNestingLevel() + 1;
		$this->beginTransaction();

		try {
			$result = $func($this);
			$this->commit();

			return $result;
		} catch (Throwable $failure) {
			try {
				if ($level === 1) {
					$this->session()->rollback();
					parent::close();
					$this->session()->finish();
				} elseif ($this->getTransactionNestingLevel() >= $level) {
					$this->rollBack();
				}
			} catch (Throwable) {
				// Preserve the operation's exception if cleanup also fails.
			}

			throw $failure;
		}
	}

	/**
	 * Start ownership at Doctrine's native transaction boundary.
	 *
	 * @throws Throwable When the session is unavailable or already owned elsewhere.
	 */
	public function beginTransaction(): void {
		$outer   = $this->getTransactionNestingLevel() === 0;
		$session = $this->session();

		if ($outer) {
			parent::close();
			$session->start();
		}

		try {
			parent::beginTransaction();
		} catch (Throwable $failure) {
			if ($outer) {
				$session->rollback();
				parent::close();
				$session->finish();
			}

			throw $failure;
		}
	}

	/**
	 * Confirm ownership before commit and distinguish a missing acknowledgement.
	 *
	 * @throws Throwable When work is terminally failed or commit is uncertain.
	 */
	public function commit(): void {
		$session = $this->session();
		$session->check();
		$outer = $this->getTransactionNestingLevel() === 1;

		try {
			parent::commit();
		} catch (Throwable $failure) {
			if ($outer) {
				throw new CommitOutcomeUnknown('Commit was not confirmed; the operation may have completed. Do not retry blindly.', 0, $failure);
			}

			throw $failure;
		}

		if ($outer) {
			parent::close();
			$session->finish();
		}
	}

	/**
	 * Keep failed commits visible to cleanup after DBAL decrements nesting.
	 */
	public function isTransactionActive(): bool {
		return parent::isTransactionActive() || $this->session()->isActive();
	}

	/**
	 * Roll back the pinned session even when a terminal failure prevents further SQL.
	 *
	 * @throws Throwable When explicit rollback fails without an earlier database failure.
	 */
	public function rollBack(): void {
		$session = $this->session();

		if ($this->getTransactionNestingLevel() > 1 && $session->hasFailed()) {
			// A terminal SQL failure invalidates the whole transaction. Preserve its
			// failure latch until the outer operation acknowledges the rollback.
			$session->rollback();
			parent::close();

			return;
		}

		if ($this->getTransactionNestingLevel() > 1 || ! $session->isActive()) {
			parent::rollBack();

			return;
		}

		$failed     = $session->hasFailed();
		$rolledBack = $session->rollback();
		parent::close();
		$session->finish();

		if (! $rolledBack && ! $failed) {
			throw new DatabaseException('The database did not acknowledge rollback.');
		}
	}

	private function session(): WordPressSession {
		foreach ($this->getConfiguration()->getMiddlewares() as $middleware) {
			if ($middleware instanceof WordPressMiddleware) {
				return $middleware->session;
			}
		}

		throw new LogicException('WordPressConnection requires WordPressMiddleware.');
	}
}
