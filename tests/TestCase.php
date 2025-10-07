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
