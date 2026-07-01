<?php declare(strict_types=1);

namespace StellarWP\Foundation\Database\Table;

/**
 * Supported MySQL index types for table definitions.
 */
final class IndexType
{
	public const string PRIMARY = 'primary';
	public const string UNIQUE  = 'unique';
	public const string KEY     = 'key';

	private function __construct() {
	}
}
