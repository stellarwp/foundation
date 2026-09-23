<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Integration\Database;

use StellarWP\Foundation\Database\Migration\MigrationCollection;
use StellarWP\Foundation\Database\Migration\Migrator;
use StellarWP\Foundation\Tests\Support\Fixtures\Database\DatabaseTestCase;
use StellarWP\Foundation\Tests\Support\Traits\WithDataDir;

final class MigrationDiscoveryTest extends DatabaseTestCase
{
	use WithDataDir;

	private string $migrationPath;

	protected function data_dir(string $appendPath = ''): string {
		return codecept_data_dir($appendPath);
	}

	protected function tearDown(): void {
		try {
			$this->cleanup_temp_dirs();
		} finally {
			parent::tearDown();
		}
	}

	protected function configuration(): array {
		return ['database' => [
			'migrations_table' => $this->suffix . '_history',
			'migrations'       => ['path' => $this->migrationDirectory()],
		]];
	}

	private function migrationDirectory(): string {
		$directory           = $this->prepare_temp_dir('anonymous-migration-runtime');
		$this->migrationPath = $directory;
		$fixtures            = dirname(__DIR__, 2) . '/Support/Fixtures/Database/Discovery';
		foreach ([$fixtures . '/20260923000001_create_reports_table.php', $fixtures . '/reports/20260923000002_add_published_at.php'] as $file) {
			file_put_contents($directory . '/' . basename($file), str_replace('discovery_reports', $this->suffix . '_reports', (string) file_get_contents($file)));
		}

		return $directory;
	}

	public function test_discovered_create_and_alter_migrations_execute_and_rollback_without_provider_lists(): void {
		$table = $this->privateTable($this->suffix . '_reports');
		$this->privateTable($this->suffix . '_history');
		$ids = $this->container->get(MigrationCollection::class)->ids();
		self::assertCount(2, $ids);
		$migrator = $this->container->get(Migrator::class);
		$migrator->migrate($ids[0]);
		$this->observer->executeStatement('INSERT INTO ' . $table . ' (title) VALUES (?)', ['Existing report']);
		$migrator->migrate();
		self::assertSame([['title' => 'Existing report', 'published_at' => null]], $this->observer->fetchAllAssociative('SELECT title, published_at FROM ' . $table));
		$migrator->rollback(1);
		self::assertSame(['Existing report'], $this->observer->fetchFirstColumn('SELECT title FROM ' . $table));
		$migrator->migrate();
		self::assertSame([['title' => 'Existing report', 'published_at' => null]], $this->observer->fetchAllAssociative('SELECT title, published_at FROM ' . $table));
	}
	public function test_anonymous_data_callbacks_use_scoped_names_and_keep_their_optional_capabilities(): void {
		$fixture = dirname(__DIR__, 2) . '/Support/Fixtures/Database/DataDiscovery/20260923000003_publish_reports.php';
		file_put_contents($this->migrationPath . '/' . basename($fixture), str_replace('discovery_reports', $this->suffix . '_reports', (string) file_get_contents($fixture)));
		$table = $this->privateTable($this->suffix . '_reports');
		$this->privateTable($this->suffix . '_history');
		$migrator = $this->container->get(Migrator::class);
		$migrator->migrate('20260923000002_add_published_at');
		$this->observer->executeStatement('INSERT INTO ' . $table . ' (title) VALUES (?)', ['Existing report']);
		$preview = $migrator->preview();
		self::assertTrue($preview[0]->hasDataStep);
		self::assertSame(['Existing report'], $this->observer->fetchFirstColumn('SELECT title FROM ' . $table));
		$migrator->migrate();
		self::assertSame(['Published report'], $this->observer->fetchFirstColumn('SELECT title FROM ' . $table));
		$status = $migrator->status()[2];
		self::assertSame('20260923000003_publish_reports', $status->migration);
		self::assertSame('Publish existing reports', $status->description);

		try {
			$migrator->rollback();
			self::fail('The inherited down() must stop rollback.');
		} catch (\StellarWP\Foundation\Database\Migration\Exceptions\IrreversibleMigration $failure) {
			self::assertStringContainsString('20260923000003_publish_reports', $failure->getMessage());
			self::assertInstanceOf(\StellarWP\Foundation\Database\Migration\Exceptions\IrreversibleMigration::class, $failure->getPrevious());
		}
		self::assertSame('applied', $migrator->status()[2]->state());
	}
}
