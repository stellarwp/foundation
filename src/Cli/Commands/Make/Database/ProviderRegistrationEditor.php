<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli\Commands\Make\Database;

use StellarWP\Foundation\Cli\Generation\Php\PhpSourceEditor;

/**
 * Updates generated database providers with generated table and migration registrations.
 *
 * The updater intentionally edits only marker-based provider files, which keeps
 * silent modifications predictable while still letting developers review or
 * discard the generated diff.
 */
final class ProviderRegistrationEditor
{
	private const string TABLE_MARKER      = '// foundation:database-tables';
	private const string MIGRATION_MARKER  = '// foundation:database-migrations';
	private const string MIGRATIONS_CLASS  = 'StellarWP\\Foundation\\Database\\DatabaseProvider';
	private const string MIGRATIONS_CONST  = 'MIGRATIONS';
	public const string UPDATED            = 'updated';
	public const string ALREADY_REGISTERED = 'already_registered';
	public const string NOT_FOUND          = 'not_found';
	public const string READ_FAILED        = 'read_failed';
	public const string NOT_WRITABLE       = 'not_writable';
	public const string MISSING_ANCHOR     = 'missing_anchor';
	public const string MISSING_MARKER     = 'missing_marker';
	public const string IMPORT_COLLISION   = 'import_collision';
	public const string PARSE_FAILED       = 'parse_failed';
	public const string WRITE_FAILED       = 'write_failed';

	public function __construct(
		private readonly PhpSourceEditor $sourceEditor
	) {
	}

	public function addTable(string $providerPath, string $class, string $classNamespace): string {
		return $this->addRegistration(
			providerPath: $providerPath,
			class: $class,
			classNamespace: $classNamespace,
			marker: self::TABLE_MARKER,
			registration: sprintf('$this->container->singleton(%s::class);', $class),
			write: true
		);
	}

	public function addMigration(string $providerPath, string $class, string $classNamespace): string {
		return $this->addMergeArrayVarRegistration(
			providerPath: $providerPath,
			class: $class,
			classNamespace: $classNamespace,
			write: true
		);
	}

	/**
	 * Add a table and its initial migration to one provider replacement.
	 */
	public function addTableAndMigration(
		string $providerPath,
		string $tableClass,
		string $tableNamespace,
		string $migrationClass,
		string $migrationNamespace
	): string {
		if (! is_file($providerPath) || ! is_readable($providerPath)) {
			return self::NOT_FOUND;
		}

		if (! $this->isWritableTarget($providerPath)) {
			return self::NOT_WRITABLE;
		}

		$contents = file_get_contents($providerPath);

		if ($contents === false) {
			return self::READ_FAILED;
		}

		$temporaryPath = tempnam(dirname($providerPath), '.foundation-provider-');

		if ($temporaryPath === false) {
			return self::WRITE_FAILED;
		}

		try {
			$written = file_put_contents($temporaryPath, $contents);

			if ($written !== strlen($contents)) {
				return self::WRITE_FAILED;
			}

			$tableStatus = $this->addTable($temporaryPath, $tableClass, $tableNamespace);

			if (! $this->registrationSucceeded($tableStatus)) {
				return $tableStatus;
			}

			$migrationStatus = $this->addMigration($temporaryPath, $migrationClass, $migrationNamespace);

			if (! $this->registrationSucceeded($migrationStatus)) {
				return $migrationStatus;
			}

			if ($tableStatus === self::ALREADY_REGISTERED && $migrationStatus === self::ALREADY_REGISTERED) {
				return self::ALREADY_REGISTERED;
			}

			$updatedContents = file_get_contents($temporaryPath);

			if ($updatedContents === false) {
				return self::WRITE_FAILED;
			}

			return $this->writeContents($providerPath, $updatedContents)
				? self::UPDATED
				: self::WRITE_FAILED;
		} finally {
			@unlink($temporaryPath);
		}
	}

	public function checkTable(string $providerPath, string $class, string $classNamespace): string {
		return $this->addRegistration(
			providerPath: $providerPath,
			class: $class,
			classNamespace: $classNamespace,
			marker: self::TABLE_MARKER,
			registration: sprintf('$this->container->singleton(%s::class);', $class),
			write: false
		);
	}

	public function checkMigration(string $providerPath, string $class, string $classNamespace): string {
		return $this->addMergeArrayVarRegistration(
			providerPath: $providerPath,
			class: $class,
			classNamespace: $classNamespace,
			write: false
		);
	}

	/**
	 * Verify that both registrations can be added without changing the provider.
	 */
	public function checkTableAndMigration(
		string $providerPath,
		string $tableClass,
		string $tableNamespace,
		string $migrationClass,
		string $migrationNamespace
	): string {
		$tableStatus = $this->checkTable($providerPath, $tableClass, $tableNamespace);

		if (! $this->registrationSucceeded($tableStatus)) {
			return $tableStatus;
		}

		$migrationStatus = $this->checkMigration($providerPath, $migrationClass, $migrationNamespace);

		if (! $this->registrationSucceeded($migrationStatus)) {
			return $migrationStatus;
		}

		return $tableStatus === self::ALREADY_REGISTERED && $migrationStatus === self::ALREADY_REGISTERED
			? self::ALREADY_REGISTERED
			: self::UPDATED;
	}

