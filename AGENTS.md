# AGENTS.md

## Project

Foundation is a StellarWP Composer monorepo for reusable PHP packages intended for libraries and WordPress plugin ecosystems.

Initial packages:

- `stellarwp/foundation-container`
- `stellarwp/foundation-log`
- `stellarwp/foundation-lock`
- `stellarwp/foundation-lock-redis`
- `stellarwp/foundation-database`
- `stellarwp/foundation-identifier`
- `stellarwp/foundation-pipeline`
- `stellarwp/foundation-wpcli`
- `stellarwp/foundation-cli`

## Namespaces

Use package namespaces under `StellarWP\Foundation\<Package>\`.

## Code Organization

Prefer feature-first organization, also known as vertical slice architecture or package-by-feature, when adding command/tooling features. Group a command and its private collaborators under the command feature namespace.

For example, use:

```text
Commands/
  Package/
    Contracts/
      PackageRepositoryCreator.php
    CreateCommand.php
    PackageResolver.php
    PackageFilesValidator.php
    GitHubPackageRepositoryCreator.php
```

instead of splitting those private collaborators into broad technical folders too early.

If a collaborator is only useful for one command group, keep it under that command group's feature folder. If it becomes useful across multiple command groups, promote it to a broader domain or infrastructure namespace such as `Package/`, `GitHub/`, `Console/`, or `Process/`.

When it is very clear that a class will be reused by many similar features, promote it immediately instead of burying it in the first feature slice. This is especially true for command/tooling infrastructure where many commands will need the same capability, such as shell command formatting, process execution, console IO helpers, package discovery, or GitHub API clients.

Feature-local interfaces should live in a `Contracts/` folder inside the feature slice, for example `Commands/Package/Contracts/PackageRepositoryCreator.php`. Only promote contracts to a package-level `Contracts/` namespace when they are intended to be shared across multiple features or consumed as public extension points.

Shared infrastructure interfaces should live under that shared namespace's `Contracts/` folder, for example `Process/Contracts/ProcessRunner.php`.

Avoid `use ... as ...` import aliases unless they resolve a real class-name collision or ambiguity. Prefer importing the class by its actual short name. The standing exception is `use lucatume\DI52\Container as C;`, which may be used for concise container factory callbacks.

Exceptions should live in an `Exceptions/` folder. Put shared package exceptions at the package root, for example `src/Database/Exceptions/DatabaseException.php`; put feature-only exceptions under that feature's `Exceptions/` folder only when they are not shared outside that feature.

Add `@throws` PHPDoc annotations to methods and constructors for exceptions they intentionally throw or propagate as part of their contract. Keep the annotation specific enough that callers can understand validation, infrastructure, and failure behavior without reading the implementation.

Generator commands should be grouped by the `make:*` workflow under `src/Cli/Commands/Make/`, for example `src/Cli/Commands/Make/WPCliCommand.php`. When a make feature grows beyond a single command class or needs private collaborators, group that feature under its own namespace such as `src/Cli/Commands/Make/Database/`. Command-specific collaborators should live inside that feature namespace, not beside unrelated command classes in `Commands/Make/`.

Shared generation infrastructure that is not itself a console command and is reused across command features should live under `src/Cli/Generation/`.

Default stubs should live with the package that owns the generated class shape. For example, WP-CLI command stubs live in `src/WPCli/stubs/` because the WPCli package owns the base `Command` API. The CLI package owns resolving, rendering, and writing generated files.

Project-level stub overrides should use `foundation/stubs/<feature>/`, for example `foundation/stubs/wpcli/command.stub`.

When generating classes intended for WordPress projects, use Snake_Case class names and WordPress formatting in the generated stub output, even though Foundation source follows this repository's formatter.

Generators that write references to Foundation classes should detect `extra.strauss.namespace_prefix` from the consuming project's `composer.json` and render those Foundation imports with the configured prefix.

Generator stubs should use context-aware placeholders for PHP literals, such as `{{ description_php }}` instead of raw `{{ description }}` inside quoted PHP strings.

Migration generators must not offer a force-overwrite option. Existing migrations are identity-bearing history: edit a migration only before it has been applied anywhere, or create a new migration for a new schema change.

## CLI Tooling Boundary

`stellarwp/foundation-cli` is developer tooling and should normally be installed by split-package consumers with `composer require --dev stellarwp/foundation-cli`. It should not be packaged into production WordPress plugin zips when installed as a split package.

The aggregate `stellarwp/foundation` package is an all-in-one convenience package and includes the CLI code and binary. For lean production archives, consuming projects should require only the split packages they need.

Do not instruct consuming WordPress plugins to register `StellarWP\Foundation\Cli\CliProvider` in their application providers. `CliProvider` boots the Foundation Symfony Console application for the `foundation` binary only.

When generated code depends on runtime APIs, require the runtime package normally. For WP-CLI commands, install `stellarwp/foundation-wpcli` in `require` if the plugin ships those commands, and install `stellarwp/foundation-cli` in `require-dev` only for generation.

Do not register WP-CLI command classes directly with `$this->container->bind(CommandClass::class)` or `$this->container->singleton(CommandClass::class)` from providers loaded during normal WordPress bootstrap. DI52 creates the binding lazily, but its builder factory immediately calls `class_exists()` for string implementations. That autoloads the command class and its `WP_CLI_Command` parent before WP-CLI is available. Keep any contextual bindings for the command, then contribute it lazily through `WPCliProvider::COMMANDS` without separately binding it:

```php
$this->container->mergeArrayVar(WPCliProvider::COMMANDS, static fn (C $c): array => [
	$c->get(CommandClass::class),
]);
```

The command class will then be autowired only when `WPCliProvider` resolves the command collection during `cli_init`.

If local scaffolding assets such as `foundation/stubs/` should not be included in a consuming project's release archive, add them to that project's `.gitattributes` production zip exclusions.

## Container Providers

When writing providers or container registration code, prefer container-driven construction over inline factories with explicit `new` calls. Bind classes and interfaces directly when the container can autowire them.

Use contextual bindings with `$this->container->when()->needs()->give()` for scalar constructor arguments, command lists, or feature-specific substitutions. Use a factory closure only when the value must be computed or resolved from the container, and keep that closure focused on supplying the constructor dependency rather than constructing the full object.

Classes should take the dependencies they need directly. Do not make constructor dependencies nullable just to instantiate fallback concrete classes internally, for example `?Dependency $dependency = null` with `$this->dependency = $dependency ?? new Dependency()`. Register default implementations and aliases in a provider instead so consumers can replace them through container configuration.

Use the optional `foundation.prefix` configuration key when Foundation-managed resources must be scoped to a consuming application. Its effective zero-configuration value is `nx`; providers should derive their default resource names from that shared value instead of repeating their own fallbacks. Distributable plugins must configure a stable, unique prefix so separate Foundation consumers do not share resources. Documentation and examples should use a generic lowercase kebab-case value such as `your-plugin`, never a developer-specific project name. Package-specific settings must take priority over values derived from the shared prefix.

Classes should receive service collaborators through constructor injection. Direct `new` expressions inside application classes are reserved for immutable value or result objects, exceptions, PHP standard-library objects, and objects deliberately produced by an owning builder or factory. Keep feature-local value objects under that feature's `ValueObjects/` namespace. Value objects should be `final readonly` where possible and must not resolve or construct service dependencies.

Organize provider registration by feature or capability, not by container mechanism. The main `register()` method should call focused private methods such as `registerConfiguration()`, `registerMigrations()`, `registerLocks()`, or `registerCliCommands()`. Keep each feature's contextual bindings beside the classes they configure. Avoid generic methods such as `configureContextualBindings()` that group unrelated bindings only because they use the same container API.

## Split Packages

Split packages live in `src/<Package>/` and are split to read-only repositories named `stellarwp/foundation-<package>`.

`stellarwp/foundation-database` is a WordPress-backed database package. Keep its runtime implementation centered on `wpdb`, `dbDelta()`, WordPress table prefixes, and WP-CLI integration. If the project later needs file storage, Redis storage, PDO database support, or another non-WordPress backend, prefer a separate package or explicit driver package instead of making `foundation-database` a generic DBAL-style abstraction.

When adding a new split package, set its package `composer.json` PHP constraint to `>=8.3` unless the user explicitly says otherwise. PHP 7.4 release compatibility will be handled later by an automated Rector downgrade workflow, not by lowering the package PHP constraint during development.

When adding external dependencies for split packages, choose version constraints whose package line supports PHP 7.4. Use `>=` constraints for those dependencies instead of caret constraints when preserving the PHP 7.4-compatible floor matters. For example, use a Symfony component version such as `>=5.4` rather than a newer line that requires PHP 8+.

Important exception: dependencies on this monorepo's own split packages, such as `stellarwp/foundation-container`, should use the correct Composer release constraint like `^1.0`. Do not use `>=` for internal Foundation package dependencies; Monorepo Builder commands such as `composer monorepo bump-interdependency` are expected to bump those constraints during releases.

### Required Files

Each split package should include:

- `composer.json`
- `README.md`
- `.gitattributes`
- `.gitignore`
- `.github/workflows/close-pull-request.yml`

When adding a new split package, add its `stellarwp/foundation-<package>` repository link to the root `README.md` repositories list.

Each split package `README.md` must include this warning immediately after the package heading:

```markdown
> [!WARNING]
> **This is a read-only repository!** For pull requests or issues, see [stellarwp/foundation](https://github.com/stellarwp/foundation).
```

### GitHub Repositories

When creating a new split repository on GitHub, use the description `[READ ONLY] Subtree split of the Foundation <Component> component (see stellarwp/foundation)` and disable wikis, issues, projects, and pull requests.

## PHP Feature Policy

Allowed for current PHP 8.3 source:

- constructor property promotion
- union types
- intersection types
- readonly properties/classes
- enums
- nullsafe operator
- match expressions
- named arguments
- first-class callables
- typed class constants

Avoid unless there is a clear reason:

- enums in public APIs
- reflection-heavy code
- attributes that affect runtime behavior
- DNF types
- `never` in public APIs

Banned while the project targets PHP 8.3:

- PHP 8.4 property hooks
- PHP 8.4 asymmetric visibility
- PHP 8.4 lazy objects API
- `#[Deprecated]`; use `@deprecated` PHPDoc instead
- PHP 8.4-only functions/classes/constants

## Monorepo Commands

After adding or changing split package dependencies, run `composer monorepo merge` and then `composer update` so root `composer.json`/lock state includes package dependency changes.

Use `composer monorepo list` to inspect available Monorepo Builder commands.

## Verification

When `composer lint` reports style-only issues, run `composer format` to let the project formatter fix them before making manual formatting edits.

Reusable test fixtures, sample classes, and test doubles should live under `tests/Support/Fixtures/<Namespace>/` instead of being declared inline in a test class file. Keep truly local one-off fakes inline only when they are not reusable and do not represent a domain/package fixture.

Tests that need writable temporary files or directories should use a test-specific subdirectory under `tests/_data/temp` instead of `sys_get_temp_dir()`. Use `$this->temp_dir('<name>')` when only the path is needed; it mirrors `codecept_data_dir()` and does not create the directory. Use `$this->prepare_temp_dir('<name>')` in `setUp()` to create a unique clean directory under that name and register it for automatic cleanup by the base test case. Only call `$this->remove_temp_dir('<name>')` manually when a test needs to remove the prepared directories before teardown.

Codeception tests run through SLIC. Use SLIC 2.3.0 or newer so PCOV-backed coverage commands are available. Use `.env.testing.slic` as the SLIC/Codeception environment file. First-time local setup is `slic here` from the directory that contains this repository, `slic use foundation` from the repository, `slic composer install`, and `slic cc build`. If host-installed dependencies conflict with the SLIC PHP version, run `slic composer update --with-all-dependencies` inside the container. Run suites with `slic run unit`, `slic run feature`, `composer test:redis` or `slic run redis`, `composer test:integration` or `slic run integration`, `composer test:wpunit` or `slic run wpunit`, and `composer test:wpcli` or `slic run wpcli`.

Test suite meanings: `Unit` is isolated class/package behavior, `Feature` is Foundation feature behavior without bootstrapping WordPress, `redis` is real Redis behavior shared across packages and run against SLIC's Redis service, `integration` is multi-provider/container behavior that may require WordPress runtime APIs such as hooks, `wpdb`, `dbDelta()`, or globals, `wpunit` is lower-level WordPress-loaded behavior through wp-browser, and `wpcli` is the shared monorepo suite for testing WP-CLI commands through wp-browser's WPCLI module. If a PHPUnit test uses `#[DataProvider]` and must run under Codeception, also include the matching `@dataProvider` docblock because Codeception's PHPUnit loader reads docblock providers for these tests.

Use `integration` for behavior where multiple providers/packages must be registered together to prove the container graph works. Use `wpunit` for a single package/class where the main concern is direct WordPress API behavior. Use `wpcli` for real WP-CLI command execution shared across packages. Keep unit tests focused on portable package behavior and pure collaborators; do not build large fake WordPress runtimes in unit tests when the behavior can be covered with wp-browser.

Use `tests/WPUnitSupport/WPTestCase.php` as the base class for wpunit tests instead of extending Codeception's `WPTestCase` directly. Keep Codeception-generated actor files in `tests/CodeceptionSupport/`; that directory is ignored and excluded from lint/static analysis.

After completing a feature, run `composer test:coverage`, review `clover.xml` for missed source coverage, and add meaningful tests for uncovered behavior before considering the feature complete. Coverage uses SLIC 2.3.0+ PCOV support, runs each SLIC suite separately, and merges the serialized `.cov` artifacts with `phpcov`; run the merge through `slic composer run coverage:merge` or `slic composer run coverage:merge-html` because the coverage files contain container paths like `/var/www/html/wp-content/plugins/foundation`.

## Releases

- Adding a new split package is usually a minor SemVer release because it introduces new functionality without breaking existing packages. Use a major release only if the change also breaks an existing public API or package contract.
- Run `composer monorepo bump-interdependency <constraint>` when planning a major version release so Foundation packages that depend on each other require the new major line, for example `^3.0`. It may also be useful for a minor release when one package must require APIs added in that new minor, for example `^1.1`.
- Before publishing a release, verify the intended release-line package constraints are already committed. For a minor release such as `1.2.0`, internal Foundation package dependencies should already require the released line, for example `^1.2`.
- Publishing a GitHub release creates the release tag and triggers the tagged monorepo split. Wait for the tagged `Split Monorepo Packages and Release` workflow to succeed before considering the release complete.
- After a successful tagged split for a minor or major `.0` release, the split workflow automatically bumps internal package constraints and branch aliases to the next development line on `main`, for example from `^1.2` and `1.2.x-dev` to `^1.3` and `1.3.x-dev`.
- The post-release automation intentionally skips patch tags such as `1.2.1`, because patch releases should not move `dev-main` to a new minor development line.
- If the post-release automation fails, fetch the release tag and manually run `composer monorepo bump-interdependency <next-dev-constraint>`, `composer monorepo package-alias`, and `composer monorepo merge`, then commit and push the updated package `composer.json` files.
- The monorepo split workflow deploys package code to each sub-repository on pushes to `main` and when release tags are pushed.
