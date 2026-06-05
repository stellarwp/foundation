<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli\Generation;

use RuntimeException;

/**
 * Renders text stubs by replacing Laravel-style placeholder tokens.
 */
final class StubRenderer
{
	/**
	 * @param array<string,string> $replacements
	 */
	public function render(string $stubPath, array $replacements): string {
		if (! is_readable($stubPath)) {
			throw new RuntimeException(sprintf('Could not read stub "%s".', $stubPath));
		}

		$contents = (string) file_get_contents($stubPath);

		foreach ($replacements as $key => $value) {
			$contents = str_replace([
				'{{ ' . $key . ' }}',
				'{{' . $key . '}}',
			], $value, $contents);
		}

		return $contents;
	}
}
