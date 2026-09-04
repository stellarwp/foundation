<?php declare(strict_types=1);

namespace StellarWP\Foundation\Container\Contracts;

/**
 * Base provider for features that read application configuration.
 */
abstract class ConfiguredProvider extends Provider
{
	public function __construct(
		Container $container,
		protected readonly Configuration $config
	) {
		parent::__construct($container);
	}
}
