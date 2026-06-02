# Foundation Log

> [!WARNING]
> **This is a read-only repository!** For pull requests or issues, see [stellarwp/foundation](https://github.com/stellarwp/foundation).

A logging library using [Monolog](https://github.com/Seldaek/monolog) that implements the `Psr\Log\LoggerInterface` interface.

## Installation


```shell
composer require stellarwp/foundation-log
```

If using [stellarwp/foundation-container](https://github.com/stellarwp/foundation-container), create a `config.php` and register
it in the container with:

```php
$this->container->bind(Dot::class, new Dot(require_once dirname(__FILE__) . '/config.php'));
```

The config.php file maps environment variables, either from an `.env` file if you configured [phpdotenv](https://github.com/vlucas/phpdotenv), or manually set, e.g.

```php
<?php declare(strict_types=1);

return [
	'log'             => [
		'level'    => $_ENV['APP_LOG_LEVEL'] ?? 'debug',
		'channel'  => $_ENV['APP_LOG_CHANNEL'] ?? 'null', // console, errorlog, stack (both console and errorlog) or null
		'channels' => [
			'errorlog' => [],
			'console'  => [
				'with' => [
					'stream' => 'php://stdout',
				],
			],
			'stack'    => [
				'with' => [
					'stream' => 'php://stdout',
				],
			],
		],
	],
];
```

Then, include the [LogProvider.php](./LogProvider.php) in your
application and call the `register` method. Anytime you inject a `Psr\Log\LoggerInterface` instance into another class, it will use 
your provided configuration.
