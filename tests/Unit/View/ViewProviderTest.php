<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\View;

use Adbar\Dot;
use InvalidArgumentException;
use StellarWP\Foundation\Tests\TestCase;
use StellarWP\Foundation\View\Contracts\DirectoryAwareView;
use StellarWP\Foundation\View\Contracts\View;
use StellarWP\Foundation\View\PhpView;
use StellarWP\Foundation\View\ViewProvider;

final class ViewProviderTest extends TestCase
{
	public function test_it_registers_the_configured_view_as_a_shared_service(): void {
		$this->container->get(Dot::class)->set('view.directory', $this->data_dir('View/default'));
		$this->container->register(ViewProvider::class);

		$view = $this->container->get(View::class);

		$this->assertInstanceOf(PhpView::class, $view);
		$this->assertSame($view, $this->container->get(View::class));
		$this->assertSame($view, $this->container->get(DirectoryAwareView::class));
		$this->assertSame($view, $this->container->get(PhpView::class));
		$this->assertSame(
			'<p>Hello, Foundation</p>' . PHP_EOL,
			$view->render('greeting', ['greeting' => 'Hello', 'name' => 'Foundation'])
		);
	}

	public function test_it_rejects_missing_view_configuration(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('view.directory configuration value must be a non-empty string');

		$this->container->register(ViewProvider::class);
	}
}
