<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\View;

use StellarWP\Foundation\Tests\Support\Fixtures\View\DelegatingDirectoryView;
use StellarWP\Foundation\Tests\Support\Fixtures\View\JsonView;
use StellarWP\Foundation\Tests\TestCase;
use StellarWP\Foundation\View\PhpView;

final class ViewContractTest extends TestCase
{
	public function test_a_custom_renderer_does_not_need_to_support_directories(): void {
		$view = new JsonView();

		$this->assertSame(
			'{"view":"product-summary","data":{"count":3}}',
			$view->render('product-summary', ['count' => 3])
		);
	}

	public function test_a_directory_aware_adapter_may_return_another_implementation(): void {
		$view        = new PhpView($this->data_dir('View/default'));
		$adapter     = new DelegatingDirectoryView($view);
		$runtimeView = $adapter->withDirectory($this->data_dir('View/runtime'));

		$this->assertInstanceOf(PhpView::class, $runtimeView);
		$this->assertNotSame($view, $runtimeView);
		$this->assertSame('<p>Runtime directory</p>' . PHP_EOL, $runtimeView->render('greeting'));
	}
}
