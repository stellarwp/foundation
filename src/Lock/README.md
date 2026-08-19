# Foundation Lock

> [!WARNING]
> **This is a read-only repository!** For pull requests or issues, see [stellarwp/foundation](https://github.com/stellarwp/foundation).

## Choosing An Implementation

`stellarwp/foundation-lock` provides the shared lock contract and
`InMemoryLock`. Both persistent implementation packages depend on this base
package, so installing either one also makes `InMemoryLock` available for
tests and process-local work.

Choose one persistent implementation for production based on where application
requests must coordinate:

| Implementation | Provided by | Use when |
| --- | --- | --- |
| `DatabaseLock` | [`stellarwp/foundation-database`](https://github.com/stellarwp/foundation-database) | WordPress requests should coordinate through the existing database |
| `RedisLock` | [`stellarwp/foundation-lock-redis`](https://github.com/stellarwp/foundation-lock-redis) | Processes or servers can coordinate through a dedicated Redis connection |

Use the included `InMemoryLock` in tests or when all coordination is confined
to one PHP process. It does not coordinate separate requests, workers, or
servers.

## Installation

Install this package directly when only the contract and `InMemoryLock` are
needed:

```shell
composer require stellarwp/foundation-lock
```

For persistent locking, install the selected implementation package from the
table above instead; Composer installs `stellarwp/foundation-lock` with it.

## Usage

`foundation-lock` defines portable lock contracts and a process-local in-memory implementation. The in-memory lock is useful for tests and single-process work, but it is not a cross-request or distributed lock.

```php
use StellarWP\Foundation\Lock\InMemoryLock;

$lock = new InMemoryLock();
```

Use both in-memory and persistent implementations through
`StellarWP\Foundation\Lock\Contracts\Lock`, as shown below. Persistent
implementations use `LockToken` ownership checks before releasing or refreshing
locks.

## Preventing Duplicate Work

Application services should depend on the shared `Lock` contract so production
can use a persistent implementation while tests use `InMemoryLock`. Include the
resource identifier in the lock name so unrelated work can proceed concurrently:

```php
use RuntimeException;
use StellarWP\Foundation\Lock\Contracts\Lock;
use Throwable;

final readonly class CatalogSynchronizer
{
    public function __construct(
        private Lock $lock
    ) {
    }

    /**
     * @param callable(): void $synchronize
     */
    public function synchronize(int $siteId, callable $synchronize): bool
    {
        $token = $this->lock->acquire(sprintf('catalog:%d:sync', $siteId), 300);

        if ($token === null) {
            return false;
        }

        try {
            $synchronize();
        } catch (Throwable $failure) {
            try {
                $this->lock->release($token);
            } catch (Throwable) {
                // Preserve the primary synchronization failure.
            }

            throw $failure;
        }

        if (! $this->lock->release($token)) {
            throw new RuntimeException('Catalog synchronization lock ownership could not be confirmed during release.');
        }

        return true;
    }
}
```

A `null` acquisition means another process already owns the lease; the caller
can skip, retry, or queue the work. A `false` release means ownership could not
be confirmed during release, so exclusive ownership may not have lasted for the
full operation.

## Expiration And Refreshing

> [!IMPORTANT]
> Locks are time-bounded leases. Mutual exclusion is guaranteed only until the token expires. Choose a TTL longer than the protected operation or refresh the lock before expiration.

`refresh()` returns a new token with an expiration of the current time plus the supplied TTL. It returns `null` if the original token no longer owns the lock:

```php
$token = $lock->refresh($token, 120);

if ($token === null) {
    // The lock expired or another process acquired it.
    return;
}
```

Refreshing must happen before the current lease expires. For a single blocking operation that cannot be refreshed safely, use a conservative TTL. Locks coordinate application processes but do not replace idempotency when interacting with external systems such as payment gateways.

## Backend Failures

Persistent implementations throw `StellarWP\Foundation\Lock\Exceptions\LockUnavailableException`
when their backend cannot provide a trustworthy result. Treat that exception as
a failure to obtain or retain the lock; do not continue the protected work
without coordination.
