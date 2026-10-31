# Foundation CLI

> [!WARNING]
> **This is a read-only repository!** For pull requests or issues, see [stellarwp/foundation](https://github.com/stellarwp/foundation).

Foundation CLI provides developer tooling for generating Foundation-aware project code and running custom project commands.

## Installation

Install this package as a development dependency in consuming projects:

```shell
composer require --dev stellarwp/foundation-cli
```

Require the runtime packages used by generated code separately in your project's
`require`. CLI's dependencies can make missing runtime requirements go unnoticed
locally; a production `composer install --no-dev` excludes packages needed only by
CLI. See the [installation guide](https://foundation.nexcess.dev/start/install-foundation/#install-developer-tooling-separately)
for dependency setup and keeping CLI out of plugin ZIPs.

## Documentation

See the [Foundation CLI documentation](https://foundation.nexcess.dev/tooling/foundation-cli/)
for project generators, stub overrides, Strauss support, and custom commands.
