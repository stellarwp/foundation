# Foundation CLI

> [!WARNING]
> **This is a read-only repository!** For pull requests or issues, see [stellarwp/foundation](https://github.com/stellarwp/foundation).

Foundation CLI tooling for generating application code and maintaining the Foundation monorepo.

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

## Generators

List all available commands:

```bash
vendor/bin/foundation list
```

Foundation CLI includes generators for packages that own generated class shapes. For example, the WPCli package provides a generator for command classes:

```bash
vendor/bin/foundation make:wpcli-command Sync_Products_Command
```

Do not add `StellarWP\Foundation\Cli\CliProvider` to the consuming WordPress plugin's provider list. That provider only boots the Foundation Symfony Console application for the `foundation` binary. Register generated WP-CLI commands from the plugin's own WP-CLI provider using `stellarwp/foundation-wpcli`.

See the WPCli package README for WP-CLI generator behavior, options, and stub overrides.

## Foundation Monorepo Maintenance

The `package:create` command is for maintainers working inside the Foundation monorepo. It creates local split-package scaffolding and can create GitHub repositories for Foundation split packages.

Create a split repository for a new Foundation package:

```bash
vendor/bin/foundation package:create Log
```

If the package does not exist yet, the command asks whether to create the local scaffold in `src/<Package>` and asks for the Composer package name. For example, `WPCli` defaults to `stellarwp/foundation-wpcli`. After scaffolding, it runs `composer monorepo merge` so the root package metadata includes the new split package.

By default, commands that change external systems run as a dry run. Pass `--apply` to execute the generated repository actions.

In the Foundation monorepo, the root Composer script can also be used:

```bash
composer run foundation -- package:create Log
```

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
	public function __construct() {
		parent::__construct('cache:clear');
	}

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
