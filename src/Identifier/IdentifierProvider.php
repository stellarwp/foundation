<?php declare(strict_types=1);

namespace StellarWP\Foundation\Identifier;

use lucatume\DI52\Container as C;
use Random\Engine\Secure;
use Random\Randomizer;
use StellarWP\Foundation\Container\Contracts\Provider;
use StellarWP\Foundation\Identifier\Ulid\Contracts\Entropy;
use StellarWP\Foundation\Identifier\Ulid\Contracts\MillisecondClock;
use StellarWP\Foundation\Identifier\Ulid\Contracts\UlidGenerator as UlidGeneratorContract;
use StellarWP\Foundation\Identifier\Ulid\RandomizerEntropy;
use StellarWP\Foundation\Identifier\Ulid\SystemMillisecondClock;
use StellarWP\Foundation\Identifier\Ulid\UlidGenerator;
use StellarWP\Foundation\Identifier\Ulid\UlidValidator;

/**
 * Registers the default identifier services for consumers that use Foundation's container.
 */
final class IdentifierProvider extends Provider
{
	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		$this->container->singleton(RandomizerEntropy::class);
		$this->container->singleton(SystemMillisecondClock::class);
		$this->container->singleton(UlidGenerator::class);
		$this->container->singleton(UlidValidator::class);

		$this->container->when(RandomizerEntropy::class)
			->needs(Randomizer::class)
			->give(static fn (): Randomizer => new Randomizer(new Secure()));

		$this->container->bind(Entropy::class, static fn (C $c): RandomizerEntropy => $c->get(RandomizerEntropy::class));
		$this->container->bind(MillisecondClock::class, static fn (C $c): SystemMillisecondClock => $c->get(SystemMillisecondClock::class));
		$this->container->bind(UlidGeneratorContract::class, static fn (C $c): UlidGenerator => $c->get(UlidGenerator::class));
	}
}
