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

	private function addRegistration(string $providerPath, string $class, string $classNamespace, string $marker, string $registration, bool $write): string {
		if (! is_file($providerPath) || ! is_readable($providerPath)) {
			return self::NOT_FOUND;
		}

		if ($write && ! is_writable($providerPath)) {
			return self::NOT_WRITABLE;
		}

		$contents = (string) file_get_contents($providerPath);

		if (! $this->sourceEditor->canParse($contents)) {
			return self::PARSE_FAILED;
		}

		if (! $this->sourceEditor->hasLineComment($contents, $marker)) {
			return self::MISSING_MARKER;
		}

		$fullyQualifiedClass = $classNamespace . '\\' . $class;

		if ($this->sourceEditor->hasImport($contents, $fullyQualifiedClass) && str_contains($contents, $registration)) {
			return self::ALREADY_REGISTERED;
		}

		if ($this->sourceEditor->hasImportShortNameCollision($contents, $class, $fullyQualifiedClass)) {
			return self::IMPORT_COLLISION;
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

		if (file_put_contents($providerPath, $contents) === false) {
			return self::WRITE_FAILED;
		}

		return self::UPDATED;
	}

	private function addMergeArrayVarRegistration(string $providerPath, string $class, string $classNamespace, bool $write): string {
		if (! is_file($providerPath) || ! is_readable($providerPath)) {
			return self::NOT_FOUND;
		}

		if ($write && ! is_writable($providerPath)) {
			return self::NOT_WRITABLE;
		}

		$contents = (string) file_get_contents($providerPath);

		if (! $this->sourceEditor->canParse($contents)) {
			return self::PARSE_FAILED;
		}

		$containerExpression = $this->sourceEditor->mergeArrayVarContainerExpression($contents, self::MIGRATIONS_CLASS, self::MIGRATIONS_CONST);

		if ($containerExpression === null || ! $this->sourceEditor->canInsertIntoMergeArrayVar($contents, self::MIGRATIONS_CLASS, self::MIGRATIONS_CONST, self::MIGRATION_MARKER)) {
			return self::MISSING_ANCHOR;
		}

		$fullyQualifiedClass = $classNamespace . '\\' . $class;
		$registration        = sprintf('%s->get(%s::class),', $containerExpression, $class);

		if ($this->sourceEditor->hasImport($contents, $fullyQualifiedClass) && str_contains($contents, $registration)) {
			return self::ALREADY_REGISTERED;
		}

		if ($this->sourceEditor->hasImportShortNameCollision($contents, $class, $fullyQualifiedClass)) {
			return self::IMPORT_COLLISION;
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

		if (file_put_contents($providerPath, $contents) === false) {
			return self::WRITE_FAILED;
		}

		return self::UPDATED;
	}
}
