<?php

namespace BoldlyGrow\Google;

use BoldlyGrow\Google\Exceptions\AuthenticationException;
use BoldlyGrow\Google\Exceptions\ConfigurationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Provisionesta\Audit\Log;

/**
 * Google API Authentication Token Generator
 *
 * @author Dillon Wheeler
 * @author Jeff Martin
 *
 * This is used to authenticate with the Google OAuth2 Sever utilizing a Google Service Account JSON API key.
 *
 * To provide a streamlined developer experience, this uses either the key_path parameter that points to your JSON
 * API key's storage path, or you can provide the JSON API key as a string in the key_string parameter. If neither
 * is set, the key_string will try to use the GOOGLE_APPLICATION_CREDENTIALS environment variable if it exists.
 *
 * The OAUTH service will return a short-lived API token that can be used with the Laravel HTTP Client to perform
 * GET, POST, PATCH, DELETE, etc. API requests that can be found in the Google API Explorer documentation.
 *
 * The token is encrypted and cached for 1 hour for future requests with the same JWT claim and JSON private_key_id.
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

    public const ENCRYPT_METHOD = 'sha256';

    /**
     * Create a new Google API Token using JWT Claim or returned cached token if the signature matches with the same
     * email, scopes, and private key.
     *
     * @param  string  $scope       One of the scopes required by the API endpoint that your API key has
     *                              been authorized to use.
     * @param  array   $connection  (optional) An array with API connection variables. See README for
     *                              schema. If not set, `config('google-api-client')` uses the GOOGLE_API_*
     *                              variables from your .env file.
     *
     * @throws AuthenticationException
     */
    public static function create(string $scope, array $connection = [])
    {
        $validated_connection = self::validateConnectionArray($connection);

        $json_key_contents = self::getJsonKeyContents($validated_connection);

        $jwt_headers = self::createJwtHeader();

        $subject_email = $validated_connection['subject_email'] ?? $json_key_contents->client_email;

        $jwt_claim = self::createJwtClaim(
            client_email: $json_key_contents->client_email ?? null,
            scope: $scope,
            subject_email: $subject_email
        );

        $signature = self::createSignature(
            jwt_header: $jwt_headers,
            jwt_claim: $jwt_claim,
            private_key: $json_key_contents->private_key
        );

        $cache_checksum_token = 'google-api-token-' . md5(json_encode([
            'scope' => $scope,
            'subject_email' => $subject_email,
            'private_key_id' => $json_key_contents->private_key_id,
        ]));

        $encrypted_token = Cache::remember(
            key: $cache_checksum_token,
            ttl: 3540,
            callback: function () use ($jwt_headers, $jwt_claim, $signature) {
                $api_token = self::sendAuthRequest(
                    jwt: $jwt_headers . '.' . $jwt_claim . '.' . $signature
                );

                return encrypt($api_token);
            }
        );

        return decrypt($encrypted_token);
    }

    /**
     * Validate that array keys exist in the connection array
     *
     * @param  array  $connection  An array with API connection variables. See README for schema.
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
            Log::create(
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
     * Get Google API JSON key contents from `key_string` or `key_path` connection configuration.
     *
     * @param  array  $connection  An array with API connection variables. See README for schema.
     *
     * @throws ConfigurationException
     */
    private static function getJsonKeyContents(array $connection): object
    {
        if (isset($connection['key_string'])) {
            $json_key = json_decode($connection['key_string']);
        } elseif (isset($connection['key_path'])) {
            $json_key = json_decode((string) file_get_contents($connection['key_path']));
        } elseif (isset($_ENV['GOOGLE_APPLICATION_CREDENTIALS'])) {
            $json_key = json_decode((string) $_ENV['GOOGLE_APPLICATION_CREDENTIALS']);
            // } elseif (isset($_ENV['GOOGLE_DEFAULT_APPLICATION_CREDENTIALS'])) {
            //     $json_key = json_decode((string) $_ENV['GOOGLE_DEFAULT_APPLICATION_CREDENTIALS']);
        } else {
            $json_key = [];
        }

        if (empty($json_key)) {
            $reason = 'The JSON key contents are empty.';

            Log::create(
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
            ]));
        }

        return $json_key;
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
     */
    private static function createSignature(
        string $jwt_header,
        string $jwt_claim,
        string $private_key
    ): string {
        $key_id = openssl_pkey_get_private($private_key);

        openssl_sign(
            $jwt_header . '.' . $jwt_claim,
            $private_key,
            $key_id,
            self::ENCRYPT_METHOD
        );

        return self::base64UrlEncode($private_key);
    }

    /**
     * Create and send the Google Authentication POST request
     *
     * @link https://developers.google.com/identity/protocols/oauth2/service-account#:~:text=Making%20the%20access%20token%20request
     *
     * @param  string  $jwt  The JWT to use for authentication
     */
    private static function sendAuthRequest(string $jwt): string
    {
        $response = Http::asForm()->post(
            url: self::AUTH_BASE_URL,
            data: [
                'grant_type' => self::AUTH_GRANT_TYPE,
                'assertion' => $jwt,
            ]
        );

        if (! $response->successful()) {
            if (property_exists($response->object(), 'error')) {
                $reason = $response->object()->error_description;
            } else {
                $reason = 'Unknown response in the sendAuthRequest method.';
            }

            Log::create(
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

        if (! property_exists($response->object(), 'access_token')) {
            $reason = 'The access_token was not returned in the sendAuthRequest method response.';

            Log::create(
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

        Log::create(
            event_type: 'google.api.auth.success',
            level: 'debug',
            message: 'Success',
            method: __METHOD__,
            transaction: false
        );

        return $response->object()->access_token;
    }
}
