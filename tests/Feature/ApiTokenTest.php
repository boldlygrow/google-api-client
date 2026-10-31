<?php

use BoldlyGrow\Google\ApiToken;
use BoldlyGrow\Google\Exceptions\AuthenticationException;
use BoldlyGrow\Google\Exceptions\ConfigurationException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('signs a JWT with the service account private key and exchanges it for an access token', function () {
    fakeGoogle();

    $token = ApiToken::create(
        scope: 'https://www.googleapis.com/auth/admin.directory.group',
        connection: ['key_string' => serviceAccountJson()]
    );

    /** @var Request $request */
    [$request] = Http::recorded()->sole();
    $assertion = $request->data()['assertion'];
    [$header, $claims, $signature] = explode('.', $assertion);
    $jwt = decodeJwt($assertion);

    expect($token)->toBe('test-access-token');
    expect($request->url())->toBe('https://oauth2.googleapis.com/token');
    expect($request->data()['grant_type'])->toBe('urn:ietf:params:oauth:grant-type:jwt-bearer');
    expect($jwt['header'])->toBe(['alg' => 'RS256', 'typ' => 'JWT']);
    expect($jwt['claims'])->toMatchArray([
        'iss' => 'robot@example-project.iam.gserviceaccount.com',
        'scope' => 'https://www.googleapis.com/auth/admin.directory.group',
        'aud' => 'https://oauth2.googleapis.com/token',
        'sub' => 'robot@example-project.iam.gserviceaccount.com',
    ]);
    expect($jwt['claims']['exp'] - $jwt['claims']['iat'])->toBe(3600);
    expect(openssl_verify(
        $header . '.' . $claims,
        base64_decode(strtr($signature, '-_', '+/')),
        testPublicKey(),
        'sha256'
    ))->toBe(1);
});

it('impersonates the subject_email with domain-wide delegation', function () {
    fakeGoogle();

    ApiToken::create(
        scope: 'https://www.googleapis.com/auth/admin.directory.user',
        connection: ['key_string' => serviceAccountJson(), 'subject_email' => 'admin@example.com']
    );

    /** @var Request $request */
    [$request] = Http::recorded()->sole();
    expect(decodeJwt($request->data()['assertion'])['claims']['sub'])->toBe('admin@example.com');
});

it('uses the GOOGLE_API_KEY_STRING config value when no connection array is passed', function () {
    config()->set('google-api-client.key_string', serviceAccountJson(['client_email' => 'config@example.iam.gserviceaccount.com']));
    fakeGoogle();

    ApiToken::create(scope: 'https://www.googleapis.com/auth/cloud-platform');

    /** @var Request $request */
    [$request] = Http::recorded()->sole();
    expect(decodeJwt($request->data()['assertion'])['claims']['iss'])->toBe('config@example.iam.gserviceaccount.com');
});

it('reads the service account JSON key from the key_path file', function () {
    $path = $this->temporaryDirectory() . '/key.json';
    file_put_contents($path, serviceAccountJson(['client_email' => 'file@example.iam.gserviceaccount.com']));
    fakeGoogle();

    ApiToken::create(scope: 'https://www.googleapis.com/auth/cloud-platform', connection: ['key_path' => $path]);

    /** @var Request $request */
    [$request] = Http::recorded()->sole();
    expect(decodeJwt($request->data()['assertion'])['claims']['iss'])->toBe('file@example.iam.gserviceaccount.com');
});

it('uses key_string instead of key_path when both are set', function () {
    fakeGoogle();

    ApiToken::create(scope: 'https://www.googleapis.com/auth/cloud-platform', connection: [
        'key_string' => serviceAccountJson(['client_email' => 'string@example.iam.gserviceaccount.com']),
        'key_path' => '/nonexistent/key.json',
    ]);

    /** @var Request $request */
    [$request] = Http::recorded()->sole();
    expect(decodeJwt($request->data()['assertion'])['claims']['iss'])->toBe('string@example.iam.gserviceaccount.com');
});

it('reads GOOGLE_APPLICATION_CREDENTIALS as a path to a JSON key file', function () {
    $path = $this->temporaryDirectory() . '/key.json';
    file_put_contents($path, serviceAccountJson(['client_email' => 'adc-path@example.iam.gserviceaccount.com']));
    $this->setEnvironmentVariable('GOOGLE_APPLICATION_CREDENTIALS', $path);
    fakeGoogle();

    ApiToken::create(scope: 'https://www.googleapis.com/auth/cloud-platform');

    /** @var Request $request */
    [$request] = Http::recorded()->sole();
    expect(decodeJwt($request->data()['assertion'])['claims']['iss'])->toBe('adc-path@example.iam.gserviceaccount.com');
});

