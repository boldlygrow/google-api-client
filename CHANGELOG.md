# Changelog

All notable changes to `boldlygrow/google-api-client` are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [5.0.0] - 2026-10-31

### Overview

This release moves the package to the `BoldlyGrow` namespace, replaces the deprecated `provisionesta/audit` dependency with `boldlygrow/audit-log`, and adds the two credential types that the documentation already recommended but that the package could not use.

- **The package is renamed** from `provisionesta/google-api-client` to `boldlygrow/google-api-client`, and the namespace from `Provisionesta\Google` to `BoldlyGrow\Google`.
- **gcloud application default credentials and attached service accounts work.** Developers can authenticate locally with `gcloud auth application-default login`, and applications on Google Cloud can use the attached service account, without downloading, storing, or rotating a JSON key.
- **Laravel v12 and v13 are supported.** Support for Laravel v8, v9, v10, and v11 and PHP v8.0 and v8.1 is dropped.
- **Every 4xx and 5xx response throws an exception with the reason that Google returned.**
- **Service account JSON keys still work** and remain the only credential type that supports `subject_email` domain-wide delegation for the Google Workspace APIs. The API methods and the response format are unchanged.

### Added

#### Framework Support

- **Laravel v13 support**
    - The `illuminate/*` constraints are `^12.0 || ^13.0`. Laravel v8 through v11 are no longer supported (see **Removed**).

#### Credentials

- **Authenticate with gcloud application default credentials**
    - Running `gcloud auth application-default login` is enough to authenticate. The refresh token in `application_default_credentials.json` is exchanged for an access token, from `CLOUDSDK_CONFIG` if it is set, or else `$HOME/.config/gcloud` (`%APPDATA%/gcloud` on Windows).
    - **Why:** The README recommended this as the way to get started, but the package only supported service account JWT signing, so following the documentation produced a `ConfigurationException`.
    - Expired or revoked gcloud credentials throw an `AuthenticationException` that says to run `gcloud auth application-default login` again.
    - A scope that wasn't consented to during the gcloud login throws a `ConfigurationException` with the `gcloud auth application-default login --scopes=` command to grant it.
- **Authenticate with the service account attached to Google Cloud infrastructure**
    - On Compute Engine, GKE, Cloud Run, or App Engine, the access token is read from the metadata server, with no configuration and no credentials on disk. The metadata server is used only when no other credentials are configured.
    - `GCE_METADATA_HOST` overrides the metadata server host for proxy and Private Google Access setups.
    - **Why:** This was documented as a known limitation, and it is the preferred method when running on Google Cloud.
- **`subject_email` is rejected for credential types that can't use it**
    - Setting `subject_email` with gcloud credentials or the metadata server throws a `ConfigurationException` that explains that domain-wide delegation needs a service account JSON key.
    - **Why:** Google would otherwise reject the token request with a less specific error.
- **Set the JSON key contents with `GOOGLE_API_KEY_STRING`**
    - The `key_string` config key reads `GOOGLE_API_KEY_STRING`. It could previously only be set in a connection array.
    - **Why:** The documentation referred to `key_string` as a config value, but the published config file didn't have it.

#### Error Messages

- **Exception messages include the reason that Google returned**
    - Messages have the format `METHOD CODE URL (Reason) <status> <message> (Details) <field violations>`. Ex. `POST 400 https://admin.googleapis.com/admin/directory/v1/users (Reason) INVALID_ARGUMENT Invalid Input: bad_email (Details) invalid`.
    - `error.details[].fieldViolations[]` and `error.errors[].reason` are appended, so a response with the generic message `Request contains an invalid argument.` names the rejected fields (ex. `(Details) parent: Invalid parent`).
    - The `error` and `error_description` keys of the OAuth 2.0 token endpoints are read as well. If a body has none of the known keys, the body is appended, truncated to 1,000 characters.
    - A decoded copy of the URL is appended when it differs from the requested URL. The encoded URL is shown first, since it is the one that reproduces the request.
    - The audit log `errors.message` value contains the same reason.
    - **Why:** The reason was previously discarded, so a failed request showed only the method, status code, and URL.

#### Static Analysis

- **PHPStan types for API responses**
    - `ApiClient` defines the `ApiResponse`, `ApiPaginatedResponse`, `ApiErrorResponse`, `ApiResponseStatus`, and `ApiResponseHeaders` types, with the same names as `boldlygrow/okta-api-client`. Import them in your application with `@phpstan-import-type ApiResponse from ApiClient`.
    - `get()`, `post()`, `patch()`, `put()`, and `delete()` are documented as returning `ApiResponse` instead of `object`, and every array parameter is documented with its value type.
    - **Why:** With a return type of `object`, PHPStan and Larastan couldn't check the `data`, `headers`, and `status` properties that you read from a response.

