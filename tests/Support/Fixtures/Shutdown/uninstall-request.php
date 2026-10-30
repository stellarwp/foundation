<?php declare(strict_types=1);

use StellarWP\Foundation\Container\Configuration\ArrayConfiguration;
use StellarWP\Foundation\Container\ContainerFactory;
use StellarWP\Foundation\Shutdown\Contracts\ShutdownRunner;
use StellarWP\Foundation\Shutdown\ShutdownProvider;

require dirname(__DIR__, 4) . '/vendor/autoload.php';
$arguments = $_SERVER['argv'];
// Load the real WordPress hook and installation APIs without sharing process constants with the suite.
require $arguments[1] . '/wp-includes/plugin.php';
require $arguments[1] . '/wp-includes/load.php';

if ($arguments[2] === 'before') {
	define('WP_UNINSTALL_PLUGIN', 'example/example.php');
}

$container = (new ContainerFactory())->create(new ArrayConfiguration([]));
$container->register(ShutdownProvider::class);
$container->singleton(ShutdownRunner::class, static function (): ShutdownRunner {
	throw new RuntimeException('Shutdown must not resolve the runner during uninstall.');
});

if ($arguments[2] === 'after') {
	define('WP_UNINSTALL_PLUGIN', 'example/example.php');
}

do_action('shutdown');
echo 'skipped';
