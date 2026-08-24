<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\View;

use InvalidArgumentException;
use RuntimeException;
use StellarWP\Foundation\Tests\TestCase;
use StellarWP\Foundation\View\Exceptions\ViewNotFoundException;
use StellarWP\Foundation\View\PhpView;

final class PhpViewTest extends TestCase
{
	public function test_it_renders_a_relative_php_view_to_a_string(): void {
		$view = new PhpView($this->data_dir('View/default'));

		$this->assertSame(
			'<p>Hello, Foundation</p>' . PHP_EOL,
			$view->render('greeting', [
				'greeting' => 'Hello',
				'name'     => 'Foundation',
			])
		);
	}

	public function test_it_escapes_template_data_in_the_template(): void {
		$view = new PhpView($this->data_dir('View/default'));

		$this->assertSame(
			'<p>&lt;strong&gt;Hello&lt;/strong&gt;, Foundation</p>' . PHP_EOL,
			$view->render('greeting', [
				'greeting' => '<strong>Hello</strong>',
				'name'     => 'Foundation',
			])
		);
	}

	public function test_it_renders_a_nested_view_name(): void {
		$view = new PhpView($this->data_dir('View/default'));

		$this->assertSame('<p>Nested view</p>' . PHP_EOL, $view->render('admin/product-summary'));
	}

	public function test_it_returns_a_new_renderer_for_a_runtime_directory_without_mutating_the_original(): void {
		$view        = new PhpView($this->data_dir('View/default'));
		$runtimeView = $view->withDirectory($this->data_dir('View/runtime'));

		$this->assertNotSame($view, $runtimeView);
		$this->assertSame('<p>Runtime directory</p>' . PHP_EOL, $runtimeView->render('greeting'));
		$this->assertSame(
			'<p>Hello, Foundation</p>' . PHP_EOL,
			$view->render('greeting', ['greeting' => 'Hello', 'name' => 'Foundation'])
		);
	}

	public function test_view_data_cannot_replace_the_resolved_view_path(): void {
		$view = new PhpView($this->data_dir('View/default'));

		$this->assertSame(
			'internal-variable.php',
			$view->render('internal-variable', [
				'foundationViewPath' => $this->data_dir('View/outside.php'),
			])
		);
	}

	public function test_it_restores_the_output_buffer_when_a_view_throws(): void {
		$view        = new PhpView($this->data_dir('View/default'));
		$bufferLevel = ob_get_level();

		try {
			$view->render('throws');
			$this->fail('Expected the view exception to be propagated.');
		} catch (RuntimeException $exception) {
			$this->assertSame('View rendering failed.', $exception->getMessage());
		}

		$this->assertSame($bufferLevel, ob_get_level());
	}

	public function test_it_rejects_and_cleans_up_an_unclosed_view_buffer(): void {
		$view        = new PhpView($this->data_dir('View/default'));
		$bufferLevel = ob_get_level();

		try {
			$view->render('unclosed-buffer');
			$this->fail('Expected unbalanced output buffering to be rejected.');
		} catch (RuntimeException $exception) {
			$this->assertSame('The view "unclosed-buffer" must leave output buffering unchanged.', $exception->getMessage());
		}

		$this->assertSame($bufferLevel, ob_get_level());
	}

	public function test_it_allows_balanced_buffers_owned_by_the_view(): void {
		$view = new PhpView($this->data_dir('View/default'));

		$this->assertSame('Balanced view output.', $view->render('balanced-buffer'));
	}

	public function test_it_does_not_close_a_caller_buffer_when_the_view_closes_its_rendering_buffer(): void {
		$view        = new PhpView($this->data_dir('View/default'));
		$bufferLevel = ob_get_level();

		ob_start();

		try {
			$view->render('closes-buffer');
			$this->fail('Expected an unexpectedly closed rendering buffer to be rejected.');
		} catch (RuntimeException $exception) {
			$this->assertSame('The view "closes-buffer" must leave output buffering unchanged.', $exception->getMessage());
			$this->assertSame($bufferLevel + 1, ob_get_level());
		} finally {
			while (ob_get_level() > $bufferLevel) {
				ob_end_clean();
			}
		}

		$this->assertSame($bufferLevel, ob_get_level());
	}

	public function test_it_rejects_a_same_depth_replacement_for_its_rendering_buffer(): void {
		$view        = new PhpView($this->data_dir('View/default'));
		$bufferLevel = ob_get_level();

		try {
			$view->render('replaces-buffer');
			$this->fail('Expected a replaced rendering buffer to be rejected.');
		} catch (RuntimeException $exception) {
			$this->assertSame('The view "replaces-buffer" must leave output buffering unchanged.', $exception->getMessage());
		}

		$this->assertSame($bufferLevel, ob_get_level());
	}

	public function test_it_rejects_flushing_its_rendering_buffer_without_leaking_output(): void {
		$view        = new PhpView($this->data_dir('View/default'));
		$bufferLevel = ob_get_level();

		ob_start();

		try {
			$view->render('flushes-buffer');
			$this->fail('Expected a flushed rendering buffer to be rejected.');
		} catch (RuntimeException $exception) {
			$this->assertSame('The view "flushes-buffer" must leave output buffering unchanged.', $exception->getMessage());
			$this->assertSame('', ob_get_contents());
		} finally {
			while (ob_get_level() > $bufferLevel) {
				ob_end_clean();
			}
		}

		$this->assertSame($bufferLevel, ob_get_level());
	}

	public function test_it_rejects_an_invalid_view_directory(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('must exist and be readable');

		new PhpView($this->data_dir('View/missing'));
	}

	public function test_it_reports_a_missing_view(): void {
		$view = new PhpView($this->data_dir('View/default'));

		$this->expectException(ViewNotFoundException::class);
		$this->expectExceptionMessage('The view "missing" could not be found');

		$view->render('missing');
	}

	/**
	 * @dataProvider invalid_view_names
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('invalid_view_names')]
	public function test_it_rejects_unsafe_view_names(string $name): void {
		$view = new PhpView($this->data_dir('View/default'));

		$this->expectException(InvalidArgumentException::class);

		$view->render($name);
	}

	/**
	 * @return array<string, array{name: string}>
	 */
	public static function invalid_view_names(): array {
		return [
			'empty'            => ['name' => ''],
			'null byte'        => ['name' => "greeting\0ignored"],
			'absolute Unix'    => ['name' => '/tmp/view'],
			'absolute Windows' => ['name' => 'C:\\tmp\\view'],
			'parent traversal' => ['name' => '../outside'],
			'nested traversal' => ['name' => 'nested/../../outside'],
		];
	}

	public function test_it_rejects_a_symlink_that_resolves_outside_the_view_directory(): void {
		$directory = $this->prepare_temp_dir('view');
		$root      = $directory . '/root';
		$outside   = $directory . '/outside.php';

		mkdir($root);
		file_put_contents($outside, 'Outside');

		if (! symlink($outside, $root . '/linked.php')) {
			$this->markTestSkipped('The test environment cannot create symbolic links.');
		}

		$view = new PhpView($root);

		$this->expectException(ViewNotFoundException::class);

		$view->render('linked');
	}
}
