<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli\Commands\Make\Database\ValueObjects;

use StellarWP\Foundation\Cli\Generation\ValueObjects\GeneratedFile;

/**
 * Describes a generated migration file and the class it declares.
 */
final readonly class GeneratedMigration
{
	/**
	 * Describe a generated file together with its declared migration class.
	 */
	public function __construct(
		public GeneratedFile $file,
		public string $class,
		public string $namespace
	) {
	}
}
