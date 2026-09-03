<?php declare(strict_types=1);

namespace StellarWP\Foundation\View\Contracts;

use Throwable;

/**
 * Renders a named view to a string.
 */
interface View
{
	/**
	 * Render a named view using the supplied data.
	 *
	 * @param array<string, mixed> $data Values made available to the renderer.
	 *
	 * @throws Throwable When rendering fails.
	 */
	public function render(string $name, array $data = []): string;
}
