<?php

namespace BoldlyGrow\Google;

use BoldlyGrow\AuditLog\AuditLog;
use BoldlyGrow\Google\Exceptions\AuthenticationException;
use BoldlyGrow\Google\Exceptions\ConfigurationException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use stdClass;
use Throwable;

/**
 * Google API Authentication Token Generator
 *
 * @author Dillon Wheeler
 * @author Jeff Martin
 *
 * This is used to authenticate with the Google OAuth2 server and return a short-lived API token that can be used
 * with the Laravel HTTP Client to perform GET, POST, PATCH, DELETE, etc. API requests that can be found in the
 * Google API Explorer documentation.
 *
 * Three credential types are supported and are resolved in the order documented in `getCredentials()`.
 *
 * 1. `service_account` - A Google service account JSON key. The key is used to sign a JWT that is exchanged for an
 *    access token. This is the only type that supports `subject_email` domain-wide delegation for Google Workspace.
 * 2. `authorized_user` - Your own Google credentials written by `gcloud auth application-default login`. The refresh
 *    token is exchanged for an access token. This is intended for local development.
 * 3. `metadata_server` - The service account attached to Google Cloud infrastructure (Compute Engine, GKE, Cloud Run,
 *    App Engine, etc.). The access token is read from the metadata server and no credentials are stored.
 *
 * The token is encrypted and cached until shortly before the expiration returned by Google for future requests with
 * the same claim and credentials.
 *
 * This is called from the inside ApiClient class and does not need to be instantiated separately in your code.
 */
class ApiToken
{
    // Standard parameters for building JWT request with Google OAuth Server.
    // They are put here for easy changing if necessary
    public const AUTH_BASE_URL = 'https://oauth2.googleapis.com/token';

    public const AUTH_ALGORITHM = 'RS256';

    public const AUTH_TYPE = 'JWT';

    public const AUTH_GRANT_TYPE = 'urn:ietf:params:oauth:grant-type:jwt-bearer';

    public const AUTH_REFRESH_GRANT_TYPE = 'refresh_token';

    public const ENCRYPT_METHOD = 'sha256';

    // The `type` value in a Google credentials file. The metadata server does not have a credentials file, so the
    // type is synthesized when the metadata server is used.
    public const TYPE_AUTHORIZED_USER = 'authorized_user';

    public const TYPE_METADATA_SERVER = 'metadata_server';

    public const TYPE_SERVICE_ACCOUNT = 'service_account';

    // The metadata server is only reachable from inside Google Cloud infrastructure. The probe timeout is
    // deliberately short since it is attempted on developer machines where it will never resolve.
    public const METADATA_HOST = 'metadata.google.internal';

    public const METADATA_TOKEN_URI = '/computeMetadata/v1/instance/service-account/default/token';

    public const METADATA_PROBE_TIMEOUT = 1;

    public const METADATA_REQUEST_TIMEOUT = 5;

    // Google returns an `expires_in` for every token, however it will hand back an already-issued token that is
    // partway through its lifetime rather than always minting a fresh one. The buffer is subtracted from whatever
    // Google returns so that a token is never served within a minute of expiring.
    public const TOKEN_DEFAULT_EXPIRATION = 3600;

    public const TOKEN_EXPIRATION_BUFFER = 60;

    /**
     * Whether the Google Cloud metadata server is reachable, memoized for the lifetime of the process.
     */
    private static ?bool $has_metadata_server = null;

