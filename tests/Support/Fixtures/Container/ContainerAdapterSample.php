<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Support\Fixtures\Container;

final readonly class ContainerAdapterSample
{
	public function __construct(
		public string $value
	) {
	}

	public function read(): string {
		return $this->value;
	}
}
