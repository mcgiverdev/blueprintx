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
            'model' => null,
        ];

        try {
            $creator->ensure($options);

            $runs = $history->listRuns();
            $authRun = $this->findAuthRun($runs, $executionId);

            $this->assertNotNull($authRun, 'Se esperaba un registro de historial para el scaffolding de auth.');

            $manifest = $authRun['manifest'];
            $this->assertSame($executionId, $manifest['execution_id']);

            $paths = array_column($manifest['entries'], 'path');

            $this->assertContains($controllersPath . '/Auth/AuthController.php', $paths);
            $this->assertContains($requestsPath . '/Auth/LoginRequest.php', $paths);
            $this->assertContains($requestsPath . '/Auth/RegisterRequest.php', $paths);
            $this->assertContains($resourcesPath . '/Crm/UserResource.php', $paths);
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
