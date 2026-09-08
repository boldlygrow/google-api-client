<?php

namespace BoldlyGrow\Google;

use BoldlyGrow\AuditLog\AuditLog;
use BoldlyGrow\Google\Exceptions\BadRequestException;
use BoldlyGrow\Google\Exceptions\ConfigurationException;
use BoldlyGrow\Google\Exceptions\ConflictException;
use BoldlyGrow\Google\Exceptions\ForbiddenException;
use BoldlyGrow\Google\Exceptions\MethodNotAllowedException;
use BoldlyGrow\Google\Exceptions\NotFoundException;
use BoldlyGrow\Google\Exceptions\PreconditionFailedException;
use BoldlyGrow\Google\Exceptions\RateLimitException;
use BoldlyGrow\Google\Exceptions\ServerErrorException;
use BoldlyGrow\Google\Exceptions\ServiceUnavailableException;
use BoldlyGrow\Google\Exceptions\UnauthorizedException;
use BoldlyGrow\Google\Exceptions\UnprocessableException;
use Carbon\Carbon;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Google API Client for OAUTH Client and GCP Service Account JSON Keys
 *
 * @author Dillon Wheeler
 * @author Jeff Martin
 *
 * This is used to perform Laravel HTTP Client REST API calls to any of Google's service APIs.
 *
 * To provide a streamlined developer experience, this uses either the key_path parameter that points to your JSON API
 * key's storage path, or you can provide the JSON API key as a string in the key_string parameter. If not set, it will
 * try to use the GOOGLE_APPLICATION_CREDENTIALS environment variable.
 *
 * All authentication is performed in the `ApiToken` class that will generate a short-lived bearer token.
 *
 * @link https://developers.google.com/apis-explorer
 */
class ApiClient
{
    /**
     * Google API Get Request
     *
     * This method is called from other services to perform a GET request and return a structured object.
     *
     * Example Usage:
     * ```php
     * $response = ApiClient::get(
     *     url: 'https://admin.googleapis.com/admin/directory/v1/groups',
     *     scope: 'https://www.googleapis.com/auth/admin.directory.group',
     *     query_data: [],
     *     query_keys: ['customer']
     * );
     * ```
     *
     * @param  string  $url         The full URL of the API endpoint
     * @param  string  $scope       One of the scopes required by the API endpoint that your API key has
     *                              been authorized to use.
     * @param  array   $query_data  (optional) Array of query data to apply to request
     * @param  array   $query_keys  (optional) Array of connection configuration keys (customer,
     *                              domain, subject_email) that should be included with API requests for specific
     *                              endpoints (ex. Google Workspace Directory API) to merge into the query_data.
     * @param  array   $connection  (optional) An array with API connection variables. See README for
     *                              schema. If not set, `config('google-api-client')` uses the GOOGLE_API_*
     *                              variables from your .env file.
     *
     * @return object See parseApiResponse() method. The content and schema of the object and json
     *                arrays can be found in the REST API documentation for the specific endpoint.
     */
    public static function get(
        string $url,
        string $scope,
        array $query_data = [],
        array $query_keys = [],
        array $connection = []
    ): object {
        $event_ms = now();

        $query_params = array_merge(
            $query_data,
            self::getConnectionQueryParams(
                connection: $connection,
                query_keys: $query_keys
            )
        );

        $query_string = ! empty($query_params) ? '?' . http_build_query($query_params) : '';

        try {
            $request = Http::withToken(ApiToken::create($scope, $connection))
                ->withHeaders(self::getRequestHeaders())
                ->get(
                    url: $url,
                    query: $query_params
                );
        } catch (RequestException $exception) {
            return self::handleException(
                exception: $exception,
                method: __METHOD__,
                url: $url
            );
        }

        $response = self::parseApiResponse($request);
        self::logResponse(
            event_ms: $event_ms,
            method: __METHOD__,
            url: $url . $query_string,
            response: $response
        );
        self::throwExceptionIfEnabled(
            method: 'get',
            url: $url . $query_string,
            response: $response
        );

        if (property_exists($request->object(), 'nextPageToken')) {
            AuditLog::create(
                event_type: 'google.api.get.process.pagination.started',
                level: 'debug',
                message: 'Paginated Results Process Started',
                metadata: ['url' => $url],
                method: __METHOD__,
                transaction: false
            );

            $paginated_results = self::getPaginatedResults(
                connection: $connection,
                url: $url,
                scope: $scope,
                query_params: array_merge($query_params, ['pageToken' => $request->object()->nextPageToken]),
                records: (array) $response->data,
            );

            $response->data = $paginated_results;

            $count_records = is_countable($response->data) ? count((array) $response->data) : null;
            $duration_ms_per_record = $count_records ? (int) ($event_ms->diffInMilliseconds() / $count_records) : null;

            AuditLog::create(
                count_records: $count_records,
                duration_ms: $event_ms,
                duration_ms_per_record: $duration_ms_per_record,
                event_type: 'google.api.get.process.pagination.finished',
                level: 'debug',
                message: 'Paginated Results Process Complete',
                metadata: ['url' => $url],
                method: __METHOD__,
                transaction: false
            );
        }

        return $response;
    }

