# AGENTS.md

## Project

Foundation is a StellarWP Composer monorepo for reusable PHP packages intended for libraries and WordPress plugin ecosystems.

Split packages:

- `stellarwp/foundation-container`
- `stellarwp/foundation-log`
- `stellarwp/foundation-lock`
- `stellarwp/foundation-lock-redis`
- `stellarwp/foundation-database`
- `stellarwp/foundation-identifier`
- `stellarwp/foundation-pipeline`
- `stellarwp/foundation-shutdown`
- `stellarwp/foundation-view`
- `stellarwp/foundation-wpcli`
- `stellarwp/foundation-cli`
- `stellarwp/foundation-docs`

## Design Priorities

Foundation helps developers adopt capabilities quickly. Assume familiarity with DI and provider-based applications, but require no knowledge of package internals for ordinary use. Preserve correctness and operational guarantees; within those constraints, prioritize understandable consumer code, compatibility, supported customization, then internal conventions.

- **Opinionated defaults:** offer a common, sensible implementation and provider wiring when the capability supports them. Require explicit configuration where values cannot safely be inferred. A package may provide contracts, standalone utilities, or a specific implementation without a provider or universal default. Offer additional implementations or integrations as separate `foundation-<name>` packages when they warrant their own dependencies or scope.
- **Explicit extension points:** support meaningful application variations without requiring consumers to assemble internal collaborators. Each extension point should have an identifiable purpose.
- **Progressive disclosure:** explain the recommended workflow, what the supplied implementation provides, and its relevant limits before advanced configuration or replacement.

Use official Laravel documentation as inspiration for familiar naming, fluent APIs, complete examples, and progressive disclosure when relevant. Adopt patterns that fit Foundation's scope; do not copy Laravel's architecture wholesale. Avoid Laravel-style static facades and global service resolution. An ordinary injected object may provide a convenient facade over complex internals when it reduces what developers must coordinate or understand.

## Implementation Approach

For substantial features or API changes, briefly present a complete consumer example, responsibility ownership, supported variation, and representative implementation shape before broad implementation. Show enough setup and central service code to expose required choices, dependencies, and control flow. Reuse established package patterns; scale this preparation to the change rather than requiring a separate plan or approval for routine work.

Implement and verify against those examples. Revisit settled decisions for demonstrated correctness, usability, required variation, or compatibility problems. Do not expand a refactor merely for architectural uniformity or hypothetical future requirements. A review against these instructions should identify concrete gaps, not trigger a repository-wide rewrite.

Review the resulting class as a whole after applying review feedback: its primary responsibility and ordinary execution path should remain easy to follow. Each added guard, helper, or abstraction must earn its complexity through an intended requirement. Passing tests establish behavior, not that the design is appropriately simple; do not invent stricter requirements merely to justify defensive code.

For unreleased greenfield APIs with no consumers, correct demonstrated problems directly, without compatibility layers for abandoned designs. Preserve obligations to supported released versions separately. Before release, establish representative consumer and replacement tests as a compatibility baseline. Completion means the intended workflows and variations work with their documented guarantees, not that every imaginable extension exists.

## Public APIs and Compatibility

Distinguish APIs consumers call, contracts they implement, base classes they extend, and Foundation-owned internals. Document supported extension points and mark implementation-only types `@internal`; PHP visibility alone is not a compatibility policy. Account for constructors, protected hooks, returned types, generated code, configuration, persistent identifiers, and failure behavior as well as method signatures. Parameter names used by PHP named arguments, argument units, callback expectations, return meanings, and side effects are compatibility commitments too.

Before exposing a widely used operation, define its durable responsibility and observable behavior in a representative consumer example. Walk through a plausible future requirement and explain how existing calls can remain unchanged; do not implement speculative extensions. Within a supported release line, preserve that operation's meaning. Add materially different behavior through a distinct method or capability rather than silently changing results, retries, failure handling, or lifecycle guarantees. Widespread use is acceptable when Foundation deliberately owns this stable promise; it is not by itself a reason to reject a convenience object that owns meaningful behavior. Preserve representative consumer calls and their behavioral assertions as compatibility tests during internal refactors.

Design contracts as the smallest coherent capabilities with meaningful substitution boundaries, not the fewest possible methods. Keep backend-specific behavior off general contracts. Use separate contracts for optional capabilities; where a provider supplies an implementation, bind its supported contracts coherently so application replacements work. Keep conveniences on concrete classes unless they are reusable capabilities, and keep internal helpers off the supported API.