#### Configuration

- The published config file has a docblock for each key.

### Changed

#### Package Architecture

- **The package is renamed to `boldlygrow/google-api-client`**
    - The `glamstack/google-api-client` and `provisionesta/google-api-client` package names are kept as Composer `replace` aliases.
    - **Why:** The project is consolidating under the maintainer's `BoldlyGrow` organization, alongside the related audit and API client packages.
- **The namespace is `BoldlyGrow\Google`**
    - It was `Provisionesta\Google`.
    - **How:** Find and replace `Provisionesta\Google` with `BoldlyGrow\Google` across your code base.

#### Audit Logging

- **`boldlygrow/audit-log` replaces `provisionesta/audit`**
    - The constraint is `^2.0.1 || ^3.0`. The logger class is `BoldlyGrow\AuditLog\AuditLog` instead of `Provisionesta\Audit\Log`, and the event types and log levels are unchanged.
    - **Why:** `provisionesta/audit` is deprecated, and `boldlygrow/audit-log` is its direct replacement.
    - **Why `2.0.1`:** audit-log 2.0.0 typed the `duration_ms` and `event_ms` parameters of `AuditLog::create()` as `?Carbon`, so an application that uses `Date::use(CarbonImmutable::class)` failed with a `TypeError` on every API call. 2.0.1 accepts any `CarbonInterface`.
    - **How:** If you call the audit logger directly, replace `Provisionesta\Audit\Log` with `BoldlyGrow\AuditLog\AuditLog`, and `Log::create()` with `AuditLog::create()`.

#### Credential Resolution

- **Credentials are resolved from the first source that is configured**
    - The order is the `key_string` and `key_path` connection values, `GOOGLE_APPLICATION_CREDENTIALS`, the gcloud application default credentials file, and then the metadata server.
    - Each credential is used according to its `type` (`service_account` or `authorized_user`), so each one uses the correct grant.
- **`GOOGLE_APPLICATION_CREDENTIALS` accepts a path to a JSON key file**
    - It accepts either a file path or the JSON key contents. 4.x accepted only the contents.
    - **Why:** Every other Google SDK treats this variable as a file path, and pointing it at a file failed with `The JSON key contents are empty.`
- **Environment variables are read from `$_SERVER` and `getenv()` as well as `$_ENV`**
    - This applies to `GOOGLE_APPLICATION_CREDENTIALS`, `CLOUDSDK_CONFIG`, `HOME`, `APPDATA`, and `GCE_METADATA_HOST`.
    - **Why:** 4.x read `GOOGLE_APPLICATION_CREDENTIALS` only from `$_ENV`, which is empty when PHP's `variables_order` doesn't include `E`, as in the default production `php.ini`.

#### Token Caching

- **A cached token expires 60 seconds before the `expires_in` that Google returns**
    - Tokens were cached for a fixed 3,540 seconds.
    - **Why:** Google can return an already-issued token that is partway through its lifetime, so a token with 10 minutes left was cached for 59 minutes and served after it expired.

#### Error Messages

- **Configuration errors name the problem and the fix**
    - When no credentials are found, the `ConfigurationException` lists every source that was checked and how to configure one, instead of `The JSON key contents are empty.`
    - A base64 encoded `GOOGLE_APPLICATION_CREDENTIALS` value is named as the problem.
    - A JSON key that can't be parsed reports the JSON error and where the key came from.
    - A service account key without `client_email`, `private_key`, or `private_key_id` names the missing keys, instead of failing with an undefined property error.
    - A `private_key` that OpenSSL can't parse is reported as a configuration error. Its newlines most often weren't escaped as `\n`.
    - A `key_path` that isn't a readable file throws a `ConfigurationException` with the path, truncated if it is too long to be a path.

#### Pagination

- `ApiClient::getPaginatedResults()`, which is `protected`, returns an object with the merged records in `data` and the `status` of the last page, instead of the records object.

#### Dependencies

- **`nesbot/carbon` is `^3.0`, and `ext-openssl` is required**
    - Laravel 12 requires Carbon 3, so Carbon 2 could no longer be installed.
    - `ext-openssl` was already needed to sign service account JWTs, and is now declared in `composer.json`.

### Removed

- **Laravel v8, v9, v10, and v11, and PHP v8.0 and v8.1 are no longer supported**
    - The `illuminate/*` constraints are `^12.0 || ^13.0`, and the PHP constraint is `^8.2`.
    - **Why:** Laravel v8 through v10 are past their maintenance windows. Laravel v11's security support ended in March 2026, and every 11.x release has security advisories that Composer blocks from installing by default.
    - **How:** Upgrade your application to Laravel v12 or v13 before you upgrade to `^5.0`. Applications on Laravel v8 through v11 can stay on `provisionesta/google-api-client:^4.0`.

