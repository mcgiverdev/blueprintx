<?php

namespace BlueprintX\Tests;

use BlueprintX\BlueprintXServiceProvider;
use BlueprintX\Tests\Concerns\InteractsWithBlueprintPaths;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    use InteractsWithBlueprintPaths;

    protected function getPackageProviders($app)
    {
        return [BlueprintXServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $this->initializeBlueprintWorkspace();

        $baseConfig = require __DIR__ . '/../resources/config/blueprintx.php';

        $baseConfig['paths']['blueprints'] = $this->blueprintsPath();
        $baseConfig['paths']['templates'] = __DIR__ . '/../resources/templates';
        $baseConfig['paths']['output'] = $this->outputPath();
        $baseConfig['twig']['cache'] = false;
        $baseConfig['twig']['debug'] = true;
        $baseConfig['twig']['auto_reload'] = true;

        $historyPath = $this->outputPath('history');
        if (! is_dir($historyPath)) {
            mkdir($historyPath, 0777, true);
        }

        $baseConfig['history']['enabled'] = true;
        $baseConfig['history']['path'] = $historyPath;

        $baseConfig['features']['tenancy'] = $baseConfig['features']['tenancy'] ?? [];
        $baseConfig['features']['tenancy']['enabled'] = true;
        $baseConfig['features']['tenancy']['driver'] = 'stancl';
        $baseConfig['features']['tenancy']['auto_detect'] = true;
        $baseConfig['features']['tenancy']['middleware_alias'] = $baseConfig['features']['tenancy']['middleware_alias'] ?? 'tenant';
        $baseConfig['features']['tenancy']['scaffold'] = $baseConfig['features']['tenancy']['scaffold'] ?? [];
        $baseConfig['features']['tenancy']['scaffold']['enabled'] = $baseConfig['features']['tenancy']['scaffold']['enabled'] ?? true;
        $baseConfig['features']['tenancy']['scaffold']['blueprint_path'] = $baseConfig['features']['tenancy']['scaffold']['blueprint_path'] ?? 'central/tenancy/tenants.yaml';

        $app['config']->set('blueprintx', $baseConfig);
        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));
        $app['config']->set('app.debug', true);
    }

    protected function tearDown(): void
    {
        $this->cleanupTemporaryDirectories();

        parent::tearDown();
    }
}
