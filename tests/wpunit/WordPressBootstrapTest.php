<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\WPUnit;

use StellarWP\Foundation\Tests\WPUnitSupport\WPTestCase;

final class WordPressBootstrapTest extends WPTestCase
{
	public function test_it_loads_wordpress(): void {
		$this->assertTrue(function_exists('add_action'));
		$this->assertTrue(isset($GLOBALS['wpdb']));
	}
}
