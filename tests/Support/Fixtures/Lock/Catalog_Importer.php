<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Lock;

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
	 * Import the catalog for one site.
	 */
	public function import(int $site_id): void {
		$this->imported_site_ids[] = $site_id;
	}
}
