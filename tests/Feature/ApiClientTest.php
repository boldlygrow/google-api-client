<?php

use BoldlyGrow\Google\ApiClient;
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
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

const DIRECTORY_URL = 'https://admin.googleapis.com/admin/directory/v1';
const DIRECTORY_SCOPE = 'https://www.googleapis.com/auth/admin.directory.group';

beforeEach(function () {
    config()->set('google-api-client.key_string', serviceAccountJson());
});

it('sends a GET request with the access token and returns the data, headers, and status', function () {
    fakeGoogle([
        'admin.googleapis.com/admin/directory/v1/groups/group1' => Http::response(
            ['kind' => 'admin#directory#group', 'etag' => '"abc"', 'id' => 'group1', 'email' => 'engineers@example.com'],
            200,
            ['ETag' => '"abc"', 'Vary' => ['Origin', 'X-Origin']]
        ),
    ]);

    $response = ApiClient::get(url: DIRECTORY_URL . '/groups/group1', scope: DIRECTORY_SCOPE);

    [$request] = apiRequests();
    expect($request->method())->toBe('GET');
    expect($request->header('Authorization'))->toBe(['Bearer test-access-token']);
    expect($request->header('User-Agent')[0])->toContain('Laravel/' . app()->version());
    expect((array) $response->data)->toBe(['id' => 'group1', 'email' => 'engineers@example.com']);
    expect($response->headers['ETag'])->toBe('abc');
    expect($response->headers['Vary'])->toBe(['Origin', 'X-Origin']);
    expect((array) $response->status)->toBe([
        'code' => 200,
        'ok' => true,
        'successful' => true,
        'failed' => false,
        'serverError' => false,
        'clientError' => false,
    ]);
});

it('unwraps a response body that has a single key and removes the etag of each record', function () {
    fakeGoogle([
        'admin.googleapis.com/admin/directory/v1/groups*' => Http::response([
            'kind' => 'admin#directory#groups',
            'etag' => '"list"',
            'groups' => [
                ['etag' => '"one"', 'id' => 'group1'],
                ['etag' => '"two"', 'id' => 'group2'],
            ],
        ]),
    ]);

    $response = ApiClient::get(url: DIRECTORY_URL . '/groups', scope: DIRECTORY_SCOPE);

    expect(collect($response->data)->map(fn ($group) => (array) $group)->values()->all())->toBe([
        ['id' => 'group1'],
        ['id' => 'group2'],
    ]);
});

it('merges the query_keys from the config into the query string', function () {
    fakeGoogle([
        'admin.googleapis.com/admin/directory/v1/groups*' => Http::response(['groups' => []]),
    ]);

    ApiClient::get(
        url: DIRECTORY_URL . '/groups',
        scope: DIRECTORY_SCOPE,
        query_data: ['maxResults' => 10],
        query_keys: ['customer', 'domain']
    );

    [$request] = apiRequests();
    expect($request->url())->toBe(DIRECTORY_URL . '/groups?maxResults=10&customer=my_customer&domain=example.com');
});

it('throws a ConfigurationException for a query_key that the connection does not have', function () {
    fakeGoogle();

    expect(fn () => ApiClient::get(url: DIRECTORY_URL . '/groups', scope: DIRECTORY_SCOPE, query_keys: ['tenant']))
        ->toThrow(ConfigurationException::class, 'The connection configuration key (tenant) does not exist for this connection.');
});

it('uses the credentials and query_keys of a connection array instead of the config', function () {
    fakeGoogle([
        'admin.googleapis.com/admin/directory/v1/users*' => Http::response(['users' => []]),
    ]);

    ApiClient::get(
        url: DIRECTORY_URL . '/users',
        scope: 'https://www.googleapis.com/auth/admin.directory.user',
        query_keys: ['customer'],
        connection: [
            'customer' => 'C0tenant',
            'key_string' => serviceAccountJson(['client_email' => 'tenant@example.iam.gserviceaccount.com']),
            'subject_email' => 'admin@tenant.example.com',
        ]
    );

    $token_request = Http::recorded()->map(fn (array $pair): Request => $pair[0])->first();
    $claims = decodeJwt($token_request->data()['assertion'])['claims'];
    [$request] = apiRequests();
    expect($claims['iss'])->toBe('tenant@example.iam.gserviceaccount.com');
    expect($claims['sub'])->toBe('admin@tenant.example.com');
    expect($request->url())->toBe(DIRECTORY_URL . '/users?customer=C0tenant');
});

