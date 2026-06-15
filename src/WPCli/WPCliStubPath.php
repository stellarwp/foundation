<?php declare(strict_types=1);

namespace StellarWP\Foundation\WPCli;

/**
 * Provides default stub paths for code generators that scaffold WP-CLI classes.
 *
 * Foundation CLI owns rendering and writing generated files; the WPCli package
 * owns the default templates for classes that extend its public APIs.
 */
final class WPCliStubPath
{
	public static function command(): string {
		return __DIR__ . '/stubs/command.stub';
	}
}