it('reads GOOGLE_APPLICATION_CREDENTIALS as the JSON key contents', function () {
    $this->setEnvironmentVariable('GOOGLE_APPLICATION_CREDENTIALS', serviceAccountJson(['client_email' => 'adc-json@example.iam.gserviceaccount.com']));
    fakeGoogle();

    ApiToken::create(scope: 'https://www.googleapis.com/auth/cloud-platform');

    /** @var Request $request */
    [$request] = Http::recorded()->sole();
    expect(decodeJwt($request->data()['assertion'])['claims']['iss'])->toBe('adc-json@example.iam.gserviceaccount.com');
});

it('rejects a base64 encoded GOOGLE_APPLICATION_CREDENTIALS value without including it in the message', function () {
    $encoded = base64_encode(serviceAccountJson());
    $this->setEnvironmentVariable('GOOGLE_APPLICATION_CREDENTIALS', $encoded);

    try {
        ApiToken::create(scope: 'https://www.googleapis.com/auth/cloud-platform');
        $this->fail('A ConfigurationException was not thrown.');
    } catch (ConfigurationException $exception) {
        expect($exception->getMessage())->toContain('are base64 encoded');
        expect($exception->getMessage())->not->toContain(substr($encoded, 0, 40));
    }
});

it('redacts a long key_path value from the exception message', function () {
    $value = str_repeat('a', 200);

    expect(fn () => ApiToken::create(scope: 'https://www.googleapis.com/auth/cloud-platform', connection: ['key_path' => $value]))
        ->toThrow(ConfigurationException::class, '(redacted, 200 characters)');
});

it('reports a JSON key that cannot be parsed', function () {
    expect(fn () => ApiToken::create(scope: 'https://www.googleapis.com/auth/cloud-platform', connection: ['key_string' => '{not json']))
        ->toThrow(ConfigurationException::class, 'The JSON key contents from the key_string connection parameter could not be parsed.');
});

it('reports the keys that are missing from a service account JSON key', function () {
    $json = json_encode(['type' => 'service_account', 'client_email' => 'robot@example.iam.gserviceaccount.com']);

    expect(fn () => ApiToken::create(scope: 'https://www.googleapis.com/auth/cloud-platform', connection: ['key_string' => $json]))
        ->toThrow(ConfigurationException::class, 'The credentials are missing the private_key, private_key_id keys required for the service_account credential type.');
});

it('reports a private key that OpenSSL cannot parse', function () {
    expect(fn () => ApiToken::create(scope: 'https://www.googleapis.com/auth/cloud-platform', connection: ['key_string' => serviceAccountJson(['private_key' => 'not a key'])]))
        ->toThrow(ConfigurationException::class, 'could not be parsed by OpenSSL');
});

it('rejects a credential type that is not supported', function () {
    expect(fn () => ApiToken::create(scope: 'https://www.googleapis.com/auth/cloud-platform', connection: ['key_string' => serviceAccountJson(['type' => 'external_account'])]))
        ->toThrow(ConfigurationException::class, 'The credential type (external_account) is not supported.');
});

it('validates the connection array value types', function () {
    expect(fn () => ApiToken::create(scope: 'https://www.googleapis.com/auth/cloud-platform', connection: ['key_path' => 123]))
        ->toThrow(ConfigurationException::class, 'The key path field must be a string.');
});

it('throws an AuthenticationException with the reason when Google rejects the JWT', function () {
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Invalid JWT Signature.'], 400),
    ]);

    expect(fn () => ApiToken::create(scope: 'https://www.googleapis.com/auth/cloud-platform', connection: ['key_string' => serviceAccountJson()]))
        ->toThrow(AuthenticationException::class, 'Google API token authentication error. (Reason) Invalid JWT Signature.');
});

it('throws an AuthenticationException when the token response has no access_token', function () {
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['expires_in' => 3599]),
    ]);

    expect(fn () => ApiToken::create(scope: 'https://www.googleapis.com/auth/cloud-platform', connection: ['key_string' => serviceAccountJson()]))
        ->toThrow(AuthenticationException::class, 'The access_token was not returned in the authentication response.');
});

it('caches the access token for the same scope, subject, and key', function () {
    fakeGoogle();
    $connection = ['key_string' => serviceAccountJson()];

    $first = ApiToken::create(scope: 'https://www.googleapis.com/auth/cloud-platform', connection: $connection);
    $second = ApiToken::create(scope: 'https://www.googleapis.com/auth/cloud-platform', connection: $connection);

    expect($first)->toBe('test-access-token');
    expect($second)->toBe('test-access-token');
    Http::assertSentCount(1);
});

