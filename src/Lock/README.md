# Foundation Lock

> [!WARNING]
> **This is a read-only repository!** For pull requests or issues, see [stellarwp/foundation](https://github.com/stellarwp/foundation).

## Installation

```shell
composer require stellarwp/foundation-lock
```

## Usage

`foundation-lock` defines portable lock contracts and a process-local in-memory implementation. The in-memory lock is useful for tests and single-process work, but it is not a cross-request or distributed lock.

```php
use StellarWP\Foundation\Lock\InMemoryLock;

$lock = new InMemoryLock();

$token = $lock->acquire('queue:sync', 60);

if ($token === null) {
    return;
}

try {
    // Run exclusive work here.
} finally {
    $lock->release($token);
}
```

Persistent implementations, such as database-backed locks, should implement `StellarWP\Foundation\Lock\Contracts\Lock` and use `LockToken` ownership checks before releasing or refreshing locks.

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
