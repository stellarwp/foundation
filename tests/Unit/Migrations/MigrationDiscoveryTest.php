<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Migrations;

use InvalidArgumentException;
use StellarWP\Foundation\Migrations\MigrationCollection;
use StellarWP\Foundation\Migrations\MigrationDiscovery;
use StellarWP\Foundation\Migrations\ValueObjects\MigrationDirectory;
use StellarWP\Foundation\Tests\TestCase;
use TypeError;

final class MigrationDiscoveryTest extends TestCase
{
	private string $fixtures;

	protected function setUp(): void {
		parent::setUp();
		$this->fixtures = dirname(__DIR__, 2) . '/Support/Fixtures/Database/Discovery';
	}

	public function test_nested_files_are_loaded_and_ordered_without_composer_or_a_container(): void {
		$discovery  = new MigrationDiscovery(null, $this->fixtures);
		$collection = new MigrationCollection($discovery->migrations());
		$this->assertSame(['20260923000001_create_reports_table', '20260923000002_add_published_at'], $collection->ids());
		$again = new MigrationCollection($discovery->migrations());
		$this->assertSame($collection->ids(), $again->ids());
		$this->assertNotSame($collection->get($collection->ids()[0]), $again->get($again->ids()[0]));
	}

	public function test_moving_a_file_preserves_identity_and_duplicate_filenames_are_rejected(): void {
		$root = $this->prepare_temp_dir('moved-migration');
		$file = '20260923000001_create_reports_table.php';
		copy($this->fixtures . '/' . $file, $root . '/' . $file);
		$original = iterator_to_array((new MigrationDiscovery(null, $this->fixtures))->migrations());
		$moved    = iterator_to_array((new MigrationDiscovery(null, $root))->migrations());
		$this->assertSame(pathinfo($file, PATHINFO_FILENAME), $moved[0]->id);
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Duplicate migration identity');
		new MigrationCollection([...$original, ...$moved]);
	}

	public function test_no_configuration_or_missing_default_directory_is_empty(): void {
		$this->assertSame([], iterator_to_array((new MigrationDiscovery(null, null))->migrations()));
		$root = $this->prepare_temp_dir('missing-migrations');
		$this->assertSame([], iterator_to_array((new MigrationDiscovery($root, null))->migrations()));
		$this->assertDirectoryDoesNotExist($root . '/db/migrations');
	}

	public function test_an_explicit_missing_directory_is_an_error(): void {
		$root = $this->prepare_temp_dir('missing-custom-migrations');
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('Configured migration directory does not exist');
		iterator_to_array((new MigrationDiscovery($root, 'migrations'))->migrations());
	}

	public function test_relative_locations_require_an_application_root(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('foundation.root');
		iterator_to_array((new MigrationDiscovery(null, 'migrations'))->migrations());
	}

	public function test_matching_files_must_return_a_migration(): void {
		$root = $this->prepare_temp_dir('invalid-migration');
		file_put_contents($root . '/20260923000001_invalid.php', '<?php return new \\stdClass();');
		$this->expectException(TypeError::class);
		iterator_to_array((new MigrationDiscovery(null, $root))->migrations());
	}

	public function test_helpers_and_non_migration_files_are_not_executed(): void {
		$root = $this->prepare_temp_dir('migration-helpers');
		foreach (['helper.php', 'README.md', '20260923000001_notes.txt'] as $file) {
			file_put_contents($root . '/' . $file, '<?php throw new \\RuntimeException("Do not execute helpers");');
		}
		mkdir($root . '/20260923000001_directory.php');
		$this->assertSame([], iterator_to_array((new MigrationDiscovery(null, $root))->migrations()));
	}

	public function test_default_and_custom_paths_are_normalized_without_namespace_mappings(): void {
		$this->assertSame('/project/db/migrations', (new MigrationDirectory('/project'))->path);
		$this->assertSame('/project/history', (new MigrationDirectory('/project', 'db/../history'))->path);
		$this->assertSame('/shared/history', (new MigrationDirectory(null, '/shared/history'))->path);
	}

	public function test_blank_migration_path_is_an_error_instead_of_scanning_the_project_root(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('cannot be blank');
		new MigrationDirectory('/project', '');
	}
}
