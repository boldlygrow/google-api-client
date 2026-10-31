<?php

namespace BoldlyGrow\Google\Tests;

use BoldlyGrow\AuditLog\AuditLogServiceProvider;
use BoldlyGrow\Google\ApiToken;
use BoldlyGrow\Google\GoogleApiServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;
use ReflectionProperty;

abstract class TestCase extends Orchestra
{
    /**
     * The original values of the environment variables that a test changed, restored after each test.
     *
     * @var array<string, array{env: mixed, server: mixed, getenv: string|false}>
     */
    private array $original_environment = [];

    /**
     * The temporary directories that a test created, removed after each test.
     *
     * @var array<int, string>
     */
    private array $temporary_directories = [];

    protected function setUp(): void
    {
        parent::setUp();

        // Every Google endpoint a test calls must be faked, so no test can reach the network.
        Http::preventStrayRequests();

        // The credentials of the machine that runs the tests must never be used. CLOUDSDK_CONFIG points at an empty
        // directory so that a real `gcloud auth application-default login` file is not found.
        $this->setEnvironmentVariable('GOOGLE_APPLICATION_CREDENTIALS', null);
        $this->setEnvironmentVariable('GCE_METADATA_HOST', null);
        $this->setEnvironmentVariable('CLOUDSDK_CONFIG', $this->temporaryDirectory());

        // The metadata server probe is memoized for the lifetime of the process.
        (new ReflectionProperty(ApiToken::class, 'has_metadata_server'))->setValue(null, null);
    }

    protected function tearDown(): void
    {
        foreach ($this->original_environment as $key => $original) {
            $this->restoreEnvironmentVariable($key, $original);
        }

        foreach ($this->temporary_directories as $directory) {
            foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $file) {
                if (is_file($directory . '/' . $file)) {
                    unlink($directory . '/' . $file);
                }
            }

            rmdir($directory);
        }

        $this->original_environment = [];
        $this->temporary_directories = [];

        parent::tearDown();
    }

    /**
     * @param  Application  $app
     *
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            AuditLogServiceProvider::class,
            GoogleApiServiceProvider::class,
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        // Required to encrypt the cached access token.
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app['config']->set('cache.default', 'array');
        $app['config']->set('logging.default', 'null');

        // The GOOGLE_API_* variables of the machine that runs the tests must never be used.
        $app['config']->set('google-api-client.key_path', null);
        $app['config']->set('google-api-client.key_string', null);
        $app['config']->set('google-api-client.subject_email', null);
        $app['config']->set('google-api-client.customer', 'my_customer');
        $app['config']->set('google-api-client.domain', 'example.com');
        $app['config']->set('google-api-client.exceptions', true);
    }

    /**
     * Set an environment variable for the current test, or unset it when the value is null.
     *
     * ApiToken reads `$_ENV`, then `$_SERVER`, then `getenv()`, and ignores an empty string, so an empty `$_ENV`
     * value hides a variable that is set in the environment of the machine that runs the tests.
     */
    protected function setEnvironmentVariable(string $key, ?string $value): void
    {
        if (! array_key_exists($key, $this->original_environment)) {
            $this->original_environment[$key] = [
                'env' => $_ENV[$key] ?? null,
                'server' => $_SERVER[$key] ?? null,
                'getenv' => getenv($key),
            ];
        }

        $_ENV[$key] = $value ?? '';
        $_SERVER[$key] = $value ?? '';
        putenv($key . '=' . ($value ?? ''));
    }

    /**
     * Create an empty directory that is removed after the test.
     */
    protected function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/google-api-client-test-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);

        $this->temporary_directories[] = $directory;

        return $directory;
    }

    /**
     * @param  array{env: mixed, server: mixed, getenv: string|false}  $original
     */
    private function restoreEnvironmentVariable(string $key, array $original): void
    {
        if ($original['env'] === null) {
            unset($_ENV[$key]);
        } else {
            $_ENV[$key] = $original['env'];
        }

        if ($original['server'] === null) {
            unset($_SERVER[$key]);
        } else {
            $_SERVER[$key] = $original['server'];
        }

        putenv($original['getenv'] === false ? $key : $key . '=' . $original['getenv']);
    }
}
