# Foundation

Foundation is a StellarWP Composer monorepo for reusable PHP packages intended for libraries and WordPress plugin ecosystems.

> [!NOTE]
> This monorepo splits each package out into their own sub-repository, if you only need a specific component you can install only that specific one.

## Repositories
- [stellarwp/foundation-container](https://github.com/stellarwp/foundation-container)
- [stellarwp/foundation-pipeline](https://github.com/stellarwp/foundation-pipeline)
- [stellarwp/foundation-log](https://github.com/stellarwp/foundation-log)
- [stellarwp/foundation-wpcli](https://github.com/stellarwp/foundation-wpcli)
- [stellarwp/foundation-cli](https://github.com/stellarwp/foundation-cli)

## Installation

Install the aggregate package when you want the convenient all-in-one Foundation package:

```shell
composer require stellarwp/foundation
```

The aggregate package includes all Foundation components, including the CLI package and `vendor/bin/foundation`. For lean production WordPress plugin archives, require only the split packages your plugin needs and install `stellarwp/foundation-cli` as a development dependency.

## ‍💻 Developer / Contributing Documentation

### ✅ Development requirements
- PHP 8.3+

### 🧪 Automated testing

Run all tests:

```bash
composer test
```

Run just the unit test suite:

```bash
composer test:unit
```

Run just the feature test suite:

```bash
composer test:feature
```

Generate the test coverage HTML dashboard (XDEBUG required to be enabled on your machine):

```bash
composer test:coverage-html
```

### Code Quality

Check your code style:

```bash
composer lint
```

Automatically fix your code style:

```bash
composer format
```

Static analysis:

```bash
composer analyze
```

### 🥳 Releasing a new version

Before drafting the release, run the monorepo maintenance commands that apply to the release:

| Situation                                                                                                                                                                                                                                                                                     | Command | Why |
|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------| --- | --- |
| You are planning a major version release, for example `2.0` to `3.0`. You should run this so any Foundation packages that depend on each other require the new major line, such as `^3.0`. You may also run it for a minor release if one package must require APIs added in that new minor version. | `composer monorepo bump-interdependency <version>` | Updates package-to-package dependency constraints. Use the new minimum Composer constraint, such as `^3.0` or `^1.2`. |
| The `dev-main` branch alias needs to move to a new development line, usually after a minor or major release. For example, after releasing `1.1.0`, update the alias from `1.1.x-dev` to `1.2.x-dev`. Do not run this before every patch release if the existing alias is still correct. | `composer monorepo package-alias` | Updates each package's `extra.branch-alias` using the format configured in `monorepo-builder.php`. |
| Neither of the above changed.                                                                                                                                                                                                                                                                 | No monorepo maintenance command is needed. | Continue to drafting the release. |

After any needed command, commit the updated `composer.json` files. Then draft a new release on [GitHub](https://github.com/stellarwp/foundation/releases/new), following [semver](https://semver.org/) closely.

The [monorepo split GitHub workflow](./.github/workflows/monorepo-split.yml) will deploy each project's code to their sub-repository.

Adding a new split package is usually a minor [semver](https://semver.org/) release because it adds new functionality without breaking existing packages. Use a major release only if the change also breaks an existing public API or package contract.

### Monorepo

This uses [Symplify's Monorepo Builder](https://github.com/symplify/monorepo-builder). There is a shortcut composer script you can
run to access their CLI: `composer monorepo list` to see the available commands.

#### Adding a New Package

1. Run the package creation command:

```bash
composer run foundation -- package:create <Package>
```

The package argument can be a new package component such as `WPCli`, an existing package directory, short package name, or Composer package name, for example `Log`, `foundation-log`, or `stellarwp/foundation-log`.

If the package does not exist yet, the command asks whether to create the local scaffold in `src/<Package>` and asks for the Composer package name with a default such as `stellarwp/foundation-wpcli`. The scaffold includes the required `composer.json`, `README.md`, `.gitattributes`, `.gitignore`, and `close-pull-request.yml` files. After scaffolding, the command runs `composer monorepo merge` so the root `composer.json` includes the new package.

The command runs as a dry run by default. It validates the required split package files, prints the target repository name and description, and shows the GitHub CLI commands it will run.

2. Add source code, tests, and any package-specific dependencies to the new package.

3. Once you've added the specific dependencies your package needs to its composer.json, run `composer monorepo merge` again and then `composer update` and commit the changes. This will merge the dependency changes into the root composer.json.

4. Create and configure the read-only split repository:

```bash
composer run foundation -- package:create <Package> --apply
```

The command creates the `stellarwp/foundation-<package>` repository with the standard `[READ ONLY]` description, disables issues, wiki, and projects, and relies on the package's `close-pull-request.yml` workflow to close pull requests.

#### Generating WP-CLI Commands

In a consuming WordPress project, install the generator as a development dependency:

```bash
composer require --dev stellarwp/foundation-cli
```

If the generated command will ship with the plugin, install the runtime WP-CLI package as a normal dependency:

```bash
composer require stellarwp/foundation-wpcli
```

Then generate a starter WP-CLI command:

```bash
vendor/bin/foundation make:wpcli-command Sync_Products_Command
```

The command reads the project's PSR-4 Composer autoload namespaces, writes a Snake_Case command class under `Cli/Commands` inside the selected PSR-4 root, and uses the default WP-CLI command stub from `foundation-wpcli`.

If your project adds a Composer script such as `"foundation": "@php vendor/bin/foundation"`, you may run the command as `composer run foundation -- make:wpcli-command Sync_Products_Command`.

`stellarwp/foundation-cli` is build-time developer tooling when installed as a split package. Do not register `StellarWP\Foundation\Cli\CliProvider` in a WordPress plugin application, and do not include the CLI split package in production plugin zips. Production split-package installs should normally run with Composer's `--no-dev` mode so the generator, Symfony Console, and other dev tooling are not packaged.

To customize the generated command stub in a project, create:

```text
foundation/stubs/wpcli/command.stub
```

Project-level stubs are local scaffolding assets. Add them to the consuming project's production zip exclusions, such as `.gitattributes`, when they should not be included in release archives.

## License

Copyright © 2026 Nexcess Corp.

Licensed under the GNU General Public License v2.0 or later.
See [LICENSE](./LICENSE) for details.
