<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\WPUnit\WPCli;

use Mockery;
use phpmock\mockery\PHPMockery;
use StellarWP\Foundation\Tests\Support\Fixtures\WPCli\TestCommand;
use StellarWP\Foundation\Tests\WPUnitSupport\WPTestCase;

final class CommandTest extends WPTestCase
{
	protected function setUp(): void {
		parent::setUp();

		PHPMockery::define('StellarWP\Foundation\WPCli', 'wp_cache_supports');
	}

	protected function tearDown(): void {
		Mockery::close();

		parent::tearDown();
	}

	public function test_batch_cleanup_clears_saved_queries_and_cache_without_resetting_hooks(): void {
		global $wpdb;

		$command = new TestCommand();
		$queries = $wpdb->queries;
		$filter  = static fn (): string => 'kept';
		add_filter('foundation_runtime_cleanup_filter', $filter);
		do_action('foundation_runtime_cleanup_action');

		try {
			foreach (['first', 'second'] as $batch) {
				wp_cache_set('batch', $batch, 'foundation-runtime-cleanup');
				$wpdb->queries = [['SELECT 1', 0.01, 'saved query']];

				$command->clearRuntimeCache();

				$this->assertSame([], $wpdb->queries);
				$this->assertFalse(wp_cache_get('batch', 'foundation-runtime-cleanup'));
				$this->assertSame(1, did_action('foundation_runtime_cleanup_action'));
				$this->assertSame('kept', apply_filters('foundation_runtime_cleanup_filter', ''));
			}
		} finally {
			$wpdb->queries = $queries;
			remove_filter('foundation_runtime_cleanup_filter', $filter);
		}
	}

	public function test_an_unsupported_runtime_flush_preserves_cached_data(): void {
		global $wpdb;

		PHPMockery::mock('StellarWP\Foundation\WPCli', 'wp_cache_supports')
			->once()->with('flush_runtime')->andReturn(false);

		$queries = $wpdb->queries;
		wp_cache_set('keep', 'shared value', 'foundation-runtime-cleanup');
		$wpdb->queries = [['SELECT 1', 0.01, 'saved query']];

		try {
			(new TestCommand())->clearRuntimeCache();

			$this->assertSame([], $wpdb->queries);
			$this->assertSame('shared value', wp_cache_get('keep', 'foundation-runtime-cleanup'));
		} finally {
			$wpdb->queries = $queries;
			wp_cache_delete('keep', 'foundation-runtime-cleanup');
		}
	}
}
