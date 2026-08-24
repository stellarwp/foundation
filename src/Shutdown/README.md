# Foundation Shutdown

> [!WARNING]
> **This is a read-only repository!** For pull requests or issues, see [stellarwp/foundation](https://github.com/stellarwp/foundation).

Some work must happen before a PHP request ends but does not need to delay the
response sent to the client, such as flushing buffered logs or telemetry to a
third-party service. Foundation Shutdown lets features contribute that bounded
end-of-request work and attempts to finish supported HTTP responses before it
begins.

Tasks run once in priority order, and one failed task does not prevent later tasks
from running. Shutdown work is best effort within the current PHP process; use a
durable queue for long-running work, retries, or work that cannot safely be lost.

## Installation

```shell
composer require stellarwp/foundation-shutdown
```

## Documentation

See the [Foundation Shutdown documentation](https://foundation.nexcess.dev/components/shutdown/)
for provider registration, task contributions, priority ordering, response
finishing, failure behavior, and testing.
