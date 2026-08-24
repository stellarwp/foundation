<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\View;

use JsonException;
use StellarWP\Foundation\View\Contracts\View;

/**
 * Demonstrates a non-filesystem renderer that satisfies the base view contract.
 */
final class JsonView implements View
{
	/**
	 * @throws JsonException When the supplied data cannot be encoded.
	 */
	public function render(string $name, array $data = []): string {
		return json_encode([
			'view' => $name,
			'data' => $data,
		], JSON_THROW_ON_ERROR);
	}
}