    /**
     * Create a new Google API token, or return the cached token if one was already issued for the same scope,
     * subject, and credentials.
     *
     * @param  string                $scope       One of the scopes required by the API endpoint that your
     *                                            credentials have been authorized to use.
     * @param  array<string, mixed>  $connection  (optional) An array with API connection variables. See README for
     *                                            schema. If not set, `config('google-api-client')` uses the
     *                                            GOOGLE_API_* variables from your .env file.
     *
     * @throws AuthenticationException
     * @throws ConfigurationException
     */
    public static function create(string $scope, array $connection = []): string
    {
        $validated_connection = self::validateConnectionArray($connection);

        $credentials = self::getCredentials($validated_connection);

        $type = isset($credentials->type) && is_string($credentials->type)
            ? $credentials->type
            : self::TYPE_SERVICE_ACCOUNT;

        return match ($type) {
            self::TYPE_SERVICE_ACCOUNT => self::createServiceAccountToken($scope, $credentials, $validated_connection),
            self::TYPE_AUTHORIZED_USER => self::createAuthorizedUserToken($scope, $credentials, $validated_connection),
            self::TYPE_METADATA_SERVER => self::createMetadataServerToken($scope, $validated_connection),
            default => throw new ConfigurationException(implode(' ', [
                'Google API validation error.',
                '(Reason) The credential type (' . $type . ') is not supported.',
                '(Fix) Use a service account JSON key, run `gcloud auth application-default login`,',
                'or run this application on Google Cloud infrastructure with an attached service account.',
            ])),
        };
    }

    /**
     * Validate that array keys exist in the connection array
     *
     * @param  array<string, mixed>  $connection  An array with API connection variables. See README for schema.
     *
     * @return array<string, mixed>
     *
     * @throws ConfigurationException
     */
    private static function validateConnectionArray(array $connection): array
    {
        $connection_config = ! empty($connection) ? $connection : config('google-api-client');

        $validator = Validator::make(
            data: $connection_config,
            rules: [
                'customer_id' => ['nullable', 'string'],
                'domain' => ['nullable', 'string'],
                'key_path' => [
                    'string',
                    'nullable',
                ],
                'key_string' => [
                    'string',
                    'nullable',
                ],
                'subject_email' => ['nullable', 'string'],
            ],
        );

        if ($validator->fails()) {
            AuditLog::create(
                errors: $validator->errors()->all(),
                event_type: 'google.api.validate.error.array',
                level: 'critical',
                message: 'Error',
                method: __METHOD__,
                transaction: false
            );

            throw new ConfigurationException(implode('', [
                'Google API configuration validation error.',
                '(Reason) ' . $validator->messages()->first(),
            ]));
        }

        return $validator->validated();
    }

