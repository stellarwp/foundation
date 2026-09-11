<?php declare(strict_types=1);

namespace Plugin\Tooling\Commands;

use StellarWP\Foundation\Cli\GeneratorCommand;

/**
 * Generate report classes from the project's report stub.
 */
final class Report_Command extends GeneratorCommand
{
	public const string CONFIG_KEY        = 'report';
	public const string NAME              = 'make:' . self::CONFIG_KEY;
	public const string DEFAULT_NAMESPACE = 'Reports';

	/**
	 * Select the project's report template.
	 */
	protected function stub(): string {
		return 'foundation/stubs/report/report.stub';
	}
}
