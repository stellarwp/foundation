# Foundation CLI

> [!WARNING]
> **This is a read-only repository!** For pull requests or issues, see [stellarwp/foundation](https://github.com/stellarwp/foundation).

Foundation CLI tooling for maintaining the Foundation monorepo and split repositories.

## Usage

List all available commands:
```bash
composer run foundation -- list
```

Create a split repository for a new package:
```bash
composer run foundation -- package:create Log
```

By default, commands that change external systems run as a dry run. Pass `--apply` to execute the generated actions.

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