    /**
     * Resolve Google API credentials from the first source that is configured.
     *
     * 1. The `key_string` connection parameter (a JSON key as a string).
     * 2. The `key_path` connection parameter, which is set by the `GOOGLE_API_KEY_PATH` .env variable.
     * 3. The `GOOGLE_APPLICATION_CREDENTIALS` environment variable, which may be a path to a JSON key file (the
     *    convention used by every other Google SDK) or the JSON key contents.
     * 4. The application default credentials file written by `gcloud auth application-default login`.
     * 5. The service account attached to the Google Cloud resource this application is running on.
     *
     * @param  array<string, mixed>  $connection  An array with API connection variables. See README for schema.
     *
     * @throws ConfigurationException
     */
    private static function getCredentials(array $connection): stdClass
    {
        if (isset($connection['key_string'])) {
            return self::decodeJsonKey(
                contents: (string) $connection['key_string'],
                source: 'the key_string connection parameter'
            );
        }

        if (isset($connection['key_path'])) {
            return self::decodeJsonKeyFile(
                path: (string) $connection['key_path'],
                source: 'the key_path connection parameter (GOOGLE_API_KEY_PATH)'
            );
        }

        $environment_credentials = self::getEnvironmentVariable('GOOGLE_APPLICATION_CREDENTIALS');

        if ($environment_credentials !== null) {
            $source = 'the GOOGLE_APPLICATION_CREDENTIALS environment variable';

            // A base64 encoded key would otherwise be reported as an unreadable file path, which buries the real
            // problem and writes the encoded private key into the exception message.
            if (self::isBase64EncodedJson($environment_credentials)) {
                $reason = 'The JSON key contents in ' . $source . ' are base64 encoded.';

                AuditLog::create(
                    errors: [$reason],
                    event_type: 'google.api.validate.error.encoding',
                    level: 'critical',
                    message: 'Error',
                    method: __METHOD__,
                    transaction: false
                );

                throw new ConfigurationException(implode(' ', [
                    'Google API validation error.',
                    '(Reason) ' . $reason,
                    '(Fix) Store the decoded JSON key contents in the variable, set the variable to the path of a',
                    'JSON key file, or set GOOGLE_API_KEY_PATH instead.',
                ]));
            }

            // Google's own SDKs treat this variable as a filesystem path. Earlier versions of this package treated
            // it as the JSON key contents, so both are accepted to avoid breaking existing deployments.
            return str_starts_with(ltrim($environment_credentials), '{')
                ? self::decodeJsonKey(contents: $environment_credentials, source: $source)
                : self::decodeJsonKeyFile(path: $environment_credentials, source: $source);
        }

        $application_default_path = self::getApplicationDefaultCredentialsPath();

        if (is_readable($application_default_path)) {
            return self::decodeJsonKeyFile(
                path: $application_default_path,
                source: 'the gcloud application default credentials file'
            );
        }

        if (self::hasMetadataServer()) {
            return (object) ['type' => self::TYPE_METADATA_SERVER];
        }

        $reason = 'No Google API credentials were found.';

        AuditLog::create(
            errors: [$reason],
            event_type: 'google.api.validate.error.empty',
            level: 'critical',
            message: 'Error',
            method: __METHOD__,
            transaction: false
        );

        throw new ConfigurationException(implode(' ', [
            'Google API validation error.',
            '(Reason) ' . $reason,
            '(Fix) Run `gcloud auth application-default login` for local development,',
            'or set GOOGLE_API_KEY_PATH in your .env to the path of a service account JSON key file.',
            '(Checked) the key_string and key_path connection parameters,',
            'the GOOGLE_APPLICATION_CREDENTIALS environment variable,',
            $application_default_path . ',',
            'and the Google Cloud metadata server.',
        ]));
    }

    /**
     * Create a token by signing a JWT with a service account private key
     *
     * @link https://developers.google.com/identity/protocols/oauth2/service-account
     *
     * @param  string                $scope        One of the scopes that the service account is authorized to use.
     * @param  stdClass              $credentials  The decoded service account JSON key.
     * @param  array<string, mixed>  $connection   An array with API connection variables.
     *
     * @throws AuthenticationException
     * @throws ConfigurationException
     */
    private static function createServiceAccountToken(
        string $scope,
        stdClass $credentials,
        array $connection
    ): string {
        self::validateCredentialKeys(
            credentials: $credentials,
            required_keys: ['client_email', 'private_key', 'private_key_id'],
            type: self::TYPE_SERVICE_ACCOUNT
        );

        $jwt_headers = self::createJwtHeader();

        $subject_email = ! empty($connection['subject_email'])
            ? (string) $connection['subject_email']
            : (string) $credentials->client_email;

        $jwt_claim = self::createJwtClaim(
            client_email: (string) $credentials->client_email,
            scope: $scope,
            subject_email: $subject_email
        );

        $signature = self::createSignature(
            jwt_header: $jwt_headers,
            jwt_claim: $jwt_claim,
            private_key: (string) $credentials->private_key
        );

        $cache_checksum_token = 'google-api-token-' . md5((string) json_encode([
            'scope' => $scope,
            'subject_email' => $subject_email,
            'private_key_id' => $credentials->private_key_id,
        ]));

        return self::rememberToken(
            cache_key: $cache_checksum_token,
            callback: fn (): array => self::sendServiceAccountRequest(
                jwt: $jwt_headers . '.' . $jwt_claim . '.' . $signature
            )
        );
    }

