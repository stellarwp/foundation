<?php declare(strict_types=1);

namespace StellarWP\Foundation\Migrations\Cli;

use StellarWP\Foundation\Migrations\ValueObjects\Step;
use WP_CLI;

/**
 * Present completed migration steps and preview SQL consistently.
 *
 * @internal
 */
final class MigrationOutput
{
	/**
	 * Print each step and summarize execution or preview.
	 *
	 * @param list<Step> $steps
	 */
	public function showSteps(array $steps, bool $preview = false): void {
		foreach ($steps as $step) {
			WP_CLI::line(($step->reverse ? 'Down ' : 'Up ') . $step->id);

			if (! $preview) {
				continue;
			}

			foreach ($step->sql as $sql) {
				WP_CLI::line($sql . ';');
			}

			if ($step->hasDataStep) {
				WP_CLI::line('Data callback will run after schema changes.');
			}
		}

		WP_CLI::success(sprintf('%s %d migration steps.', $preview ? 'Previewed' : 'Completed', count($steps)));
	}
}