### Fixed

- **Every 4xx and 5xx response throws the exception for its status code**
    - In 4.x, a status code without its own log event type, such as `206`, `304`, `409`, `410`, `502`, or `504`, failed with an `ErrorException` (`Undefined array key`) while the response was being logged, even with `GOOGLE_API_EXCEPTIONS=false`. A `409` never reached `ConflictException`.
    - A `500` response failed with an `Undefined property` error instead of throwing `ServerErrorException`, because the exception was constructed with `$response->json`, which the parsed response doesn't have.
    - A `501` or `503` response returned the response object without an exception. `ServiceUnavailableException` was listed in the README but never thrown.
    - Any 4xx or 5xx code without its own exception now throws `BadRequestException` or `ServerErrorException`.
    - A `409` is logged as `google.api.{method}.error.conflict`. Other status codes without their own event type are logged as `google.api.{method}.success` (2xx), `google.api.{method}.error.client-error` (4xx), `google.api.{method}.critical.server-error` (5xx), or `google.api.{method}.warning.unexpected-status` (anything else).
    - **Why:** An `ErrorException` doesn't include the status code or the reason that Google returned, and it was thrown before the request was logged, so these failures couldn't be handled or searched for.
    - **How:** If you catch `ErrorException` around API calls, catch the exceptions in `BoldlyGrow\Google\Exceptions` instead. If you handle a `501` or `503` response object with exceptions enabled, catch `ServerErrorException` or `ServiceUnavailableException` instead.
- **A failed page of paginated results throws its exception**
    - In 4.x, an error on a later page (ex. a `429` rate limit) was merged into the records as if it were data, with no exception. Each page is now checked like any other request, and the exception message starts with `GET|PAGINATED`.
    - With `GOOGLE_API_EXCEPTIONS=false`, pagination stops at the failed page, and the response has the failed page's `status` and the records from the pages before it.
    - **Why:** A rate limit partway through a large list returned incomplete results that looked complete.
    - **How:** If you disable exceptions, check `$response->status->successful` before you use the `data` of a paginated `get()`.
- **Connections that can't be JSON encoded no longer share a cached access token**
    - The token cache key is an MD5 hash of the JSON-encoded scope and credential identifiers (ex. the `subject_email` and `private_key_id`). In 4.x, if one of these values wasn't valid UTF-8, encoding failed and the key was the hash of an empty string.
    - Every connection that failed to encode shared that one cache key, so a connection could be given an access token that was minted for a different service account, subject, or scope.
    - `ApiToken::create()`, and any API request that needs a token, now throws a `JsonException` instead. The JWT claims also throw instead of being sent empty.
    - **Why:** An access token carries the permissions of the service account and subject that it was minted for, so it must never be reused for another connection.
    - **How:** If you see a `JsonException` from `ApiToken`, check the connection values and the `scope` argument for invalid UTF-8 bytes.
- **A misconfigured `key_path` is not written into an exception message**
    - In 4.x, setting `GOOGLE_API_KEY_PATH` to the JSON key contents instead of a path failed with a PHP warning whose message included the whole key, including the private key.
    - **Why:** Exception messages are written to logs and error trackers, where a private key must never appear.
- **`get()` handles a response without a JSON body when exceptions are disabled**
    - In 4.x, an empty or HTML body (ex. a `404` or a `503` from a proxy) failed with a `TypeError` from `property_exists()` while checking for a `nextPageToken`.
- **Request logs include the record count again**
    - 4.2 logged every request with a null `count_records` and `event_ms_per_record`, since the parsed `data` is an object, which is never countable. The pagination finished log had the same problem.
- **`CarbonImmutable` timestamps work with the API client**
    - The `$event_ms` parameter of `ApiClient::logResponse()` is `?CarbonInterface` instead of `?Carbon`.
    - **Why:** Applications that use `Date::use(CarbonImmutable::class)` failed with a `TypeError` on every API call, since `now()` returns a `CarbonImmutable`.
- `ApiClient::handleException()` returns `data` (`null`) and `headers` (an empty array) with the `error` and `status` properties, so code that reads `$response->data` from an error response doesn't fail with an undefined property.
- A connection array validation error has a space between the heading and `(Reason)`.
- The `customer` connection value is validated as a string. The validation rule checked a `customer_id` key, which the config and `query_keys` don't use.
- The docblock for `ApiClient::throwExceptionIfEnabled()` referred to `OKTA_API_EXCEPTIONS` instead of `GOOGLE_API_EXCEPTIONS`.

### Upgrade Steps

