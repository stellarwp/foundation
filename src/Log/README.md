# Foundation Log

> [!WARNING]
> **This is a read-only repository!** For pull requests or issues, see [stellarwp/foundation](https://github.com/stellarwp/foundation).

Foundation Log configures [Monolog](https://github.com/Seldaek/monolog) behind
the standard `Psr\Log\LoggerInterface`. It includes console, PHP error log,
stacked, and null channels.

## Installation

```shell
composer require stellarwp/foundation-log
```

Supports Monolog `^2.11 || ^3.10`, using the version compatible with the
application's other dependencies.

## Documentation

See the [Foundation Log documentation](https://foundation.nexcess.dev/components/log/)
for channel configuration, structured logging, failure behavior, and testing.