    /**
     * Create a token by exchanging the refresh token from `gcloud auth application-default login`
     *
     * @link https://cloud.google.com/docs/authentication/application-default-credentials#personal
     *
     * @param  string                $scope        One of the scopes that was consented to during the gcloud login.
     * @param  stdClass              $credentials  The decoded application default credentials.
     * @param  array<string, mixed>  $connection   An array with API connection variables.
     *
     * @throws AuthenticationException
     * @throws ConfigurationException
     */
    private static function createAuthorizedUserToken(
        string $scope,
        stdClass $credentials,
        array $connection
    ): string {
        self::validateSubjectEmailIsNotSet($connection, self::TYPE_AUTHORIZED_USER);

        self::validateCredentialKeys(
            credentials: $credentials,
            required_keys: ['client_id', 'client_secret', 'refresh_token'],
            type: self::TYPE_AUTHORIZED_USER
        );

        // The refresh token is hashed so that the credential never becomes part of a cache key.
        $cache_checksum_token = 'google-api-token-' . md5((string) json_encode([
            'scope' => $scope,
            'type' => self::TYPE_AUTHORIZED_USER,
            'client_id' => $credentials->client_id,
            'refresh_token' => hash('sha256', (string) $credentials->refresh_token),
        ]));

        return self::rememberToken(
            cache_key: $cache_checksum_token,
            callback: fn (): array => self::sendAuthorizedUserRequest(scope: $scope, credentials: $credentials)
        );
    }

    /**
     * Create a token using the service account attached to Google Cloud infrastructure
     *
     * @link https://cloud.google.com/docs/authentication/application-default-credentials#attached-sa
     *
     * @param  string                $scope       One of the scopes that the attached service account can use.
     * @param  array<string, mixed>  $connection  An array with API connection variables.
     *
     * @throws AuthenticationException
     * @throws ConfigurationException
     */
    private static function createMetadataServerToken(string $scope, array $connection): string
    {
        self::validateSubjectEmailIsNotSet($connection, self::TYPE_METADATA_SERVER);

        $cache_checksum_token = 'google-api-token-' . md5((string) json_encode([
            'scope' => $scope,
            'type' => self::TYPE_METADATA_SERVER,
        ]));

        return self::rememberToken(
            cache_key: $cache_checksum_token,
            callback: fn (): array => self::sendMetadataServerRequest(scope: $scope)
        );
    }

    /**
     * Return the cached token for the checksum, or request a new one and cache it until shortly before it expires.
     *
     * @param  string                                $cache_key  The cache key for this scope and credential combination.
     * @param  callable(): array{0: string, 1: int}  $callback   Returns the access token and its lifetime in seconds.
     */
    private static function rememberToken(string $cache_key, callable $callback): string
    {
        $encrypted_token = Cache::get($cache_key);

        if (is_string($encrypted_token)) {
            return (string) decrypt($encrypted_token);
        }

        [$access_token, $expires_in] = $callback();

        Cache::put(
            key: $cache_key,
            value: encrypt($access_token),
            ttl: max(self::TOKEN_EXPIRATION_BUFFER, $expires_in - self::TOKEN_EXPIRATION_BUFFER)
        );

        return $access_token;
    }

    /**
     * Create and encode the required JWT Headers for Google OAuth2 authentication
     *
     * @link https://developers.google.com/identity/protocols/oauth2/service-account#:~:text=Forming%20the%20JWT%20header
     */
    private static function createJwtHeader(): string
    {
        return self::base64UrlEncode((string) json_encode([
            'alg' => self::AUTH_ALGORITHM,
            'typ' => self::AUTH_TYPE,
        ]));
    }

    /**
     * Encoding schema utilized by Google OAuth2 Servers
     *
     * @link https://stackoverflow.com/a/65893524
     *
     * @param  string  $input  The input string to encode
     */
    private static function base64UrlEncode(string $input): string
    {
        return str_replace('=', '', strtr(base64_encode($input), '+/', '-_'));
    }