    /**
     * Google API POST Request
     *
     * This method is called from other services to perform a POST request and return a structured object.
     *
     * Example Usage:
     *  ```php
     *     $response = ApiClient::post(
     *         url: 'https://admin.googleapis.com/admin/directory/v1/groups',
     *         scope: 'https://www.googleapis.com/auth/admin.directory.group',
     *         form_data: [
     *             'name' => 'Hack the Planet Engineers',
     *             'email' => 'elite-engineers@example.com',
     *             'description' => 'This group of engineers hacked the Gibson and have proven they are elite.'
     *         ]
     *         query_data: [],
     *         query_keys: ['customer']
     *     );
     *  ```
     *
     * @param  string  $url         The full URL of the API endpoint
     * @param  string  $scope       One of the scopes required by the API endpoint that your API key has
     *                              been authorized to use.
     * @param  array   $form_data   (optional) Array of form data that will be converted to JSON
     * @param  array   $query_data  (optional) Array of query data to apply to request
     * @param  array   $query_keys  (optional) Array of connection configuration keys (customer,
     *                              domain, subject_email) that should be included with API requests for specific
     *                              endpoints (ex. Google Workspace Directory API) to merge into the query_data.
     * @param  array   $connection  (optional) An array with API connection variables. See README for
     *                              schema. If not set, `config('google-api-client')` uses the GOOGLE_API_*
     *                              variables from your .env file.
     */
    public static function post(
        string $url,
        string $scope,
        array $form_data = [],
        array $query_data = [],
        array $query_keys = [],
        array $connection = []
    ): object {
        $event_ms = now();

        $query_params = array_merge(
            $query_data,
            self::getConnectionQueryParams(
                connection: $connection,
                query_keys: $query_keys
            )
        );

        $query_string = ! empty($query_params) ? '?' . http_build_query($query_params) : '';

        try {
            $request = Http::withToken(ApiToken::create($scope, $connection))
                ->withHeaders(self::getRequestHeaders())
                ->post(
                    url: $url . $query_string,
                    data: $form_data
                );
        } catch (RequestException $exception) {
            return self::handleException(
                exception: $exception,
                method: __METHOD__,
                url: $url
            );
        }

        $response = self::parseApiResponse($request);
        self::logResponse(
            event_ms: $event_ms,
            method: __METHOD__,
            url: $url . $query_string,
            response: $response
        );
        self::throwExceptionIfEnabled(
            method: 'post',
            url: $url . $query_string,
            response: $response
        );

        return $response;
    }

    /**
     * Google API PATCH Request
     *
     * This method is called from other services to perform a PATCH request and return a structured object.
     *
     * Example Usage:
     * ```php
     * $group_id = 'elite-engineers@example.com';
     * $response = ApiClient::patch(
     *     url: 'https://admin.googleapis.com/admin/directory/v1/groups/' . $group_id,
     *     scope: 'https://www.googleapis.com/auth/admin.directory.group',
     *     form_data: [
     *         'name' => 'Elite Engineers',
     *     ]
     *     query_keys: ['customer']
     * );
     * ```
     *
     * @param  string  $url         The full URL of the API endpoint
     * @param  string  $scope       One of the scopes required by the API endpoint that your API key has
     *                              been authorized to use.
     * @param  array   $form_data   (optional) Array of form data that will be converted to JSON
     * @param  array   $query_data  (optional) Array of query data to apply to request
     * @param  array   $query_keys  (optional) Array of connection configuration keys (customer,
     *                              domain, subject_email) that should be included with API requests for specific
     *                              endpoints (ex. Google Workspace Directory API) to merge into the query_data.
     * @param  array   $connection  (optional) An array with API connection variables. See README for
     *                              schema. If not set, `config('google-api-client')` uses the GOOGLE_API_*
     *                              variables from your .env file.
     */
    public static function patch(
        string $url,
        string $scope,
        array $form_data = [],
        array $query_data = [],
        array $query_keys = [],
        array $connection = []
    ): object {
        $event_ms = now();

        $query_params = array_merge(
            $query_data,
            self::getConnectionQueryParams(
                connection: $connection,
                query_keys: $query_keys
            )
        );

        $query_string = ! empty($query_params) ? '?' . http_build_query($query_params) : '';

        try {
            $request = Http::withToken(ApiToken::create($scope, $connection))
                ->withHeaders(self::getRequestHeaders())
                ->patch(
                    url: $url . $query_string,
                    data: $form_data
                );
        } catch (RequestException $exception) {
            return self::handleException(
                exception: $exception,
                method: __METHOD__,
                url: $url
            );
        }

        $response = self::parseApiResponse($request);
        self::logResponse(
            event_ms: $event_ms,
            method: __METHOD__,
            url: $url . $query_string,
            response: $response
        );
        self::throwExceptionIfEnabled(
            method: 'patch',
            url: $url . $query_string,
            response: $response
        );

        return $response;
    }

