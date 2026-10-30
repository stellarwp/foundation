<?php declare(strict_types=1);

namespace StellarWP\Foundation\View\Contracts;

use InvalidArgumentException;

/**
 * Renders named views and can select another trusted template directory at runtime.
 */
interface DirectoryAwareView extends View
{
	/**
	 * Return a new renderer scoped to another view directory.
	 *
	 * @throws InvalidArgumentException When the directory does not exist, is unreadable, or is not a directory.
	 */
	public function withDirectory(string $directory): DirectoryAwareView;
}
