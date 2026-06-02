# Foundation Container

> [!WARNING]
> **This is a read-only repository!** For pull requests or issues, see [stellarwp/foundation](https://github.com/stellarwp/foundation).

The DI Container configuration and Service Provider implementation, utilizing
[di52](https://github.com/lucatume/di52).

## Installation

```shell
composer require stellarwp/foundation-container
```

## Container Configuration

Create a new ContainerAdapter, by passing in an instance of di52:

```php
<?php declare(strict_types=1);

namespace My\App;

use lucatume\DI52\Container;
use StellarWP\Foundation\Container\ContainerAdapter;

// Optionally, use the included vlucas/phpdotenv to load environment variables before the container.
$path = __DIR__;

if ( file_exists( $path . '/.env' )  ) {
    $dotenv = Dotenv::createImmutable( $path );
    $dotenv->load();
}

// This implements the Contracts/Container.php interface.
$container = new ContainerAdapter(new Container());

// Bind the concrete to the interface, so anytime we ask for a container we get this one.
$container->bind(Container::class, $container);

// Register our project's configuration. See "Making a config.php" below for more detail.
$container->bind(Dot::class, new Dot(require_once dirname(__FILE__) . '/config.php'));

// Register any service providers in the container.
$providers = [
   StellarWP\YourProject\ServiceProvider::class,
   // as many as you have made... 
];

foreach ( $providers as $provider ) {
    $container->register($provider);
}
```

Here is an example Service Provider:

```php
<?php declare(strict_types=1);

namespace StellarWP\YourProject\Client;

use lucatume\DI52\ContainerException;
use StellarWP\Foundation\Container\Contracts\Provider;
use StellarWP\Foundation\Storage\Contracts\Storage;
use StellarWP\Foundation\Storage\Drivers\LocalStorage;

final class ServiceProvider extends Provider
{
	/**
	 * @throws ContainerException
	 */
	public function register(): void {
		$this->container->bind(Storage::class, LocalStorage::class);
		$this->container->when(LocalStorage::class)
						->needs('$storagePath')
						->give($this->config->get('storage_path'));
	}
}
```

## Environment Variable Configuration

This library uses the [Dot](https://github.com/adbario/php-dot-notation) package to set and fetch configuration
values, which are initially provided via Environment Variables, either manually set or via an `.env` file
utilizing [vlucas/phpdotenv](https://github.com/vlucas/phpdotenv) to read them.

Each Service Provider will have access to Dot via the `$this->config` property, it is best practice to only
access configuration variables from a Service Provider and never in your concrete classes.

### Making a config.php

A sample config.php for a project. Note: we fall back to sane defaults if the environment variable isn't available.

```php
<?php declare(strict_types=1);

return [
	'some_key'    => $_ENV['SOME_KEY'] ?? '',
	'log'             => [
		'level'    => $_ENV['LOG_LEVEL'] ?? 'debug',
		'channel'  => $_ENV['LOG_CHANNEL'] ?? 'null',
		'channels' => [
			'errorlog' => [],
			'console'  => [
				'with' => [
					'stream' => 'php://stdout',
				],
			],
		],
	],
];
```

Inside a Provider, we can then access deep variables with dot notation, e.g.

```php
// Get the console log stream type from config.php.
$stream = $this->config->get('log.channels.console.with.stream');

// Get the log level.
$level = $this->config->get('log.level');
```

> 💡 If using a `config.php` in a WordPress plugin, simply add your configured $_ENV vars in your wp-config.php.

```php
// Inside wp-config.php

// App configuration.
$_ENV['SOME_KEY'] = 'abcd-1234'; 
$_ENV['LOG_LEVEL'] = 'info';
$_ENV['LOG_CHANNEL'] = 'errorlog';
```

