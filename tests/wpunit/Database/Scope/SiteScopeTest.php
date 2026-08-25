<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\WPUnit\Database\Scope;

use StellarWP\Foundation\Database\Exceptions\DatabaseContextChanged;
use StellarWP\Foundation\Database\Scope\SiteScope;
use StellarWP\Foundation\Tests\WPUnitSupport\WPTestCase;

final class SiteScopeTest extends WPTestCase
{
	public function test_it_resolves_logical_tables_for_the_active_site(): void {
		$scope = new SiteScope($GLOBALS['wpdb']);

		$this->assertSame($GLOBALS['wpdb']->prefix . 'jobs', $scope->resolveTableName('jobs'));
		$this->assertSame($GLOBALS['wpdb']->prefix . 'wp_reports', $scope->resolveTableName('wp_reports'));
	}

	public function test_it_captures_the_current_site(): void {
		$this->assertSame(get_current_blog_id(), (new SiteScope($GLOBALS['wpdb']))->capture());
	}

	public function test_it_rejects_a_changed_site_context(): void {
		$scope    = new SiteScope($GLOBALS['wpdb']);
		$current  = get_current_blog_id();
		$expected = $current + 1;

		$this->expectException(DatabaseContextChanged::class);
		$this->expectExceptionMessage('site ' . $expected);
		$this->expectExceptionMessage('site ' . $current);

		$scope->assertCurrent($expected);
	}
}
