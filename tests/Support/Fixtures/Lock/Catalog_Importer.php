<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Lock;

use Closure;
use Throwable;

/**
 * Records catalog imports requested by a synchronizer.
 */
final class Catalog_Importer
{
	/**
	 * @var list<int>
	 */
	public array $imported_site_ids = [];

	/**
	 * Optional continuation after the import's recorded side effect.
	 *
	 * @var (Closure(int): void)|null
	 */
	public ?Closure $after_import = null;

	/**
	 * Import the catalog for one site.
	 *
	 * @throws Throwable When the configured continuation fails.
	 */
	public function import(int $site_id): void {
		$this->imported_site_ids[] = $site_id;

		if ($this->after_import !== null) {
			($this->after_import)($site_id);
		}
	}
}
