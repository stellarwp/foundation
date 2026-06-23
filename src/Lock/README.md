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
