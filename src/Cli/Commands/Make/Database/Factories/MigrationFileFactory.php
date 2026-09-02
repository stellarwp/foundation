<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli\Commands\Make\Database\Factories;

use RuntimeException;
use StellarWP\Foundation\Cli\Commands\Make\Database\ValueObjects\GeneratedMigration;
use StellarWP\Foundation\Cli\Generation\ComposerAutoloadResolver;
use StellarWP\Foundation\Cli\Generation\StubRenderer;
use StellarWP\Foundation\Cli\Generation\StubResolver;
use StellarWP\Foundation\Cli\Generation\ValueObjects\ComposerProject;
use StellarWP\Foundation\Cli\Generation\ValueObjects\GeneratedFile;
use StellarWP\Foundation\Cli\Generation\ValueObjects\Psr4Namespace;
use StellarWP\Foundation\Cli\Generation\WordPressClassNameResolver;
use StellarWP\Foundation\Database\DatabaseStubPath;
use StellarWP\Foundation\Database\Migration\ValueObjects\Id;

/**
 * Creates generic, create-table, and update-table migration artifacts.
 */
final readonly class MigrationFileFactory
{
	public function __construct(
		private string $rootPath,
		private ComposerAutoloadResolver $autoloadResolver,
		private WordPressClassNameResolver $classNameResolver,
		private StubResolver $stubResolver,
		private StubRenderer $stubRenderer
	) {
	}

	/**
	 * Build a migration artifact without implied table behavior.
	 *
	 * @throws RuntimeException When project metadata or generator input is invalid.
	 */
	public function generic(string $name, ?string $namespace = null, ?string $path = null, ?string $id = null): GeneratedMigration {
		$context = $this->context($name, $namespace, $path, $id);
		$stub    = $this->stubResolver->resolve('database', 'migration', DatabaseStubPath::migration());

		return $this->migration($context, $this->stubRenderer->render($stub, [
			'namespace'                                  => $context['namespace'],
			'class'                                      => $context['class'],
			'id_php'                                     => $this->phpString($context['id']),
			'foundation_database_migration'              => $context['project']->foundationClass('StellarWP\\Foundation\\Database\\Contracts\\Migration'),
			'foundation_database_schema'                 => $context['project']->foundationClass('StellarWP\\Foundation\\Database\\Contracts\\Schema'),
			'foundation_database_irreversible_migration' => $context['project']->foundationClass('StellarWP\\Foundation\\Database\\Exceptions\\IrreversibleMigration'),
		]));
	}

	/**
	 * Build a migration that creates and owns the selected table.
	 *
	 * @throws RuntimeException When project metadata or generator input is invalid.
	 */
	public function createTable(string $name, string $tableClass, ?string $namespace = null, ?string $path = null, ?string $id = null): GeneratedMigration {
		$context = $this->context($name, $namespace, $path, $id);
		$table   = $this->tableReference($tableClass, $context['project']->defaultPsr4Namespace());
		$stub    = $this->stubResolver->resolve('database', 'create-table-migration', DatabaseStubPath::createTableMigration());

		return $this->migration($context, $this->stubRenderer->render($stub, [
			'namespace'                     => $context['namespace'],
			'class'                         => $context['class'],
			'id_php'                        => $this->phpString($context['id']),
			'table_class'                   => $table['class'],
			'table_namespace'               => $table['namespace'],
			'foundation_database_migration' => $context['project']->foundationClass('StellarWP\\Foundation\\Database\\Contracts\\Migration'),
			'foundation_database_schema'    => $context['project']->foundationClass('StellarWP\\Foundation\\Database\\Contracts\\Schema'),
		]));
	}

	/**
	 * Build a migration that reconciles an existing table definition.
	 *
	 * @throws RuntimeException When project metadata or generator input is invalid.
	 */
	public function reconcileTable(string $name, string $tableClass, ?string $namespace = null, ?string $path = null, ?string $id = null): GeneratedMigration {
		$context = $this->context($name, $namespace, $path, $id);
		$table   = $this->tableReference($tableClass, $context['project']->defaultPsr4Namespace());
		$stub    = $this->stubResolver->resolve('database', 'reconcile-table-migration', DatabaseStubPath::reconcileTableMigration());

		return $this->migration($context, $this->stubRenderer->render($stub, [
			'namespace'                                  => $context['namespace'],
			'class'                                      => $context['class'],
			'id_php'                                     => $this->phpString($context['id']),
			'table_class'                                => $table['class'],
			'table_namespace'                            => $table['namespace'],
			'foundation_database_irreversible_migration' => $context['project']->foundationClass('StellarWP\\Foundation\\Database\\Exceptions\\IrreversibleMigration'),
			'foundation_database_migration'              => $context['project']->foundationClass('StellarWP\\Foundation\\Database\\Contracts\\Migration'),
			'foundation_database_schema'                 => $context['project']->foundationClass('StellarWP\\Foundation\\Database\\Contracts\\Schema'),
		]));
	}

	/**
	 * @return array{project: ComposerProject, class: string, namespace: string, path: string, id: string}
	 */
	private function context(string $name, ?string $namespace, ?string $path, ?string $id): array {
		$className        = $this->classNameResolver->className($name);
		$project          = $this->autoloadResolver->project();
		$defaultNamespace = $project->defaultPsr4Namespace();
		$namespace        = $this->migrationNamespace($namespace, $defaultNamespace);
		$path             = $this->migrationPath($path, $namespace, $project);
		$id               = (new Id($id ?? $this->classNameResolver->migrationId($className)))->value;

		return [
			'project'   => $project,
			'class'     => $className,
			'namespace' => $namespace,
			'path'      => $path,
			'id'        => $id,
		];
	}

	/**
	 * @param array{project: ComposerProject, class: string, namespace: string, path: string, id: string} $context
	 */
	private function migration(array $context, string $contents): GeneratedMigration {
		$file = new GeneratedFile(
			path: $context['path'] . '/' . $context['class'] . '.php',
			relativePath: $this->relativePath($context['path'] . '/' . $context['class'] . '.php'),
			contents: $contents
		);

		return new GeneratedMigration($file, $context['class'], $context['namespace']);
	}

	/**
	 * Resolve a short or fully qualified table class into an importable reference.
	 *
	 * @return array{class: string, namespace: string}
	 */
	private function tableReference(string $tableClass, Psr4Namespace $autoload): array {
		$tableClass = trim($tableClass);

		if ($tableClass === '') {
			throw new RuntimeException('The table class cannot be blank.');
		}

		if (str_contains($tableClass, '/')) {
			throw new RuntimeException(sprintf('Table class "%s" is not a valid PHP class name.', $tableClass));
		}

		$tableClass = ltrim($tableClass, '\\');
		$separator  = strrpos($tableClass, '\\');

		if ($separator === false) {
			if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $tableClass) !== 1) {
				throw new RuntimeException(sprintf('Table class "%s" is not a valid PHP class name.', $tableClass));
			}

			return [
				'class'     => $tableClass,
				'namespace' => trim($autoload->namespace, '\\') . '\\Database\\Tables',
			];
		}

		$namespace = substr($tableClass, 0, $separator);
		$class     = substr($tableClass, $separator + 1);

		if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $class) !== 1) {
			throw new RuntimeException(sprintf('Table class "%s" is not a valid PHP class name.', $tableClass));
		}

		return [
			'class'     => $class,
			'namespace' => $this->validNamespace($namespace),
		];
	}

	private function migrationNamespace(?string $namespace, Psr4Namespace $autoload): string {
		if ($namespace !== null && trim($namespace) !== '') {
			return $this->validNamespace(trim($namespace, '\\'));
		}

		return trim($autoload->namespace, '\\') . '\\Database\\Migrations';
	}

	private function migrationPath(?string $path, string $namespace, ComposerProject $project): string {
		if ($path !== null && trim($path) !== '') {
			return $this->absolutePath($path);
		}

		$autoload = $project->psr4NamespaceFor($namespace);

		if ($autoload === null) {
			throw new RuntimeException(sprintf(
				'Namespace "%s" is outside the Composer PSR-4 namespaces in composer.json. Pass --path to choose an output directory.',
				$namespace
			));
		}

		return $this->rootPath . '/' . $autoload->pathFor($namespace);
	}

	private function validNamespace(string $namespace): string {
		if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $namespace)) {
			throw new RuntimeException(sprintf('Namespace "%s" is not a valid PHP namespace.', $namespace));
		}

		return $namespace;
	}

	private function absolutePath(string $path): string {
		$path = trim($path);

		if (str_starts_with($path, '/')) {
			return rtrim($path, '/');
		}

		return $this->rootPath . '/' . trim($path, '/');
	}

	private function relativePath(string $path): string {
		$root = rtrim($this->rootPath, '/') . '/';

		if (str_starts_with($path, $root)) {
			return substr($path, strlen($root));
		}

		return $path;
	}

	private function phpString(string $value): string {
		return var_export($value, true);
	}
}
