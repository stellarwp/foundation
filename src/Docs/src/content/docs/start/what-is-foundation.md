---
title: What Foundation Is
description: Understand when to use the Foundation aggregate package or one of its focused components.
sidebar:
  order: 1
---

Foundation is a Composer monorepo of reusable PHP components maintained for libraries and WordPress plugin ecosystems. It provides common application infrastructure without requiring every project to invent its own container, logging, locking, database, identifier, pipeline, shutdown, view, or command conventions.

Foundation is primarily developed for internal Nexcess projects. Its packages are publicly available and designed to remain reusable, but the needs of Nexcess applications will primarily drive changes, priorities, and the project roadmap.

Each component is published to its own read-only repository. A project can install the aggregate `stellarwp/foundation` package or require only the components it uses.

:::caution[Choose dependencies before installing]
For a distributable WordPress plugin, install split runtime packages and require `stellarwp/foundation-cli` with `--dev`. The aggregate package includes the developer CLI in its normal installation; `composer install --no-dev` does not remove it.
:::

## Choose individual components

Use split packages when a library or production plugin should ship with the smallest practical dependency set.

```shell
composer require stellarwp/foundation-container stellarwp/foundation-log
composer require --dev stellarwp/foundation-cli
```

Runtime features belong in Composer's `require` section. Install `stellarwp/foundation-cli` in `require-dev` when it is used only to generate project code.

## Choose the aggregate package

Use `stellarwp/foundation` when convenience is more important than minimizing installed code. It provides every component and the `vendor/bin/foundation` developer CLI.

```shell
composer require stellarwp/foundation
```

The aggregate package is appropriate for complete applications or development environments that intentionally own the whole Foundation installation. Prefer split packages for production plugin archives.

## Treat Foundation as application infrastructure

Foundation components provide infrastructure and extension points. Application-specific decisions still belong to the consuming project:

- Select and configure the components the application needs.
- Register application services through focused providers.
- Set a unique application prefix for shared WordPress resources.
- Keep authorization, business rules, and domain behavior in application code.

Foundation does not require an application to adopt every component at once.

## Continue

[Install Foundation](/start/install-foundation/) using the approach that matches the application.
