<?php

return [
    /**
     * The customer number of the Google Account that the APIs run against
     *
     * This must match the customer number that the service account is
     * associated with. Google provides an alias `my_customer` that uses the
     * customer ID of the service account by default.
     *
     * @see https://support.google.com/a/answer/10070793
     */
    'customer' => env('GOOGLE_API_CUSTOMER', 'my_customer'),

    /**
     * The primary email domain to filter Google Workspace results to
     *
     * When working with Workspace groups and users, the `domain` can be added
     * to the request `query_keys` to filter results to this domain. Leave
     * blank for testing or to skip domain filtering.
     */
    'domain' => env('GOOGLE_API_DOMAIN'),

    /**
     * Whether PHP exceptions are thrown if the API experiences an error
     *
     * All requests including 4xx and 5xx errors are logged using the audit and
     * event log, including with ERROR and CRITICAL log levels. If your log
     * level in Laravel catches the problem with your bug report, then you may
     * not need these exceptions. If you want to handle problems behind the
     * scenes without users seeing an error message, then you can disable this
     * and inspect the `status` array returned in each response instead.
     *
     * @see vendor/boldlygrow/google-api-client/src/Exceptions
     */
    'exceptions' => env('GOOGLE_API_EXCEPTIONS', true),

    /**
     * The full filesystem path to the service account JSON key file
     *
     * For security reasons, this should be saved outside the Laravel directory
     * unless you have configured proper file permissions. Prefer the gcloud CLI
     * or an attached service account over a stored key file where possible.
     */
    'key_path' => env('GOOGLE_API_KEY_PATH'),

    /**
     * The service account JSON key contents as a string
     *
     * This is intended for applications that load keys from a database or a
     * secrets manager at runtime. Prefer `key_path`, the gcloud CLI, or an
     * attached service account. Takes precedence over `key_path`.
     */
    'key_string' => env('GOOGLE_API_KEY_STRING'),

    /**
     * The email address to impersonate when running the Google Workspace API
     *
     * This is not related to granting access; it just needs to be a valid user
     * email that has permissions for the same action in the Admin UI.
     */
    'subject_email' => env('GOOGLE_API_SUBJECT_EMAIL'),
];
