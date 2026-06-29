<?php declare(strict_types=1);

namespace StellarWP\Foundation\Tests\Unit\Identifier;

use lucatume\DI52\Container as C;
use StellarWP\Foundation\Identifier\Contracts\IdentifierGenerator;
use StellarWP\Foundation\Identifier\IdentifierProvider;
use StellarWP\Foundation\Identifier\Ulid\Contracts\Entropy;
use StellarWP\Foundation\Identifier\Ulid\Contracts\MillisecondClock;
use StellarWP\Foundation\Identifier\Ulid\Contracts\UlidGenerator as UlidGeneratorContract;
use StellarWP\Foundation\Identifier\Ulid\RandomizerEntropy;
use StellarWP\Foundation\Identifier\Ulid\SystemMillisecondClock;
use StellarWP\Foundation\Identifier\Ulid\UlidGenerator;
use StellarWP\Foundation\Identifier\Ulid\UlidValidator;
use StellarWP\Foundation\Tests\Support\Fixtures\Identifier\Ulid\FixedEntropy;
use StellarWP\Foundation\Tests\Support\Fixtures\Identifier\Ulid\FixedMillisecondClock;
use StellarWP\Foundation\Tests\TestCase;

final class IdentifierProviderTest extends TestCase
{
	public function test_it_registers_identifier_services(): void {
		$this->container->register(IdentifierProvider::class);

		$this->assertInstanceOf(RandomizerEntropy::class, $this->container->get(Entropy::class));
		$this->assertInstanceOf(SystemMillisecondClock::class, $this->container->get(MillisecondClock::class));
		$this->assertInstanceOf(UlidGenerator::class, $this->container->get(UlidGeneratorContract::class));
		$this->assertInstanceOf(UlidGenerator::class, $this->container->get(UlidGenerator::class));
		$this->assertInstanceOf(UlidValidator::class, $this->container->get(UlidValidator::class));
	}

	public function test_consumers_can_bind_ulids_as_the_default_identifier_strategy(): void {
		$this->container->register(IdentifierProvider::class);
		$this->container->bind(IdentifierGenerator::class, static fn (C $c): UlidGeneratorContract => $c->get(UlidGeneratorContract::class));

		$this->assertInstanceOf(UlidGeneratorContract::class, $this->container->get(IdentifierGenerator::class));
	}

	public function test_it_does_not_bind_the_generic_identifier_contract_by_default(): void {
		$this->container->register(IdentifierProvider::class);

		$this->assertFalse($this->container->has(IdentifierGenerator::class));
	}

	public function test_it_generates_ulids_with_configured_provider_dependencies(): void {
		$entropy = new FixedEntropy(str_repeat("\0", 10));

		$this->container->register(IdentifierProvider::class);
		$this->container->bind(Entropy::class, $entropy);
		$this->container->bind(MillisecondClock::class, new FixedMillisecondClock(1_469_918_176_385));

		$this->assertSame('01ARYZ6S410000000000000000', $this->container->get(UlidGeneratorContract::class)->generate());
		$this->assertSame([10], $entropy->requestedLengths());
	}
}
