<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Container;

use StellarWP\Foundation\Container\Contracts\Configuration;

final readonly class FixedConfiguration implements Configuration
{
	/**
	 * @param array<string, mixed> $values
	 */
	public function __construct(
		private array $values
	) {
	}

	/**
	 * {@inheritDoc}
	 */
	public function all(): array {
		return $this->values;
	}

	/**
	 * {@inheritDoc}
	 */
	public function has(string $key): bool {
		return $this->find($key)['exists'];
	}

	/**
	 * {@inheritDoc}
	 */
	public function get(string $key, mixed $default = null): mixed {
		$result = $this->find($key);

		return $result['exists'] ? $result['value'] : $default;
	}

	/**
	 * Find an exact key or traverse its dotted path through the fixed values.
	 *
	 * @return array{exists: bool, value: mixed}
	 */
	private function find(string $key): array {
		if (array_key_exists($key, $this->values)) {
			return [
				'exists' => true,
				'value'  => $this->values[$key],
			];
		}

		$value = $this->values;

		foreach (explode('.', $key) as $segment) {
			if (! is_array($value) || ! array_key_exists($segment, $value)) {
				return [
					'exists' => false,
					'value'  => null,
				];
			}

			$value = $value[$segment];
		}

		return [
			'exists' => true,
			'value'  => $value,
		];
	}
}
