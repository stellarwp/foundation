# Foundation WP-CLI

> [!WARNING]
> **This is a read-only repository!** For pull requests or issues, see [stellarwp/foundation](https://github.com/stellarwp/foundation).

Foundation WP-CLI provides a container-aware command base class and a shared
provider for registering application commands during WP-CLI bootstrap.

## Installation

```shell
composer require stellarwp/foundation-wpcli
```

WP-CLI supplies its runtime classes when commands execute, so applications do
not normally need to install `wp-cli/wp-cli` separately.

## Documentation

See the [Foundation WP-CLI documentation](https://foundation.stellarwp.com/components/wp-cli/)
for command generation, provider registration, prefixes, arguments, failure
behavior, and testing.
