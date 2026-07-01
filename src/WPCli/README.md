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

## Generating Commands

If the project also installs `stellarwp/foundation-cli` as a development dependency, scaffold a WP-CLI command class in a consuming WordPress project:

```bash
vendor/bin/foundation make:wpcli-command Sync_Products_Command
```

If the consuming project has a Composer script named `foundation` that points to the installed Foundation binary, it can also run `composer run foundation -- make:wpcli-command Sync_Products_Command`.

The generator reads the project's `autoload.psr-4` namespaces from `composer.json` and writes a Snake_Case command class under `Cli/Commands` inside the default PSR-4 root. When `--namespace` is passed, the output path is resolved from the matching PSR-4 root unless `--path` is also passed.

For example, a project with this Composer autoload entry:

```json
{
    "autoload": {
        "psr-4": {
            "Acme\\Plugin\\": "src"
        }
    }
}
```

will generate:

```text
src/Cli/Commands/Sync_Products_Command.php
```

with namespace:

```php
Acme\Plugin\Cli\Commands
```

The generated class extends `StellarWP\Foundation\WPCli\Command` and includes example positional, associative, and flag arguments using constants.

When generated through `foundation-cli`, projects using Strauss with `extra.strauss.namespace_prefix` receive prefixed Foundation imports automatically.

Available options:

```bash
vendor/bin/foundation make:wpcli-command Sync_Products_Command --namespace="Acme\\Plugin\\Cli" --path=src/Cli --subcommand=sync-products --description="Sync products." --force
```

Project stub overrides live under:

```text
foundation/stubs/wpcli/command.stub
```

When present, the override is used instead of the default stub from the `foundation-wpcli` package.

Override stubs should use the same context-aware placeholders as the default stub when writing PHP literals. For example, use `{{ description_php }}` and `{{ subcommand_php }}` for values returned from PHP methods, and `{{ foundation_wpcli_command }}` for the Foundation command import so Strauss-prefixed projects keep working.

```php
<?php declare(strict_types=1);

namespace {{ namespace }};

use {{ foundation_wpcli_command }};

final class {{ class }} extends Command
{
	public function runCommand( array $args = [], array $assocArgs = [] ): int {
		// Run the command using services from $this->container.

		return self::SUCCESS;
	}

	protected function subcommand(): string {
		return {{ subcommand_php }};
	}

	protected function description(): string {
		return {{ description_php }};
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

Applications should register `StellarWP\Foundation\WPCli\Provider` once in the application provider list. Feature-specific providers can then add command classes to the shared command list with `mergeArrayVar()`.

Do not register `StellarWP\Foundation\Cli\CliProvider` in a WordPress plugin. That provider belongs to the developer-facing `foundation` console binary, not plugin runtime bootstrap.

Generated command classes use Strauss-prefixed Foundation imports automatically when `extra.strauss.namespace_prefix` is configured. Handwritten provider code is still application code, so projects using Strauss with `update_call_sites=false` may need to use their prefixed Foundation namespace in the imports below.

```php
<?php declare(strict_types=1);

namespace Acme\App\Cli;

use StellarWP\Foundation\Container\Contracts\Provider;
use StellarWP\Foundation\WPCli\WPCliProvider as WPCliProvider;

final class Wp_Cli_Provider extends Provider
{
	private const string COMMAND_PREFIX = 'acme';

	/**
	 * @var list<class-string<Command>>
	 */
	private const array COMMANDS = [
		Sync_Command::class,
	];

	public function register(): void {
		$this->container->singleton( WPCliProvider::COMMAND_PREFIX, self::COMMAND_PREFIX );
		$this->container->mergeArrayVar( WPCliProvider::COMMANDS, self::COMMANDS );
	}
}
```

The Foundation WP-CLI provider uses `cli_init` internally so commands are registered only during WP-CLI command bootstrap, after all application providers have had a chance to add command classes.

If your application wants a different default command prefix without a feature-specific CLI provider, bind `WPCliProvider::COMMAND_PREFIX` before WP-CLI's `cli_init` hook runs.
