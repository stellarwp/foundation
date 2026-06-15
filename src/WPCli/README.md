# Foundation WP-CLI

> [!WARNING]
> **This is a read-only repository!** For pull requests or issues, see [stellarwp/foundation](https://github.com/stellarwp/foundation).

Foundation helpers for building WP-CLI commands with the Foundation container.

## Installation

```shell
composer require stellarwp/foundation-wpcli
```

WP-CLI is expected to provide the `WP_CLI` and `WP_CLI_Command` runtime classes. This package includes `wp-cli/wp-cli` as a development dependency for tests and static analysis, but applications normally do not need to install it separately when running inside WP-CLI.

Install `stellarwp/foundation-wpcli` as a normal dependency when the plugin ships WP-CLI commands. Install `stellarwp/foundation-cli` separately with `composer require --dev stellarwp/foundation-cli` only when developers need generators such as `make:wpcli-command`.

## Commands

Extend `StellarWP\Foundation\WPCli\Command` for commands that should receive the Foundation container.

If the project also installs `stellarwp/foundation-cli` as a development dependency, scaffold a command with:

```bash
composer run foundation -- make:wpcli-command Sync_Products_Command
```

Generated WP project command classes use Snake_Case names and WordPress formatting. The default command stub includes examples for positional, associative, and flag arguments.

When generated through `foundation-cli`, projects using Strauss with `extra.strauss.namespace_prefix` receive prefixed Foundation imports automatically.

```php
<?php declare(strict_types=1);

namespace Acme\App\Cli;

use StellarWP\Foundation\WPCli\Command;

final class SyncCommand extends Command
{
	public function runCommand( array $args = [], array $assocArgs = [] ): int {
		// Run the command using services from $this->container.

		return self::SUCCESS;
	}

	protected function subcommand(): string {
		return 'sync';
	}

	protected function description(): string {
		return 'Sync Acme data.';
	}

	protected function arguments(): array {
		return [
			[
				'type'        => self::FLAG,
				'name'        => 'dry-run',
				'description' => 'Preview the sync without writing changes.',
				'optional'    => true,
			],
		];
	}
}
```

## Provider Setup

Applications should register their own provider so they control the command namespace and command list.

Do not register `StellarWP\Foundation\Cli\CliProvider` in a WordPress plugin. That provider belongs to the developer-facing `foundation` console binary, not plugin runtime bootstrap.

```php
<?php declare(strict_types=1);

namespace Acme\App\Cli;

use StellarWP\Foundation\Container\Contracts\Provider;
use StellarWP\Foundation\WPCli\Command;
use StellarWP\Foundation\WPCli\TimestampedLogger;
use WP_CLI;
use WP_CLI\Loggers\Regular;

final class WpCliProvider extends Provider
{
	private const string COMMAND_PREFIX = 'acme';

	/**
	 * @var list<class-string<Command>>
	 */
	private const array COMMANDS = [
		SyncCommand::class,
	];

	public function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		$this->configureCommands();
		$this->registerTimestampedLogger();

		add_action( 'cli_init', function (): void {
			foreach ( self::COMMANDS as $commandClass ) {
				$command = $this->container->get( $commandClass );

				if ( $command instanceof Command ) {
					$command->register();
				}
			}
		}, 0, 0 );
	}

	private function configureCommands(): void {
		foreach ( self::COMMANDS as $commandClass ) {
			$this->container->when( $commandClass )
				->needs( '$commandPrefix' )
				->give( self::COMMAND_PREFIX );
		}
	}

	private function registerTimestampedLogger(): void {
		$wpCliLogger = WP_CLI::get_logger();

		if ( $wpCliLogger instanceof Regular ) {
			WP_CLI::set_logger( new TimestampedLogger( $wpCliLogger ) );
		}
	}
}
```

Use `cli_init` so commands are registered only during WP-CLI command bootstrap, after WordPress has loaded enough for plugin providers and hooks to be available.

If your application does not use WordPress hooks during bootstrap, call the command registration loop at the point where WP-CLI is active and your container has been configured.