	private function addRegistration(string $providerPath, string $class, string $classNamespace, string $marker, string $registration, bool $write): string {
		if (! is_file($providerPath) || ! is_readable($providerPath)) {
			return self::NOT_FOUND;
		}

		$contents = file_get_contents($providerPath);

		if ($contents === false) {
			return self::READ_FAILED;
		}

		if (! $this->sourceEditor->canParse($contents)) {
			return self::PARSE_FAILED;
		}

		if (! $this->sourceEditor->hasLineComment($contents, $marker)) {
			return self::MISSING_MARKER;
		}

		$fullyQualifiedClass = $classNamespace . '\\' . $class;

		if ($this->sourceEditor->hasContainerSingleton($contents, $fullyQualifiedClass)) {
			return self::ALREADY_REGISTERED;
		}

		if ($this->sourceEditor->hasImportShortNameCollision($contents, $class, $fullyQualifiedClass)) {
			return self::IMPORT_COLLISION;
		}

		if (! $this->isWritableTarget($providerPath)) {
			return self::NOT_WRITABLE;
		}

		if (! $write) {
			return self::UPDATED;
		}

		$contents = $this->sourceEditor->addImport($contents, $fullyQualifiedClass);

		if ($contents === null) {
			return self::PARSE_FAILED;
		}

		$contents = $this->sourceEditor->insertBeforeLineComment($contents, $marker, $registration);

		if ($contents === null) {
			return self::MISSING_MARKER;
		}

		if (! $this->writeContents($providerPath, $contents)) {
			return self::WRITE_FAILED;
		}

		return self::UPDATED;
	}

	private function addMergeArrayVarRegistration(string $providerPath, string $class, string $classNamespace, bool $write): string {
		if (! is_file($providerPath) || ! is_readable($providerPath)) {
			return self::NOT_FOUND;
		}

		$contents = file_get_contents($providerPath);

		if ($contents === false) {
			return self::READ_FAILED;
		}

		if (! $this->sourceEditor->canParse($contents)) {
			return self::PARSE_FAILED;
		}

		$containerExpression = $this->sourceEditor->mergeArrayVarContainerExpression($contents, self::MIGRATIONS_CLASS, self::MIGRATIONS_CONST);

		if ($containerExpression === null || ! $this->sourceEditor->canInsertIntoMergeArrayVar($contents, self::MIGRATIONS_CLASS, self::MIGRATIONS_CONST, self::MIGRATION_MARKER)) {
			return self::MISSING_ANCHOR;
		}

		$fullyQualifiedClass = $classNamespace . '\\' . $class;
		$registration        = sprintf('%s->get(%s::class),', $containerExpression, $class);

		if ($this->sourceEditor->mergeArrayVarContainsClass($contents, self::MIGRATIONS_CLASS, self::MIGRATIONS_CONST, $fullyQualifiedClass)) {
			return self::ALREADY_REGISTERED;
		}

		if ($this->sourceEditor->hasImportShortNameCollision($contents, $class, $fullyQualifiedClass)) {
			return self::IMPORT_COLLISION;
		}

		if (! $this->isWritableTarget($providerPath)) {
			return self::NOT_WRITABLE;
		}

		if (! $write) {
			return self::UPDATED;
		}

		$contents = $this->sourceEditor->addImport($contents, $fullyQualifiedClass);

		if ($contents === null) {
			return self::PARSE_FAILED;
		}

		$contents = $this->sourceEditor->insertIntoMergeArrayVar(
			contents: $contents,
			class: self::MIGRATIONS_CLASS,
			constant: self::MIGRATIONS_CONST,
			statement: $registration,
			beforeComment: self::MIGRATION_MARKER
		);

		if ($contents === null) {
			return self::MISSING_ANCHOR;
		}

		if (! $this->writeContents($providerPath, $contents)) {
			return self::WRITE_FAILED;
		}

		return self::UPDATED;
	}

	private function registrationSucceeded(string $status): bool {
		return $status === self::UPDATED || $status === self::ALREADY_REGISTERED;
	}

	private function writeContents(string $path, string $contents): bool {
		$path = $this->targetPath($path);

		if ($path === null) {
			return false;
		}

		$temporaryPath = tempnam(dirname($path), '.foundation-write-');

		if ($temporaryPath === false) {
			return false;
		}

		try {
			$written = file_put_contents($temporaryPath, $contents);

			if ($written !== strlen($contents)) {
				return false;
			}

			$permissions = fileperms($path);

			if ($permissions !== false && ! chmod($temporaryPath, $permissions & 0777)) {
				return false;
			}

			return @rename($temporaryPath, $path);
		} finally {
			if (file_exists($temporaryPath)) {
				@unlink($temporaryPath);
			}
		}
	}

	private function isWritableTarget(string $path): bool {
		$target = $this->targetPath($path);

		return $target !== null
			&& is_writable($target)
			&& is_writable(dirname($target));
	}

	private function targetPath(string $path): ?string {
		if (! is_link($path)) {
			return $path;
		}

		$target = realpath($path);

		return $target === false ? null : $target;
	}
}
