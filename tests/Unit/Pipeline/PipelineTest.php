<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Pipeline;

use Closure;
use Error;
use RuntimeException;
use StellarWP\Foundation\Pipeline\Pipeline;
use StellarWP\Foundation\Tests\Support\Pipeline\PipelineParameterizedStage;
use StellarWP\Foundation\Tests\Support\Pipeline\PipelineStageOne;
use StellarWP\Foundation\Tests\Support\Pipeline\PipelineStageTwo;
use StellarWP\Foundation\Tests\TestCase;

final class PipelineTest extends TestCase
{
	private Pipeline $pipeline;

	protected function setUp(): void {
		parent::setUp();

		$this->pipeline = new Pipeline($this->container);
	}

	public function test_it_runs_a_pipeline_with_closures(): void {
		$result = $this->pipeline->send('a sample string that is passed through to all pipes.')
			->through(
				static function (string $passable, Closure $next) {
					$passable = ucwords($passable);

					return $next($passable);
				},
				static function (string $passable, Closure $next) {
					$passable = str_ireplace('All', 'All The', $passable);

					return $next($passable);
				}
			)->thenReturn();

		$this->assertSame('A Sample String That Is Passed Through To All The Pipes.', $result);
	}

	public function test_it_runs_a_pipeline_with_class_strings_where_the_container_makes_the_instances(): void {
		$result = $this->pipeline->send('a sample string that is passed through to all pipes.')
			->through([
				PipelineStageOne::class,
				PipelineStageTwo::class,
			])->thenReturn();

		$this->assertSame('A Sample String That Is Passed Through To All The Pipes.', $result);
	}

	public function test_it_runs_a_pipeline_using_object_handlers(): void {
		$stage1 = new class() {
			public function handle(string $passable, Closure $next): mixed {
				$passable = ucwords($passable);

				return $next($passable);
			}
		};

		$stage2 = new class() {
			public function handle(string $passable, Closure $next): mixed {
				$passable = str_ireplace('All', 'All The', $passable);

				return $next($passable);
			}
		};

		$result = $this->pipeline->send('a sample string that is passed through to all pipes.')
			->through(
				$stage1,
				$stage2
			)->thenReturn();

		$this->assertSame('A Sample String That Is Passed Through To All The Pipes.', $result);
	}

	public function test_it_runs_a_pipeline_using_custom_object_handlers(): void {
		$stage1 = new class() {
			public function run(string $passable, Closure $next): mixed {
				$passable = ucwords($passable);

				return $next($passable);
			}
		};

		$stage2 = new class() {
			public function run(string $passable, Closure $next): mixed {
				$passable = str_ireplace('All', 'All The', $passable);

				return $next($passable);
			}
		};

		// Tell the pipeline to use the "run" method instead of the default "handle" on all stages.
		$result = $this->pipeline->via('run')
			->send('a sample string that is passed through to all pipes.')
			->through(
				$stage1,
				$stage2
			)->thenReturn();

		$this->assertSame('A Sample String That Is Passed Through To All The Pipes.', $result);
	}

	public function test_it_sets_the_container_after_construction(): void {
		$result = (new Pipeline())->setContainer($this->container)
			->send('a sample string that is passed through to all pipes.')
			->through([
				PipelineStageOne::class,
				PipelineStageTwo::class,
			])->thenReturn();

		$this->assertSame('A Sample String That Is Passed Through To All The Pipes.', $result);
	}

	public function test_it_pushes_additional_pipes(): void {
		$result = $this->pipeline->send('a sample string that is passed through to all pipes.')
			->pipe(static fn (string $passable, Closure $next): string => $next(ucwords($passable)))
			->pipe([
				static fn (string $passable, Closure $next): string => $next(str_ireplace('All', 'All The', $passable)),
			])->thenReturn();

		$this->assertSame('A Sample String That Is Passed Through To All The Pipes.', $result);
	}

	public function test_it_runs_class_string_pipes_with_parameters(): void {
		$result = $this->pipeline->send('original value')
			->through(PipelineParameterizedStage::class . ':original,replaced')
			->thenReturn();

		$this->assertSame('replaced value', $result);
	}

	public function test_it_runs_invokable_object_pipes_without_a_handle_method(): void {
		$stage = new class() {
			public function __invoke(string $passable, Closure $next): string {
				return $next(ucwords($passable));
			}
		};

		$result = $this->pipeline->send('a sample string')
			->through($stage)
			->thenReturn();

		$this->assertSame('A Sample String', $result);
	}

	public function test_it_rethrows_when_an_object_pipe_has_no_callable_handler(): void {
		$this->expectException(Error::class);

		$this->pipeline->send('passable')
			->through(new class() {
			})
			->thenReturn();
	}

	public function test_it_rethrows_destination_exceptions(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Destination failed.');

		$this->pipeline->send('passable')
			->then(static fn (): never => throw new RuntimeException('Destination failed.'));
	}

	public function test_it_rethrows_pipe_exceptions(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Pipe failed.');

		$this->pipeline->send('passable')
			->through(static fn (): never => throw new RuntimeException('Pipe failed.'))
			->thenReturn();
	}

	public function test_it_requires_a_container_for_class_string_pipes(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('A container instance has not been passed to the Pipeline.');

		(new Pipeline())->send('passable')
			->through(PipelineStageOne::class)
			->thenReturn();
	}
}