    /**
     * Google API PUT Request
     *
     * Example Usage:
     * ```php
     * $group_id = 'elite-engineers@example.com';
     * $response = ApiClient::put(
     *     url: 'https://admin.googleapis.com/admin/directory/v1/groups/' . $group_id,
     *     scope: 'https://www.googleapis.com/auth/admin.directory.group',
     *     form_data: [
     *         'name' => 'Elite Engineers',
     *         'email' => 'elite-engineers@example.com',
     *         'description' => 'This group of engineers hacked the Gibson and have proven they are elite.'
     *     ]
     *     query_keys: ['customer']
     * );
     * ```
     *
     * @param  string  $url         The full URL of the API endpoint
     * @param  string  $scope       One of the scopes required by the API endpoint that your API key has
     *                              been authorized to use.
     * @param  array   $form_data   (optional) Array of form data that will be converted to JSON
     * @param  array   $query_data  (optional) Array of query data to apply to request
     * @param  array   $query_keys  (optional) Array of connection configuration keys (customer,
     *                              domain, subject_email) that should be included with API requests for specific
     *                              endpoints (ex. Google Workspace Directory API) to merge into the query_data.
     * @param  array   $connection  (optional) An array with API connection variables. See README for
     *                              schema. If not set, `config('google-api-client')` uses the GOOGLE_API_*
     *                              variables from your .env file.
     */
    public static function put(
        string $url,
        string $scope,
        array $form_data = [],
        array $query_data = [],
        array $query_keys = [],
        array $connection = []
    ): object {
        $event_ms = now();

        $query_params = array_merge(
            $query_data,
            self::getConnectionQueryParams(
                connection: $connection,
                query_keys: $query_keys
            )
        );

        $query_string = ! empty($query_params) ? '?' . http_build_query($query_params) : '';

        try {
            $request = Http::withToken(ApiToken::create($scope, $connection))
                ->withHeaders(self::getRequestHeaders())
                ->put(
                    url: $url . $query_string,
                    data: $form_data
                );
        } catch (RequestException $exception) {
            return self::handleException(
                exception: $exception,
                method: __METHOD__,
                url: $url
            );
        }

        $response = self::parseApiResponse($request);
        self::logResponse(
            event_ms: $event_ms,
            method: __METHOD__,
            url: $url . $query_string,
            response: $response
        );
        self::throwExceptionIfEnabled(
            method: 'put',
            url: $url . $query_string,
            response: $response
        );

        return $response;
    }

    /**
     * Google API DELETE Request
     *
     * This method is called from other services to perform a DELETE request and return a structured object.
     *
     * Example Usage:
     * ```php
     * $group_id = 'elite-engineers@example.com';
     * $response = ApiClient::delete(
     *     url: 'https://admin.googleapis.com/admin/directory/v1/groups/' . $group_id,
     *     scope: 'https://www.googleapis.com/auth/admin.directory.group',
     *     query_keys: ['customer']
     * );
     * ```
     *
     * @param  string  $url         The full URL of the API endpoint
     * @param  string  $scope       One of the scopes required by the API endpoint that your API key has
     *                              been authorized to use.
     * @param  array   $form_data   (optional) Array of form data that will be converted to JSON
     * @param  array   $query_data  (optional) Array of query data to apply to request
     * @param  array   $query_keys  (optional) Array of connection configuration keys (customer,
     *                              domain, subject_email) that should be included with API requests for specific
     *                              endpoints (ex. Google Workspace Directory API) to merge into the query_data.
     * @param  array   $connection  (optional) An array with API connection variables. See README for
     *                              schema. If not set, `config('google-api-client')` uses the GOOGLE_API_*
     *                              variables from your .env file.
     */
    public static function delete(
        string $url,
        string $scope,
        array $form_data = [],
        array $query_data = [],
        array $query_keys = [],
        array $connection = []
    ): object {
        $event_ms = now();

        $query_params = array_merge(
            $query_data,
            self::getConnectionQueryParams(
                connection: $connection,
                query_keys: $query_keys
            )
        );

        $query_string = ! empty($query_params) ? '?' . http_build_query($query_params) : '';

        try {
            $request = Http::withToken(ApiToken::create($scope, $connection))
                ->withHeaders(self::getRequestHeaders())
                ->delete(
                    url: $url . $query_string,
                    data: $form_data
                );
        } catch (RequestException $exception) {
            return self::handleException(
                exception: $exception,
                method: __METHOD__,
                url: $url
            );
        }

        $response = self::parseApiResponse($request);
        self::logResponse(
            event_ms: $event_ms,
            method: __METHOD__,
            url: $url . $query_string,
            response: $response
        );
        self::throwExceptionIfEnabled(
            method: 'delete',
            url: $url . $query_string,
            response: $response
        );

        return $response;
    }

