<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli\Commands\Make\Database;

use StellarWP\Foundation\Cli\Commands\Make\Database\ValueObjects\ProviderRegistrationResult;
use StellarWP\Foundation\Cli\Generation\Php\PhpSourceEditor;

/**
 * Updates generated database providers with generated table registrations.
 *
 * The updater intentionally edits only marker-based provider files, which keeps
 * silent modifications predictable while still letting developers review or
 * discard the generated diff.
 */
final class ProviderRegistrationEditor
{
	private const string TABLE_MARKER = '// foundation:database-tables';

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
			registration: sprintf('$this->container->singleton( %s::class );', $class),
			write: true
		);
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
			registration: sprintf('$this->container->singleton( %s::class );', $class),
			write: false
		);
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
