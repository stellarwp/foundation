<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli\Commands\Package;

/**
 * Result of creating or completing a Foundation split package scaffold.
 *
 * This value object keeps the resolved package together with the files that
 * were created so commands can report the local changes before repository work.
 */
final readonly class PackageScaffold
{
	/**
	 * @param list<string> $createdFiles
	 */
	public function __construct(
		public Package $package,
		public array $createdFiles
	) {
	}
}