    /**
     * Convert API Response Headers to Object
     *
     * This method is called from the parseApiResponse method to prettify the Guzzle Headers that are an array with
     * nested array for each value, and converts the single array values into strings and converts to an object for
     * easier and consistent accessibility with the parseApiResponse format.
     *
     * @param  array  $header_response
     *                                  [
     *                                  "ETag" => [
     *                                  ""nMRgLWac8h8NyH7Uk5VvV4DiNp4uxXg5gNUd9YhyaJE/dky_PFyA8Zq0WLn1WqUCn_A8oes""
     *                                  ]
     *                                  "Content-Type" => [
     *                                  "application/json; charset=UTF-8"
     *                                  ]
     *                                  "Vary" => [
     *                                  "Origin"
     *                                  "X-Origin"
     *                                  "Referer"
     *                                  ]
     *                                  "Date" => [
     *                                  "Mon, 24 Jan 2022 15:39:46 GMT"
     *                                  ]
     *                                  "Server" => [
     *                                  "ESF"
     *                                  ]
     *                                  "Content-Length" => [
     *                                  "355675"
     *                                  ]
     *                                  "X-XSS-Protection" => [
     *                                  "0"
     *                                  ]
     *                                  "X-Frame-Options" => [
     *                                  "SAMEORIGIN"
     *                                  ]
     *                                  "X-Content-Type-Options" => [
     *                                  "nosniff"
     *                                  ]
     *                                  "Alt-Svc" => [
     *                                  (truncated)
     *                                  ]
     *                                  ]
     *
     * @return array
     *               [
     *               "ETag" => "nMRgLWac8h8NyH7Uk5VvV4DiNp4uxXg5gNUd9YhyaJE/dky_PFyA8Zq0WLn1WqUCn_A8oes",
     *               "Content-Type" => "application/json; charset=UTF-8",
     *               "Vary" => "Origin X-Origin Referer",
     *               "Date" => "Mon, 24 Jan 2022 15:39:46 GMT",
     *               "Server" => "ESF",
     *               "Content-Length" => "355675",
     *               "X-XSS-Protection" => "0",
     *               "X-Frame-Options" => "SAMEORIGIN",
     *               "X-Content-Type-Options" => "nosniff",
     *               "Alt-Svc" => (truncated)
     *               ]
     */
    protected static function convertHeadersToArray(array $header_response): array
    {
        $headers = [];

        foreach ($header_response as $header_key => $header_value) {
            if (is_countable($header_value) && count($header_value) > 1) {
                // If array has multiple keys, leave as array
                $headers[$header_key] = $header_value;
            } else {
                // Some values may be wrapped in two quotes that breaks array parsing
                $headers[$header_key] = trim($header_value[0], '"');
            }
        }

        return $headers;
    }

    /**
     * Helper function used to get Google API results that require pagination.
     *
     * @param  array   $connection    An array with API connection variables. This is passed from the
     *                                get() method.
     * @param  string  $url           The full URL of the API endpoint
     *                                https://admin.googleapis.com/admin/directory/v1/groups/a1b2c3d4e5
     * @param  string  $scope         One of the scopes required by the API endpoint that your API key has
     *                                been authorized to use.
     * @param  array   $query_params  Array of query params that were calculated in get() method
     * @param  array   $records       Array of records from first page of results from the get() method
     *
     * @return object An array of the response objects for each page combined casted as an object.
     */
    protected static function getPaginatedResults(
        array $connection,
        string $url,
        string $scope,
        array $query_params = [],
        array $records = [],
    ): object {
        $event_ms = now();

        do {
            $request = Http::withToken(ApiToken::create($scope, $connection))
                ->withHeaders(self::getRequestHeaders())
                ->get($url, $query_params);

            $response = self::parseApiResponse($request);

            self::logResponse(
                event_ms: $event_ms,
                method: __METHOD__,
                url: $url . '?' . http_build_query($query_params),
                response: $response
            );

            $records = array_merge($records, (array) $response->data);

            if (property_exists($request->object(), 'nextPageToken')) {
                $query_params = array_merge($query_params, ['pageToken' => $request->object()->nextPageToken]);
            } else {
                $query_params = [];
            }
        } while ($query_params != []);

        return (object) $records;
    }

