<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli\Commands\Make\Database\Factories;

use DateTimeImmutable;
use DateTimeZone;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use StellarWP\Foundation\Cli\Composer\ComposerAutoloadResolver;
use StellarWP\Foundation\Cli\Generation\StubRenderer;
use StellarWP\Foundation\Cli\Generation\StubResolver;
use StellarWP\Foundation\Cli\Generation\ValueObjects\GeneratedFile;
use StellarWP\Foundation\Cli\Generation\ValueObjects\ProjectDirectory;
use StellarWP\Foundation\Cli\Generation\WordPressClassNameResolver;
use StellarWP\Foundation\Container\Contracts\Configuration;
use StellarWP\Foundation\Database\DatabaseStubPath;
use StellarWP\Foundation\Database\Migration\ValueObjects\Id;
use StellarWP\Foundation\Database\Migration\ValueObjects\MigrationDirectory;

/**
 * Create anonymous migration files with permanent, timestamped filenames.
 */
final readonly class MigrationFileFactory
{
	/**
	 * Receive project configuration and migration rendering services.
	 */
	public function __construct(
		private ProjectDirectory $projectDirectory,
		private ComposerAutoloadResolver $autoloadResolver,
		private WordPressClassNameResolver $classNameResolver,
		private StubResolver $stubResolver,
		private StubRenderer $stubRenderer,
		private Configuration $config,
	) {
	}

	/**
	 * Build a migration without implied table ownership.
	 *
	 * @throws RuntimeException          When generator input or project metadata is invalid.
	 * @throws \InvalidArgumentException When the directory or identity is invalid.
	 */
	public function generic(string $name): GeneratedFile {
		return $this->render($name, 'migration', DatabaseStubPath::migration());
	}

	/**
	 * Build a migration that creates and owns the named table.
	 *
	 * @throws RuntimeException          When generator input or project metadata is invalid.
	 * @throws \InvalidArgumentException When the directory or identity is invalid.
	 */
	public function createTable(string $name, string $table): GeneratedFile {
		return $this->render($name, 'create-table-migration', DatabaseStubPath::createTableMigration(), $table);
	}

	/**
	 * Build a migration that alters the named table.
	 *
	 * @throws RuntimeException          When generator input or project metadata is invalid.
	 * @throws \InvalidArgumentException When the directory or identity is invalid.
	 */
	public function alterTable(string $name, string $table): GeneratedFile {
		return $this->render($name, 'alter-table-migration', DatabaseStubPath::alterTableMigration(), $table);
	}

	/**
	 * Render a migration without loading application classes or bootstrapping WordPress.
	 */
	private function render(string $name, string $stubName, string $defaultStub, ?string $table = null): GeneratedFile {
		if ($table !== null && preg_match('/\A[A-Za-z0-9_]+\z/', $table) !== 1) {
			throw new RuntimeException('Use an unprefixed table name containing only ASCII letters, numbers, and underscores.');
		}

		$directory = new MigrationDirectory(
			$this->config->get('foundation.root', $this->projectDirectory->path),
			$this->config->get('database.migrations.path', MigrationDirectory::DEFAULT_PATH),
		);

		$parts = explode('/', $name);

		foreach ($parts as $part) {
			if ($part === '' || $part === '.' || $part === '..' || str_contains($part, '\\')) {
				throw new RuntimeException('Use a migration name or relative group/name, such as reports/add_published_at.');
			}
		}

		$description = strtolower($this->classNameResolver->className(array_pop($parts)));
		$groups      = array_map(fn (string $part): string => strtolower($this->classNameResolver->className($part)), $parts);
		$id          = (new Id($this->nextTimestamp($directory->path) . '_' . $description))->value;
		$path        = implode('/', [$directory->path, ...$groups, $id . '.php']);
		$prefix      = $this->autoloadResolver->straussNamespacePrefix() ?? '';
		$stub        = $this->stubResolver->resolve('database', $stubName, $defaultStub);

		return new GeneratedFile(
			path: $path,
			relativePath: $this->projectDirectory->relativePath($path),
			contents: $this->stubRenderer->render($stub, [
				'table_php'                                  => $this->stubRenderer->phpStringLiteral($table ?? ''),
				'foundation_database_migration'              => $prefix . 'StellarWP\\Foundation\\Database\\Migration\\Migration',
				'foundation_database_schema'                 => $prefix . 'StellarWP\\Foundation\\Database\\Migration\\Schema\\Blueprint',
				'foundation_database_irreversible_migration' => $prefix . 'StellarWP\\Foundation\\Database\\Migration\\Exceptions\\IrreversibleMigration',
			]),
		);
	}

	/**
	 * Keep sequentially generated migrations ordered even within the same second.
	 */
	private function nextTimestamp(string $directory): string {
		$now       = new DateTimeImmutable('now', new DateTimeZone('UTC'));
		$timestamp = $now->format('YmdHis');

		if (! is_dir($directory)) {
			return $timestamp;
		}

		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS));

		foreach ($files as $file) {
			if (! $file->isFile() || preg_match('/^([0-9]{14})_[a-z0-9_]+\.php$/', $file->getFilename(), $matches) !== 1 || $matches[1] < $timestamp) {
				continue;
			}

			$previous = DateTimeImmutable::createFromFormat('!YmdHis', $matches[1], new DateTimeZone('UTC'));

			if ($previous !== false) {
				$timestamp = $previous->modify('+1 second')->format('YmdHis');
			}
		}

		return $timestamp;
	}
}
