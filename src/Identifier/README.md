# Foundation Identifier

> [!WARNING]
> **This is a read-only repository!** For pull requests or issues, see [stellarwp/foundation](https://github.com/stellarwp/foundation).

## Installation

```shell
composer require stellarwp/foundation-identifier
```

## Usage

`foundation-identifier` provides injectable identifier generation contracts and a ULID implementation.

```php
use StellarWP\Foundation\Identifier\Ulid\Contracts\UlidGenerator;

final class CreateJob
{
	public function __construct(
		private readonly UlidGenerator $identifiers
	) {
	}

	public function __invoke(): string {
		return $this->identifiers->generate();
	}
}
```

Consumers using Foundation's container can register `StellarWP\Foundation\Identifier\IdentifierProvider` to make the ULID services available. The provider does not bind `IdentifierGenerator` globally; applications should decide which identifier strategy satisfies that contract.

The provider binds `StellarWP\Foundation\Identifier\Ulid\Contracts\UlidGenerator` to the default ULID implementation. If ULIDs should be the application's default identifier strategy, bind the broader `IdentifierGenerator` contract in an application provider:

```php
use lucatume\DI52\Container as C;
use StellarWP\Foundation\Container\Contracts\Provider as ServiceProvider;
use StellarWP\Foundation\Identifier\Contracts\IdentifierGenerator;
use StellarWP\Foundation\Identifier\IdentifierProvider as FoundationIdentifierProvider;
use StellarWP\Foundation\Identifier\Ulid\Contracts\UlidGenerator;

final class IdentifierProvider extends ServiceProvider
{
	public function register(): void {
		$this->container->register(FoundationIdentifierProvider::class);
		$this->container->bind(IdentifierGenerator::class, static fn (C $c): UlidGenerator => $c->get(UlidGenerator::class));
	}
}
```

The default generator returns canonical uppercase ULIDs, such as `01ARYZ6S410000000000000000`. Use `StellarWP\Foundation\Identifier\Ulid\UlidValidator` when accepting ULIDs from external input.