    /**
     * Parse the API response and return custom formatted response for consistency
     *
     * @link https://laravel.com/docs/10.x/http-client#making-requests
     *
     * @param  object  $response  Response object from API results
     *
     * @return object Custom response returned for consistency
     *                ```php
     *                {
     *                +"data": {
     *                +"kind": "admin#directory#user"
     *                +"id": "114522752583947996869"
     *                +"etag": (truncated)
     *                +"primaryEmail": "klibby@example.com"
     *                +"name": {
     *                +"givenName": "Kate"
     *                +"familyName": "Libby"
     *                +"fullName": "Kate Libby"
     *                }
     *                +"isAdmin": true
     *                +"isDelegatedAdmin": false
     *                +"lastLoginTime": "2022-01-21T17:44:13.000Z"
     *                +"creationTime": "2021-12-08T13:15:43.000Z"
     *                +"agreedToTerms": true
     *                +"suspended": false
     *                +"archived": false
     *                +"changePasswordAtNextLogin": false
     *                +"ipWhitelisted": false
     *                +"emails": array:3 [
     *                0 => {
     *                +"address": "klibby@example.com"
     *                +"type": "work"
     *                }
     *                1 => {
     *                +"address": "klibby@example-test.com"
     *                +"primary": true
     *                }
     *                2 => {
     *                +"address": "klibby@example.com.test-google-a.com"
     *                }
     *                ]
     *                +"phones": array:1 [
     *                0 => {
     *                +"value": "5555555555"
     *                +"type": "work"
     *                }
     *                ]
     *                +"languages": array:1 [
     *                0 => {
     *                +"languageCode": "en"
     *                +"preference": "preferred"
     *                }
     *                ]
     *                +"nonEditableAliases": array:1 [
     *                0 => "klibby@example.com.test-google-a.com"
     *                ]
     *                +"customerId": "C000nnnnn"
     *                +"orgUnitPath": "/"
     *                +"isMailboxSetup": true
     *                +"isEnrolledIn2Sv": false
     *                +"isEnforcedIn2Sv": false
     *                +"includeInGlobalAddressList": true
     *                },
     *                +"headers" => [
     *                "ETag" => (truncated),
     *                "Content-Type" => "application/json; charset=UTF-8",
     *                "Vary" => "Origin X-Origin Referer",
     *                "Date" => "Mon, 24 Jan 2022 17:25:15 GMT",
     *                "Server" => "ESF",
     *                "Content-Length" => "1259",
     *                "X-XSS-Protection" => "0",
     *                "X-Frame-Options" => "SAMEORIGIN",
     *                "X-Content-Type-Options" => "nosniff",
     *                "Alt-Svc" => (truncated)
     *                ],
     *                +"status": {#1269
     *                +"code": 200
     *                +"ok": true
     *                +"successful": true
     *                +"failed": false
     *                +"serverError": false
     *                +"clientError": false
     *                }
     */
    public static function parseApiResponse(object $response): object
    {
        $response_data = collect($response->object())->forget('etag')->forget('kind')->forget('nextPageToken');

        return (object) [
            'data' => (object) collect($response_data->count() == 1 ? $response_data->flatten(1) : $response_data)
                ->transform(function ($item) {
                    unset($item->etag);

                    return $item;
                })
                ->toArray(),
            'headers' => self::convertHeadersToArray($response->headers()),
            'status' => (object) [
                'code' => $response->status(),
                'ok' => $response->ok(),
                'successful' => $response->successful(),
                'failed' => $response->failed(),
                'serverError' => $response->serverError(),
                'clientError' => $response->clientError(),
            ],
        ];
    }

