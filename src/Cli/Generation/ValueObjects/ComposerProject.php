<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli\Generation\ValueObjects;

use RuntimeException;

/**
 * Represents Composer metadata needed by Foundation generators.
 */
final readonly class ComposerProject
{
	/**
	 * @param list<Psr4Namespace> $psr4Namespaces
	 */
	public function __construct(
		public array $psr4Namespaces,
		public ?StraussConfig $strauss
	) {
		if ($this->psr4Namespaces === []) {
			throw new RuntimeException('Could not find a valid autoload.psr-4 namespace in composer.json.');
		}
	}

	public function defaultPsr4Namespace(): Psr4Namespace {
		return $this->psr4Namespaces[0];
	}

	public function psr4NamespaceFor(string $namespace): ?Psr4Namespace {
		$match = null;

		foreach ($this->psr4Namespaces as $psr4Namespace) {
			if ($psr4Namespace->matches($namespace)) {
				if ($match === null || strlen($psr4Namespace->namespace) > strlen($match->namespace)) {
					$match = $psr4Namespace;
				}
			}
		}

		return $match;
	}

	public function foundationClass(string $class): string {
		if (! str_starts_with($class, 'StellarWP\\Foundation\\')) {
			throw new RuntimeException(sprintf('Cannot apply Foundation namespace prefix to non-Foundation class "%s".', $class));
		}

		if ($this->strauss === null) {
			return $class;
		}

		return $this->strauss->foundationClass($class);
	}
}
