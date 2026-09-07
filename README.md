# Google API Client

[[_TOC_]]

## Overview

The Google API Client is an open source [Composer](https://getcomposer.org/) package for use in Laravel applications for connecting to Google for provisioning and deprovisioning of resources, particularly in Google Workspace (Admin SDK) and Google Cloud.

Please use at your own risk and create merge requests for any bugs that you encounter.

### Problem Statement

The official Google API client for PHP is challenging to use and does not use common conventions or namespacing used by other Composer packages in the Laravel ecosystem. This is primarily since it was created using another language and the PHP library is automatically generated rather than human generated. Due to the vastness of the Google products and services and different APIs, this results in a clunky experience and the documentation is difficult to find and API response results are difficult to parse. The authors of this package have spent hundreds of hours trying to consume less than 10 endpoints and gave up and decided to build something to scratch our own itch and help other Laravel Artisans.

Instead of providing an SDK method for every endpoint in the API documentation, we have taken a simpler approach by providing a universal `ApiClient` that can perform `GET`, `POST`, `PUT`, and `DELETE` requests to any endpoint that you find in the [Google API documentation](https://developers.google.com/apis-explorer).

This builds upon the simplicity of the [Laravel HTTP Client](https://laravel.com/docs/10.x/http-client) that is powered by the [Guzzle HTTP client](http://docs.guzzlephp.org/en/stable/) to provide "last lines of code parsing" for Google API responses to improve the developer experience.

The value of this API Client is that it handles the API request logging, response pagination, and 4xx/5xx exception handling for you. Rate limit errors will be thrown, however rate limit backoff is not available due to non-standardized variations across various Google API services.

### Example Usage

```php
use BoldlyGrow\Google\ApiClient;

// Create a group
// https://developers.google.com/admin-sdk/directory/reference/rest/v1/groups/insert
$group = ApiClient::post(
    url: "https://admin.googleapis.com/admin/directory/v1/groups",
    scope: "https://www.googleapis.com/auth/admin.directory.group",
    form_data: [
        "name" => "Hack the Planet Engineers",
        "email" => "elite-engineers@example.com"
    ],
);

// {
//     +"data": {
//       +"id": "0a1b2c3d4e5f6g7",
//       +"email": "elite-engineers@example.com",
//       +"name": "Hack the Planet Engineers",
//       +"description": "",
//       +"adminCreated": true,
//     },
//     +"headers": [
//        ...
//     ],
//     +"status": {
//       +"code": 200,
//       +"ok": true,
//       +"successful": true,
//       +"failed": false,
//       +"serverError": false,
//       +"clientError": false,
//     },
//   }

// Get a list of records
// https://developers.google.com/admin-sdk/directory/reference/rest/v1/groups/list
$groups = ApiClient::get(
    url: 'https://admin.googleapis.com/admin/directory/v1/groups',
    scope: 'https://www.googleapis.com/auth/admin.directory.group',
    query_data: [],
    query_keys: ['customer']
);

foreach($groups->data as $group) {
    dd($group);
}

// {
//     +"kind": "admin#directory#group",
//     +"id": "0a1b2c3d4e5f6g7",
//     +"email": "elite-engineers@example.com",
//     +"name": "Hack the Planet Engineers",
//     +"directMembersCount": "0",
//     +"description": "",
//     +"adminCreated": true,
//     +"nonEditableAliases": [
//         "elite-engineers@example.com.test-google-a.com",
//     ],
// },

// Get a specific record
// https://developers.google.com/admin-sdk/directory/reference/rest/v1/groups/get
// $group_id = '0a1b2c3d4e5f6g7';
$group_id = 'elite-engineers@example.com';
$existing_group = ApiClient::get(
    url: 'https://admin.googleapis.com/admin/directory/v1/groups/' . $group_id,
    scope: 'https://www.googleapis.com/auth/admin.directory.group',
);

dd($existing_group);

// {
//     +"data": {
//         +"id": "0a1b2c3d4e5f6g7",
//         +"email": "elite-engineers@example.com",
//         +"name": "Hack the Planet Engineers",
//         +"directMembersCount": "0",
//         +"description": "",
//         +"adminCreated": true,
//         +"nonEditableAliases": [
//             "elite-engineers@example.com.test-google-a.com",
//         ],
//     },
//     +"headers": [
//         ...
//     ],
//     +"status": {
//         +"code": 200,
//         +"ok": true,
//         +"successful": true,
//         +"failed": false,
//         +"serverError": false,
//         +"clientError": false,
//     },
// }

$group_name = $group->data->name;
// Hack the Planet Engineers

// Update a group (patch)
// https://developers.google.com/admin-sdk/directory/reference/rest/v1/groups/patch
// $group_id = '0a1b2c3d4e5f6g7';
$group_id = 'elite-engineers@example.com';
$response = ApiClient::patch(
    url: 'https://admin.googleapis.com/admin/directory/v1/groups/' . $group_id,
    scope: 'https://www.googleapis.com/auth/admin.directory.group',
    form_data: [
        'description' => 'This group contains engineers that have liberated the garbage files.'
    ],
);

// Delete a group
// https://developers.google.com/admin-sdk/directory/reference/rest/v1/groups/delete
// $group_id = '0a1b2c3d4e5f6g7';
$group_id = 'elite-engineers@example.com';
$response = ApiClient::delete(
    url: 'https://admin.googleapis.com/admin/directory/v1/groups/' . $group_id,
    scope: 'https://www.googleapis.com/auth/admin.directory.group',
);
```

### Issue Tracking and Bug Reports

We do not maintain a roadmap of feature requests, however we invite you to contribute and we will gladly review your merge requests.

Please create an [issue](https://github.com/boldlygrow/google-api-client/issues) for bug reports.

### Contributing

Please see [CONTRIBUTING.md](CONTRIBUTING.md) to learn more about how to contribute.

### Maintainers

| Name | GitHub Handle | Email |
|------|---------------|-------|
| [Jeff Martin](https://www.linkedin.com/in/jeffersonmmartin/) | [@jeffersonmartin](https://github.com/jeffersonmartin) | `jeff [at] boldlygrow [dot] us` |

### Contributor Credit

- Dillon Wheeler
- Jeff Martin

## Installation

### Requirements

| Requirement | Version                     |
|-------------|-----------------------------|
| PHP         | `^8.2`                      |
| Laravel     | `^11.0`, `^12.0`, `^13.0`   |

### Upgrade Guide

See the [changelog](https://github.com/boldlygrow/google-api-client/tree/main/changelog) for release notes.

Still using `glamstack/*`? This package is a replacement for `glamstack/google-auth-sdk`, `glamstack/google-workspace-sdk`, and `glamstack/google-cloud-sdk`, however has been fully refactored and requires re-implementation for all previous usage.

### Add Composer Package

```plain
composer require boldlygrow/google-api-client:^5.0
```

If you are contributing to this package, see [CONTRIBUTING.md](CONTRIBUTING.md) for instructions on configuring a local composer package with symlinks.

### Publish the configuration file

**This is optional**. The configuration file specifies which `.env` variable names that that the API connection is stored in. You only need to publish the configuration file if you want to rename the `GOOGLE_API_*` `.env` variable names.

```plain
php artisan vendor:publish --tag=google-api-client
```

### Environment Variables

Add the following variables to your `.env` file. You can add these anywhere in the file on a new line, or add to the bottom of the file (your choice).

Any commented out variables are optional and show the default value if not set.

```bash
# GOOGLE_API_CUSTOMER="my_customer"
# GOOGLE_API_DOMAIN=""
# GOOGLE_API_EXCEPTIONS=true
# GOOGLE_API_KEY_PATH=""
GOOGLE_API_SUBJECT_EMAIL=""
```

#### `GOOGLE_API_CUSTOMER`

The [customer number](https://support.google.com/a/answer/10070793?hl=en) of the Google Account that the API's will be run on. This will need to match the customer number that the Service Account is associated with. Google provides an alias `my_customer` that uses the customer ID of the service account by default.

#### `GOOGLE_API_DOMAIN`

The domain in the Google Workspace organization that you want to filter results to. This should be set to your primary email domain name. When working with Workspace Groups and Users, the `domain` can be added to the ApiClient request `query_params` array to filter results to only this domain name. You can also leave this blank for testing.

#### `GOOGLE_API_EXCEPTIONS`

Whether to throw exceptions when a 4xx or 5xx error is received. If `false`, you can catch and handle exceptions based on the `status` array returned in each response.

#### `GOOGLE_API_KEY_PATH`

You can specify the full operating system path to the JSON key file. For security reasons, this should be saved outside the Laravel directory unless you have configured proper file permissions in storage/keys or similar.

You should not be storing and using JSON key files unless you cannot use the [gcloud CLI](#local-development-environment-with-gcloud-cli) or [attached service account](#gcp-infrastructure-with-attached-service-account-iam-authentication) approaches. If your architecture supports it, you should store your variables in CI/CD variables or a secrets vault (ex. Ansible Vault, AWS Parameter Store, GCP Secrets Manager, HashiCorp Vault, etc.) instead of locally on the server.

#### `GOOGLE_API_SUBJECT_EMAIL`

The email of the address to run the Google Workspace API as. This is not related to permissions or granting access, it just needs to be a valid user email that has permissions for the same action in the Admin UI.

## API Credentials

API authentication with Google Cloud, Google Workspace and other Google APIs is complex and can be confusing. These instructions are designed to get you started as quickly and securely as possible. See the [Google API authentication documentation](https://cloud.google.com/docs/authentication) to learn more.

This package uses the `GOOGLE_APPLICATION_CREDENTIALS` environment variable, a JSON key file, or a JSON string passed into a [connection array](#connection-arrays) with a database value at runtime, and will generate bearer tokens using OAuth2 JWT automatically that is used for calling an API endpoint.

**Known Limitation:** Due to architectural and technical discovery challenges, you might be able to use GCP instance-level attached service accounts, however we have not been able to sufficiently test this.

In most cases, this will either be a [GCP Project Service account](https://cloud.google.com/iam/docs/service-account-overview) or [Application Default Credentials](https://cloud.google.com/docs/authentication/provide-credentials-adc).

If you have the `GOOGLE_APPLICATION_CREDENTIALS` environment variable specified, leave the `GOOGLE_API_KEY_FILE` variable commented out. If you have downloaded the JSON key from GCP, see instructions for using the [GOOGLE_API_KEY_PATH](#google_api_key_path) variable.

If you have your connection secrets stored in your database or secrets manager, you can override the `config/google-api-client.php` configuration or provide a connection array on each request. See [connection arrays](#connection-arrays) to learn more.

### API Key Precedence

Each API request checks for the existence of the key in the following order and will use the first value that it finds.

1. The `key_string` key exists in an array passed to the `connection` parameter for a GET, POST, PATCH, PUT, or DELETE request.
2. The `key_path` key exists in an array passed to the `connection` parameter of a request.
3. The `key_path` key is set in the `.env` file or as a `GOOGLE_API_KEY_PATH` environment variable.
4. The `GOOGLE_APPLICATION_CREDENTIALS` environment variable is set either on you or your configuration-as-code on your server, or using the `gcloud auth application-default login` command.
5. (Untested) Metadata server credentials for GCP instance, cluster, container, CloudRun, etc.

If no valid JSON key can be found, an log message will be created and `BoldlyGrow\Google\Exceptions\ConfigurationException` will be thrown with the `google.api.validate.error.empty` event type.

### Security Best Practices

#### No Shared Tokens

Do not use an API service account or key that you have already created for another application or script. You should generate a new service account and key for each use case.

This is helpful during security incidents when a key needs to be revoked on a compromised system and you do not want other systems that use the same user or service account to be affected since they use a different key that wasn't revoked.

#### API Token Storage

> **Best Practice:** You should not be storing and using JSON key files unless you cannot use the [gcloud CLI](#local-development-environment-with-gcloud-cli) or [attached service account](#gcp-infrastructure-with-attached-service-account-iam-authentication) approaches. If your architecture supports it, you should store your variables in CI/CD variables or a secrets vault (ex. Ansible Vault, AWS Parameter Store, GCP Secrets Manager, HashiCorp Vault, etc.) instead of locally on the server.

Do not add your API key to any `config/*.php` files to avoid committing to your repository (secret leak).

All JSON API keys should be usually be saved as a `.json` file in a secure location in the filesystem and referenced by the [GOOGLE_API_KEY_PATH](#google_api_key_path) `.env` variable.

You can also use the `GOOGLE_APPLICATION_CREDENTIALS` environment variable which is used if no values are set in the `.env` file.

See the Google documentation for additional best practices:

- [Service Account Best Practices](https://cloud.google.com/iam/docs/best-practices-service-accounts)
- [Service Accounts in Deployment Pipelines](https://cloud.google.com/iam/docs/best-practices-for-using-service-accounts-in-deployment-pipelines)
- [Service Account Keys Best Practices](https://cloud.google.com/iam/docs/best-practices-for-managing-service-account-keys)

### Create a JSON Key

There are several methods to statically or dynamically use JSON key credentials. This is similar in concept to using AWS IAM Access Keys and Secrets (a JSON credential file) or IAM Role Assumption.

Follow the steps that are most applicable to your environment.

There are additional advanced use cases with secrets managers that are not covered here. Regardless of method, the API Client simply needs either the `GOOGLE_APPLICATION_CREDENTIALS` environment variable or `GOOGLE_API_KEY_PATH` environment variable or `.env` variable value to be set.

#### Local Development Environment with `gcloud` CLI

> This is **recommended method for getting started**. You may need to reauthenticate from time to time, especially if you use `gcloud` actively to manage multiple GCP projects or resources. Simply use the `gcloud auth login` and/or `gcloud auth application-default login` commands needed.

1. **(Prerequisite)** [Install](https://cloud.google.com/sdk/docs/install) and [initialize](https://cloud.google.com/sdk/docs/initializing) the `gcloud` SDK on your computer.
2. Run `gcloud auth login` to [authenticate](https://cloud.google.com/sdk/docs/authorizing) with your Google Cloud organization.
3. Run `gcloud auth application-default login` to [automatically configure](https://cloud.google.com/sdk/docs/authorizing#adc) the `GOOGLE_APPLICATION_CREDENTIALS` environment variable on your machine.
4. You can see the key that was configured in `~/.config/gcloud/application_default_credentials.json`. Do **not** copy this to your Laravel repository or another location.
    > The format of this key looks slightly different than a JSON key that you would download, however Google can parse it anyway during the bearer token generation behind the scenes.
5. You're all set! You do not need to touch or move any JSON keys or update the `.env` variables. All API calls use your email address's assigned roles and permissions.
    > Keep in mind that the API client automatically uses the `GOOGLE_APPLICATION_CREDENTIALS` environment variable if no `GOOGLE_API_KEY_PATH` or `GOOGLE_API_KEY_STRING` value is set.

See the [Google documentation](https://cloud.google.com/docs/authentication/provide-credentials-adc#local-dev) if you need additional assistance.

#### GCP Infrastructure with Attached Service Account IAM Authentication

> **Known Limitation:** Due to architectural and technical discovery challenges, you might be able to use GCP instance-level attached service accounts, however we have not been able to sufficiently test this. Please fall back to [GCP Project Service Account Key](#gcp-project-service-account-key) and setting the `GOOGLE_APPLICATION_CREDENTIALS` environment variable if needed.

If your application is running on Google Cloud infrastructure or managed service, you can use IAM authentication at the machine/resource level instead of the application level. Many Google Cloud services (ex. Compute Engine virtual machines, Google Kubernetes Engine clusters, Cloud Run deployments, AppEngine, etc.) let you attach a service account that can be used to provide credentials for accessing Google Cloud APIs. If ADC does not find credentials it can use in either the `GOOGLE_APPLICATION_CREDENTIALS` environment variable or the well-known location for Google Account credentials, it uses the metadata server to get credentials for the service where the code is running.

Using the credentials from the attached service account is the **preferred method if your Laravel application is running on Google Cloud**.

See the Google documentation and best practices to learn more.

- [Application Default Credentials](https://cloud.google.com/docs/authentication/application-default-credentials)
- [Attached service accounts](https://cloud.google.com/docs/authentication/application-default-credentials#attached-sa)
- [Enable Service Accounts for Compute Engine Instances](https://cloud.google.com/compute/docs/access/create-enable-service-accounts-for-instances)

#### GCP Project Service Account Key

> This is the manual method and is not recommended unless you cannot use the [gcloud CLI](#local-development-environment-with-gcloud-cli) or [attached service account](#gcp-infrastructure-with-attached-service-account-iam-authentication) approaches.

##### Create a Key

1. **(Prerequisite)** Create a GCP project or choose an existing project that you can create a service account user in.
2. Navigate to [https://console.cloud.google.com/iam-admin/serviceaccounts](https://console.cloud.google.com/iam-admin/serviceaccounts).
3. Choose your project from the dropdown menu in the top left corner.
4. Click the **Create Service Account** button at the top of the page.
5. Use the following recommended values or choose your own.
    - Service Account Name: `Laravel Google API Client`
    - Service Account ID: `laravel-app`
    - Description: (blank)
    - Project Roles: (see [service account project roles](#service-account-project-roles) instructions)
    - Grant Users: (blank)
6. Click the **Done** button.
7. Click on the linked name of your new service account in the table. If you have a long list of service accounts in this GCP project, you can use the search bar (ex. `laravel-app`).
8. Click the **Keys** tab at the top of the page.
9. Click the **Add Key > Create a new key** and choose `JSON` type.
10. The key will be automatically downloaded.

##### Storing your Key on Mac

> **Best Practice:** If possible, you should set the `GOOGLE_APPLICATION_CREDENTIALS` environment variable with the JSON key contents instead of saving the JSON key file to the local disk.

All of the steps below are recommended getting started instructions. You can choose to store your JSON key wherever you like as long as it is in a secure location with appropriate permissions (ex. 0600) and not accidentally committed it into your code repository.

1. Open your Terminal or iTerm.
2. Run `mkdir -p ~/.config/gcloud/service-accounts` make a directory for service accounts.
3. Run `cd ~/.config/gcloud/service-accounts` to change to the new directory.
4. Run `cp ~/Downloads/{project-name}-###########.json .` to move your service account to the new (current) directory.
    > You can use tab complete (or optionally rename the file).
5. Run `realpath {project-name}-###########.json` to get the full path to this file.

##### Updating your Laravel Environment Variable

1. Use your IDE (ex. VS Code) to open your Laravel application repository.
2. Open and edit the `.env` file.
3. Uncomment `GOOGLE_API_KEY_PATH` and set the value to the file path from your Terminal.

```plain
GOOGLE_API_KEY_PATH="/Users/dmurphy/.config/gcloud/service-accounts/{project-name}-###########.json"
```

##### Grant Permissions to Key

You will need to [add permissions for Google Workspace](#add-permissions-for-google-workspace) and/or [add permissions for Google Cloud](#add-permissions-for-google-cloud) depending on the API endpoints that you will be using.

### Service Account Project Roles

- If you will be performing API calls that manage resources in **this** GCP project, set this to `Basic > Editor` as a starting point (or one or more granular [GCP roles](https://cloud.google.com/iam/docs/understanding-roles#predefined_roles)).
- Leave this blank and continue without assigning a role if this will be used for GCP organization-level management, folder-level management of child projects, or a **different** GCP project's resources.
- Leave this blank and continue without assigning a role if this will be used for Google Workspace management.

### Add Permissions for Google Workspace

To use Google Workspace (Admin API) endpoints for Groups and Users, you will need to add the Oauth Client ID of the service account to [Domain-wide delegation](https://support.google.com/a/answer/162106?hl=en#zippy=%2Cset-up-domain-wide-delegation-for-a-client) with the respective scopes for the endpoints that you want to call. There is alternative approach with adding a service account to an admin role, however it is not as intuitive and does not always work as expected.

See the [Google documentation](https://developers.google.com/workspace/guides/create-credentials#optional_set_up_domain-wide_delegation_for_a_service_account) for domain wide delegation step by step instructions. See [API Scopes](#api-token-permissions-and-scopes) for more details on which scopes to grant to the service account.

See [Enabling APIs in Google Cloud](#enabling-apis-in-google-cloud) for the steps to enable the `Admin SDK API` and any other APIs that you may need.

### Add Permissions for Google Cloud

For Google Cloud, you will need to add the gcloud user email address (ex. `dmurphy@example.com`) or service account email address (ex. `laravel-app@{project}.iam.gserviceaccount.com`) as an IAM principal at the organization-level, folder-level or project-level (or multiple as needed) with either a [custom role](https://cloud.google.com/iam/docs/creating-custom-roles) or a [pre-defined role](https://cloud.google.com/iam/docs/understanding-roles#predefined_roles) when managing resources in any given organization, folder, or project respectively.

See the [Google documentation](https://cloud.google.com/iam/docs/granting-changing-revoking-access) to learn more about granting, changing, and revoking access to GCP organization, folders, and projects.

### Enabling APIs in Google Cloud

If you are using a service account (not using `gcloud auth`), you need to enable the respective service API in the GCP project where the service account is created in (ex. `@{project}.iam.gserviceaccount.com`). This is needed for Google Workspace service account too.

1. Navigate to [https://console.cloud.google.com/apis/library](https://console.cloud.google.com/apis/library).
2. Search for the API name (common examples below).
    - Admin SDK API (for Google Workspace Groups and Users)
    - Google Drive API
    - Google Sheets API
    - Cloud Resource Manager API
    - Identity and Access Management (IAM) API
3. Click the **Enable** button. This can take a few moments to complete.
    > If the **Manage** button is visible, the API is already enabled and no action is required.

### API Token Permissions and Scopes

Google uses OAUTH scopes for permission management within Google Workspace.

You need to specify one of the scopes for that specific endpoint (that your API key has been authorized to use). You will find a list of scopes on the REST API documentation for the specific endpoint that you are calling.

Here are some common ones that you will likely use to get you started:

- `https://www.googleapis.com/auth/admin.directory.group`
- `https://www.googleapis.com/auth/admin.directory.user`
- `https://www.googleapis.com/auth/cloud-identity`
- `https://www.googleapis.com/auth/cloud-platform`

You can see a full list of scopes in the [Google documentation](https://developers.google.com/identity/protocols/oauth2/scopes).

### Connection Arrays

The variables that you define in your `.env` file are used by default unless you set the connection argument with an array containing the required arguments.

This approach is recommended if your Google service account JSON key is stored in your database (ex. multiple-tenant architecture or isolated service accounts with granular permissions).

The `key_string` parameter can be used for passing the JSON array as a string.

> **Security Warning:** Do not commit a hard coded API token into your code base. This should only be used when using dynamic variables that are stored in your database or secrets manager.

```php
use App\Models\GoogleServiceAccount;
use BoldlyGrow\Google\ApiClient;

class MyClass
{
    private array $connection;

    public function __construct($service_account_id)
    {
        $service_account = GoogleServiceAccount::findOrFail($service_account_id);

        $this->connection = [
            'customer' => 'my_customer',
            'exceptions' => true,
            'key_string' => $service_account->json_key,
            'subject_email' => $service_account->subject_email
        ];
    }

    public function listGroups($group_key)
    {
        return ApiClient::get(
            url: 'https://admin.googleapis.com/admin/directory/v1/groups',
            scope: 'https://www.googleapis.com/auth/admin.directory.group',
            query_keys: ['customer'],
            connection: $this->connection,
        )->data;
    }
}
```

## API Requests

You can make an API request to any of the resource endpoints in the [Google API documentation](https://developers.google.com/apis-explorer).

**Just getting started?** Explore the [Google Workspace groups](https://developers.google.com/admin-sdk/directory/reference/rest/v1/groups), [Google Workspace users](https://developers.google.com/admin-sdk/directory/reference/rest/v1/users), [Cloud Identity Groups](https://cloud.google.com/identity/docs/reference/rest/v1/groups), and [Google Cloud Compute Engine](https://cloud.google.com/compute/docs/reference/rest/v1) endpoints.

### Dependency Injection

If you include the fully-qualified namespace at the top of of each class, you can use the class name inside the method where you are making an API call.

```php
use BoldlyGrow\Google\ApiClient;

class MyClass
{
    public function getGroup($group_id)
    {
        return ApiClient::get(
            url: 'https://admin.googleapis.com/admin/directory/v1/groups/' . $group_id,
            scope: 'https://www.googleapis.com/auth/admin.directory.group'
        )->data;
    }
}
```

If you do not use dependency injection, you need to provide the fully qualified namespace when using the class.

```php
class MyClass
{
    public function getGroup($group_id)
    {
        return \BoldlyGrow\Google\ApiClient::get(
            url: 'https://admin.googleapis.com/admin/directory/v1/groups/' . $group_id,
            scope: 'https://www.googleapis.com/auth/admin.directory.group'
        )->data;
    }
}
```

### Class Instantiation

We transitioned to using static methods in v4.0 and you do not need to instantiate the ApiClient class.

```php
use BoldlyGrow\Google\ApiClient;

ApiClient::get(...);
ApiClient::post(...);
ApiClient::patch(...);
ApiClient::put(...);
ApiClient::delete(...);
```

### Named vs Positional Arguments

You can use named arguments/parameters (introduced in PHP 8) or positional function arguments/parameters.

It is recommended is to use named arguments.

Learn more in the PHP documentation for [function arguments](https://www.php.net/manual/en/functions.arguments.php), [named parameters](https://php.watch/versions/8.0/named-parameters), and this helpful [blog article](https://stitcher.io/blog/php-8-named-arguments).

```php
use BoldlyGrow\Google\ApiClient;

// Named Arguments
ApiClient::get(
    url: 'https://admin.googleapis.com/admin/directory/v1/groups',
    scope: 'https://www.googleapis.com/auth/admin.directory.group',
    query_keys: ['customer'],
)->data;

// Positional Arguments
ApiClient::get(
    'https://admin.googleapis.com/admin/directory/v1/groups',
    'https://www.googleapis.com/auth/admin.directory.group',
    [],
    ['customer'],
    []
)->data;
```

### GET Requests

Since Google's APIs are spread across multiple domains and subdomains, you need to provide the full URL of the API endpoint in the `url` named argument/parameter.

See [API Token Permissions and Scopes](#api-token-permissions-and-scopes) to learn more about the finding the scope for each endpoint.

#### GET Method Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `url` | string | The full URL of the API endpoint |
| `scope` | string | One of the scopes required by the API endpoint that your API key has been authorized to use. |
| `query_data` | array (optional) | Array of query data to apply to request |
| `query_keys` | array (optional) | Array of connection [configuration keys](#query-keys) (`customer`, `domain`, `subject_email`) that should be included with API requests for specific endpoints (ex. Google Workspace Directory API) to merge into the query_data. |
| `connection` | array (optional) | An array with API connection variables. If not set, `config('google-api-client')` uses the GOOGLE_API_* variables from your .env file. |

#### GET Example Usage

```php
use BoldlyGrow\Google\ApiClient;

// Get a list of records
// https://developers.google.com/admin-sdk/directory/reference/rest/v1/groups/list
$groups = ApiClient::get(
    url: 'https://admin.googleapis.com/admin/directory/v1/groups',
    scope: 'https://www.googleapis.com/auth/admin.directory.group',
    query_data: [],
    query_keys: ['customer']
);
```

You can also use variables or database models to get data for constructing your endpoints. Some endpoints require an ID while others allow a human friendly alias (ex. resource name or email address).

```php
use BoldlyGrow\Google\ApiClient;

// Get a specific record using a variable
// https://developers.google.com/admin-sdk/directory/reference/rest/v1/groups/get
// $group_id = '0a1b2c3d4e5f6g7';
$group_id = 'elite-engineers@example.com';
$existing_group = ApiClient::get(
    url: 'https://admin.googleapis.com/admin/directory/v1/groups/' . $group_id,
    scope: 'https://www.googleapis.com/auth/admin.directory.group',
    query_data: [],
    query_keys: ['customer']
);

// Get a specific record using a database value
// This assumes that you have a database column named `api_group_id` that
// contains the string with the Google ID `0a1b2c3d4e5f6g7`.
$database_group = \App\Models\GoogleGroup::where('id', $id)->firstOrFail();
$api_group = ApiClient::get(
    url: 'https://admin.googleapis.com/admin/directory/v1/groups/' . $google_group->api_group_id,
    scope: 'https://www.googleapis.com/auth/admin.directory.group',
    query_data: [],
    query_keys: ['customer']
);
```

#### GET Requests with Query String Parameters

The third positional argument or `query_data` named argument of a `get()` method is an optional array of parameters that is parsed by the API Client and the [Laravel HTTP Client](https://laravel.com/docs/10.x/http-client#get-request-query-parameters) and rendered as a query string with the `?` and `&` added automatically.

#### Query Keys

Many endpoints will require the `customer`, `domain`, and/or `subject_email` to be passed in the query string. To improve your developer experience, you do not need to add these values or merge query string arrays yourself. You simply need to provide the keys that the endpoint needs in the `query_keys` array. The values are pulled from your connection configuration (or `.env` file) and the key/value pairs are automatically merged with the `query_data` array to form a query string.

See the `getConnectionQueryParams()` method in `ApiClient.php` to learn more.

```php
use BoldlyGrow\Google\ApiClient;

$group = ApiClient::post(
    url: 'https://admin.googleapis.com/admin/directory/v1/groups',
    scope: 'https://www.googleapis.com/auth/admin.directory.group',
    query_data: [],
    query_keys: ['customer']
    // query_keys: ['customer', 'domain', 'subject_email']
);
```

```plain
https://admin.googleapis.com/admin/directory/v1/groups?customer=my_customer
```

##### API Request Filtering

The Google API uses child arrays for several resources. When searching for values, you use dot notation (ex. `profile.name`) to access to these attributes.

##### API Response Filtering

You can also use [Laravel Collections](https://laravel.com/docs/10.x/collections#available-methods) to filter and transform results, either using a full data set or one that you already filtered with your API request.

See [Using Laravel Collections](responses.md#using-laravel-collections) in the [API Responses](responses.md) documentation.

### POST Requests

The `post()` method works almost identically to a `get()` request, with the addition of the `form_data` array parameter. This is industry standard and not specific to the API Client.

Since many Google endpoints require one of the `query_keys` and some use additional query string arguments so we cannot consolidate to just a `data` parameter.

You can learn more about request data in the [Laravel HTTP Client documentation](https://laravel.com/docs/10.x/http-client#request-data).

```php
use BoldlyGrow\Google\ApiClient;

// Create a group
// https://developers.google.com/admin-sdk/directory/reference/rest/v1/groups/insert
$group = ApiClient::post(
    url: "https://admin.googleapis.com/admin/directory/v1/groups",
    scope: "https://www.googleapis.com/auth/admin.directory.group",
    form_data: [
        "name" => "Hack the Planet Engineers",
        "email" => "elite-engineers@example.com"
    ],
    query_keys: ["customer"]
);
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `url` | string | The full URL of the API endpoint |
| `scope` | string | One of the scopes required by the API endpoint that your API key has been authorized to use. |
| `form_data` | array (optional) | Array of form data that will be converted to JSON |
| `query_data` | array (optional) | Array of query data to apply to request |
| `query_keys` | array (optional) | Array of connection [configuration keys](#query-keys) (`customer`, `domain`, `subject_email`) that should be included with API requests for specific endpoints (ex. Google Workspace Directory API) to merge into the query_data. |
| `connection` | array (optional) | An array with API connection variables. If not set, `config('google-api-client')` uses the GOOGLE_API_* variables from your .env file. |

### PATCH Requests

The `patch()` method is used for updating one or more attributes on existing records. A patch is used for partial updates. If you want to update and replace the attributes for the **entire** existing record, you should use the [put() method](#put-requests).

You need to ensure that the ID of the record that you want to update is provided in the URL. In most applications, this will be a variable that you get from your database or another location and won't be hard-coded.

#### PATCH Method Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `url` | string | The full URL of the API endpoint |
| `scope` | string | One of the scopes required by the API endpoint that your API key has been authorized to use. |
| `form_data` | array (optional) | Array of form data that will be converted to JSON |
| `query_data` | array (optional) | Array of query data to apply to request |
| `query_keys` | array (optional) | Array of connection [configuration keys](#query-keys) (`customer`, `domain`, `subject_email`) that should be included with API requests for specific endpoints (ex. Google Workspace Directory API) to merge into the query_data. |
| `connection` | array (optional) | An array with API connection variables. If not set, `config('google-api-client')` uses the GOOGLE_API_* variables from your .env file. |

#### PATCH Example Usage

```php
use BoldlyGrow\Google\ApiClient;

// Update a group (patch)
// https://developers.google.com/admin-sdk/directory/reference/rest/v1/groups/patch
// $group_id = '0a1b2c3d4e5f6g7';
$group_id = 'elite-engineers@example.com';
$response = ApiClient::patch(
    url: 'https://admin.googleapis.com/admin/directory/v1/groups/' . $group_id,
    scope: 'https://www.googleapis.com/auth/admin.directory.group',
    form_data: [
        'description' => 'This group contains engineers that have liberated the garbage files.'
    ],
    query_keys: ['customer']
);
```

### PUT Requests

The `put()` method is used for updating and replacing the attributes for an **entire** existing record. If you want to update one or more attributes **without updating the entire existing record**, use the [patch() method](#patch-requests). For most use cases, you will want to use the `patch()` method to update records.

This may require fetching the record and overriding the value of the specific key in the array and passing the entire array back to the API client `form_data` argument.

You need to ensure that the ID of the record that you want to update is provided in the first argument (URI). In most applications, this will be a variable that you get from your database or another location and won't be hard-coded.

#### PUT Method Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `url` | string | The full URL of the API endpoint |
| `scope` | string | One of the scopes required by the API endpoint that your API key has been authorized to use. |
| `form_data` | array (optional) | Array of form data that will be converted to JSON |
| `query_data` | array (optional) | Array of query data to apply to request |
| `query_keys` | array (optional) | Array of connection [configuration keys](#query-keys) (`customer`, `domain`, `subject_email`) that should be included with API requests for specific endpoints (ex. Google Workspace Directory API) to merge into the query_data. |
| `connection` | array (optional) | An array with API connection variables. If not set, `config('google-api-client')` uses the GOOGLE_API_* variables from your .env file. |

#### PUT Example Usage

```php
use BoldlyGrow\Google\ApiClient;

// Get a specific record using a variable
// https://developers.google.com/admin-sdk/directory/reference/rest/v1/groups/get
// $group_id = '0a1b2c3d4e5f6g7';
$group_id = 'elite-engineers@example.com';
$existing_group = ApiClient::get(
    url: 'https://admin.googleapis.com/admin/directory/v1/groups/' . $group_id,
    scope: 'https://www.googleapis.com/auth/admin.directory.group',
);

// {
//     +"data": {
//         +"id": "0a1b2c3d4e5f6g7",
//         +"email": "elite-engineers@example.com",
//         +"name": "Hack the Planet Engineers",
//         +"directMembersCount": "0",
//         +"description": "",
//         +"adminCreated": true,
//         +"nonEditableAliases": [
//             "elite-engineers@example.com.test-google-a.com",
//         ],
//     },
//     +"headers": [
//         ...
//     ],
//     +"status": {
//         +"code": 200,
//         +"ok": true,
//         +"successful": true,
//         +"failed": false,
//         +"serverError": false,
//         +"clientError": false,
//     },
// }

$existing_group->data->description = 'This group contains engineers that have revealed to the world their elite skills.';

// Update a group (put)
// https://developers.google.com/admin-sdk/directory/reference/rest/v1/groups/update
// $group_id = '0a1b2c3d4e5f6g7';
$group_id = 'elite-engineers@example.com';
$response = ApiClient::patch(
    url: 'https://admin.googleapis.com/admin/directory/v1/groups/' . $group_id,
    scope: 'https://www.googleapis.com/auth/admin.directory.group',
    form_data: $existing_group->data->description
);
```

### DELETE Requests

The `delete()` method is used for methods that will destroy the resource based on the ID that you provide.

Keep in mind that `delete()` methods will return different status codes depending on the vendor (ex. 200, 201, 202, 204, etc). Google's API will return a `204` status code for successfully deleted resources. You should use the `$response->status->successful` boolean for checking results.

#### DELETE Method Parameters

| Parameter | Type | Description |
|-----------|------|-------------|
| `url` | string | The full URL of the API endpoint |
| `scope` | string | One of the scopes required by the API endpoint that your API key has been authorized to use. |
| `form_data` | array (optional) | Array of form data that will be converted to JSON |
| `query_data` | array (optional) | Array of query data to apply to request |
| `query_keys` | array (optional) | Array of connection [configuration keys](#query-keys) (`customer`, `domain`, `subject_email`) that should be included with API requests for specific endpoints (ex. Google Workspace Directory API) to merge into the query_data. |
| `connection` | array (optional) | An array with API connection variables. If not set, `config('google-api-client')` uses the GOOGLE_API_* variables from your .env file. |

#### DELETE Example Usage

```php
use BoldlyGrow\Google\ApiClient;

// Delete a group
// https://developers.google.com/admin-sdk/directory/reference/rest/v1/groups/delete
// $group_id = '0a1b2c3d4e5f6g7';
$group_id = 'elite-engineers@example.com';
$response = ApiClient::delete(
    url: 'https://admin.googleapis.com/admin/directory/v1/groups/' . $group_id,
    scope: 'https://www.googleapis.com/auth/admin.directory.group'
);
```

### Class Methods

The examples above show basic inline usage that is suitable for most use cases. If you prefer to use classes and constructors, the example below will be helpful.

```php
<?php

use BoldlyGrow\Google\ApiClient;
use BoldlyGrow\Google\Exceptions\NotFoundException;

class GoogleGroupService
{
    private $connection;

    public function __construct(array $connection = [])
    {
        $this->connection = $connection;
    }

    public function listGroups($query = [])
    {
        $groups = ApiClient::get(
            url: 'https://admin.googleapis.com/admin/directory/v1/groups',
            scope: 'https://www.googleapis.com/auth/admin.directory.group',
            query_data: $query,
            query_keys: ['customer'],
            connection: $this->connection
        );

        return $groups->data;
    }

    public function getGroup($id, $query = [])
    {
        try {
            $existing_group = ApiClient::get(
                url: 'https://admin.googleapis.com/admin/directory/v1/groups/' . $id,
                scope: 'https://www.googleapis.com/auth/admin.directory.group',
                query_data: $query,
                connection: $this->connection
            );
        } catch (NotFoundException $e) {
            // Custom logic to handle a record not found. For example, you could
            // redirect to a page and flash an alert message.
        }

        return $group->data;
    }

    public function storeGroup($request_data)
    {
        $group = ApiClient::post(
            url: "https://admin.googleapis.com/admin/directory/v1/groups",
            scope: "https://www.googleapis.com/auth/admin.directory.group",
            form_data: $request_data,
            connection: $this->connection
        );

        // To return an object with the newly created group
        return $group->data;

        // To return the ID of the newly created group
        // return $group->data->id;

        // To return the status code of the form request
        // return $group->status->code;

        // To return a bool with the status of the form request
        // return $group->status->successful;

        // To throw an exception if the request fails
        // throw_if(!$group->status->successful, new \Exception($group->error->message, $group->status->code));

        // To return the entire API response with the data, headers, and status
        // return $group;
    }

    public function updateGroup($id, $request_data)
    {
        try {
            $group = ApiClient::patch(
                url: 'https://admin.googleapis.com/admin/directory/v1/groups/' . $id,
                scope: 'https://www.googleapis.com/auth/admin.directory.group',
                form_data: $request_data,
                connection: $this->connection
            );
        } catch (NotFoundException $e) {
            // Custom logic to handle a record not found. For example, you could
            // redirect to a page and flash an alert message.
        }

        // To return an object with the updated group
        return $group->data;

        // To return a bool with the status of the form request
        // return $group->status->successful;
    }

    public function deleteGroup($id)
    {
        try {
            $group = ApiClient::delete(
                url: 'https://admin.googleapis.com/admin/directory/v1/groups/' . $group_id,
                scope: 'https://www.googleapis.com/auth/admin.directory.group',
                connection: $this->connection
            );
        } catch (NotFoundException $e) {
            // Custom logic to handle a record not found. For example, you could
            // redirect to a page and flash an alert message.
        }

        return $group->status->successful;
    }
}
```

### Rate Limits

Due to the vast amount services available in Google API endpoints, this API client cannot provide rate limit approaching detection and backoff. An exception is logged and thrown if a rate limit is exceeded and the request will fail.

See the rate limit documentation for the specific service that you are calling for any rate limiting needed, or consider adding `sleep(#)` helpers if needed.

## API Responses

This API Client uses the BoldlyGrow standards for API response formatting.

```php
// API Request
$group = ApiClient::get('groups/00g1ab2c3D4E5F6G7h8i');

// API Response
$group->data; // object
$group->headers; // array
$group->status; // object
$group->status->code; // int (ex. 200)
$group->status->ok; // bool (is 200 status)
$group->status->successful; // bool (is 2xx status)
$group->status->failed; // bool (is 4xx/5xx status)
$group->status->clientError; // bool (is 4xx status)
$group->status->serverError; // bool (is 5xx status)
```

### Response Data

The `data` property contains the contents of the Laravel HTTP Client `object()` method that has been parsed and has the final merged output of any paginated results.

```php
$group_id = 'elite-engineers@example.com';
$group = ApiClient::get(
    url: 'https://admin.googleapis.com/admin/directory/v1/groups/' . $group_id,
    scope: 'https://www.googleapis.com/auth/admin.directory.group'
);

$group->data;
```

```json
{
    +"id": "0a1b2c3d4e5f6g7",
    +"email": "elite-engineers@example.com",
    +"name": "Hack the Planet Engineers",
    +"directMembersCount": "0",
    +"description": "This group contains engineers that have liberated the garbage files.",
    +"adminCreated": true,
    +"nonEditableAliases": [
        "elite-engineers@example.com.test-google-a.com",
    ],
},
```

#### Access a single record value

You can access these variables using object notation. This is the most common use case for handling API responses.

```php
$group_id = 'elite-engineers@example.com';
$group = ApiClient::get(
    url: 'https://admin.googleapis.com/admin/directory/v1/groups/' . $group_id,
    scope: 'https://www.googleapis.com/auth/admin.directory.group'
)->data;

$group_name = $group->name;
// Hack the Planet Engineers
```

#### Looping through records

If you have an array of multiple objects, you can loop through the records. The API Client automatically paginates and merges the array of records for improved developer experience.

```php
$groups = ApiClient::get(
    url: 'https://admin.googleapis.com/admin/directory/v1/groups',
    scope: 'https://www.googleapis.com/auth/admin.directory.group',
    query_data: [],
    query_keys: ['customer']
)->data;

foreach($groups as $group) {
    dd($group->name);
    // Hack the Planet Engineers
}
```

#### Caching responses

The API Client does not use caching to avoid any constraints with you being able to control which endpoints you cache.

You can wrap an endpoint in a cache facade when making an API call. You can learn more in the [Laravel Cache](https://laravel.com/docs/10.x/cache) documentation.

```php
use Illuminate\Support\Facades\Cache;
use BoldlyGrow\Google\ApiClient;

$groups = Cache::remember('google_groups', now()->addHours(2), function () {
    return ApiClient::get(
        url: 'https://admin.googleapis.com/admin/directory/v1/groups',
        scope: 'https://www.googleapis.com/auth/admin.directory.group',
        query_keys: ['customer']
    )->data;
});

foreach($groups as $group) {
    dd($group->name);
    // Hack the Planet Engineers
}
```

When getting a specific ID or passing additional arguments, be sure to pass variables into `use($var1, $var2)`.

```php
$group_id = '0a1b2c3d4e5f6g7';
// $group_id = 'elite-engineers@example.com';

$groups = Cache::remember('google_group_' . $group_id, now()->addHours(12), function () use ($group_id) {
    return ApiClient::get(
        url: 'https://admin.googleapis.com/admin/directory/v1/groups/' . $group_id,
        scope: 'https://www.googleapis.com/auth/admin.directory.group'
    )->data;
});
```

#### Date Formatting

You can use the [Carbon](https://carbon.nesbot.com/docs/) library for formatting dates and performing calculations.

```php
use Carbon\Carbon;
```

```php
$created_date = Carbon::parse($group->data->creationTime)->format('Y-m-d');
// 2023-01-01
```

```php
$created_age_days = Carbon::parse($group->data->creationTime)->diffInDays();
// 265
```

#### Using Laravel Collections

You can use [Laravel Collections](https://laravel.com/docs/10.x/collections#available-methods) which are powerful array helper tools that are similar to array searching and SQL queries that you may already be familiar with.

### Response Headers

> The headers are returned as an array instead of an object since the keys use hyphens that conflict with the syntax of accessing keys and values easily.

```php
$group_id = 'elite-engineers@example.com';
$group = ApiClient::get(
    url: 'https://admin.googleapis.com/admin/directory/v1/groups/' . $group_id,
    scope: 'https://www.googleapis.com/auth/admin.directory.group'
)->data;

$group->headers;
```

```php
[
    "ETag" => "vd5VuYRc7EabgWBin1OmNozuzPe13OUXakGXQzUmnHA/AKpK7DnSvYzi2eNow9bdQ2H0TcE",
    "Content-Type" => "application/json; charset=UTF-8",
    "Vary" => [
        "Origin",
        "X-Origin",
        "Referer",
    ],
    "Date" => "Tue, 27 Feb 2024 03:30:20 GMT",
    "Server" => "ESF",
    "Content-Length" => "400",
    "X-XSS-Protection" => "0",
    "X-Frame-Options" => "SAMEORIGIN",
    "X-Content-Type-Options" => "nosniff",
    "Alt-Svc" => "h3=":443"; ma=2592000,h3-29=":443"; ma=2592000",
],
```

#### Getting a Header Value

```php
$content_type = $group->headers['Content-Type'];
```

```plain
application/json; charset=UTF-8
```

### Response Status

See the [Laravel HTTP Client documentation](https://laravel.com/docs/10.x/http-client#error-handling) to learn more about the different status booleans.

```php
$group_id = 'elite-engineers@example.com';
$group = ApiClient::get(
    url: 'https://admin.googleapis.com/admin/directory/v1/groups/' . $group_id,
    scope: 'https://www.googleapis.com/auth/admin.directory.group'
)->data;

$group->status;
```

```php
{
  +"code": 200 // int (ex. 200)
  +"ok": true // bool (is 200 status)
  +"successful": true // bool (is 2xx status)
  +"failed": false // bool (is 4xx/5xx status)
  +"serverError": false // bool (is 4xx status)
  +"clientError": false // bool (is 5xx status)
}
```

#### API Response Status Code

```php
$group_id = 'elite-engineers@example.com';
$group = ApiClient::get(
    url: 'https://admin.googleapis.com/admin/directory/v1/groups/' . $group_id,
    scope: 'https://www.googleapis.com/auth/admin.directory.group',
    query_data: [],
    query_keys: ['customer']
)->data;

$status_code = $group->status->code;
// 200
```

## Error Responses

An exception is thrown for any 4xx or 5xx responses. All responses are automatically logged.

### Exceptions

| Code | Exception Class                                               |
|------|---------------------------------------------------------------|
| N/A  | `BoldlyGrow\Google\Exceptions\AuthenticationException`     |
| N/A  | `BoldlyGrow\Google\Exceptions\ConfigurationException`      |
| 400  | `BoldlyGrow\Google\Exceptions\BadRequestException`         |
| 401  | `BoldlyGrow\Google\Exceptions\UnauthorizedException`       |
| 403  | `BoldlyGrow\Google\Exceptions\ForbiddenException`          |
| 404  | `BoldlyGrow\Google\Exceptions\NotFoundException`           |
| 405  | `BoldlyGrow\Google\Exceptions\MethodNotAllowedException`   |
| 409  | `BoldlyGrow\Google\Exceptions\ConflictException`           |
| 412  | `BoldlyGrow\Google\Exceptions\PreconditionFailedException` |
| 422  | `BoldlyGrow\Google\Exceptions\UnprocessableException`      |
| 429  | `BoldlyGrow\Google\Exceptions\RateLimitException`          |
| 500  | `BoldlyGrow\Google\Exceptions\ServerErrorException`        |
| 503  | `BoldlyGrow\Google\Exceptions\ServiceUnavailableException` |

### Catching Exceptions

You can catch any exceptions that you want to handle silently. Any uncaught exceptions will appear for users and cause 500 errors that will appear in your monitoring software.

```php
use BoldlyGrow\Google\Exceptions\NotFoundException;

try {
    $group_id = 'elite-engineers@example.com';
    $group = ApiClient::get(
        url: 'https://admin.googleapis.com/admin/directory/v1/groups/' . $group_id,
        scope: 'https://www.googleapis.com/auth/admin.directory.group',
        query_data: [],
        query_keys: ['customer']
    )->data;
} catch (NotFoundException $e) {
    // Group is not found. You can create a log entry, throw an exception, or handle it another way.
    Log::error('Google group could not be found', ['google_group_id' => $group_id]);
}
```

### Disabling Exceptions

If you do not want exceptions to be thrown, you can globally disable exceptions for the Google API Client and handle the status for each request yourself. Simply set the `GOOGLE_API_EXCEPTIONS=false` in your `.env` file.

```php
GOOGLE_API_EXCEPTIONS=false
```

## Log Examples

This package uses the [boldlygrow/audit-log](https://github.com/boldlygrow/audit-log) package for standardized logs.

### Event Types

The `event_type` key should be used for any categorization and log searches.

- **Format:** `google.api.{method}.{result/log_level}.{reason?}`
- **Methods:** `get|post|patch|put|delete`

| Status Code | Event Type                                        | Log Level |
|-------------|---------------------------------------------------|-----------|
| N/A         | `google.api.validate.error`                       | CRITICAL  |
| N/A         | `google.api.validate.error.array`                 | CRITICAL  |
| N/A         | `google.api.validate.error.empty`                 | CRITICAL  |
| N/A         | `google.api.auth.error`                           | CRITICAL  |
| N/A         | `google.api.auth.success`                         | DEBUG     |
| N/A         | `google.api.get.process.pagination.started`       | DEBUG     |
| N/A         | `google.api.get.process.pagination.finished`      | DEBUG     |
| N/A         | `google.api.{method}.error.http.exception`        | ERROR     |
| 200         | `google.api.{method}.success`                     | DEBUG     |
| 201         | `google.api.{method}.success`                     | DEBUG     |
| 202         | `google.api.{method}.success`                     | DEBUG     |
| 204         | `google.api.{method}.success`                     | DEBUG     |
| 400         | `google.api.{method}.warning.bad-request`         | WARNING   |
| 401         | `google.api.{method}.error.unauthorized`          | ERROR     |
| 403         | `google.api.{method}.error.forbidden`             | ERROR     |
| 404         | `google.api.{method}.warning.not-found`           | WARNING   |
| 405         | `google.api.{method}.error.method-not-allowed`    | ERROR     |
| 412         | `google.api.{method}.error.precondition-failed`   | DEBUG     |
| 422         | `google.api.{method}.error.unprocessable`         | DEBUG     |
| 429         | `google.api.{method}.critical.rate-limit`         | CRITICAL  |
| 500         | `google.api.{method}.critical.server-error`       | CRITICAL  |
| 501         | `google.api.{method}.error.not-implemented`       | ERROR     |
| 503         | `google.api.{method}.critical.server-unavailable` | CRITICAL  |

### Successful Requests

#### GET Request Log

```plain
[YYYY-MM-DD HH:II:SS] local.DEBUG: ApiToken::sendAuthRequest Success {"event_type":"google.api.auth.success","method":"App\\Actions\\Connections\\Google\\ApiToken::sendAuthRequest"}

[YYYY-MM-DD HH:II:SS] local.DEBUG: ApiClient::get Success {"event_type":"google.api.get.success","method":"App\\Actions\\Connections\\Google\\ApiClient::get","count_records":5,"event_ms":810,"event_ms_per_record":162,"metadata":{"url":"https://admin.googleapis.com/admin/directory/v1/users?customer=my_customer"}}
```

```plain
[YYYY-MM-DD HH:II:SS] local.DEBUG: ApiClient::get Success {"event_type":"google.api.get.success","method":"App\\Actions\\Connections\\Google\\ApiClient::get","count_records":29,"event_ms":334,"event_ms_per_record":11,"metadata":{"url":"https://admin.googleapis.com/admin/directory/v1/users/0a1b2c3d4e5f6g7?customer=my_customer"}}
```

#### GET Paginated Request Log

```plain
[YYYY-MM-DD HH:II:SS] local.DEBUG: ApiToken::sendAuthRequest Success {"event_type":"google.api.auth.success","method":"App\\Actions\\Connections\\Google\\ApiToken::sendAuthRequest"}
[YYYY-MM-DD HH:II:SS] local.DEBUG: ApiClient::get Success {"event_type":"google.api.get.success","method":"App\\Actions\\Connections\\Google\\ApiClient::get","count_records":100,"event_ms":966,"event_ms_per_record":9,"metadata":{"url":"https://admin.googleapis.com/admin/directory/v1/users?customer=my_customer"}}
[YYYY-MM-DD HH:II:SS] local.DEBUG: ApiClient::get Paginated Results Process Started {"event_type":"google.api.get.process.pagination.started","method":"App\\Actions\\Connections\\Google\\ApiClient::get","metadata":{"url":"https://admin.googleapis.com/admin/directory/v1/users"}}
[YYYY-MM-DD HH:II:SS] local.DEBUG: ApiClient::getPaginatedResults Success {"event_type":"google.api.getPaginatedResults.success","method":"App\\Actions\\Connections\\Google\\ApiClient::getPaginatedResults","count_records":100,"event_ms":613,"event_ms_per_record":6,"metadata":{"url":"https://admin.googleapis.com/admin/directory/v1/users?customer=my_customer&pageToken=REDACTED"}}
[YYYY-MM-DD HH:II:SS] local.DEBUG: ApiClient::getPaginatedResults Success {"event_type":"google.api.getPaginatedResults.success","method":"App\\Actions\\Connections\\Google\\ApiClient::getPaginatedResults","count_records":100,"event_ms":1352,"event_ms_per_record":13,"metadata":{"url":"https://admin.googleapis.com/admin/directory/v1/users?customer=my_customer&pageToken=REDACTED"}}
[YYYY-MM-DD HH:II:SS] local.DEBUG: ApiClient::getPaginatedResults Success {"event_type":"google.api.getPaginatedResults.success","method":"App\\Actions\\Connections\\Google\\ApiClient::getPaginatedResults","count_records":100,"event_ms":2151,"event_ms_per_record":21,"metadata":{"url":"https://admin.googleapis.com/admin/directory/v1/users?customer=my_customer&pageToken=REDACTED"}}
[YYYY-MM-DD HH:II:SS] local.DEBUG: ApiClient::getPaginatedResults Success {"event_type":"google.api.getPaginatedResults.success","method":"App\\Actions\\Connections\\Google\\ApiClient::getPaginatedResults","count_records":100,"event_ms":2835,"event_ms_per_record":28,"metadata":{"url":"https://admin.googleapis.com/admin/directory/v1/users?customer=my_customer&pageToken=REDACTED"}}
...
[YYYY-MM-DD HH:II:SS] local.DEBUG: ApiClient::get Paginated Results Process Complete {"event_type":"google.api.get.process.pagination.finished","method":"App\\Actions\\Connections\\Google\\ApiClient::get","count_records":3820,"duration_ms":29423,"duration_ms_per_record":7,"metadata":{"url":"https://admin.googleapis.com/admin/directory/v1/users"}}
```

#### POST Request Log

```plain
[YYYY-MM-DD HH:II:SS] local.DEBUG: ApiClient::post Success {"event_type":"google.api.post.success","method":"App\\Actions\\Connections\\Google\\ApiClient::post","count_records":5,"event_ms":1054,"event_ms_per_record":210,"metadata":{"url":"https://admin.googleapis.com/admin/directory/v1/groups?customer=my_customer"}}
```

#### PATCH Request Log

```plain
[YYYY-MM-DD HH:II:SS] local.DEBUG: ApiClient::patch Success {"event_type":"google.api.patch.success","method":"App\\Actions\\Connections\\Google\\ApiClient::patch","count_records":6,"event_ms":775,"event_ms_per_record":128,"metadata":{"url":"https://admin.googleapis.com/admin/directory/v1/groups/0a1b2c3d4e5f6g7?customer=my_customer"}}
```

#### PUT Success Log

```plain
[YYYY-MM-DD HH:II:SS] local.DEBUG: ApiClient::put Success {"event_type":"google.api.put.success","method":"App\\Actions\\Connections\\Google\\ApiClient::put","count_records":6,"event_ms":623,"event_ms_per_record":103,"metadata":{"url":"https://admin.googleapis.com/admin/directory/v1/groups/0a1b2c3d4e5f6g7?customer=my_customer"}}
```

#### DELETE Success Log

```plain
[YYYY-MM-DD HH:II:SS] local.DEBUG: ApiClient::delete Success {"event_type":"google.api.delete.success","method":"App\\Actions\\Connections\\Google\\ApiClient::delete","event_ms":584,"metadata":{"url":"https://admin.googleapis.com/admin/directory/v1/groups/0a1b2c3d4e5f6g7?customer=my_customer"}}
```

### Errors

#### Authentication Scopes Missing Error

```plain
[YYYY-MM-DD HH:II:SS] local.CRITICAL: ApiToken::sendAuthRequest Error {"event_type":"google.api.auth.error","method":"BoldlyGrow\\Google\\ApiToken::sendAuthRequest","errors":["Client is unauthorized to retrieve access tokens using this method, or client not authorized for any of the scopes requested."]}
```

#### Missing Customer ID Error

The most frequent error that you will see is mysteriously generic `400 Bad Request` that is caused by not adding `query_keys: ['customer']` to your request, especially with Google Workspace related API calls. This is particularly common with `get()` requests with a large number of results.

```php
// Before
$groups = ApiClient::get(
    url: 'https://admin.googleapis.com/admin/directory/v1/groups',
    scope: 'https://www.googleapis.com/auth/admin.directory.group'
);

// After
$groups = ApiClient::get(
    url: 'https://admin.googleapis.com/admin/directory/v1/groups',
    scope: 'https://www.googleapis.com/auth/admin.directory.group',
    query_keys: ['customer'],
);
```

#### 400 Bad Request

> See the [Missing Customer ID Error](#missing-customer-id-error) for initial troubleshooting.

```plain
[YYYY-MM-DD HH:II:SS] local.WARNING: ApiClient::get Client Error {"event_type":"google.api.get.warning.bad-request","method":"App\\Actions\\Connections\\Google\\ApiClient::get","event_ms":468,"metadata":{"url":"https://admin.googleapis.com/admin/directory/v1/groups"}}
```

#### 404 Not Found

```plain
[YYYY-MM-DD HH:II:SS] local.WARNING: ApiClient::get Client Error {"event_type":"google.api.get.warning.not-found","method":"App\\Actions\\Connections\\Google\\ApiClient::get","event_ms":354,"metadata":{"url":"https://admin.googleapis.com/admin/directory/v1/users/user@example.com?customer=my_customer"}}
```

#### 429 Rate Limit Exception

```plain
[YYYY-MM-DD HH:II:SS] local.CRITICAL: ApiClient::get Client Error {"event_type":"google.api.get.critical.rate-limit","method":"BoldlyGrow\\Google\\ApiClient::get","errors":{"message":"Quota exceeded for quota metric 'Read requests' and limit 'Read requests per minute per user' of service 'sheets.googleapis.com' for consumer 'project_number:123456789012'."},"event_ms":129,"metadata":{"url":"https://sheets.googleapis.com/v4/spreadsheets/REDACTED"}}
```
