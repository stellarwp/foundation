<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Lock;

use StellarWP\Foundation\Lock\Exceptions\LockContendedException;
use StellarWP\Foundation\Lock\LockOperation;
use Throwable;

/**
 * Synchronizes a site's catalog without overlapping another request for that site.
 */
final readonly class Catalog_Synchronizer
{
	/**
	 * Use the application's configured lock lifecycle.
	 */
	public function __construct(
		private LockOperation $lock_operation,
		private Catalog_Importer $catalog_importer
	) {
	}

	/**
	 * Synchronize the catalog, or skip this attempt when another request owns its lock.
	 *
	 * @throws Throwable When importing or lock coordination fails.
	 */
	public function synchronize(int $site_id): bool {
		$started = false;

		try {
			return $this->lock_operation->run(
				name: sprintf('catalog:%d:sync', $site_id),
				ttl: 300,
				operation: function () use ($site_id, &$started): bool {
					$started = true;
					$this->catalog_importer->import($site_id);

					return true;
				}
			);
		} catch (LockContendedException $failure) {
			if ($started) {
				throw $failure;
			}

			return false;
		}
	}
}