it('sends a JSON body and the query string with POST, PATCH, PUT, and DELETE', function (string $method, int $status) {
    fakeGoogle([
        'admin.googleapis.com/admin/directory/v1/groups*' => Http::response(['id' => 'group1', 'name' => 'Engineers'], $status),
    ]);

    $response = ApiClient::$method(
        url: DIRECTORY_URL . '/groups',
        scope: DIRECTORY_SCOPE,
        form_data: ['name' => 'Engineers'],
        query_keys: ['customer']
    );

    [$request] = apiRequests();
    expect($request->method())->toBe(strtoupper($method));
    expect($request->url())->toBe(DIRECTORY_URL . '/groups?customer=my_customer');
    expect($request->data())->toBe(['name' => 'Engineers']);
    expect($request->header('Authorization'))->toBe(['Bearer test-access-token']);
    expect($response->status->code)->toBe($status);
})->with([
    'post' => ['post', 201],
    'patch' => ['patch', 200],
    'put' => ['put', 200],
    'delete' => ['delete', 200],
]);

it('follows the nextPageToken and returns the records from every page', function () {
    fakeGoogle([
        'admin.googleapis.com/admin/directory/v1/groups*' => Http::sequence()
            ->push(['groups' => [['id' => 'group1'], ['id' => 'group2']], 'nextPageToken' => 'page2'])
            ->push(['groups' => [['id' => 'group3']], 'nextPageToken' => 'page3'])
            ->push(['groups' => [['id' => 'group4']]]),
    ]);

    $response = ApiClient::get(url: DIRECTORY_URL . '/groups', scope: DIRECTORY_SCOPE, query_keys: ['customer']);

    $urls = array_map(fn (Request $request): string => $request->url(), apiRequests());
    expect(collect($response->data)->pluck('id')->all())->toBe(['group1', 'group2', 'group3', 'group4']);
    expect($urls)->toBe([
        DIRECTORY_URL . '/groups?customer=my_customer',
        DIRECTORY_URL . '/groups?customer=my_customer&pageToken=page2',
        DIRECTORY_URL . '/groups?customer=my_customer&pageToken=page3',
    ]);
});

it('throws the exception for each status code that has one', function (int $status, string $exception) {
    fakeGoogle([
        'admin.googleapis.com/admin/directory/v1/groups/group1' => Http::response(['error' => ['code' => $status, 'message' => 'Failed', 'status' => 'FAILED']], $status),
    ]);

    expect(fn () => ApiClient::get(url: DIRECTORY_URL . '/groups/group1', scope: DIRECTORY_SCOPE))
        ->toThrow($exception);
})->with([
    'bad request' => [400, BadRequestException::class],
    'unauthorized' => [401, UnauthorizedException::class],
    'forbidden' => [403, ForbiddenException::class],
    'not found' => [404, NotFoundException::class],
    'method not allowed' => [405, MethodNotAllowedException::class],
    'precondition failed' => [412, PreconditionFailedException::class],
    'unprocessable' => [422, UnprocessableException::class],
    'rate limit' => [429, RateLimitException::class],
    'server error' => [500, ServerErrorException::class],
    'service unavailable' => [503, ServiceUnavailableException::class],
]);

