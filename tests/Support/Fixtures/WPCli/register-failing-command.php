<?php declare(strict_types=1);

use StellarWP\Foundation\Tests\Support\Fixtures\WPCli\TestCommand;
use StellarWP\Foundation\WPCli\CommandContext;
use StellarWP\Foundation\WPCli\ValueObjects\CommandPrefix;

require_once dirname(__DIR__, 4) . '/vendor/autoload.php';

WP_CLI::add_hook('after_wp_load', static function (): void {
	$command          = new TestCommand();
	$command->failure = new RuntimeException('Import could not complete.', 42, new RuntimeException('Database rejected the row.'));
	$command->register(new CommandContext(new CommandPrefix('foundation-failure')));
});