    /**
     * Create a log entry for an API call
     *
     * This method is called from other methods and create log entry and throw exception
     *
     * @param  string  $method    The upstream method that invoked this method for traceability
     *                            Ex. __METHOD__
     * @param  string  $url       The URL of the API call including the concatenated base URL and URI
     * @param  object  $response  The raw unformatted HTTP client response
     * @param  Carbon  $event_ms  A process start timestamp used to calculate duration in ms for logs
     */
    private static function logResponse(
        string $method,
        string $url,
        object $response,
        ?Carbon $event_ms = null
    ): void {
        $log_type = [
            200 => ['event_type' => 'success', 'level' => 'debug'],
            201 => ['event_type' => 'success', 'level' => 'debug'],
            202 => ['event_type' => 'success', 'level' => 'debug'],
            204 => ['event_type' => 'success', 'level' => 'debug'],
            400 => ['event_type' => 'warning.bad-request', 'level' => 'warning'],
            401 => ['event_type' => 'error.unauthorized', 'level' => 'error'],
            403 => ['event_type' => 'error.forbidden', 'level' => 'error'],
            404 => ['event_type' => 'warning.not-found', 'level' => 'warning'],
            405 => ['event_type' => 'error.method-not-allowed', 'level' => 'error'],
            412 => ['event_type' => 'error.precondition-failed', 'level' => 'error'],
            422 => ['event_type' => 'error.unprocessable', 'level' => 'error'],
            429 => ['event_type' => 'critical.rate-limit', 'level' => 'critical'],
            500 => ['event_type' => 'critical.server-error', 'level' => 'critical'],
            501 => ['event_type' => 'error.not-implemented', 'level' => 'error'],
            503 => ['event_type' => 'critical.server-unavailable', 'level' => 'critical'],
        ];

        $errors = [];
        if ($response->status->failed && ($error_message = self::parseErrorMessage($response)) !== null) {
            $errors['message'] = $error_message;
        }

        if ($response->status->successful) {
            $message = 'Success';
        } elseif ($response->status->clientError) {
            $message = 'Client Error';
        } elseif ($response->status->serverError) {
            $message = 'Server Error';
        } else {
            $message = 'Unknown Error';
        }

        $count_records = null;
        if ($response->status->ok && is_countable($response->data)) {
            $count_records = count((array) $response->data);
        }

        $event_ms_per_record = null;
        if ($event_ms && $count_records && $count_records > 1) {
            $event_ms_per_record = (int) ($event_ms->diffInMilliseconds() / $count_records);
        }

        AuditLog::create(
            count_records: $count_records,
            errors: $errors,
            event_ms: $event_ms,
            event_ms_per_record: $event_ms_per_record,
            event_type: implode('.', [
                'google',
                'api',
                explode('::', $method)[1],
                $log_type[$response->status->code]['event_type'],
            ]),
            level: $log_type[$response->status->code]['level'],
            message: $message,
            metadata: [
                'url' => $url,
            ],
            method: $method,
            transaction: false
        );
    }

    /**
     * Encoding schema utilized by Google OAuth2 Servers
     *
     * @link https://stackoverflow.com/a/65893524
     *
     * @param  string  $input  The input string to encode
     */
    protected static function base64UrlEncode(string $input): string
    {
        return str_replace('=', '', strtr(base64_encode($input), '+/', '-_'));
    }

    /**
     * Generate an array of request headers for the API request
     */
    private static function getRequestHeaders(): array
    {
        $composer_array = json_decode((string) file_get_contents(base_path('composer.json')), true);
        $package_name = Str::title($composer_array['name']);

        return [
            'User-Agent' => $package_name . ' ' . 'Laravel/' . app()->version() . ' ' . 'PHP/' . phpversion(),
        ];
    }

    /**
     * Add connection config array values to the request data included with all API requests
     *
     * @param  array  $connection  (optional) An array with API connection variables. See README for
     *                             schema. If not set, `config('google-api-client')` uses the GOOGLE_API_*
     *                             variables from your .env file.
     * @param  array  $query_keys  (optional) Array of connection configuration keys (customer,
     *                             domain, subject_email) that should be included with API requests for specific
     *                             endpoints (ex. Google Workspace Directory API) to merge into the query_data.
     */
    private static function getConnectionQueryParams(
        array $connection = [],
        array $query_keys = []
    ): array {
        $connection_config = ! empty($connection) ? $connection : config('google-api-client');

        $query_params = [];
        foreach ($query_keys as $key) {
            if (array_key_exists($key, $connection_config)) {
                $query_params[$key] = $connection_config[$key];
            } else {
                throw new ConfigurationException(
                    'The connection configuration key (' . $key . ') does not exist for this connection.'
                );
            }
        }

        return $query_params;
    }