it('requests a new access token for a different scope, subject, or key', function () {
    fakeGoogle();

    ApiToken::create(scope: 'https://www.googleapis.com/auth/cloud-platform', connection: ['key_string' => serviceAccountJson()]);
    ApiToken::create(scope: 'https://www.googleapis.com/auth/admin.directory.user', connection: ['key_string' => serviceAccountJson()]);
    ApiToken::create(scope: 'https://www.googleapis.com/auth/cloud-platform', connection: ['key_string' => serviceAccountJson(), 'subject_email' => 'admin@example.com']);
    ApiToken::create(scope: 'https://www.googleapis.com/auth/cloud-platform', connection: ['key_string' => serviceAccountJson(['private_key_id' => 'rotated-key-id'])]);

    Http::assertSentCount(4);
});

it('exchanges the gcloud application default credentials refresh token for an access token', function () {
    $directory = $this->temporaryDirectory();
    file_put_contents($directory . '/application_default_credentials.json', authorizedUserJson());
    $this->setEnvironmentVariable('CLOUDSDK_CONFIG', $directory);
    fakeGoogle();

    $token = ApiToken::create(scope: 'https://www.googleapis.com/auth/cloud-platform');

    /** @var Request $request */
    [$request] = Http::recorded()->sole();
    expect($token)->toBe('test-access-token');
    expect($request->data())->toBe([
        'client_id' => '764086051850-test.apps.googleusercontent.com',
        'client_secret' => 'test-client-secret',
        'refresh_token' => 'test-refresh-token',
        'grant_type' => 'refresh_token',
        'scope' => 'https://www.googleapis.com/auth/cloud-platform',
    ]);
});

it('reads the application default credentials from the home directory when CLOUDSDK_CONFIG is not set', function () {
    $home = $this->temporaryDirectory();
    mkdir($home . '/.config/gcloud', 0700, true);
    file_put_contents($home . '/.config/gcloud/application_default_credentials.json', authorizedUserJson(['refresh_token' => 'home-refresh-token']));
    $this->setEnvironmentVariable('CLOUDSDK_CONFIG', null);
    $this->setEnvironmentVariable('HOME', $home);
    fakeGoogle();

    try {
        ApiToken::create(scope: 'https://www.googleapis.com/auth/cloud-platform');
    } finally {
        unlink($home . '/.config/gcloud/application_default_credentials.json');
        rmdir($home . '/.config/gcloud');
        rmdir($home . '/.config');
    }

    /** @var Request $request */
    [$request] = Http::recorded()->sole();
    expect($request->data()['refresh_token'])->toBe('home-refresh-token');
});

it('caches the application default credentials access token', function () {
    $directory = $this->temporaryDirectory();
    file_put_contents($directory . '/application_default_credentials.json', authorizedUserJson());
    $this->setEnvironmentVariable('CLOUDSDK_CONFIG', $directory);
    fakeGoogle();

    ApiToken::create(scope: 'https://www.googleapis.com/auth/cloud-platform');
    ApiToken::create(scope: 'https://www.googleapis.com/auth/cloud-platform');

    Http::assertSentCount(1);
});

it('tells the developer to sign in again when the gcloud refresh token has expired', function () {
    $directory = $this->temporaryDirectory();
    file_put_contents($directory . '/application_default_credentials.json', authorizedUserJson());
    $this->setEnvironmentVariable('CLOUDSDK_CONFIG', $directory);
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'], 400),
    ]);

    expect(fn () => ApiToken::create(scope: 'https://www.googleapis.com/auth/cloud-platform'))
        ->toThrow(AuthenticationException::class, '(Fix) Run `gcloud auth application-default login` to sign in again.');
});

it('tells the developer how to consent to a scope that the gcloud credentials were not granted', function () {
    $directory = $this->temporaryDirectory();
    file_put_contents($directory . '/application_default_credentials.json', authorizedUserJson());
    $this->setEnvironmentVariable('CLOUDSDK_CONFIG', $directory);
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_scope'], 400),
    ]);

    expect(fn () => ApiToken::create(scope: 'https://www.googleapis.com/auth/admin.directory.user'))
        ->toThrow(ConfigurationException::class, '`gcloud auth application-default login --scopes=https://www.googleapis.com/auth/admin.directory.user`');
});

it('rejects a subject_email with application default credentials', function () {
    $directory = $this->temporaryDirectory();
    file_put_contents($directory . '/application_default_credentials.json', authorizedUserJson());
    $this->setEnvironmentVariable('CLOUDSDK_CONFIG', $directory);
    config()->set('google-api-client.subject_email', 'admin@example.com');

    expect(fn () => ApiToken::create(scope: 'https://www.googleapis.com/auth/admin.directory.user'))
        ->toThrow(ConfigurationException::class, 'The subject_email impersonation is not supported by the authorized_user credential type.');
});