Within a supported release line, do not add required methods to consumer-implemented interfaces or abstract classes, or infrastructure constructor requirements that consuming subclasses must mirror. Add optional behavior through separate capabilities or internally owned construction/lifecycle mechanisms. Generated application classes should declare application concerns while Foundation owns its infrastructure.

## Code Organization and Construction

Use package namespaces under `StellarWP\Foundation\<Package>\`. Organize by component and feature, then local responsibility. Keep a command and private collaborators together, for example `Commands/Package/{CreateCommand,PackageResolver,PackageFilesValidator,GitHubPackageRepositoryCreator}.php` with `Contracts/PackageRepositoryCreator.php` beneath that feature.

Promote collaborators shared across features to namespaces such as `Package/`, `GitHub/`, `Console/`, or `Process/`. Place clearly shared infrastructure there immediately when concrete planned consumers are known. Contracts belong in their owning namespace's `Contracts/` folder, such as `Process/Contracts/ProcessRunner.php`. Exceptions belong in the owning package or feature's `Exceptions/` folder.

Prefer composition and cohesive, named responsibilities. Extract a collaborator when it owns a distinct policy, invariant, resource lifetime, substantial algorithm, or supported variation. Keep cohesive algorithms and ordinary control flow together; small methods, additional interfaces, and factories are not goals by themselves.

Inject service collaborators through constructors. Do not hide fallback services behind nullable dependencies; supply defaults through a provider when appropriate, an owning factory, or documented composition. Direct `new` expressions inside application classes are for values/results, exceptions, PHP standard-library objects, and objects deliberately produced by an owning builder or factory. Do not create construction layers without a meaningful ownership or configuration responsibility.

Enforce Foundation-owned invariants at their owning construction, parsing, or resolution boundary so downstream code can rely on them. Delegate dependency-specific formats and connection settings to the dependency's parser and validation, propagating or translating its failures at the adapter boundary. Add pre-validation only for a demonstrated gap that would violate an intended Foundation guarantee, and explain that gap. Providers should primarily wire services. Keep small local guards inline; do not introduce chains of `assert*` or `validate*` helpers merely to subdivide configuration checks. Revalidate only across an independent trust boundary, after mutable state can invalidate the guarantee, or when completing a progressively built value.

Feature-local value objects belong in `ValueObjects/`, should be `final readonly` where possible, and must not construct or resolve services.

Encapsulate meaningful closed state behind named factories and predicates such as `isUnavailable()`. Expose raw state only for presentation or serialization. Simple transport DTO fields need not acquire getters or state machinery.

## Source Conventions

Do not declare empty constructors when PHP's implicit public constructor suffices. Private empty constructors may prevent instantiation of static utilities or constants holders.

Avoid import aliases unless resolving a real collision or ambiguity. The standing exception is `use StellarWP\Foundation\Container\Contracts\Resolver as C;` for container factories.

Use multiline class and method docblocks. Public methods begin with a concise purpose or `{@inheritDoc}`. Describe unclear parameter constraints, and add specific `@throws` annotations for intentional validation, infrastructure, and propagated failures so callers need not inspect implementation details.

## Generators

Group `make:*` commands under `src/Cli/Commands/Make/`. A feature with private collaborators gets its own namespace, such as `Make/Database/`. Shared non-command generation infrastructure belongs in `src/Cli/Generation/`.

Default stubs belong to the package owning the generated API, for example `src/WPCli/stubs/`. The CLI owns resolving, rendering, and writing files. Project overrides use `foundation/stubs/<feature>/`, such as `foundation/stubs/wpcli/command.stub`.

Generate WordPress code with Snake_Case classes and WordPress formatting. Detect `extra.strauss.namespace_prefix` in the consumer's `composer.json` and prefix generated Foundation imports. Use context-aware PHP literal placeholders such as `{{ description_php }}` rather than raw text inside quoted strings.

Migration generators must not offer a force-overwrite option. Existing migrations are identity-bearing history: edit a migration only before it has been applied anywhere, or create a new migration for a new schema change.

Database migration generators must distinguish table ownership from a table dependency through explicit options. Only `make:database-migration <name> --create=<table-class>` may use the create-table stub whose migration-owned blueprint defines the complete initial schema and whose rollback drops the complete table. `make:database-migration <name> --table=<table-class>` selects an alteration migration: inject the existing table, declare only the explicit additions, changes, and removals owned by that migration, and default `down()` to `IrreversibleMigration` until the developer supplies a safe inverse. The options are mutually exclusive and accept either a short class from the default table namespace or a fully qualified class. Never infer destructive behavior from a migration name. `make:database-table <name> --migration` must reuse the same create-migration factory so combined and standalone generation remain consistent.

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

`WPCliProvider` owns the configured `StellarWP\Foundation\WPCli\CommandContext` and passes it to each contributed `RegistrableCommand` during `cli_init`. Commands extending the base `Command` should accept only their application dependencies; do not add the Foundation container, command prefix, or command context to application command constructors. Feature providers should not repeat command-prefix bindings or define their own command-prefix container entries.

If local scaffolding assets such as `foundation/stubs/` should not be included in a consuming project's release archive, add them to that project's `.gitattributes` production zip exclusions.

## Container Providers

When a package provides container integration, bind autowireable classes and interfaces directly. Use contextual `when()->needs()->give()` bindings for scalar arguments, collections, and substitutions. Use closures for computed or resolved dependencies; keep construction in owning providers or factories.

All providers extend `Provider`. Its final constructor receives `Container` and resolves the application-lifetime `Configuration` snapshot; subclasses use `$this->config` when needed and must not declare constructors. Keep one provider shape. Bind `Configuration` before registering providers, and keep third-party implementations such as `Adbar\Dot` behind a Foundation-owned adapter.

Foundation providers have one eager `register()` lifecycle. Keep deferred construction in lazy service bindings rather than adding provider-level `provides()`, `isDeferred()`, or `boot()` phases tied to a particular container engine. Providers that append to additive collections or register hooks must make repeated registration idempotent so a second `register()` call does not duplicate contributions or callbacks.

Use `StellarWP\Foundation\Container\ContainerFactory` at composition roots for the default container; it owns DI52 construction and core contract bindings. Construct `ContainerAdapter` directly only in adapter-specific tests or deliberate integration with a separately owned DI52 instance.

Container factory closures receive `StellarWP\Foundation\Container\Contracts\Resolver`; type-hint it whenever resolving another service. Raw `lucatume\DI52\Container` references belong only in the Container backend adapter, default factory, and adapter-specific tests.

Container contracts and application code must use Foundation-owned container exceptions. Keep backend exceptions, raw-container accessors, and magic passthrough methods inside the adapter implementation so replacing the underlying engine requires changes only at the composition root and adapter boundary.

Foundation provider identifiers use `self::class . '.descriptive_suffix'` so Strauss scopes them, including scalar bindings and additive collections. Keep wiring identifiers private; expose intentional contribution points such as migration, command, and shutdown-task collections. Test resolved behavior rather than private entries. Application identifiers use stable text such as `your-plugin.report.exporters`. Configuration keys, persistent database/cache identifiers, lock/channel names, and WP-CLI commands must remain stable across builds, independent of PHP namespaces.

Use the optional `foundation.prefix` configuration key when Foundation-managed resources must be scoped to a consuming application. Its effective zero-configuration value is `nx`; providers should derive their default resource names from that shared value instead of repeating their own fallbacks. Distributable plugins must configure a stable, unique prefix so separate Foundation consumers do not share resources. Documentation and examples should use a generic lowercase kebab-case value such as `your-plugin`, never a developer-specific project name. Package-specific settings must take priority over values derived from the shared prefix.

Organize substantial provider registration into focused methods such as `registerMigrations()` or `registerLocks()`, keeping contextual bindings beside the classes they configure. Group by capability, not container mechanism. A small, cohesive `register()` need not delegate merely for uniformity.

Configure application pipe sequences in providers under feature-local identifiers and inject them contextually. Use `bind()`, not `singleton()`, because `Pipeline` carries mutable execution state. Providers may use the concrete pipeline's `through()`, `pipe()`, and `via()` methods; consumers execute through `StellarWP\Foundation\Pipeline\Contracts\Pipeline`, sending values and choosing destinations. Construct the supplied pipeline with the Foundation `Resolver`, without registration dependencies, nullable fallbacks, or setter-based initialization.

Register infrastructure providers and top-level feature providers from the application's composition root, such as the ordered provider list in `App.php`. A provider that registers definitions, configuration, or hooks must not also register other providers. The exception is a feature composition provider whose sole responsibility is registering that feature's internal providers; it should contain no service bindings, configuration, hooks, or other behavior. Keep cross-feature and application-level dependencies visible in the `App.php` provider list.

## Database

`stellarwp/foundation-database` is a WordPress-backed database package. Keep its runtime implementation centered on `wpdb`, `dbDelta()`, WordPress table prefixes, and WP-CLI integration. If the project later needs file storage, Redis storage, PDO database support, or another non-WordPress backend, prefer a separate package or explicit driver package instead of making `foundation-database` a generic DBAL-style abstraction.

Application table classes should extend `StellarWP\Foundation\Database\Table\Table`. Inject the concrete table into repositories and other classes that perform ordinary table-scoped queries or writes; the table provides `query()`, `insert()`, `insertGetId()`, `update()`, and `delete()` without requiring callers to coordinate a separate database service and table name. Inject the smallest additional database capability, such as `QueryExecutor`, only for specialized raw or cross-table SQL that does not fit the table gateway or query builder. Use the aggregate `Database` contract only when a collaborator genuinely needs several capabilities. Implement the minimal `Contracts\Table` directly only for custom table implementations that do not need the supplied gateway. Every table implementation must return a stable name without a WordPress prefix from `unprefixedName()`. Foundation SQL services must resolve and validate the physical name through `Database::tableName($table)` at the point of use; custom table implementations must not apply `$wpdb->prefix` or enforce physical-name constraints themselves.

Generated table classes must not repeat the base table's infrastructure constructor. The base class owns its container-resolved `TableGateway`, and generated subclasses provide only table identity through `unprefixedName()`. Migration-owned `Blueprint` instances define historical schema operations; do not place a mutable current-schema definition on the table class. Migration identity and schema history belong to each migration, not to the table it changes. This keeps generated application code stable if Foundation changes its internal construction dependencies or a table evolves over time.

Use `Schema::create()` with a complete migration-owned blueprint for a table's initial migration. Use `Schema::alter()` with only the explicit additions, full column modifications marked by `change()`, and removals owned by a later migration. Alterations must be safe to retry after DDL succeeds without a ledger write: existing additions and absent removals are treated as completed work, while incompatible existing declarations fail verification. Do not continuously compare a table against one mutable desired definition. Physical indexes not declared by the current migration may be owned by another migration or external system and must not cause reconciliation to fail.

Keep the concrete `Database` as the convenient complete facade, but add new implementable behavior through narrow capability contracts. Raw read-only collaborators should depend on `QueryReader`, table-scoped query builders should depend on `QueryGateway`, and the supplied table base may depend on `TableGateway` because it exposes both reads and writes. Do not add optional features such as upserts, transactions, streaming reads, or bulk writes directly to an existing aggregate when alternate implementations would be forced to support them. Bind default capability contracts through the aggregate `Database` contract so an application replacement remains coherent.

Foundation Database and Schema operations should accept `Table` instances rather than offering parallel string table-name arguments. Keep unprefixed strings at the table construction and prefix-resolution boundary instead of exposing them as an alternative developer workflow.

Migration IDs determine forward execution order. Collect all provider contributions into one migration collection, sort globally in ascending byte-exact ID order, and run pending migrations. Contribution order must not affect execution. Rollbacks reverse actual ledger execution order. Generated timestamps provide sortable IDs; custom IDs remain valid and assume responsibility for their lexical position.

Foundation Database stores unprefixed table names and applies the active WordPress site's prefix through `DatabaseScope` on every access. The same container and database services may be reused after `switch_to_blog()` between complete `Migrator` calls. Each migration operation must capture its starting scope and reject a context change before lock renewal, ledger writes, or lock release; when the context is uncertain, leave the original lock to expire rather than touching another site's lock table. A migration must never call `switch_to_blog()` itself, even temporarily, because restoring the original site before a boundary check would hide schema writes made in the wrong scope. Keep developer-authored `Migration` and `Schema` contracts independent of site scope so a future network-wide implementation can use `$wpdb->base_prefix` and network-specific state without requiring migration rewrites.

## Split Packages

Split packages live in `src/<Package>/` and are split to read-only repositories named `stellarwp/foundation-<package>`.

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

Non-Composer split projects may use their ecosystem manifest instead of `composer.json`. For example, `src/Docs/` uses `package.json` and must remain discoverable by `.github/bin/repo-map.sh` so it splits to `stellarwp/foundation-docs`. All other required split-repository files and warning text still apply.

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

## Documentation

The documentation site lives in `src/Docs/` and uses Astro with Starlight. For site changes, use the Node version in `src/Docs/.nvmrc`, install dependencies with `npm ci`, and run `npm run build` there.

Same-repository documentation pull requests are previewed from the monorepo workflow. Production documentation is deployed only for published stable releases by the tag workflow that is split into `stellarwp/foundation-docs`; never deploy documentation production from a push to the monorepo's `main` branch. Both workflows use the `foundation-docs` Cloudflare Pages project through Direct Upload, configured with `production` as its production branch.

Document the current product, without implementation phases or temporary plans. Update relevant guides alongside behavior, API, dependency, configuration, or integration changes. New public packages need a component guide and sidebar entry. Keep split READMEs to an overview, installation, and canonical guide links.

Lead guides with what the package provides and its simplest complete use case: installation, essential configuration and provider registration where applicable, useful operations, failures, and testing. Introduce customization afterward. Use a few root sections with task-oriented subsections; independently used capabilities get nested task guides under a shared overview. Link shared behavior rather than duplicating it.

Explain what developers configure, call, and receive. Include implementation details or exclusions only when they affect a concrete usage decision or failure response; avoid cataloging what a component does not do.

Place operational warnings beside the decision or API behavior they qualify. State the concrete failure mode, distinguish expected outcomes from infrastructure failures, tell the developer whether to skip, retry, or abort, and include compact pseudocode when the response would otherwise remain ambiguous.

Order sequential setup guides so files are created before later examples reference or call them. When a component example assumes the application composition root or provider architecture, link back to the relevant Start Here guides. Prefer Starlight link cards for these prerequisite guides and Starlight asides for decisions or warnings developers must not miss.

Keep runtime and developer dependencies distinct in installation documentation. Standalone WordPress plugins should require only the split runtime packages they ship and install `stellarwp/foundation-cli` with `--dev`. Before showing `composer require stellarwp/foundation`, warn that the aggregate package includes the developer CLI in its normal installation and that `--no-dev` will not remove it.

Explain `foundation.prefix` according to the deployment boundary. A complete WordPress application that centrally owns its themes, plugins, and Foundation composition root can use the shared `nx` default. A distributable standalone plugin must configure a stable, unique prefix because PHP namespace prefixing does not isolate shared WP-CLI command names, database tables, or locks.

Documentation configuration examples must read deployment-specific settings such as hosts, ports, databases, credentials, and resource prefixes from `$_ENV`, with appropriate type conversions and sensible defaults where safe. Preserve existing environment-variable mappings when simplifying examples. Read environment variables in the application's configuration file; providers and services consume the resulting configuration or injected values. When an optional override is absent, preserve the package's derived default rather than duplicating its derivation in application code.

Documentation examples for consuming WordPress projects should use Snake_Case class names and WordPress formatting, including a blank line immediately after each class declaration's opening brace. Keep translatable user-facing text in the class that renders it; configuration examples should represent runtime or deployment behavior rather than untranslated display copy. Validate required scalar configuration at construction boundaries when an empty value would make the feature invalid.

WP-CLI command class examples should include one or more `@example` annotations showing the complete `wp <prefix> <subcommand>` invocation, including a representative invocation with options or flags when applicable.

When examples include WordPress application bootstrap, use the canonical architecture: `App` owns the request singleton, providers register in explicit dependency order, and feature providers group definitions and hooks by capability. Use `$this->container->callback(ClassName::class, 'method')` for lazy WordPress callbacks.

Keep full source paths in the prose immediately before code examples and use only the filename in a code-block `title`. Nova's Shiki metadata parser interprets path segments such as `/Lock/` as word-highlighting instructions and otherwise adds unintended borders around matching code tokens.

## Verification

Test observable behavior, operational guarantees, and supported substitutions. Keep representative consumer fixtures, including generated class shapes, stable after establishing the release baseline; changing them during an internal refactor requires a compatibility explanation. Avoid tests that merely mirror trivial construction or private wiring. Extend existing coverage rather than creating a test for every class mechanically.

When `composer lint` reports style-only issues, run `composer format` to let the project formatter fix them before making manual formatting edits.

Reusable test fixtures, sample classes, and test doubles should live under `tests/Support/Fixtures/<Namespace>/` instead of being declared inline in a test class file. Keep truly local one-off fakes inline only when they are not reusable and do not represent a domain/package fixture.

Tests that need writable temporary files or directories should use a test-specific subdirectory under `tests/_data/temp` instead of `sys_get_temp_dir()`. Use `$this->temp_dir('<name>')` when only the path is needed; it mirrors `codecept_data_dir()` and does not create the directory. Use `$this->prepare_temp_dir('<name>')` in `setUp()` to create a unique clean directory under that name and register it for automatic cleanup by the base test case. Only call `$this->remove_temp_dir('<name>')` manually when a test needs to remove the prepared directories before teardown.

Codeception tests run through SLIC. Use SLIC 2.3.0 or newer so PCOV-backed coverage commands are available. Use `.env.testing.slic` as the SLIC/Codeception environment file. First-time local setup is `slic here` from the directory that contains this repository, `slic use foundation` from the repository, `slic composer install`, and `slic cc build`. If host-installed dependencies conflict with the SLIC PHP version, run `slic composer update --with-all-dependencies` inside the container. Run suites with `slic run unit`, `slic run feature`, `composer test:redis` or `slic run redis`, `composer test:integration` or `slic run integration`, `composer test:wpunit` or `slic run wpunit`, and `composer test:wpcli` or `slic run wpcli`.

GitHub workflows should check out SLIC from `main`; do not pin SLIC to a release tag or commit.

Test suite meanings: `Unit` is isolated class/package behavior, `Feature` is Foundation feature behavior without bootstrapping WordPress, `redis` is real Redis behavior shared across packages and run against SLIC's Redis service, `integration` is multi-provider/container behavior that may require WordPress runtime APIs such as hooks, `wpdb`, `dbDelta()`, or globals, `wpunit` is lower-level WordPress-loaded behavior through wp-browser, and `wpcli` is the shared monorepo suite for testing WP-CLI commands through wp-browser's WPCLI module. If a PHPUnit test uses `#[DataProvider]` and must run under Codeception, also include the matching `@dataProvider` docblock because Codeception's PHPUnit loader reads docblock providers for these tests.

