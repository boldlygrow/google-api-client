# Contributing Guide

This project does not have formalized or rigid contribution processes. We keep it simple and subscribe to a "see something, say something" philosophy with a "if it's broken, figure out where and fix it". Due to the simple architecture, it's likely that any problems encountered can be fixed within a single method or with a find and replace of a repeated line of code.

Please consider these to be guidelines. If in doubt, please create an issue and tag the [maintainers](README.md#maintainers) to discuss.

## Feature Requests and Ideas

> **Disclaimer:** This is not an official package maintained by any company. Please use at your own risk and create merge requests for any bugs that you encounter.

We do not maintain a roadmap of feature requests, however we invite you to contribute and we will gladly review your merge requests.

## Code Contributions

We have transitioned from issue-first to PR-first development. We will create an issue for any deferred work, however you can start contributing by creating a new `feature/*` or `hotfix/*` branch and create a merge request.

Before assigning your PR to a maintainer, please review the pipeline CI job outputs for any errors and fix anything that appears.

All merge requests can be assigned to one or all of the maintainers at your discretion. It is helpful to add a comment with any context that the maintainer/reviewer should know or be on the look out for.

### Laravel Test Application

You can create a new Laravel application for a specific version to perform local testing with. This allows you to easily use Tinkerwell for each
respective Laravel version.

```bash
# Set temporary environment variable
export SDK_LARAVEL_VERSION=10
cd ~/Code
# Create new Laravel projects
composer create-project laravel/laravel:^${SDK_LARAVEL_VERSION}.0 laravel${SDK_LARAVEL_VERSION}-pkg-test
# Create symlinks in directory
mkdir -p laravel${SDK_LARAVEL_VERSION}-pkg-test/packages/provisionesta
ln -s ~/Code/google-api-client ~/Code/laravel${SDK_LARAVEL_VERSION}-pkg-test/packages/provisionesta/google-api-client
# Custom repository location configuration
cd ~/Code/laravel${SDK_LARAVEL_VERSION}-pkg-test
sed -i '.bak' -e 's/seeders\/"/&,\n            "BoldlyGrow\\\\Google\\\\": "packages\/provisionesta\/google-api-client\/src"/g' composer.json
composer config repositories.google-api-client '{"type": "path", "url": "packages/provisionesta/google-api-client"}' --file composer.json
composer require provisionesta/google-api-client:dev-main
php artisan vendor:publish --tag=google-api-client
# Unset temporary environment variable
unset SDK_LARAVEL_VERSION
```

You can link this package into an existing Laravel application using the following commands.

```bash
ce ~/Code/my-project-name

mkdir -p packages/provisionesta
ln -s ~/Code/provisionesta/google-api-client packages/provisionesta/google-api-client
sed -i '.bak' -e 's/seeders\/"/&,\n            "BoldlyGrow\\\\Google\\\\": "packages\/provisionesta\/google-api-client\/src"/g' composer.json
composer config repositories.google-api-client '{"type": "path", "url": "packages/provisionesta/google-api-client"}' --file composer.json
composer require provisionesta/google-api-client:dev-main
php artisan vendor:publish --tag=google-api-client
```

## Custom Application Configuration

### Configuring Your Application with Working Copies of Packages

When you run `composer install`, you will get the latest copy of the packages from the GitHub and GitLab repositories. However, you won't be able to see real-time changes if you change any code in the packages.

You can mitigate this problem by creating a local symlink (with resolved namespaces) for the package inside of your application that you're using for development and testing. By symlinking the packages into the newly created `packages` directory, you'll be able to preview and test your work without doing any Git commits (bad practice).

```bash
# Pre-Requisite (you should already have this)
# You can use any directory you want (if not using ~/Code)
cd ~/Code
git clone https://gitlab.com/provisionesta/google-api-client.git
```

```bash
cd ~/Code/{my-laravel-app}
mkdir -p packages/provisionesta
cd packages/provisionesta
ln -s ~/Code/google-api-client google-api-client
```

### Application Composer

Update the `composer.json` file in your testing application (not the package) to add the package to the `autoload.psr-4` array (append the array, don't replace anything).

```json
# ~/Code/{my-laravel-app}/composer.json

"autoload": {
    "psr-4": {
        "App\\": "app/",
        "BoldlyGrow\\Google\\": "packages/provisionesta/google-api-client/src",
    }
},
```

### Configure Local Composer Repository

Credit: [https://laravel-news.com/developing-laravel-packages-with-local-composer-dependencies](https://laravel-news.com/developing-laravel-packages-with-local-composer-dependencies)

```bash
cd ~/Code/{my-laravel-app}

composer config repositories.google-api-client '{"type": "path", "url": "packages/provisionesta/google-api-client"}' --file composer.json

composer require provisionesta/google-api-client:dev-main

# Package operations: 1 install, 0 updates, 0 removals
#  - Installing provisionesta/google-api-client (dev-main): Symlinking from packages/provisionesta/google-api-client
```

### Validation and Config Copy

```bash
php artisan vendor:publish --tag=google-api-client

# Copied File [/Users/dmurphy/Code/google-api-client/src/Config/google-api-client.php] To [/config/google-api-client.php]
# Publishing complete.
```

### Caching Problems

If you run into any classes or files that are renamed and are throwing `Not Found` errors, you may need to use the `composer dump-autoload` command.