it('reads the attached service account access token from the metadata server at GCE_METADATA_HOST', function () {
    $this->setEnvironmentVariable('GCE_METADATA_HOST', 'metadata.test');
    Http::fake([
        'metadata.test/computeMetadata/v1/' => Http::response('', 200, ['Metadata-Flavor' => 'Google']),
        'metadata.test/computeMetadata/v1/instance/service-accounts/default/token*' => Http::response(tokenResponseBody('metadata-access-token')),
    ]);

    $token = ApiToken::create(scope: 'https://www.googleapis.com/auth/cloud-platform');

    $requests = Http::recorded()->map(fn (array $pair): Request => $pair[0])->values();
    expect($token)->toBe('metadata-access-token');
    expect($requests)->toHaveCount(2);
    expect($requests[1]->url())->toBe('http://metadata.test/computeMetadata/v1/instance/service-accounts/default/token?scopes=https%3A%2F%2Fwww.googleapis.com%2Fauth%2Fcloud-platform');
    expect($requests[0]->header('Metadata-Flavor'))->toBe(['Google']);
    expect($requests[1]->header('Metadata-Flavor'))->toBe(['Google']);
});

it('caches the metadata server access token and probes the metadata server once', function () {
    $this->setEnvironmentVariable('GCE_METADATA_HOST', 'metadata.test');
    Http::fake([
        'metadata.test/computeMetadata/v1/' => Http::response('', 200, ['Metadata-Flavor' => 'Google']),
        'metadata.test/computeMetadata/v1/instance/service-accounts/default/token*' => Http::response(tokenResponseBody('metadata-access-token')),
    ]);

    ApiToken::create(scope: 'https://www.googleapis.com/auth/cloud-platform');
    ApiToken::create(scope: 'https://www.googleapis.com/auth/cloud-platform');
    ApiToken::create(scope: 'https://www.googleapis.com/auth/admin.directory.user');

    // One probe, and one token request for each of the two scopes.
    Http::assertSentCount(3);
});

it('rejects a subject_email with the metadata server', function () {
    $this->setEnvironmentVariable('GCE_METADATA_HOST', 'metadata.test');
    config()->set('google-api-client.subject_email', 'admin@example.com');
    Http::fake([
        'metadata.test/computeMetadata/v1/' => Http::response('', 200, ['Metadata-Flavor' => 'Google']),
    ]);

    expect(fn () => ApiToken::create(scope: 'https://www.googleapis.com/auth/admin.directory.user'))
        ->toThrow(ConfigurationException::class, 'The subject_email impersonation is not supported by the metadata_server credential type.');
});

it('does not use a metadata server that does not return the Metadata-Flavor header', function () {
    $this->setEnvironmentVariable('GCE_METADATA_HOST', 'metadata.test');
    Http::fake([
        'metadata.test/computeMetadata/v1/' => Http::response('<html>captive portal</html>', 200),
    ]);

    expect(fn () => ApiToken::create(scope: 'https://www.googleapis.com/auth/cloud-platform'))
        ->toThrow(ConfigurationException::class, 'No Google API credentials were found.');
});

it('lists every credential source that was checked when no credentials are found', function () {
    expect(fn () => ApiToken::create(scope: 'https://www.googleapis.com/auth/cloud-platform'))
        ->toThrow(ConfigurationException::class, '(Checked) the key_string and key_path connection parameters, the GOOGLE_APPLICATION_CREDENTIALS environment variable,');
});

it('does not share a cached access token between connections that cannot be JSON encoded', function () {
    fakeGoogle();

    expect(fn () => ApiToken::create(scope: 'https://www.googleapis.com/auth/cloud-platform', connection: ['key_string' => serviceAccountJson(), 'subject_email' => "admin\xB1@example.com"]))
        ->toThrow(JsonException::class);
    expect(fn () => ApiToken::create(scope: 'https://www.googleapis.com/auth/cloud-platform', connection: ['key_string' => serviceAccountJson(), 'subject_email' => "other\xB2@example.com"]))
        ->toThrow(JsonException::class);

    Http::assertNothingSent();
});

it('separates the reason from the heading in a connection array validation error', function () {
    expect(fn () => ApiToken::create(scope: 'https://www.googleapis.com/auth/cloud-platform', connection: ['key_path' => 123]))
        ->toThrow(ConfigurationException::class, 'Google API configuration validation error. (Reason) The key path field must be a string.');
});