The `integration` suite boots WordPress in multisite mode so cross-provider behavior can exercise real site creation and `switch_to_blog()` lifecycles.

Use `integration` for behavior where multiple providers/packages must be registered together to prove the container graph works. Use `wpunit` for a single package/class where the main concern is direct WordPress API behavior. Use `wpcli` for real WP-CLI command execution shared across packages. Keep unit tests focused on portable package behavior and pure collaborators; do not build large fake WordPress runtimes in unit tests when the behavior can be covered with wp-browser.

Use `tests/WPUnitSupport/WPTestCase.php` as the base class for wpunit tests instead of extending Codeception's `WPTestCase` directly. Keep Codeception-generated actor files in `tests/CodeceptionSupport/`; that directory is ignored and excluded from lint/static analysis.

After completing a feature, run `composer test:coverage`, review `clover.xml` for missed source coverage, and add meaningful tests for uncovered behavior before considering the feature complete. Coverage uses SLIC 2.3.0+ PCOV support, runs each SLIC suite separately, and merges the serialized `.cov` artifacts with `phpcov`; run the merge through `slic composer run coverage:merge` or `slic composer run coverage:merge-html` because the coverage files contain container paths like `/var/www/html/wp-content/plugins/foundation`.

## Releases

- Adding a new split package is usually a minor SemVer release because it introduces new functionality without breaking existing packages. Use a major release only if the change also breaks an existing public API or package contract.
- Run `composer monorepo bump-interdependency <constraint>` when planning a major version release so Foundation packages that depend on each other require the new major line, for example `^3.0`. It may also be useful for a minor release when one package must require APIs added in that new minor, for example `^1.1`.
- Before publishing a release, verify the intended release-line package constraints are already committed. For a minor release such as `1.2.0`, internal Foundation package dependencies should already require the released line, for example `^1.2`.
- Publishing a GitHub release creates the release tag and triggers the tagged monorepo split. Wait for the tagged `Split Monorepo Packages and Release` workflow to succeed before considering the release complete.
- When a release includes `foundation-docs`, also verify that the tag-triggered `Deploy Documentation` workflow succeeds in the `stellarwp/foundation-docs` split repository before considering the documentation release complete.
- After a successful tagged split for a minor or major `.0` release, the split workflow automatically bumps internal package constraints and branch aliases to the next development line on `main`, for example from `^1.2` and `1.2.x-dev` to `^1.3` and `1.3.x-dev`.
- The post-release automation intentionally skips patch tags such as `1.2.1`, because patch releases should not move `dev-main` to a new minor development line.
- If the post-release automation fails, fetch the release tag and manually run `composer monorepo bump-interdependency <next-dev-constraint>`, `composer monorepo package-alias`, and `composer monorepo merge`, then commit and push the updated package `composer.json` files.
- The monorepo split workflow deploys package code to each sub-repository on pushes to `main` and when release tags are pushed.
