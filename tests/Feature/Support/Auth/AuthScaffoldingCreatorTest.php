<?php

namespace BlueprintX\Tests\Feature\Support\Auth;

use BlueprintX\Kernel\History\GenerationHistoryManager;
use BlueprintX\Support\Auth\AuthScaffoldingCreator;
use BlueprintX\Tests\TestCase;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

class AuthScaffoldingCreatorTest extends TestCase
{
    public function test_it_records_written_files_in_history(): void
    {
        /** @var GenerationHistoryManager $history */
        $history = $this->app->make(GenerationHistoryManager::class);
        /** @var AuthScaffoldingCreator $creator */
        $creator = $this->app->make(AuthScaffoldingCreator::class);
        /** @var Filesystem $files */
        $files = $this->app->make(Filesystem::class);

        $historyRoot = $history->historyRoot();
        if (is_string($historyRoot) && $files->exists($historyRoot)) {
            $files->deleteDirectory($historyRoot);
        }

        $controllersPath = 'storage/framework/testing/auth/controllers';
        $requestsPath = 'storage/framework/testing/auth/requests';
        $resourcesPath = 'storage/framework/testing/auth/resources';

        $this->removePath($files, base_path($controllersPath));
        $this->removePath($files, base_path($requestsPath));
        $this->removePath($files, base_path($resourcesPath));

        $routesPath = base_path('routes/api.php');
        $originalRoutes = $files->exists($routesPath) ? $files->get($routesPath) : '';
        $files->put($routesPath, "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\n");

        $executionId = 'auth-test-' . Str::uuid();

        $options = [
            'architecture' => 'hexagonal',
            'controllers_path' => $controllersPath,
            'controllers_namespace' => 'App\\Http\\Controllers\\Api',
            'requests_path' => $requestsPath,
            'requests_namespace' => 'App\\Http\\Requests\\Api',
            'resources_path' => $resourcesPath,
            'resources_namespace' => 'App\\Http\\Resources',
            'force' => true,
            'dry_run' => false,
            'sanctum_installed' => true,
            'execution_id' => $executionId,
            'model' => [
                'module' => 'central/auth',
                'entity' => 'user',
                'tenancy' => ['mode' => 'central'],
            ],
        ];

        try {
            $creator->ensure($options);

            $runs = $history->listRuns();
            $authRun = $this->findAuthRun($runs, $executionId);

            $this->assertNotNull($authRun, 'Se esperaba un registro de historial para el scaffolding de auth.');

            $manifest = $authRun['manifest'];
            $this->assertSame($executionId, $manifest['execution_id']);

            $paths = array_column($manifest['entries'], 'path');

            $this->assertContains($controllersPath . '/Central/Auth/CentralAuthController.php', $paths);
            $this->assertContains($requestsPath . '/Central/Auth/CentralLoginRequest.php', $paths);
            $this->assertContains($requestsPath . '/Central/Auth/CentralRegisterRequest.php', $paths);
            $this->assertContains($resourcesPath . '/Central/Auth/CentralUserResource.php', $paths);
            $this->assertContains('routes/api.php', $paths);

            foreach ($manifest['entries'] as $entry) {
                $this->assertSame('auth_scaffolding', $entry['layer'] ?? null);
            }
        } finally {
            $files->put($routesPath, $originalRoutes);
            $this->removePath($files, base_path($controllersPath));
            $this->removePath($files, base_path($requestsPath));
            $this->removePath($files, base_path($resourcesPath));

            if (is_string($historyRoot) && $files->exists($historyRoot)) {
                $files->deleteDirectory($historyRoot);
            }
        }
    }

