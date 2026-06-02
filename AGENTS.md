# AGENTS.md

## Project

Foundation is a StellarWP Composer monorepo for reusable PHP packages intended for libraries and WordPress plugin ecosystems.

Initial packages:

- `stellarwp/foundation-container`
- `stellarwp/foundation-log`
- `stellarwp/foundation-pipeline`

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

## Container Providers

When writing providers or container registration code, prefer container-driven construction over inline factories with explicit `new` calls. Bind classes and interfaces directly when the container can autowire them.

Use contextual bindings with `$this->container->when()->needs()->give()` for scalar constructor arguments, command lists, or feature-specific substitutions. Use a factory closure only when the value must be computed or resolved from the container, and keep that closure focused on supplying the constructor dependency rather than constructing the full object.

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

After completing a feature, run `composer test:coverage`, review `clover.xml` for missed source coverage, and add meaningful tests for uncovered behavior before considering the feature complete.

## Releases

- Adding a new split package is usually a minor SemVer release because it introduces new functionality without breaking existing packages. Use a major release only if the change also breaks an existing public API or package contract.
- Run `composer monorepo bump-interdependency <version>` when planning a major version release so Foundation packages that depend on each other require the new major line. It may also be useful for a minor release when one package must require APIs added in that new minor.
- Run `composer monorepo package-alias` when `dev-main` should move to a new development line, usually after a minor or major release. Do not run it for every patch release when the current branch alias is still correct.
- The monorepo split workflow deploys package code to each sub-repository after a GitHub release is drafted.