    /**
     * Handle Google API Exception
     *
     * @param  RequestException  $exception  An instance of the exception
     * @param  string            $method     The upstream method that invoked this method for
     *                                       traceability Ex. __METHOD__
     * @param  string            $url        HTTP Request URL
     *
     * @return object
     *                {
     *                +"error": {
     *                +"code": "<string>"
     *                +"message": "<string>"
     *                +"method": "<string>"
     *                +"url": "<string>"
     *                }
     *                +"status": {
     *                +"code": 400
     *                +"ok": false
     *                +"successful": false
     *                +"failed": true
     *                +"serverError": false
     *                +"clientError": true
     *                }
     */
    public static function handleException(
        RequestException $exception,
        $method,
        $url
    ): object {
        AuditLog::create(
            errors: [
                'code' => $exception->getCode(),
                'message' => $exception->getMessage(),
                'trace' => $exception->getTrace(),
            ],
            event_type: 'google.api.' . explode('::', $method)[1] . '.error.http.exception',
            level: 'error',
            message: 'HTTP Response Exception',
            metadata: [
                'url' => $url,
            ],
            method: $method,
            transaction: true
        );

        return (object) [
            'error' => (object) [
                'code' => $exception->getCode(),
                'message' => $exception->getMessage(),
                'method' => $method,
                'url' => $url,
            ],
            'status' => (object) [
                'code' => $exception->getCode(),
                'ok' => false,
                'successful' => false,
                'failed' => true,
                'serverError' => true,
                'clientError' => false,
            ],
        ];
    }

    /**
     * Parse the reason for an unsuccessful API response into a human readable string
     *
     * The Google APIs and the OAuth 2.0 token endpoints use different keys for error messages, and
     * the top level `message` is often generic, so the reason for a failed request is discarded when
     * only checking a single key.
     *
     * - `error.status` and `error.message` are returned by the Google APIs.
     *   Ex. `{"error":{"code":400,"message":"Invalid Input: bad_email","status":"INVALID_ARGUMENT"}}`
     * - `error.details[].fieldViolations[]` names the specific request field that was rejected. The
     *   `message` for these responses is generic (ex. `Request contains an invalid argument.`), so
     *   the violations are the only actionable part of the response.
     * - `error.errors[].reason` is the machine readable reason returned by the Workspace APIs.
     *   Ex. `{"error":{"errors":[{"domain":"global","reason":"duplicate"}]}}`
     * - `error` and `error_description` are returned by the OAuth 2.0 token endpoints.
     *   Ex. `{"error":"invalid_grant","error_description":"Invalid grant: account not found"}`
     *
     * @link https://cloud.google.com/apis/design/errors
     *
     * @param  object  $response  The HTTP response formatted with $this->parseApiResponse()
     *
     * @return ?string The reason for the error or null if the response body is empty
     *                 Ex. `INVALID_ARGUMENT Invalid Input: bad_email`
     *                 Ex. `INVALID_ARGUMENT Request contains an invalid argument. (Details) parent: Invalid parent`
     */
    private static function parseErrorMessage(object $response): ?string
    {
        $data = $response->data ?? null;

        // An empty body (ex. a 204 No Content or a non-JSON body that could not be decoded) has no reason
        if (blank($data) || ((is_object($data) || is_array($data)) && blank((array) $data))) {
            return null;
        }

        // self::parseApiResponse() unwraps a response body that has a single key, so the `error`
        // object of a failed request is moved to the first element of the response data. The `error`
        // key is checked first for a body that was not unwrapped, then the first element is used.
        $error = data_get($data, 'error');
        $first = collect($data)->first();

        if (! blank($error) && ! is_scalar($error)) {
            $data = $error;
        } elseif (! blank($first) && ! is_scalar($first)) {
            $data = $first;
        }

        $reason = [];

        // `status` is the canonical error code (ex. `INVALID_ARGUMENT`). `error` is its equivalent
        // on the OAuth 2.0 token endpoints (ex. `invalid_grant`).
        foreach (['status', 'error'] as $key) {
            $value = data_get($data, $key);

            if (! blank($value) && is_scalar($value)) {
                $reason[] = trim((string) $value);
                break;
            }
        }

        // `message` is returned by the Google APIs. `error_description` is returned by the OAuth 2.0
        // token endpoints.
        foreach (['message', 'error_description'] as $key) {
            $value = data_get($data, $key);

            if (! blank($value)) {
                $reason[] = self::flattenErrorMessage($value);
                break;
            }
        }

        $details = self::parseErrorDetails($data);

        if ($details !== null) {
            $reason[] = '(Details) ' . $details;
        }

        if (! empty($reason)) {
            return implode(' ', $reason);
        }

        // The response body does not use a known error key (ex. an HTML error page from a proxy or
        // an undocumented response), so the entire body is returned to avoid discarding the reason.
        return Str::limit(is_scalar($data) ? (string) $data : (string) json_encode($data), 1000);
    }

