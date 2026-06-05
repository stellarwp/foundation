<?php declare(strict_types=1);

namespace StellarWP\Foundation\Cli\Generation;

use RuntimeException;

/**
 * Normalizes generator input into WP-style class, command, and description names.
 */
final class ClassNameResolver
{
	public function commandClass(string $input): string {
		$words = $this->words($input);

		if ($words === []) {
			throw new RuntimeException(sprintf('Could not create a class name from "%s".', $input));
		}

		if (strtolower((string) end($words)) !== 'command') {
			$words[] = 'command';
		}

		return implode('_', array_map($this->pascalWord(...), $words));
	}

	public function subcommand(string $className): string {
		$words = $this->words((string) preg_replace('/_?Command$/', '', $className));

		return implode('-', array_map(strtolower(...), $words));
	}

	public function description(string $className): string {
		$words = $this->words((string) preg_replace('/_?Command$/', '', $className));

		if ($words === []) {
			return 'Run the command.';
		}

		return ucfirst(implode(' ', array_map(strtolower(...), $words))) . '.';
	}

	/**
	 * @return list<string>
	 */
	private function words(string $input): array {
		$input = trim($input);
		$input = str_replace('\\', '/', $input);
		$input = basename($input);
		$input = (string) preg_replace('/([a-z0-9])([A-Z])/', '$1 $2', $input);
		$input = (string) preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1 $2', $input);
		$input = (string) preg_replace('/[^A-Za-z0-9]+/', ' ', $input);
		$words = preg_split('/\s+/', trim($input)) ?: [];

		return array_values(array_filter($words, static fn (string $word): bool => $word !== ''));
	}

	private function pascalWord(string $word): string {
		$word = strtolower($word);

		return ucfirst($word);
	}
}
