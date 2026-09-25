---
title: Install Foundation
description: Install split runtime packages and keep developer tooling out of production archives.
sidebar:
  order: 2
---

Foundation requires PHP 8.3 or newer. Projects should generally install the split packages they use, with runtime components in `require` and developer tooling in `require-dev`.

:::caution[Building a production WordPress plugin?]
Use split packages for distributable WordPress plugins. The aggregate `stellarwp/foundation` package physically includes Foundation CLI and its binary, so `composer install --no-dev` will not remove that tooling from an aggregate installation.

Require only the split runtime packages the plugin ships, then install `stellarwp/foundation-cli` separately with `--dev`.
:::

## Install runtime components

Install the packages the application uses in production:

```shell
composer require \
  stellarwp/foundation-container \
  stellarwp/foundation-log \
  stellarwp/foundation-lock
```

Add other components as the application needs them rather than installing integrations speculatively.

## Install developer tooling separately

The CLI package generates project code. Install it for development and exclude it from production WordPress plugin ZIPs:

```shell
composer require --dev stellarwp/foundation-cli
```

:::caution[CLI dependencies can hide missing runtime requirements]
Installing CLI also installs Database, Migrations, and WP-CLI integration for its generators. Their availability in your development environment does not make them production dependencies. If your application uses a package installed only through the development CLI, `composer install --no-dev` excludes it and the application can fail with missing classes.

Explicitly require the runtime packages your application uses, even when Composer has already installed them through CLI. For example, a project shipping migrations needs:

```shell
composer require stellarwp/foundation-migrations
```

Composer keeps one shared installation and retains that package for production. Installing CLI before or after the runtime package makes no difference.
:::

Build production archives from a clean staging directory with development dependencies excluded:

```shell
composer install --no-dev --classmap-authoritative
```

Check the resulting plugin ZIP contains neither the `stellarwp/foundation-cli` package nor its `vendor/bin/foundation` binary. Shared dependencies remain when required by runtime packages. Shipped WP-CLI commands are operational functionality: require `stellarwp/foundation-wpcli` normally when your plugin uses them.

## Deliberately choose the aggregate package

The aggregate includes CLI in production, even with `--no-dev`. Use split packages for distributable plugin ZIPs.

For an environment that intentionally keeps every component and developer tooling, install the aggregate:

```shell
composer require stellarwp/foundation
```

This provides every runtime component and the developer CLI at `vendor/bin/foundation`. It is convenient for a complete application or development environment, but it is not the lean installation path for a distributable WordPress plugin.

## Load Composer before Foundation

Load Composer's generated autoloader from the application entrypoint before constructing the container or registering providers:

```php
<?php declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';
```

WordPress projects may use a build tool such as Strauss to prefix production dependencies. That packaging step does not change which Foundation packages belong in `require` or `require-dev`.