    /**
     * Parse the per-field reasons from an error object
     *
     * @param  mixed  $error  The `error` object from an error response body
     *
     * @return ?string Ex. `parent: Invalid parent`
     *                 Ex. `duplicate`
     */
    private static function parseErrorDetails(mixed $error): ?string
    {
        $violations = collect((array) data_get($error, 'details'))
            ->flatMap(fn ($detail) => (array) data_get($detail, 'fieldViolations'))
            ->map(fn ($violation) => trim(implode(': ', array_filter([
                data_get($violation, 'field'),
                data_get($violation, 'description'),
            ]))))
            ->filter()
            ->all();

        if (! empty($violations)) {
            return implode(', ', $violations);
        }

        // The `message` of an `errors[]` entry is usually identical to the top level message, so it
        // is only appended when it adds something that is not already in the exception message.
        $message = data_get($error, 'message');

        $errors = collect((array) data_get($error, 'errors'))
            ->map(fn ($item) => trim(implode(': ', array_filter([
                data_get($item, 'reason'),
                data_get($item, 'message') !== $message ? data_get($item, 'message') : null,
            ]))))
            ->filter()
            ->all();

        return ! empty($errors) ? implode(', ', $errors) : null;
    }

    /**
     * Flatten a nested error message into a single line string
     *
     * @param  mixed  $value  A string, array, or object from an error response body
     *
     * @return string Ex. `name: has already been taken, path: can't be blank`
     */
    private static function flattenErrorMessage(mixed $value): string
    {
        if (is_scalar($value)) {
            return trim((string) $value);
        }

        $messages = [];

        foreach ((array) $value as $key => $item) {
            $item = self::flattenErrorMessage($item);

            $messages[] = is_string($key) ? $key . ': ' . $item : $item;
        }

        return implode(', ', $messages);
    }

    /**
     * Throw an exception for a 4xx or 5xx response for an API call
     *
     * This method checks whether the .env variable or config value for `GOOGLE_API_EXCEPTIONS=true`
     *
     * @param  string  $method    The lowercase name of the method that calls this function (ex. `get`)
     * @param  string  $url       The URL of the API call including the concatenated base URL and URI
     * @param  object  $response  The HTTP response formatted with $this->parseApiResponse()
     *
     * @throws BadRequestException
     * @throws ConflictException
     * @throws ForbiddenException
     * @throws MethodNotAllowedException
     * @throws NotFoundException
     * @throws PreconditionFailedException
     * @throws RateLimitException
     * @throws ServerErrorException
     * @throws ServiceUnavailableException
     * @throws UnauthorizedException
     * @throws UnprocessableException
     */
    protected static function throwExceptionIfEnabled(
        string $method,
        string $url,
        object $response
    ): void {
        if (config('google-api-client.exceptions') == true) {
            $error_message = self::parseErrorMessage($response);

            // The URL is kept exactly as it was requested so that it can be reproduced verbatim. A
            // percent encoded path or query string is hard to read, so a decoded copy is appended
            // when it differs. The decoded URL is for display only and is never used on its own,
            // because decoding a `%2F` produces a URL that the API routes to a different endpoint.
            $decoded_url = urldecode($url);

            $message = implode(' ', array_filter([
                Str::upper($method),
                $response->status->code,
                $url,
                $error_message ? '(Reason) ' . $error_message : null,
                $decoded_url !== $url ? '(Decoded) ' . $decoded_url : null,
            ]));

            switch ($response->status->code) {
                case 400:
                    throw new BadRequestException($message);
                case 401:
                    $message = implode(' ', [
                        'The Google API credentials have been configured but the key or query keys may be invalid.',
                        '(Reason) Verify that you have specified a valid scope for the endpoint.',
                        '(Reason) Verify that your API service account has been granted one of the OAUTH scopes.',
                        '(Reason) Verify if the endpoint requires a customer, domain, or subject_email query key.',
                    ]);
                    throw new UnauthorizedException($message);
                case 403:
                    throw new ForbiddenException($message);
                case 404:
                    throw new NotFoundException($message);
                case 405:
                    throw new MethodNotAllowedException($message);
                case 409:
                    throw new ConflictException($message);
                case 412:
                    throw new PreconditionFailedException($message);
                case 422:
                    throw new UnprocessableException($message);
                case 429:
                    throw new RateLimitException($message);
                case 500:
                    throw new ServerErrorException($message);
                case 503:
                    throw new ServiceUnavailableException($message);
            }
        }
    }
}