    /**
     * Create and encode the required JWT Claims for Google OAuth2 authentication
     *
     * @link https://developers.google.com/identity/protocols/oauth2/service-account#:~:text=Forming%20the%20JWT%20claim%20set
     *
     * @param  string  $client_email   The `client_email` from the Google JSON key
     * @param  string  $scope          One of the scopes required by the API endpoint that your API key has
     *                                 been authorized to use.
     * @param  string  $subject_email  The `subject_email` to use for authentication
     */
    private static function createJwtClaim(
        string $client_email,
        string $scope,
        string $subject_email
    ): string {
        return self::base64UrlEncode((string) json_encode([
            'iss' => $client_email,
            'scope' => $scope,
            'aud' => self::AUTH_BASE_URL,
            'exp' => time() + 3600,
            'iat' => time(),
            'sub' => $subject_email,
        ]));
    }

    /**
     * Create a OpenSSL signature using JWT Header and Claim and the private_key from the Google JSON key
     *
     * @link https://developers.google.com/identity/protocols/oauth2/service-account#:~:text=Computing%20the-,signature,-JSON%20Web%20Signature
     * @link https://datatracker.ietf.org/doc/html/rfc7515
     * @link https://www.php.net/manual/en/function.openssl-pkey-get-private.php
     *
     * @param  string  $jwt_header   The JWT Header string required for Google OAuth2 authentication
     * @param  string  $jwt_claim    The JWT Claim string required for Google OAuth2 authentication
     * @param  string  $private_key  The Google JSON key `private_key` value
     *
     * @throws ConfigurationException
     */
    private static function createSignature(
        string $jwt_header,
        string $jwt_claim,
        string $private_key
    ): string {
        $key_id = openssl_pkey_get_private($private_key);

        if ($key_id === false) {
            $reason = 'The private_key in the service account JSON key could not be parsed by OpenSSL.';

            AuditLog::create(
                errors: [$reason],
                event_type: 'google.api.validate.error.key',
                level: 'critical',
                message: 'Error',
                method: __METHOD__,
                transaction: false
            );

            throw new ConfigurationException(implode(' ', [
                'Google API validation error.',
                '(Reason) ' . $reason,
                '(Fix) Verify that the newlines in the private_key value are escaped as \n and that the key has not',
                'been truncated or re-encoded.',
            ]));
        }

        // The signature is written into $private_key by reference, which is why it is returned rather than $key_id.
        openssl_sign(
            $jwt_header . '.' . $jwt_claim,
            $private_key,
            $key_id,
            self::ENCRYPT_METHOD
        );

        return self::base64UrlEncode($private_key);
    }

    /**
     * Create and send the Google Authentication POST request for a service account JWT
     *
     * @link https://developers.google.com/identity/protocols/oauth2/service-account#:~:text=Making%20the%20access%20token%20request
     *
     * @param  string  $jwt  The JWT to use for authentication
     *
     * @return array{0: string, 1: int}
     *
     * @throws AuthenticationException
     */
    private static function sendServiceAccountRequest(string $jwt): array
    {
        $response = Http::asForm()->post(
            url: self::AUTH_BASE_URL,
            data: [
                'grant_type' => self::AUTH_GRANT_TYPE,
                'assertion' => $jwt,
            ]
        );

        return self::parseTokenResponse($response);
    }

