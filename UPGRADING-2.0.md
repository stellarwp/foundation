# Upgrading to Foundation 2.0

Foundation 2.0 requires PHP 8.3 or later. Upgrade the Foundation packages used by an application together; their internal dependencies require `^2.0`.

## Container and configuration

Create the container through `ContainerFactory::create()` with an `ArrayConfiguration` snapshot. This replaces application bootstrap code that constructed DI52 and bound `Adbar\Dot` directly. See the [Container setup](src/Container/README.md).

Ordinary providers extend `Contracts\Provider`, whose final constructor accepts the container and resolves the registered `Contracts\Configuration`. Remove constructors that only forwarded container/configuration arguments. Put runtime collaborators in the services registered by the provider.

Applications that own a registration base class can instead implement `Contracts\Providable`. Its only method is `register(): void`; its constructor belongs to the application. The container accepts both styles. `isDeferred()`, `provides()` and `boot()` are no longer invoked: move required registration into `register()` and defer expensive construction through lazy bindings.

The former `foundation-container-wordpress` package is removed. Register WordPress hooks in application providers and use lazy container callbacks where appropriate.

Factory callbacks receive `Contracts\Resolver`, not DI52. Change callback parameter types and use its `get()` and `has()` methods. Raw-container access through `getContainer()` and magic forwarding of undocumented DI52 operations are removed. Use the declared Foundation container operations. Catch Foundation's `Exceptions\ContainerException` or `Exceptions\NotFoundException` instead of DI52 exceptions.

Configuration is a read-only application-lifetime snapshot. Load application settings with `$values = require __DIR__ . '/config.php';` and pass the returned array to `ArrayConfiguration`. Combine settings from plugins sharing a container before creating that snapshot; replace late calls to `Dot::merge()` with this bootstrap-time assembly. Replacing configuration after providers have been constructed does not update their stored snapshot.

## Logging

[Foundation Log](src/Log/README.md) supports Monolog `^2.11 || ^3.10`. Composer selects a compatible major for the application. Custom handlers and processors must support the selected major's record representation.

The configured PSR-3 logger is now a singleton. Handlers and processors added to it persist for that container's lifetime. Audit code that previously expected a fresh logger on each resolution.

The former public `LogProvider::LOG_LEVEL`, `CHANNEL_ERRORLOG` and `CHANNELS` implementation constants are no longer exposed. Configure the level and channels through `log.level`, `log.channel` and `log.channels`.

Custom channels and stacks can resolve application-defined handlers. Built-in channel names remain `errorlog`, `console`, `null` and `stack`; use those strings in configuration. Duplicate handler instances in a stack are rejected to prevent conflicting destination setup.

## New packages

- [Shutdown](src/Shutdown/README.md) runs application-owned termination tasks with failure isolation and deterministic ordering. It does not add an Axiom transport, retry queue or automatic log buffering.
- [View](src/View/README.md) renders trusted PHP templates inside a configured directory and supports immutable directory selection.

## Split repositories

Package scaffolding and validation no longer require `.github/workflows/close-pull-request.yml`. The repository-creation command disables pull requests through GitHub's API. Existing repositories need that setting configured before their closing workflow is removed. Release assembly does not change remote repository settings.