    public function test_it_restores_missing_auth_provider_section(): void
    {
        /** @var GenerationHistoryManager $history */
        $history = $this->app->make(GenerationHistoryManager::class);
        /** @var AuthScaffoldingCreator $creator */
        $creator = $this->app->make(AuthScaffoldingCreator::class);
        /** @var Filesystem $files */
        $files = $this->app->make(Filesystem::class);

        $historyRoot = $history->historyRoot();

        if (is_string($historyRoot) && $files->exists($historyRoot)) {
            $files->deleteDirectory($historyRoot);
        }

        $controllersPath = 'storage/framework/testing/auth/providers/controllers';
        $requestsPath = 'storage/framework/testing/auth/providers/requests';
        $resourcesPath = 'storage/framework/testing/auth/providers/resources';

        $this->removePath($files, base_path($controllersPath));
        $this->removePath($files, base_path($requestsPath));
        $this->removePath($files, base_path($resourcesPath));

        $configPath = base_path('config/auth.php');
        $routesPath = base_path('routes/api.php');
        $userModelPath = base_path('app/Models/User.php');

        $originalConfig = $files->exists($configPath) ? $files->get($configPath) : null;
        $originalRoutes = $files->exists($routesPath) ? $files->get($routesPath) : null;
        $originalUserModel = $files->exists($userModelPath) ? $files->get($userModelPath) : null;

        $brokenConfig = <<<'PHP'
<?php

return [

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
        'sanctum' => [
            'driver' => 'sanctum',
            'provider' => 'users',
        ],
        'api' => [
            'driver' => 'sanctum',
            'provider' => 'users',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | User Providers
    |--------------------------------------------------------------------------
    |
    | All authentication guards have a user provider, which defines how the
    | users are actually retrieved out of your database or other storage
    | system used by the application. Typically, Eloquent is utilized.
    |
    | If you have multiple user tables or models you may configure multiple
    | sources which represent each model / table. These sources may then be
    | assigned to any extra authentication guards you have defined.
    |
    */

    // Provider section intentionally removed for this regression test.

    /*
    |--------------------------------------------------------------------------
    | Resetting Passwords
    |--------------------------------------------------------------------------
    |
    | These configuration options determine the behavior of Laravel's
    | password reset feature.
    |
    */

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => 10800,

];
PHP;

        $files->put($configPath, $brokenConfig);
        $files->put($routesPath, "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\n");

        try {
            $creator->ensure([
                'architecture' => 'hexagonal',
                'controllers_path' => $controllersPath,
                'controllers_namespace' => 'App\\Http\\Controllers\\Api',
                'requests_path' => $requestsPath,
                'requests_namespace' => 'App\\Http\\Requests\\Api',
                'resources_path' => $resourcesPath,
                'resources_namespace' => 'App\\Http\\Resources',
                'force' => true,
                'dry_run' => false,
                'sanctum_installed' => true,
                'contexts' => [
                    'central' => [
                        'key' => 'central',
                        'model' => [
                            'module' => 'central/auth',
                            'entity' => 'user',
                            'tenancy' => ['mode' => 'central'],
                        ],
                    ],
                    'tenant' => [
                        'key' => 'tenant',
                        'model' => [
                            'module' => 'tenant/auth',
                            'entity' => 'user',
                            'tenancy' => ['mode' => 'tenant'],
                        ],
                    ],
                ],
            ]);

            $updated = $files->get($configPath);

            $this->assertStringContainsString("'providers' => [", $updated);
            $this->assertStringContainsString("'central_users' => [", $updated);
            $this->assertStringContainsString("'tenant_users' => [", $updated);
            $this->assertMatchesRegularExpression(
                "/'sanctum'\s*=>\s*\[\s*'driver'\s*=>\s*'sanctum',\s*'provider'\s*=>\s*'central_users'/s",
                $updated
            );
        } finally {
            if ($originalConfig !== null) {
                $files->put($configPath, $originalConfig);
            } else {
                $files->delete($configPath);
            }

            if ($originalRoutes !== null) {
                $files->put($routesPath, $originalRoutes);
            } elseif ($files->exists($routesPath)) {
                $files->delete($routesPath);
            }

            if ($originalUserModel !== null) {
                $files->put($userModelPath, $originalUserModel);
            } elseif ($files->exists($userModelPath)) {
                $files->delete($userModelPath);
            }

            $this->removePath($files, base_path($controllersPath));
            $this->removePath($files, base_path($requestsPath));
            $this->removePath($files, base_path($resourcesPath));

            if (is_string($historyRoot) && $files->exists($historyRoot)) {
                $files->deleteDirectory($historyRoot);
            }
        }
    }

    /**
     * @param array<int, array{id:string,path:string,manifest:array<string,mixed>}> $runs
     * @return array{id:string,path:string,manifest:array<string,mixed>}|null
     */
    private function findAuthRun(array $runs, string $executionId): ?array
    {
        foreach ($runs as $run) {
            $manifest = $run['manifest'] ?? [];
            $blueprint = $manifest['blueprint'] ?? [];

            if (($blueprint['entity'] ?? null) === 'auth_scaffolding' && ($manifest['execution_id'] ?? null) === $executionId) {
                return $run;
            }
        }

        return null;
    }

    private function removePath(Filesystem $files, string $path): void
    {
        if ($files->exists($path)) {
            $files->deleteDirectory($path);
        }
    }
}