    /**
     * Create and send the Google Authentication POST request for an application default credentials refresh token
     *
     * @link https://developers.google.com/identity/protocols/oauth2/web-server#offline
     *
     * @param  string    $scope        One of the scopes that was consented to during the gcloud login.
     * @param  stdClass  $credentials  The decoded application default credentials.
     *
     * @return array{0: string, 1: int}
     *
     * @throws AuthenticationException
     * @throws ConfigurationException
     */
    private static function sendAuthorizedUserRequest(string $scope, stdClass $credentials): array
    {
        $response = Http::asForm()->post(
            url: self::AUTH_BASE_URL,
            data: [
                'client_id' => $credentials->client_id,
                'client_secret' => $credentials->client_secret,
                'refresh_token' => $credentials->refresh_token,
                'grant_type' => self::AUTH_REFRESH_GRANT_TYPE,
                'scope' => $scope,
            ]
        );

        // Google Cloud organizations can require periodic reauthentication, which expires the refresh token
        // written by gcloud. This is routine for developers and is fixed by logging in again rather than by
        // changing any application configuration.
        if ($response->json('error') === 'invalid_grant') {
            $reason = 'The gcloud application default credentials have expired or been revoked.';

            AuditLog::create(
                errors: [$reason, (string) $response->json('error_description')],
                event_type: 'google.api.auth.error.expired',
                level: 'critical',
                message: 'Error',
                method: __METHOD__,
                transaction: false
            );

            throw new AuthenticationException(implode(' ', [
                'Google API token authentication error.',
                '(Reason) ' . $reason,
                '(Google) ' . self::getResponseErrorReason($response) . '.',
                '(Fix) Run `gcloud auth application-default login` to sign in again.',
            ]));
        }

        // A refresh token can only be narrowed to the scopes that were consented to when it was issued. Google
        // rejects anything else outright, which is a configuration problem rather than an authentication failure.
        if ($response->json('error') === 'invalid_scope') {
            $reason = 'The scope (' . $scope . ') was not granted to your gcloud application default credentials.';

            AuditLog::create(
                errors: [$reason],
                event_type: 'google.api.validate.error.scope',
                level: 'critical',
                message: 'Error',
                method: __METHOD__,
                transaction: false
            );

            throw new ConfigurationException(implode(' ', [
                'Google API validation error.',
                '(Reason) ' . $reason,
                '(Fix) Run `gcloud auth application-default login --scopes=' . $scope . '`',
                'to consent to this scope, and include any other scopes your application uses in the same command.',
                '(Note) Google Workspace Admin SDK scopes also require a service account with domain-wide delegation.',
            ]));
        }

        return self::parseTokenResponse($response);
    }

    /**
     * Read an access token for the attached service account from the Google Cloud metadata server
     *
     * @link https://cloud.google.com/compute/docs/metadata/overview
     *
     * @param  string  $scope  One of the scopes that the attached service account can use.
     *
     * @return array{0: string, 1: int}
     *
     * @throws AuthenticationException
     */
    private static function sendMetadataServerRequest(string $scope): array
    {
        $response = Http::withHeaders(['Metadata-Flavor' => 'Google'])
            ->timeout(self::METADATA_REQUEST_TIMEOUT)
            ->get('http://' . self::getMetadataHost() . self::METADATA_TOKEN_URI, [
                'scopes' => $scope,
            ]);

        return self::parseTokenResponse($response);
    }

    /**
     * Parse an access token and its lifetime out of a Google authentication response
     *
     * @param  Response  $response  The HTTP response from a token endpoint.
     *
     * @return array{0: string, 1: int}
     *
     * @throws AuthenticationException
     */
    private static function parseTokenResponse(Response $response): array
    {
        if (! $response->successful()) {
            $reason = self::getResponseErrorReason($response);

            AuditLog::create(
                errors: [$reason],
                event_type: 'google.api.auth.error',
                level: 'critical',
                message: 'Error',
                method: __METHOD__,
                transaction: false
            );

            throw new AuthenticationException(implode(' ', [
                'Google API token authentication error.',
                '(Reason) ' . $reason,
            ]));
        }

        $access_token = $response->json('access_token');

        if (! is_string($access_token) || $access_token === '') {
            $reason = 'The access_token was not returned in the authentication response.';

            AuditLog::create(
                errors: [$reason],
                event_type: 'google.api.auth.error',
                level: 'critical',
                message: 'Error',
                method: __METHOD__,
                transaction: false
            );

            throw new AuthenticationException(implode(' ', [
                'Google API token authentication error.',
                '(Reason) ' . $reason,
            ]));
        }

        AuditLog::create(
            event_type: 'google.api.auth.success',
            level: 'debug',
            message: 'Success',
            method: __METHOD__,
            transaction: false
        );

        $expires_in = $response->json('expires_in');

        return [$access_token, is_numeric($expires_in) ? (int) $expires_in : self::TOKEN_DEFAULT_EXPIRATION];
    }

