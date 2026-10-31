# Contributing Guide

This project does not have formalized or rigid contribution processes. We keep it simple and subscribe to a "see something, say something" philosophy with a "if it's broken, figure out where and fix it". Due to the simple architecture, it's likely that any problems encountered can be fixed within a single method or with a find and replace of a repeated line of code.

Please consider these to be guidelines. If in doubt, please create an issue and tag the [maintainers](README.md#maintainers) to discuss.

## Feature Requests and Ideas

> **Disclaimer:** This is not an official package maintained by any company. Please use at your own risk and create pull requests for any bugs that you encounter.

We do not maintain a roadmap of feature requests, however we invite you to contribute and we will gladly review your pull requests.

## Code Contributions

We have transitioned from issue-first to PR-first development. We will create an issue for any deferred work, however you can start contributing by creating a new `feature/*` or `hotfix/*` branch and opening a pull request.

Before assigning your pull request to a maintainer, run `composer prerelease` and review the GitHub Actions checks on the pull request for any errors.

All pull requests can be assigned to one or all of the maintainers at your discretion. It is helpful to add a comment with any context that the maintainer/reviewer should know or be on the look out for.

### Checks

Every check that CI runs is a script in `bin/`, so you can run the same checks locally before you push.

| Command | Script | Checks |
|---------|--------|--------|
| `composer prerelease` | `bin/test_prerelease.sh` | Runs every script below, in order, and stops at the first failure. |
| | `bin/test_composer.sh` | `composer validate --strict`, which catches `composer.json` problems before Packagist does. |
| `composer lint` | `bin/test_code_style.sh` | Code style with [Laravel Pint](https://laravel.com/docs/pint), without changing files. Run `vendor/bin/pint` to fix the reported files. |
| `composer analyse` | `bin/test_phpstan.sh` | Static analysis (see [Static Analysis](#static-analysis)). |
| `composer test` | `bin/test_unit.sh` | The Pest test suite (see [Testing](#testing)). |
| | `bin/dep_vuln.sh` | `composer audit`, which reports security advisories and abandoned packages. `composer.lock` is not committed, so run `composer update` first. |

The CI workflow in `.github/workflows/ci.yml` runs the tests on every PHP and Laravel version that `composer.json` supports. `bin/ci_matrix.php` reads the versions from the `php` and `illuminate/support` constraints, so the matrix changes with `composer.json`. When a new Laravel major is released, add it to `LARAVEL_VERSIONS` in `bin/ci_matrix.php`.

The tooling files are the same in each of the BoldlyGrow Laravel packages ([audit-log](https://github.com/boldlygrow/audit-log), [gitlab-api-client](https://github.com/boldlygrow/gitlab-api-client), [google-api-client](https://github.com/boldlygrow/google-api-client), and [okta-api-client](https://github.com/boldlygrow/okta-api-client)). This covers `bin/`, `.github/`, `.claude/skills/changelog/`, `phpstan.neon`, `pint.json`, `phpunit.xml`, `.gitignore`, and `.gitattributes`. If you change one of them, make the same change in the other packages.

### Testing

The package uses [Pest](https://pestphp.com/) (on top of PHPUnit) with [Orchestra Testbench](https://github.com/orchestral/testbench) to boot a lightweight Laravel container for feature tests. The suite lives in `tests/Feature` and covers connection validation, each `ApiToken` credential type (service account JSON keys with `subject_email` delegation, gcloud application default credentials, and the metadata server) and the token cache, `nextPageToken` pagination, and the exception and log event for each response status code.

```bash
composer install
composer test
```

`TestCase` calls `Http::preventStrayRequests()`, so every Google endpoint that a test calls (including the OAuth 2.0 token endpoint and the metadata server) must be faked with `Http::fake()`. No test can reach the network. `TestCase` also points `CLOUDSDK_CONFIG` at an empty directory and clears `GOOGLE_APPLICATION_CREDENTIALS` and `GCE_METADATA_HOST`, so the tests never use your own gcloud login or JSON key. Please add or update tests for any behavior you change.

### Static Analysis

The package is analyzed with [Larastan](https://github.com/larastan/larastan) (PHPStan) at the level in `phpstan.neon`, which is level 8.

```bash
composer analyse
```

Fix errors instead of adding them to a baseline. If an error can't be fixed, add a `// @phpstan-ignore <identifier> (<reason>)` comment on that line. `reportIgnoresWithoutComments` is enabled, so an ignore without a reason in parentheses is reported as an error.

### Changelog

Release notes live in the root `CHANGELOG.md`, newest release first. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) with two project-specific additions.

In Claude Code, run `/changelog` (or `/changelog 5.1.0`) to draft the next entry from the commits since the last tag. The skill in `.claude/skills/changelog/` follows the rules below. Use the 5.0.0 entry as the example for a major release, and the 4.1.0 entry for a minor release.

#### Format

- Each release is an `h2` heading of the form `## [5.0.0] - 2026-10-31`, with a matching link reference at the bottom of the file. Because the historical git tags are two-component (`4.2`, `4.1`, `4.0`), those link references point at the real tag names even though the heading uses the full `X.Y.Z` label.
- Categories are `h3` and use Keep a Changelog's vocabulary — **Added**, **Changed**, **Deprecated**, **Removed**, **Fixed**, **Security** — plus **Overview** and **Upgrade Steps**, which are reserved for major releases. Omit any category with nothing to report; do not write "none this release".
- Deprecations and removals go in their own category. Do not bury them inside **Changed**, where nobody looking for them will find them.
- Every non-trivial entry carries a `**Why:**` sub-bullet explaining the reasoning, and a `**How:**` sub-bullet wherever a consumer has to take action. This rationale is the point of the changelog — an entry that only restates the diff is not worth writing.
- Start each entry that has details with a short bold headline that says what changed (ex. `**Exception messages include the reason that Google returned**`), and put the details, `**Why:**`, and `**How:**` in sub-bullets underneath. Keep each sub-bullet to one or two sentences so the headlines can be skimmed. A one-line entry with no details does not need the bold.
- Large releases may group entries under `h4` thematic subheads inside a category (see 5.0.0's **Added**).
- Tag releases as `X.Y.Z` with no `v` prefix.

#### What to Include

- Write for people who use the package, and describe each change relative to the previous release tag. Something that was added and then changed or removed before a release never shipped, so leave it out.
- Read the diff, not just the commit messages. A one-line change labeled `fix:` can change behavior for existing users.
- A changed default value is a behavior change. List it under **Changed** with a `**How:**` sub-bullet for the users it affects, and check that the config file, the code fallbacks, the tests, and the README all agree on the new default.
- Leave out changes that don't affect users, such as CI, lint fixes, and test refactors, unless they change what users can rely on (for example, the supported PHP or Laravel versions).

#### Releasing

1. Add entries under `## [Unreleased]` at the top of `CHANGELOG.md` as changes are merged.
2. Choose the version. Use a major version for breaking changes, including dropping a PHP or Laravel version, a minor version for new features or a changed default, and a patch version for bug fixes and documentation only.
3. Rename `## [Unreleased]` to `## [X.Y.Z] - YYYY-MM-DD` with the release date, and add `[X.Y.Z]: https://github.com/boldlygrow/google-api-client/compare/<previous tag>...X.Y.Z` to the link references at the bottom.
4. If `README.md` links to the release section (the **Upgrade Guide** links the latest major release), update the anchor, since it includes the date (ex. `#500---2026-10-31`).
5. Run `composer prerelease`, merge to `main` after the CI checks pass, and tag the merge commit `X.Y.Z`.

### Dependencies

This package intentionally depends only on the individual `illuminate/*` components it uses (`config`, `http`, `log`, `support`) rather than the full `laravel/framework`, plus `nesbot/carbon` and [boldlygrow/audit-log](https://github.com/boldlygrow/audit-log) for logging. Service account JWT assertions are signed with the `openssl` extension, and the package doesn't depend on the Google API PHP client or any other Google SDK.

When adding functionality, prefer a specific `illuminate/*` component over pulling in the framework, and add new `require` entries with the same version span as the existing ones (ex. `^12.0 || ^13.0`). Dropping a PHP or Laravel version is a breaking change that needs a major release.

### Laravel Test Application

You can create a new Laravel application for a specific version to perform local testing with. This allows you to easily use Tinkerwell for each respective Laravel version.

```bash
# Set temporary environment variable
export SDK_LARAVEL_VERSION=13
cd ~/Code
# Create new Laravel projects
composer create-project laravel/laravel:^${SDK_LARAVEL_VERSION}.0 laravel${SDK_LARAVEL_VERSION}-pkg-test
# Create symlinks in directory
mkdir -p laravel${SDK_LARAVEL_VERSION}-pkg-test/packages/boldlygrow
ln -s ~/Code/google-api-client ~/Code/laravel${SDK_LARAVEL_VERSION}-pkg-test/packages/boldlygrow/google-api-client
# Custom repository location configuration
cd ~/Code/laravel${SDK_LARAVEL_VERSION}-pkg-test
composer config repositories.google-api-client '{"type": "path", "url": "packages/boldlygrow/google-api-client"}' --file composer.json
composer require boldlygrow/google-api-client:dev-main
php artisan vendor:publish --tag=google-api-client
# Unset temporary environment variable
unset SDK_LARAVEL_VERSION
```

You can link this package into an existing Laravel application using the following commands.

```bash
cd ~/Code/my-project-name

mkdir -p packages/boldlygrow
ln -s ~/Code/google-api-client packages/boldlygrow/google-api-client
composer config repositories.google-api-client '{"type": "path", "url": "packages/boldlygrow/google-api-client"}' --file composer.json
composer require boldlygrow/google-api-client:dev-main
php artisan vendor:publish --tag=google-api-client
```

## Custom Application Configuration

### Configuring Your Application with Working Copies of Packages

When you run `composer install`, you will get the latest copy of the packages from Packagist. However, you won't be able to see real-time changes if you change any code in the packages.

You can mitigate this problem by creating a local symlink for the package inside of your application that you're using for development and testing. By symlinking the packages into the newly created `packages` directory, you'll be able to preview and test your work without doing any Git commits (bad practice).

```bash
# Pre-Requisite (you should already have this)
# You can use any directory you want (if not using ~/Code)
cd ~/Code
git clone https://github.com/boldlygrow/google-api-client.git
```

```bash
cd ~/Code/{my-laravel-app}
mkdir -p packages/boldlygrow
cd packages/boldlygrow
ln -s ~/Code/google-api-client google-api-client
```

### Configure Local Composer Repository

Credit: [https://laravel-news.com/developing-laravel-packages-with-local-composer-dependencies](https://laravel-news.com/developing-laravel-packages-with-local-composer-dependencies)

A `path` repository symlinks the package into `vendor/` and loads its autoloader, so you don't need to add the package's namespace to your application's `autoload.psr-4`.

```bash
cd ~/Code/{my-laravel-app}

composer config repositories.google-api-client '{"type": "path", "url": "packages/boldlygrow/google-api-client"}' --file composer.json

composer require boldlygrow/google-api-client:dev-main

# Package operations: 1 install, 0 updates, 0 removals
#  - Installing boldlygrow/google-api-client (dev-main): Symlinking from packages/boldlygrow/google-api-client
```

### Validation and Config Copy

```bash
php artisan vendor:publish --tag=google-api-client

# Copied File [~/Code/google-api-client/src/Config/google-api-client.php] To [/config/google-api-client.php]
# Publishing complete.
```

### Caching Problems

If you run into any classes or files that are renamed and are throwing `Not Found` errors, you may need to use the `composer dump-autoload` command.
