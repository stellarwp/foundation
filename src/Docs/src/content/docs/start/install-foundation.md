---
title: Install Foundation
description: Install the aggregate Foundation package or select individual runtime components.
sidebar:
  order: 2
---

Foundation requires PHP 8.3 or newer. Composer resolves the dependencies required by the aggregate package or selected components.

:::caution[Building a production WordPress plugin?]
Do not install `stellarwp/foundation` only to get the Foundation CLI. The aggregate package includes the developer CLI as a normal dependency, so `composer install --no-dev` will not remove it.

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

The CLI package generates project code and normally does not belong in a production WordPress plugin archive:

```shell
composer require --dev stellarwp/foundation-cli
```

Production builds can then exclude developer dependencies:

```shell
composer install --no-dev --classmap-authoritative
```

If generated code extends a runtime Foundation class, install that runtime package normally. For example, generated WP-CLI commands require `stellarwp/foundation-wpcli` in `require`.

## Install every component

Install the aggregate package when one application intentionally owns the complete Foundation installation and expects to use most components:

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

## Continue

[Configure the container](/start/configure-the-container/) that providers and application services will share.