it('includes the method, status code, URL, and reason in the exception message', function () {
    fakeGoogle([
        'admin.googleapis.com/admin/directory/v1/groups/missing*' => Http::response(
            ['error' => ['code' => 404, 'message' => 'Resource Not Found: groupKey', 'status' => 'NOT_FOUND', 'errors' => [['reason' => 'notFound', 'message' => 'Resource Not Found: groupKey']]]],
            404
        ),
    ]);

    expect(fn () => ApiClient::get(url: DIRECTORY_URL . '/groups/missing', scope: DIRECTORY_SCOPE, query_keys: ['customer']))
        ->toThrow(NotFoundException::class, 'GET 404 ' . DIRECTORY_URL . '/groups/missing?customer=my_customer (Reason) NOT_FOUND Resource Not Found: groupKey (Details) notFound');
});

it('includes the field violations in the exception message', function () {
    fakeGoogle([
        'cloudresourcemanager.googleapis.com/*' => Http::response([
            'error' => [
                'code' => 400,
                'message' => 'Request contains an invalid argument.',
                'status' => 'INVALID_ARGUMENT',
                'details' => [['fieldViolations' => [['field' => 'parent', 'description' => 'Invalid parent']]]],
            ],
        ], 400),
    ]);

    expect(fn () => ApiClient::post(url: 'https://cloudresourcemanager.googleapis.com/v3/projects', scope: 'https://www.googleapis.com/auth/cloud-platform'))
        ->toThrow(BadRequestException::class, 'POST 400 https://cloudresourcemanager.googleapis.com/v3/projects (Reason) INVALID_ARGUMENT Request contains an invalid argument. (Details) parent: Invalid parent');
});

it('appends the decoded URL to the exception message when the URL is percent encoded', function () {
    fakeGoogle([
        'admin.googleapis.com/*' => Http::response(['error' => ['code' => 403, 'message' => 'Not Authorized to access this resource/api', 'status' => 'PERMISSION_DENIED']], 403),
    ]);

    expect(fn () => ApiClient::get(url: DIRECTORY_URL . '/groups/engineers%40example.com', scope: DIRECTORY_SCOPE))
        ->toThrow(ForbiddenException::class, '(Decoded) ' . DIRECTORY_URL . '/groups/engineers@example.com');
});

it('explains the likely causes of a 401 response', function () {
    fakeGoogle([
        'admin.googleapis.com/*' => Http::response(['error' => ['code' => 401, 'message' => 'Login Required.', 'status' => 'UNAUTHENTICATED']], 401),
    ]);

    expect(fn () => ApiClient::get(url: DIRECTORY_URL . '/groups', scope: DIRECTORY_SCOPE))
        ->toThrow(UnauthorizedException::class, '(Reason) Verify that you have specified a valid scope for the endpoint.');
});

it('returns the response instead of throwing an exception when exceptions are disabled', function () {
    config()->set('google-api-client.exceptions', false);
    fakeGoogle([
        'admin.googleapis.com/*' => Http::response(['error' => ['code' => 404, 'message' => 'Resource Not Found: groupKey', 'status' => 'NOT_FOUND']], 404),
    ]);

    $response = ApiClient::get(url: DIRECTORY_URL . '/groups/missing', scope: DIRECTORY_SCOPE);

    expect($response->status->code)->toBe(404);
    expect($response->status->clientError)->toBeTrue();
});

