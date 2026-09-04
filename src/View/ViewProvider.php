<?php declare(strict_types=1);

namespace StellarWP\Foundation\View;

use InvalidArgumentException;
use StellarWP\Foundation\Container\Contracts\Provider;
use StellarWP\Foundation\Container\Contracts\Resolver as C;
use StellarWP\Foundation\View\Contracts\DirectoryAwareView;
use StellarWP\Foundation\View\Contracts\View;

/**
 * Registers a shared PHP view renderer rooted at the configured view directory.
 */
final class ViewProvider extends Provider
{
	/**
	 * @throws InvalidArgumentException When view.directory is not a non-empty string.
	 */
	public function register(): void {
		$this->registerView();
	}

	/**
	 * @throws InvalidArgumentException When view.directory is not a non-empty string.
	 */
	private function registerView(): void {
		$directory = $this->config->get('view.directory');

		if (! is_string($directory) || trim($directory) === '') {
			throw new InvalidArgumentException('The view.directory configuration value must be a non-empty string.');
		}

		$this->container->when(PhpView::class)
			->needs('$directory')
			->give($directory);

		$this->container->singleton(PhpView::class);
		$this->container->singleton(View::class, static fn (C $c): PhpView => $c->get(PhpView::class));
		$this->container->singleton(DirectoryAwareView::class, static fn (C $c): PhpView => $c->get(PhpView::class));
	}
}
