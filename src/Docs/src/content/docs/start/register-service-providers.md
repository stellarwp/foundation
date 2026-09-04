---
title: Register Service Providers
description: Group application bindings, configuration, and WordPress hooks into focused feature providers.
sidebar:
  order: 5
---

Service providers are the composition boundary between application features and the container. A provider describes how to construct a feature and where it connects to WordPress, leaving the feature class focused on its own behavior.

## Build a contained feature

For example, an admin notice can live in one feature namespace:

```text
src/
  Admin_Notice/
    Notice.php
    Provider.php
```

Name the feature provider `Provider` inside its namespace. This keeps references such as `Admin_Notice\Provider` immediately recognizable in the application's provider list.

## Configure the notice

Add feature configuration to the root `config.php`:

```php title="config.php"
<?php declare(strict_types=1);

return [
	'foundation' => [
		'prefix' => $_ENV['FOUNDATION_PREFIX'] ?? 'your-plugin',
	],
	'admin_notice' => [
		'capability' => $_ENV['ADMIN_NOTICE_CAPABILITY'] ?? 'manage_options',
	],
];
```

Application classes should receive resolved values through constructor injection rather than reading `$_ENV` or the configuration object directly.

## Create the feature class

Create `src/Admin_Notice/Notice.php`. The notice receives the capability supplied by its provider, while user-facing text remains in the feature so WordPress can translate it:

```php title="Notice.php"
<?php declare(strict_types=1);

namespace Plugin\Admin_Notice;

use InvalidArgumentException;

/**
 * Displays the configured plugin notice in WordPress administration screens.
 */
final readonly class Notice {

	/**
	 * @throws InvalidArgumentException When the capability is empty.
	 */
	public function __construct(
		private string $capability
	) {
		if ( trim( $this->capability ) === '' ) {
			throw new InvalidArgumentException( 'The admin notice capability cannot be empty.' );
		}
	}

	/**
	 * Display the configured notice.
	 *
	 * @action admin_notices
	 */
	public function display(): void {
		if ( ! current_user_can( $this->capability ) ) {
			return;
		}

		printf(
			'<div class="notice notice-info"><p>%s</p></div>',
			esc_html__( 'Your plugin is ready.', 'your-plugin' )
		);
	}
}
```

The `@action admin_notices` annotation records why WordPress calls `display()`. The class itself does not register global hooks or resolve dependencies from the container.

## Register the complete feature

In `src/Admin_Notice/Provider.php`, alias the Foundation base provider because it shares the `Provider` short name. Keep the feature's definitions and hooks together in one focused registration method:

```php title="Provider.php"
<?php declare(strict_types=1);

namespace Plugin\Admin_Notice;

use StellarWP\Foundation\Container\Contracts\Provider as Service_Provider;

/**
 * Configures the admin notice and connects it to WordPress.
 */
final class Provider extends Service_Provider {

	public function register(): void {
		$this->register_admin_notice();
	}

	private function register_admin_notice(): void {
		$this->container->when( Notice::class )
			->needs( '$capability' )
			->give( (string) $this->config->get( 'admin_notice.capability' ) );

		$this->container->singleton( Notice::class );

		add_action(
			'admin_notices',
			$this->container->callback( Notice::class, 'display' )
		);
	}
}
```

The provider registers the hook before resolving `Notice`. WordPress creates the notice through the container only when `admin_notices` runs.

As a provider grows, add methods named for the feature or capability they configure, such as `register_admin_notice()` or `register_report_export()`. Avoid grouping unrelated work under methods such as `register_bindings()` or `register_hooks()` merely because it uses the same API.

## Add the provider to the application

Register the feature in the ordered provider list in `src/App.php`:

```php title="App.php"
use StellarWP\Foundation\Container\Contracts\Provider;
use Plugin\Admin_Notice;

/** @var list<class-string<Provider>> */
private const array PROVIDERS = [
	Admin_Notice\Provider::class,
];
```

Register infrastructure providers before feature providers that consume them. Avoid resolving application services while providers are still registering; complete the container graph before WordPress invokes its feature entrypoints.

For a larger feature, its top-level `Provider` may register internal providers so `App` only needs to know the feature entrypoint. Keep that provider as a pure composition boundary: if it registers other providers, it should not also contain bindings, configuration, hooks, or feature behavior. Providers shared across features still belong in the application's ordered provider list.