    /**
     * Build a human readable reason from a failed Google authentication response
     *
     * @param  Response  $response  The HTTP response from a token endpoint.
     */
    private static function getResponseErrorReason(Response $response): string
    {
        $description = $response->json('error_description');

        if (is_string($description) && $description !== '') {
            return $description;
        }

        $error = $response->json('error');

        if (is_string($error) && $error !== '') {
            return $error;
        }

        return 'The Google authentication endpoint returned an HTTP ' . $response->status() . ' response.';
    }

    /**
     * Decode a JSON key file from the filesystem
     *
     * @param  string  $path    The full filesystem path to the JSON key file.
     * @param  string  $source  A description of where the path was configured, used in error messages.
     *
     * @throws ConfigurationException
     */
    private static function decodeJsonKeyFile(string $path, string $source): stdClass
    {
        if (! is_readable($path)) {
            $reason = 'The JSON key file configured in ' . $source . ' does not exist or is not readable.';

            AuditLog::create(
                errors: [$reason],
                event_type: 'google.api.validate.error.file',
                level: 'critical',
                message: 'Error',
                method: __METHOD__,
                transaction: false
            );

            throw new ConfigurationException(implode(' ', [
                'Google API validation error.',
                '(Reason) ' . $reason,
                '(Path) ' . self::redactValueForMessage($path),
            ]));
        }

        return self::decodeJsonKey(contents: (string) file_get_contents($path), source: $source);
    }

    /**
     * Decode JSON key contents into a credentials object
     *
     * @param  string  $contents  The JSON key contents.
     * @param  string  $source    A description of where the contents came from, used in error messages.
     *
     * @throws ConfigurationException
     */
    private static function decodeJsonKey(string $contents, string $source): stdClass
    {
        $credentials = json_decode($contents);

        if (! $credentials instanceof stdClass) {
            $reason = 'The JSON key contents from ' . $source . ' could not be parsed.';

            AuditLog::create(
                errors: [$reason],
                event_type: 'google.api.validate.error.empty',
                level: 'critical',
                message: 'Error',
                method: __METHOD__,
                transaction: false
            );

            throw new ConfigurationException(implode(' ', [
                'Google API validation error.',
                '(Reason) ' . $reason,
                '(JSON Error) ' . json_last_error_msg() . '.',
                '(Fix) Verify that the value is valid JSON, that it has not been base64 encoded,',
                'and that the newlines in the private_key value are escaped as \n.',
            ]));
        }

        return $credentials;
    }

    /**
     * Validate that the keys required for a credential type are present
     *
     * @param  stdClass       $credentials    The decoded credentials.
     * @param  array<string>  $required_keys  The keys that must be present for this credential type.
     * @param  string         $type           The credential type, used in error messages.
     *
     * @throws ConfigurationException
     */
    private static function validateCredentialKeys(
        stdClass $credentials,
        array $required_keys,
        string $type
    ): void {
        $missing_keys = array_values(array_filter(
            $required_keys,
            fn (string $key): bool => ! isset($credentials->{$key}) || $credentials->{$key} === ''
        ));

        if ($missing_keys === []) {
            return;
        }

        $reason = implode(' ', [
            'The credentials are missing the',
            implode(', ', $missing_keys),
            count($missing_keys) === 1 ? 'key' : 'keys',
            'required for the ' . $type . ' credential type.',
        ]);

        AuditLog::create(
            errors: [$reason],
            event_type: 'google.api.validate.error.keys',
            level: 'critical',
            message: 'Error',
            method: __METHOD__,
            transaction: false
        );

        throw new ConfigurationException(implode(' ', [
            'Google API validation error.',
            '(Reason) ' . $reason,
        ]));
    }

