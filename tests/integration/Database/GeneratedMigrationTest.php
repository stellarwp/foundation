<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Integration\Database;

use PHPUnit\Framework\Attributes\DataProvider;
use StellarWP\Foundation\Cli\CliProvider;
use StellarWP\Foundation\Cli\Commands\Make\Database\MigrationCommand;
use StellarWP\Foundation\Cli\Commands\Make\Database\TableCommand;
use StellarWP\Foundation\Cli\Generation\ValueObjects\ProjectDirectory;
use StellarWP\Foundation\Container\Configuration\ArrayConfiguration;
use StellarWP\Foundation\Container\ContainerFactory;
use StellarWP\Foundation\Database\DatabaseProvider;
use StellarWP\Foundation\Database\Migration\MigrationCollection;
use StellarWP\Foundation\Tests\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class GeneratedMigrationTest extends TestCase
{
	/** @return iterable<string, array{?string}> */
	public static function locations(): iterable {
		yield 'default' => [null];

		yield 'root migrations directory' => ['migrations'];

		yield 'nested custom directory' => ['src/Database/Migrations'];
	}

	/**
	 * @dataProvider locations
	 */
	#[DataProvider('locations')]
	public function test_generated_migrations_load_without_application_classes_or_migration_autoload_mappings(?string $path): void {
		$root          = $this->prepare_temp_dir('generated-migration-consumer');
		$namespace     = 'Consumer' . bin2hex(random_bytes(5)) . '\\';
		$mappings      = [$namespace => 'src/'];
		$configuration = ['foundation' => ['root' => $root]];

		if ($path !== null) {
			$configuration['database']['migrations']['path'] = $path;
		}

		file_put_contents($root . '/composer.json', json_encode(['autoload' => ['psr-4' => $mappings], 'require' => ['stellarwp/foundation-database' => '^2.0']], JSON_THROW_ON_ERROR));
		$config  = new ArrayConfiguration($configuration);
		$tooling = (new ContainerFactory())->create($config);
		$tooling->register(CliProvider::class);
		$tooling->singleton(ProjectDirectory::class, new ProjectDirectory($root));
		$table = new CommandTester($tooling->get(TableCommand::class));
		self::assertSame(0, $table->execute(['name' => 'Reports', '--migration' => true]), $table->getDisplay());
		$migration = new CommandTester($tooling->get(MigrationCommand::class));
		self::assertSame(0, $migration->execute(['name' => 'Reports/Add_Published_At', '--table' => 'reports']), $migration->getDisplay());
		// The generated application table is deliberately not autoloadable at runtime.
		$runtime = (new ContainerFactory())->create($config);
		$runtime->register(DatabaseProvider::class);
		$collection = $runtime->get(MigrationCollection::class);
		$ids        = $collection->ids();
		self::assertCount(2, $ids);
		self::assertStringEndsWith('_create_reports_table', $ids[0]);
		self::assertStringEndsWith('_add_published_at', $ids[1]);
		self::assertInstanceOf(\StellarWP\Foundation\Database\Migration\Migration::class, $collection->get($ids[1]));
	}
}