1. **Upgrade Laravel and PHP first.** Your application must run Laravel v12 or v13. Laravel v12 supports PHP v8.2 or newer, and Laravel v13 requires PHP v8.3 or newer. Applications on Laravel v8 through v11 can stay on `provisionesta/google-api-client:^4.0`.
2. **Update the package.** Replace `provisionesta/google-api-client` with `"boldlygrow/google-api-client": "^5.0"` in your `composer.json`, and run `composer update boldlygrow/google-api-client`.
3. **Update the namespace.** Find and replace `Provisionesta\Google` with `BoldlyGrow\Google`. If you call the audit logger directly, replace `Provisionesta\Audit\Log` with `BoldlyGrow\AuditLog\AuditLog`, and `Log::create()` with `AuditLog::create()`.
4. **Publish the config file again** with `php artisan vendor:publish --tag=google-api-client` to get the docblocks and the `key_string` key, and copy over any values that you customized.
5. **Review your exception handling.** Every 4xx and 5xx response now throws an exception (see **Fixed**). If you disable exceptions, check the `status` of paginated responses.
6. **Optionally, authenticate locally without a JSON key.** Run `gcloud auth application-default login`, and remove `GOOGLE_API_KEY_PATH`, `GOOGLE_API_KEY_STRING`, and `GOOGLE_APPLICATION_CREDENTIALS` from your `.env` file.
    - The default scopes are `cloud-platform`, `sqlservice.login`, `userinfo.email`, and `openid`. If your application needs another scope, pass every scope that it uses to `gcloud auth application-default login --scopes=`.
    - Google Workspace APIs that use `subject_email` still require a service account key with domain-wide delegation.

Your existing connection configuration, service account keys, and API calls keep working after these steps. Review the `README.md` for the latest usage.

## [4.2.0] - 2025-04-27

### Added

- **Laravel v12 support**
    - The `illuminate/*` constraints are `^8.0 || ^9.0 || ^10.0 || ^11.0 || ^12.0`.

## [4.1.0] - 2024-03-22

### Changed

- **`nesbot/carbon` 3.x is allowed**
    - The constraint is `^2.67 || ^3.0`.
    - **Why:** Laravel v11 applications can use Carbon 3.
- **`provisionesta/audit` 1.1 or newer is required**
    - The constraint is `^1.1` instead of `^1.0`.

## [4.0.0] - 2024-03-18

### Overview

This is the first release of `provisionesta/google-api-client`. It is a rewrite and consolidation of the `glamstack/google-auth-sdk`, `glamstack/google-workspace-sdk`, and `glamstack/google-cloud-sdk` packages that focuses on the developer experience and the lessons learned from them.

It is not backward compatible with those packages, so treat it as a new package and reimplement any API calls that used them.

### Added

- **One `ApiClient` for any Google API endpoint**
    - `get()`, `post()`, `patch()`, `put()`, and `delete()` accept the full URL of any endpoint in the [Google API Explorer](https://developers.google.com/apis-explorer), instead of an SDK method for each endpoint.
    - Each response is an object with the parsed `data`, the `headers`, and the `status`.
    - **Why:** The official Google API client for PHP is generated from another language and doesn't follow Laravel conventions, so it is hard to use and its responses are hard to parse.
- **Service account authentication with the `ApiToken` class**
    - A JWT is signed with the service account JSON key from `GOOGLE_API_KEY_PATH`, a `key_string`, or the `GOOGLE_APPLICATION_CREDENTIALS` contents, and exchanged for an access token that is encrypted and cached.
    - `GOOGLE_API_SUBJECT_EMAIL` impersonates a user with domain-wide delegation for the Google Workspace APIs.
- **`get()` follows the `nextPageToken` and merges every page of results**
- **`query_keys` add the `customer`, `domain`, or `subject_email` connection values to a request**
    - **Why:** Many Google Workspace Directory API endpoints return a generic `400 Bad Request` without the `customer` query parameter.
- **Connection arrays for more than one Google account**
    - Each request can pass its own `connection` array instead of using the `GOOGLE_API_*` variables.
- **Every request is logged with `provisionesta/audit`**
    - Each log entry has an `event_type` (ex. `google.api.get.success`) for searching and alerting.
- **An exception for each 4xx and 5xx status code**
    - Set `GOOGLE_API_EXCEPTIONS=false` to handle the `status` of each response yourself.
- **Laravel v8, v9, v10, and v11 on PHP v8.0 or newer**

[5.0.0]: https://github.com/boldlygrow/google-api-client/compare/4.2...5.0.0
[4.2.0]: https://github.com/boldlygrow/google-api-client/compare/4.1...4.2
[4.1.0]: https://github.com/boldlygrow/google-api-client/compare/4.0...4.1
[4.0.0]: https://github.com/boldlygrow/google-api-client/releases/tag/4.0
