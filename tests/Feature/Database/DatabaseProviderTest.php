<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Feature\Database;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use StellarWP\Foundation\Database\DatabaseProvider;
use StellarWP\Foundation\Tests\TestCase;

final class DatabaseProviderTest extends TestCase
{
	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function test_it_does_not_load_wp_cli_during_registration(): void {
		$this->assertFalse(class_exists('WP_CLI_Command', false));

		$this->container->register(DatabaseProvider::class);

		$this->assertFalse(class_exists('WP_CLI_Command', false));
	}
}