    /**
     * Reject a `subject_email` for credential types that cannot perform domain-wide delegation
     *
     * @param  array<string, mixed>  $connection  An array with API connection variables.
     * @param  string                $type        The credential type, used in error messages.
     *
     * @throws ConfigurationException
     */
    private static function validateSubjectEmailIsNotSet(array $connection, string $type): void
    {
        if (empty($connection['subject_email'])) {
            return;
        }

        $reason = 'The subject_email impersonation is not supported by the ' . $type . ' credential type.';

        AuditLog::create(
            errors: [$reason],
            event_type: 'google.api.validate.error.subject',
            level: 'critical',
            message: 'Error',
            method: __METHOD__,
            transaction: false
        );

        throw new ConfigurationException(implode(' ', [
            'Google API validation error.',
            '(Reason) ' . $reason,
            'Domain-wide delegation requires a JWT signed by a service account private key.',
            '(Fix) Set GOOGLE_API_KEY_PATH to a service account JSON key that has been granted domain-wide',
            'delegation in the Google Workspace Admin console, or remove GOOGLE_API_SUBJECT_EMAIL.',
        ]));
    }

    /**
     * Get the path of the credentials file written by `gcloud auth application-default login`
     *
     * @link https://cloud.google.com/docs/authentication/application-default-credentials#personal
     */
    private static function getApplicationDefaultCredentialsPath(): string
    {
        $config_directory = self::getEnvironmentVariable('CLOUDSDK_CONFIG');

        if ($config_directory === null) {
            $is_windows = DIRECTORY_SEPARATOR === '\\';

            $config_directory = ($is_windows
                ? (self::getEnvironmentVariable('APPDATA') ?? '') . '/gcloud'
                : (self::getEnvironmentVariable('HOME') ?? '') . '/.config/gcloud');
        }

        return $config_directory . '/application_default_credentials.json';
    }

    /**
     * Get the metadata server host, which can be overridden for testing and for private Google Access setups
     */
    private static function getMetadataHost(): string
    {
        return self::getEnvironmentVariable('GCE_METADATA_HOST') ?? self::METADATA_HOST;
    }

    /**
     * Check whether this application is running on Google Cloud infrastructure with an attached service account.
     *
     * The result is memoized because the probe cannot resolve outside of Google Cloud and would otherwise cost the
     * timeout on every token request.
     */
    private static function hasMetadataServer(): bool
    {
        if (self::$has_metadata_server !== null) {
            return self::$has_metadata_server;
        }

        try {
            $response = Http::withHeaders(['Metadata-Flavor' => 'Google'])
                ->timeout(self::METADATA_PROBE_TIMEOUT)
                ->get('http://' . self::getMetadataHost() . '/computeMetadata/v1/');

            self::$has_metadata_server = $response->successful()
                && $response->header('Metadata-Flavor') === 'Google';
        } catch (Throwable) {
            self::$has_metadata_server = false;
        }

        return self::$has_metadata_server;
    }

    /**
     * Check whether a value is base64 encoded JSON, which is a common way for a JSON key to be stored incorrectly
     *
     * @param  string  $value  The configured credential value.
     */
    private static function isBase64EncodedJson(string $value): bool
    {
        if (preg_match('/^[A-Za-z0-9+\/\r\n]+={0,2}$/', $value) !== 1) {
            return false;
        }

        $decoded = base64_decode($value, true);

        return is_string($decoded) && str_starts_with(ltrim($decoded), '{');
    }

    /**
     * Truncate a value that is too long to be a filesystem path so that a misconfigured credential is never
     * written into an exception message, a log, or a stack trace.
     *
     * @param  string  $value   The value to include in a message.
     * @param  int     $length  The number of characters to keep.
     */
    private static function redactValueForMessage(string $value, int $length = 96): string
    {
        if (strlen($value) <= $length) {
            return $value;
        }

        return substr($value, 0, $length) . '... (redacted, ' . strlen($value) . ' characters)';
    }

    /**
     * Read an environment variable from any of the locations PHP and Laravel populate
     *
     * @param  string  $key  The environment variable name.
     */
    private static function getEnvironmentVariable(string $key): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if (! is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}
