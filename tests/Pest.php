<?php

use BoldlyGrow\Google\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(TestCase::class)->in(__DIR__ . '/Feature');

/**
 * An RSA private key for signing service account JWTs, generated once per test run since key generation is slow.
 */
function testPrivateKey(): string
{
    static $private_key = null;

    if ($private_key === null) {
        $key_resource = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ]);

        openssl_pkey_export($key_resource, $private_key);
    }

    return $private_key;
}

/**
 * The public key that matches testPrivateKey(), for verifying JWT signatures.
 */
function testPublicKey(): string
{
    $details = openssl_pkey_get_details(openssl_pkey_get_private(testPrivateKey()));

    return $details['key'];
}

/**
 * A service account JSON key, as Google Cloud downloads it.
 *
 * @param  array<string, mixed>  $overrides
 */
function serviceAccountJson(array $overrides = []): string
{
    return json_encode(array_merge([
        'type' => 'service_account',
        'project_id' => 'example-project',
        'private_key_id' => 'test-private-key-id',
        'private_key' => testPrivateKey(),
        'client_email' => 'robot@example-project.iam.gserviceaccount.com',
        'client_id' => '100000000000000000001',
        'token_uri' => 'https://oauth2.googleapis.com/token',
    ], $overrides), JSON_THROW_ON_ERROR);
}

/**
 * The application default credentials file that `gcloud auth application-default login` writes.
 *
 * @param  array<string, mixed>  $overrides
 */
function authorizedUserJson(array $overrides = []): string
{
    return json_encode(array_merge([
        'type' => 'authorized_user',
        'client_id' => '764086051850-test.apps.googleusercontent.com',
        'client_secret' => 'test-client-secret',
        'refresh_token' => 'test-refresh-token',
        'quota_project_id' => 'example-project',
    ], $overrides), JSON_THROW_ON_ERROR);
}

/**
 * A successful Google token endpoint response body.
 *
 * @return array<string, mixed>
 */
function tokenResponseBody(string $access_token = 'test-access-token', int $expires_in = 3599): array
{
    return [
        'access_token' => $access_token,
        'expires_in' => $expires_in,
        'token_type' => 'Bearer',
    ];
}

/**
 * Fake the Google OAuth 2.0 token endpoint and the API responses for a test.
 *
 * @param  array<string, mixed>  $responses  The Http::fake() responses for the API endpoints.
 */
function fakeGoogle(array $responses = []): void
{
    Http::fake(array_merge([
        'oauth2.googleapis.com/token' => Http::response(tokenResponseBody()),
    ], $responses));
}

/**
 * The requests that were sent to an API, without the token endpoint request.
 *
 * @return array<int, Request>
 */
function apiRequests(): array
{
    return Http::recorded()
        ->map(fn (array $pair): Request => $pair[0])
        ->reject(fn (Request $request): bool => str_starts_with($request->url(), 'https://oauth2.googleapis.com/'))
        ->values()
        ->all();
}

/**
 * Decode the header and claims of a JWT without verifying its signature.
 *
 * @return array{header: array<string, mixed>, claims: array<string, mixed>}
 */
function decodeJwt(string $jwt): array
{
    [$header, $claims] = explode('.', $jwt);

    return [
        'header' => json_decode(base64_decode(strtr($header, '-_', '+/')), true),
        'claims' => json_decode(base64_decode(strtr($claims, '-_', '+/')), true),
    ];
}
