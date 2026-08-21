---
title: Scope Foundation to Your Application
description: Decide whether Foundation-managed commands and database resources need an application prefix.
sidebar:
  order: 6
---

The Foundation prefix gives shared resources an application identity. It affects resources that exist outside PHP's class namespace, including WP-CLI command names, migration tables, and migration locks.

## Decide whether you need a prefix

:::tip[Building the complete WordPress application?]
If one project owns the WordPress installation, its themes, its plugins, and the Foundation composition root, you generally do not need to configure a prefix. Foundation's default `nx` prefix gives that application one shared resource identity.

This is appropriate when the deployed code is intentionally one application and Foundation is configured centrally rather than bundled independently by its features.
:::

:::caution[Building a standalone plugin?]
A plugin distributed for installation on WordPress sites must configure its own stable prefix. It can run alongside unrelated plugins that also bundle Foundation, and those plugins must not share its commands, migration history, or locks.
:::

PHP namespace prefixing tools such as Strauss do not solve this problem. They isolate PHP classes, but WordPress database tables and WP-CLI command names still share the same installation-wide namespace.

## Configure a standalone plugin

Set `foundation.prefix` in the plugin's `config.php`:

```php title="config.php"
<?php declare(strict_types=1);

return [
	'foundation' => [
		'prefix' => $_ENV['FOUNDATION_PREFIX'] ?? 'your-plugin',
	],
];
```

Use a stable lowercase kebab-case value based on the plugin's permanent identity:

```text
your-plugin
```

Do not derive the prefix from a display name, installation directory, release version, or other value that may change. Changing it later makes Foundation look for a different set of resources.

## Understand what it changes

Foundation adapts the prefix to the format required by each package. Given `your-plugin`:

- WP-CLI commands are registered under `wp your-plugin`.
- The migration ledger defaults to `<wp_prefix>your_plugin_foundation_migrations`.
- Database-backed migration locks default to `<wp_prefix>your_plugin_foundation_locks`.
- The migration lock name defaults to `your-plugin-foundation-database-migrations`.

Without configuration, those resources use `nx`. That is convenient for an application that owns the installation, but unsafe for a standalone plugin because another Foundation consumer may use the same defaults.

## Keep the prefix stable

Treat the prefix as persisted application identity. Once a release has created migration tables or registered operational commands, changing the prefix can make existing migrations appear missing and can leave the old resources behind.

:::note
Different environments may use different databases, but a deployed environment should retain the same prefix for the lifetime of its Foundation-managed resources.
:::

## Override one package when necessary

Package-specific configuration takes precedence over names derived from `foundation.prefix`. Use an override when an existing installation must retain a previously published table, lock, or command name.

New applications and plugins should normally configure only `foundation.prefix` and allow Foundation packages to derive consistent defaults.

## Continue

[Configure application locks](/components/lock/) when work must not overlap across requests, workers, or servers.