it('logs each status code with its event type and log level', function (int $status, string $event_type, string $level) {
    config()->set('google-api-client.exceptions', false);
    Log::spy();
    fakeGoogle([
        'admin.googleapis.com/*' => Http::response(['error' => ['code' => $status, 'message' => 'Message']], $status),
    ]);

    ApiClient::delete(url: DIRECTORY_URL . '/groups/group1', scope: DIRECTORY_SCOPE);

    Log::shouldHaveReceived('log')
        ->withArgs(fn (string $log_level, string $message, array $context) => $log_level === $level && $context['event_type'] === $event_type)
        ->once();
})->with([
    'ok' => [200, 'google.api.delete.success', 'debug'],
    'no content' => [204, 'google.api.delete.success', 'debug'],
    'bad request' => [400, 'google.api.delete.warning.bad-request', 'warning'],
    'unauthorized' => [401, 'google.api.delete.error.unauthorized', 'error'],
    'forbidden' => [403, 'google.api.delete.error.forbidden', 'error'],
    'not found' => [404, 'google.api.delete.warning.not-found', 'warning'],
    'method not allowed' => [405, 'google.api.delete.error.method-not-allowed', 'error'],
    'precondition failed' => [412, 'google.api.delete.error.precondition-failed', 'error'],
    'unprocessable' => [422, 'google.api.delete.error.unprocessable', 'error'],
    'rate limit' => [429, 'google.api.delete.critical.rate-limit', 'critical'],
    'server error' => [500, 'google.api.delete.critical.server-error', 'critical'],
    'not implemented' => [501, 'google.api.delete.error.not-implemented', 'error'],
    'service unavailable' => [503, 'google.api.delete.critical.server-unavailable', 'critical'],
]);

it('logs the reason that Google returned for a failed request', function () {
    config()->set('google-api-client.exceptions', false);
    Log::spy();
    fakeGoogle([
        'admin.googleapis.com/*' => Http::response(['error' => ['code' => 409, 'message' => 'Entity already exists.', 'status' => 'ALREADY_EXISTS']], 400),
    ]);

    ApiClient::post(url: DIRECTORY_URL . '/groups', scope: DIRECTORY_SCOPE);

    Log::shouldHaveReceived('log')
        ->withArgs(fn (string $log_level, string $message, array $context) => $context['event_type'] === 'google.api.post.warning.bad-request'
            && $context['errors'] === ['message' => 'ALREADY_EXISTS Entity already exists.'])
        ->once();
});

it('throws a ConflictException for a 409 response', function () {
    fakeGoogle([
        'admin.googleapis.com/*' => Http::response(['error' => ['code' => 409, 'message' => 'Entity already exists.', 'status' => 'ALREADY_EXISTS']], 409),
    ]);

    expect(fn () => ApiClient::post(url: DIRECTORY_URL . '/groups', scope: DIRECTORY_SCOPE))
        ->toThrow(ConflictException::class, 'POST 409 ' . DIRECTORY_URL . '/groups (Reason) ALREADY_EXISTS Entity already exists.');
});

it('throws the exception for the range of a 4xx or 5xx status code without its own exception', function (int $status, string $exception) {
    fakeGoogle([
        'admin.googleapis.com/*' => Http::response('', $status),
    ]);

    expect(fn () => ApiClient::delete(url: DIRECTORY_URL . '/groups/group1', scope: DIRECTORY_SCOPE))
        ->toThrow($exception, 'DELETE ' . $status . ' ' . DIRECTORY_URL . '/groups/group1');
})->with([
    'gone' => [410, BadRequestException::class],
    'not implemented' => [501, ServerErrorException::class],
    'bad gateway' => [502, ServerErrorException::class],
    'gateway timeout' => [504, ServerErrorException::class],
]);

it('logs a status code without its own event type with the event type for its range', function (int $status, string $event_type, string $level) {
    config()->set('google-api-client.exceptions', false);
    Log::spy();
    fakeGoogle([
        'admin.googleapis.com/*' => Http::response('', $status),
    ]);

    $response = ApiClient::delete(url: DIRECTORY_URL . '/groups/group1', scope: DIRECTORY_SCOPE);

    expect($response->status->code)->toBe($status);
    Log::shouldHaveReceived('log')
        ->withArgs(fn (string $log_level, string $message, array $context) => $log_level === $level && $context['event_type'] === $event_type)
        ->once();
})->with([
    'partial content' => [206, 'google.api.delete.success', 'debug'],
    'not modified' => [304, 'google.api.delete.warning.unexpected-status', 'warning'],
    'conflict' => [409, 'google.api.delete.error.conflict', 'error'],
    'gone' => [410, 'google.api.delete.error.client-error', 'error'],
    'bad gateway' => [502, 'google.api.delete.critical.server-error', 'critical'],
]);

