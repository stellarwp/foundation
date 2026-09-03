<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli\Commands\Make\Database;

use StellarWP\Foundation\Cli\Commands\Make\Database\ValueObjects\ProviderRegistrationResult;
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
	private const string TABLE_MARKER     = '// foundation:database-tables';
	private const string MIGRATION_MARKER = '// foundation:database-migrations';
	private const string MIGRATIONS_CLASS = 'StellarWP\\Foundation\\Database\\DatabaseProvider';
	private const string MIGRATIONS_CONST = 'MIGRATIONS';

	/**
	 * Create an editor backed by structured PHP source inspection.
	 */
	public function __construct(
		private readonly PhpSourceEditor $sourceEditor
	) {
	}

	/**
	 * Add a table singleton registration to a generated database provider.
	 */
	public function addTable(string $providerPath, string $class, string $classNamespace): ProviderRegistrationResult {
		return $this->addRegistration(
			providerPath: $providerPath,
			class: $class,
			classNamespace: $classNamespace,
			marker: self::TABLE_MARKER,
			registration: sprintf('$this->container->singleton(%s::class);', $class),
			write: true
		);
	}

	/**
	 * Add a migration contribution to a generated database provider.
	 */
	public function addMigration(string $providerPath, string $class, string $classNamespace): ProviderRegistrationResult {
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
	): ProviderRegistrationResult {
		if (! is_file($providerPath) || ! is_readable($providerPath)) {
			return ProviderRegistrationResult::notFound();
		}

		if (! $this->isWritableTarget($providerPath)) {
			return ProviderRegistrationResult::notWritable();
		}

		$contents = file_get_contents($providerPath);

		if ($contents === false) {
			return ProviderRegistrationResult::readFailed();
		}

		$temporaryPath = tempnam(dirname($providerPath), '.foundation-provider-');

		if ($temporaryPath === false) {
			return ProviderRegistrationResult::writeFailed();
		}

		try {
			$written = file_put_contents($temporaryPath, $contents);

			if ($written !== strlen($contents)) {
				return ProviderRegistrationResult::writeFailed();
			}

			$tableResult = $this->addTable($temporaryPath, $tableClass, $tableNamespace);

			if (! $tableResult->succeeded()) {
				return $tableResult;
			}

			$migrationResult = $this->addMigration($temporaryPath, $migrationClass, $migrationNamespace);

			if (! $migrationResult->succeeded()) {
				return $migrationResult;
			}

			if ($tableResult->wasAlreadyRegistered() && $migrationResult->wasAlreadyRegistered()) {
				return ProviderRegistrationResult::alreadyRegistered();
			}

			$updatedContents = file_get_contents($temporaryPath);

			if ($updatedContents === false) {
				return ProviderRegistrationResult::writeFailed();
			}

			return $this->writeContents($providerPath, $updatedContents)
				? ProviderRegistrationResult::updated()
				: ProviderRegistrationResult::writeFailed();
		} finally {
			@unlink($temporaryPath);
		}
	}

	/**
	 * Verify that a table registration can be added without changing the provider.
	 */
	public function checkTable(string $providerPath, string $class, string $classNamespace): ProviderRegistrationResult {
		return $this->addRegistration(
			providerPath: $providerPath,
			class: $class,
			classNamespace: $classNamespace,
			marker: self::TABLE_MARKER,
			registration: sprintf('$this->container->singleton(%s::class);', $class),
			write: false
		);
	}

	/**
	 * Verify that a migration contribution can be added without changing the provider.
	 */
	public function checkMigration(string $providerPath, string $class, string $classNamespace): ProviderRegistrationResult {
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
	): ProviderRegistrationResult {
		$tableResult = $this->checkTable($providerPath, $tableClass, $tableNamespace);

		if (! $tableResult->succeeded()) {
			return $tableResult;
		}

		$migrationResult = $this->checkMigration($providerPath, $migrationClass, $migrationNamespace);

		if (! $migrationResult->succeeded()) {
			return $migrationResult;
		}

		return $tableResult->wasAlreadyRegistered() && $migrationResult->wasAlreadyRegistered()
			? ProviderRegistrationResult::alreadyRegistered()
			: ProviderRegistrationResult::ready();
	}

	/**
	 * Validate and optionally insert one marker-based provider registration.
	 */
	private function addRegistration(string $providerPath, string $class, string $classNamespace, string $marker, string $registration, bool $write): ProviderRegistrationResult {
		if (! is_file($providerPath) || ! is_readable($providerPath)) {
			return ProviderRegistrationResult::notFound();
		}

		$contents = file_get_contents($providerPath);

		if ($contents === false) {
			return ProviderRegistrationResult::readFailed();
		}

		if (! $this->sourceEditor->canParse($contents)) {
			return ProviderRegistrationResult::parseFailed();
		}

		if (! $this->sourceEditor->hasLineComment($contents, $marker)) {
			return ProviderRegistrationResult::missingMarker();
		}

		$fullyQualifiedClass = $classNamespace . '\\' . $class;

		if ($this->sourceEditor->hasContainerSingleton($contents, $fullyQualifiedClass)) {
			return ProviderRegistrationResult::alreadyRegistered();
		}

		if ($this->sourceEditor->hasImportShortNameCollision($contents, $class, $fullyQualifiedClass)) {
			return ProviderRegistrationResult::importCollision();
		}

		if (! $this->isWritableTarget($providerPath)) {
			return ProviderRegistrationResult::notWritable();
		}

		if (! $write) {
			return ProviderRegistrationResult::ready();
		}

		$contents = $this->sourceEditor->addImport($contents, $fullyQualifiedClass);

		if ($contents === null) {
			return ProviderRegistrationResult::parseFailed();
		}

		$contents = $this->sourceEditor->insertBeforeLineComment($contents, $marker, $registration);

		if ($contents === null) {
			return ProviderRegistrationResult::missingMarker();
		}

		if (! $this->writeContents($providerPath, $contents)) {
			return ProviderRegistrationResult::writeFailed();
		}

		return ProviderRegistrationResult::updated();
	}

	/**
	 * Validate and optionally add a class to the provider's migration contribution.
	 */
	private function addMergeArrayVarRegistration(string $providerPath, string $class, string $classNamespace, bool $write): ProviderRegistrationResult {
		if (! is_file($providerPath) || ! is_readable($providerPath)) {
			return ProviderRegistrationResult::notFound();
		}

		$contents = file_get_contents($providerPath);

		if ($contents === false) {
			return ProviderRegistrationResult::readFailed();
		}

		if (! $this->sourceEditor->canParse($contents)) {
			return ProviderRegistrationResult::parseFailed();
		}

		$containerExpression = $this->sourceEditor->mergeArrayVarContainerExpression($contents, self::MIGRATIONS_CLASS, self::MIGRATIONS_CONST);

		if ($containerExpression === null || ! $this->sourceEditor->canInsertIntoMergeArrayVar($contents, self::MIGRATIONS_CLASS, self::MIGRATIONS_CONST, self::MIGRATION_MARKER)) {
			return ProviderRegistrationResult::missingAnchor();
		}

		$fullyQualifiedClass = $classNamespace . '\\' . $class;
		$registration        = sprintf('%s->get(%s::class),', $containerExpression, $class);

		if ($this->sourceEditor->mergeArrayVarContainsClass($contents, self::MIGRATIONS_CLASS, self::MIGRATIONS_CONST, $fullyQualifiedClass)) {
			return ProviderRegistrationResult::alreadyRegistered();
		}

		if ($this->sourceEditor->hasImportShortNameCollision($contents, $class, $fullyQualifiedClass)) {
			return ProviderRegistrationResult::importCollision();
		}

		if (! $this->isWritableTarget($providerPath)) {
			return ProviderRegistrationResult::notWritable();
		}

		if (! $write) {
			return ProviderRegistrationResult::ready();
		}

		$contents = $this->sourceEditor->addImport($contents, $fullyQualifiedClass);

		if ($contents === null) {
			return ProviderRegistrationResult::parseFailed();
		}

		$contents = $this->sourceEditor->insertIntoMergeArrayVar(
			contents: $contents,
			class: self::MIGRATIONS_CLASS,
			constant: self::MIGRATIONS_CONST,
			statement: $registration,
			beforeComment: self::MIGRATION_MARKER
		);

		if ($contents === null) {
			return ProviderRegistrationResult::missingAnchor();
		}

		if (! $this->writeContents($providerPath, $contents)) {
			return ProviderRegistrationResult::writeFailed();
		}

		return ProviderRegistrationResult::updated();
	}

	/**
	 * Atomically replace provider source while preserving file permissions.
	 */
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

	/**
	 * Determine whether a provider or its symlink target can be replaced atomically.
	 */
	private function isWritableTarget(string $path): bool {
		$target = $this->targetPath($path);

		return $target !== null
			&& is_writable($target)
			&& is_writable(dirname($target));
	}

	/**
	 * Resolve a provider symlink to its physical target when possible.
	 */
	private function targetPath(string $path): ?string {
		if (! is_link($path)) {
			return $path;
		}

		$target = realpath($path);

		return $target === false ? null : $target;
	}
}
