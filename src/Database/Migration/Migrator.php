<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Migration;

use StellarWP\Foundation\Database\Contracts\Migration;

/**
 * Configured entry point for preparing and running database migrations.
 */
final readonly class Migrator
{
	public function __construct(
		private Store $store,
		private Runner $runner,
		private Collection $migrations
	) {
	}

	/**
	 * Ensure the migration subsystem storage is ready.
	 */
	public function prepare(): void {
		$this->store->prepare();
	}

	/**
	 * Drop the migration subsystem storage.
	 */
	public function drop(): void {
		$this->store->drop();
	}

	/**
	 * Determine whether the migration subsystem storage is ready.
	 */
	public function exists(): bool {
		return $this->store->exists();
	}

	/**
	 * Run all pending configured migrations.
	 */
	public function run(): Result {
		return $this->withPreparedStore(fn (): Result => $this->runner->run($this->migrations));
	}

	/**
	 * Roll back the latest configured migration batch.
	 */
	public function rollback(?int $batch = null): Result {
		return $this->withPreparedStore(fn (): Result => $this->runner->rollback($this->migrations, $batch));
	}

	/**
	 * Roll back and rerun all configured migrations.
	 */
	public function refresh(): Result {
		return $this->withPreparedStore(fn (): Result => $this->runner->refresh($this->migrations));
	}

	/**
	 * @return list<Status>
	 */
	public function status(): array {
		if (! $this->store->exists()) {
			return array_map(
				static fn (Migration $migration): Status => Status::pending($migration->id()),
				$this->migrations->all()
			);
		}

		return $this->runner->status($this->migrations);
	}

	/**
	 * @template T
	 *
	 * @param callable(): T $callback
	 *
	 * @return T
	 */
	private function withPreparedStore(callable $callback): mixed {
		$this->store->prepare();

		return $callback();
	}
}