it('returns a GET response without a JSON body when exceptions are disabled', function (int $status) {
    config()->set('google-api-client.exceptions', false);
    fakeGoogle([
        'admin.googleapis.com/*' => Http::response('<html>Not Found</html>', $status, ['Content-Type' => 'text/html']),
    ]);

    $response = ApiClient::get(url: DIRECTORY_URL . '/groups/missing', scope: DIRECTORY_SCOPE);

    expect($response->status->code)->toBe($status);
})->with([
    'not found' => [404],
    'service unavailable' => [503],
]);

it('throws the exception for a page of paginated results that fails', function () {
    fakeGoogle([
        'admin.googleapis.com/admin/directory/v1/groups*' => Http::sequence()
            ->push(['groups' => [['id' => 'group1']], 'nextPageToken' => 'page2'])
            ->push(['error' => ['code' => 429, 'message' => 'Quota exceeded.', 'status' => 'RESOURCE_EXHAUSTED']], 429),
    ]);

    expect(fn () => ApiClient::get(url: DIRECTORY_URL . '/groups', scope: DIRECTORY_SCOPE))
        ->toThrow(RateLimitException::class, 'GET|PAGINATED 429 ' . DIRECTORY_URL . '/groups?pageToken=page2 (Reason) RESOURCE_EXHAUSTED Quota exceeded.');
});

it('returns the status of a failed page and the records before it when exceptions are disabled', function () {
    config()->set('google-api-client.exceptions', false);
    fakeGoogle([
        'admin.googleapis.com/admin/directory/v1/groups*' => Http::sequence()
            ->push(['groups' => [['id' => 'group1']], 'nextPageToken' => 'page2'])
            ->push(['error' => ['code' => 429, 'message' => 'Quota exceeded.', 'status' => 'RESOURCE_EXHAUSTED']], 429),
    ]);

    $response = ApiClient::get(url: DIRECTORY_URL . '/groups', scope: DIRECTORY_SCOPE);

    expect($response->status->code)->toBe(429);
    expect($response->status->failed)->toBeTrue();
    expect(collect($response->data)->pluck('id')->all())->toBe(['group1']);
});

it('returns an error response with the same properties as other responses from handleException()', function () {
    $exception = new RequestException(new Response(new GuzzleResponse(500)));

    $response = ApiClient::handleException(exception: $exception, method: 'BoldlyGrow\Google\ApiClient::get', url: DIRECTORY_URL . '/groups');

    expect($response->data)->toBeNull();
    expect($response->headers)->toBe([]);
    expect($response->status->failed)->toBeTrue();
    expect($response->error->url)->toBe(DIRECTORY_URL . '/groups');
});

it('logs the number of records from every page when pagination finishes', function () {
    Log::spy();
    fakeGoogle([
        'admin.googleapis.com/admin/directory/v1/groups*' => Http::sequence()
            ->push(['groups' => [['id' => 'group1'], ['id' => 'group2']], 'nextPageToken' => 'page2'])
            ->push(['groups' => [['id' => 'group3']]]),
    ]);

    ApiClient::get(url: DIRECTORY_URL . '/groups', scope: DIRECTORY_SCOPE);

    Log::shouldHaveReceived('log')
        ->withArgs(fn (string $log_level, string $message, array $context) => $context['event_type'] === 'google.api.get.process.pagination.finished'
            && $context['count_records'] === 3)
        ->once();
});

it('logs the number of records in a successful response', function () {
    Log::spy();
    fakeGoogle([
        'admin.googleapis.com/admin/directory/v1/groups*' => Http::response(['groups' => [['id' => 'group1'], ['id' => 'group2']]]),
    ]);

    ApiClient::get(url: DIRECTORY_URL . '/groups', scope: DIRECTORY_SCOPE);

    Log::shouldHaveReceived('log')
        ->withArgs(fn (string $log_level, string $message, array $context) => $context['event_type'] === 'google.api.get.success'
            && $context['count_records'] === 2)
        ->once();
});
