# Foundation CLI

> [!WARNING]
> **This is a read-only repository!** For pull requests or issues, see [stellarwp/foundation](https://github.com/stellarwp/foundation).

Foundation CLI tooling for maintaining the Foundation monorepo and split repositories.

## Installation

Install this package as a development dependency in consuming projects:

```bash
composer require --dev stellarwp/foundation-cli
```

`foundation-cli` is build-time tooling. It should not be registered in a WordPress plugin application and should not be packaged in production plugin zips. Use Composer's `--no-dev` install mode for production builds so the CLI, Symfony Console, generators, and local tooling stay out of the runtime artifact.

If a generated WP-CLI command ships with the plugin, install `stellarwp/foundation-wpcli` as a normal runtime dependency:

```bash
composer require stellarwp/foundation-wpcli
```

The CLI package itself requires `foundation-wpcli` because its WP-CLI generator uses the default command stub owned by that runtime package.

## Usage

List all available commands:
```bash
composer run foundation -- list
```

Create a split repository for a new package:
```bash
composer run foundation -- package:create Log
```

If the package does not exist yet, the command asks whether to create the local scaffold in `src/<Package>` and asks for the Composer package name. For example, `WPCli` defaults to `stellarwp/foundation-wpcli`. After scaffolding, it runs `composer monorepo merge` so the root package metadata includes the new split package.

By default, commands that change external systems run as a dry run. Pass `--apply` to execute the generated repository actions.

## Generators

Generate a WP-CLI command class in a consuming WordPress project:

```bash
composer run foundation -- make:wpcli-command Sync_Products_Command
```

This assumes the consuming project has a Composer script named `foundation` that points to the installed Foundation binary. Without a script, call `vendor/bin/foundation` directly.

The generator reads the project's first `autoload.psr-4` namespace from `composer.json` and writes a Snake_Case command class under `src/Cli/Commands` by default.

Do not add `StellarWP\Foundation\Cli\CliProvider` to the consuming WordPress plugin's provider list. That provider only boots the Foundation Symfony Console application for the `foundation` binary. Register generated WP-CLI commands from the plugin's own WP-CLI provider using `stellarwp/foundation-wpcli`.

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

Available options:

```bash
composer run foundation -- make:wpcli-command Sync_Products_Command --namespace="Acme\\Plugin\\Cli" --path=src/Cli --subcommand=sync-products --description="Sync products." --force
```

Project stub overrides live under:

```text
foundation/stubs/wpcli/command.stub
```

When present, the override is used instead of the default stub from the `foundation-wpcli` package.

## Custom Commands

Applications can build their own Foundation CLI by creating Symfony Console commands and registering them with `StellarWP\Foundation\Cli\Application`.

```php
<?php declare(strict_types=1);

namespace Acme\App\Cli;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class CacheClearCommand extends Command
{
	protected static $defaultName = 'cache:clear';

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$output->writeln('Cache cleared.');

		return Command::SUCCESS;
	}
}
```

For one or more related commands, group them behind a command provider.

```php
<?php declare(strict_types=1);

namespace Acme\App\Cli;

use StellarWP\Foundation\Cli\Contracts\CommandProvider;

final class AppCommandProvider implements CommandProvider
{
	public function commands(): iterable {
		yield new CacheClearCommand();
	}
}
```

Then boot an application with your provider.

```php
<?php declare(strict_types=1);

use Acme\App\Cli\AppCommandProvider;
use StellarWP\Foundation\Cli\Application;

require __DIR__ . '/vendor/autoload.php';

$application = new Application(commandProviders: [
	new AppCommandProvider(),
]);

exit($application->run());
```

When commands need shared services, register them in your container and pass constructed commands or command providers into the `Application`.
