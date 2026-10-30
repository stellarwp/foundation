<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\View;

use StellarWP\Foundation\View\Contracts\DirectoryAwareView;

/**
 * Demonstrates a directory-aware adapter that may return another implementation.
 */
final readonly class DelegatingDirectoryView implements DirectoryAwareView
{
	public function __construct(
		private DirectoryAwareView $view
	) {
	}

	public function withDirectory(string $directory): DirectoryAwareView {
		return $this->view->withDirectory($directory);
	}

	public function render(string $name, array $data = []): string {
		return $this->view->render($name, $data);
	}
}
