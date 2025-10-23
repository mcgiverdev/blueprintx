<?php

namespace BlueprintX;

use BlueprintX\Console\Commands\GenerateBlueprintsCommand;
use BlueprintX\Console\Commands\ListBlueprintsCommand;
use BlueprintX\Console\Commands\PublishTenancyAssetsCommand;
use BlueprintX\Console\Commands\RollbackBlueprintsCommand;
use BlueprintX\Console\Commands\ValidateBlueprintsCommand;
use BlueprintX\Contracts\BlueprintParser;
use BlueprintX\Contracts\BlueprintValidator as BlueprintValidatorContract;
use BlueprintX\Contracts\LayerGenerator;
use BlueprintX\Docs\OpenApiDocumentBuilder;
use BlueprintX\Generators\ApiLayerGenerator;
use BlueprintX\Generators\DocsLayerGenerator;
use BlueprintX\Generators\TestsLayerGenerator;
use BlueprintX\Kernel\DriverManager;
use BlueprintX\Kernel\History\GenerationHistoryManager;
use BlueprintX\Kernel\BlueprintLocator;
use BlueprintX\Generators\PostmanLayerGenerator;
use BlueprintX\Kernel\GenerationPipeline;
use BlueprintX\Kernel\OutputWriter;
use BlueprintX\Kernel\TemplateEngine;
use BlueprintX\Parsers\YamlBlueprintParser;
use BlueprintX\Validation\BlueprintSchemaValidator;
use BlueprintX\Validation\DefaultBlueprintValidator;
use BlueprintX\Validation\SemanticBlueprintValidator;
use Illuminate\Support\ServiceProvider;
use Throwable;

class BlueprintXServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../resources/config/blueprintx.php', 'blueprintx');

        $this->app->singleton(
            BlueprintParser::class,
            function ($app) {
                $config = $app['config']->get('blueprintx');

                return new YamlBlueprintParser(
                    $config['paths']['blueprints'] ?? base_path('blueprints'),
                    $config['default_architecture'] ?? 'hexagonal',
                );
            }
        );

        $this->app->singleton(
            TemplateEngine::class,
            function ($app) {
                $config = $app['config']->get('blueprintx');
                $packageTemplates = __DIR__ . '/../resources/templates';
                $publishedTemplates = $config['paths']['templates'] ?? null;

                $paths = array_values(array_filter([
                    $publishedTemplates,
                    $packageTemplates,
                ], static fn ($path): bool => is_string($path) && is_dir($path)));

                return new TemplateEngine(
                    $paths,
                    [
                        'cache' => $config['twig']['cache'] ?? false,
                        'debug' => $config['twig']['debug'] ?? false,
                        'auto_reload' => $config['twig']['auto_reload'] ?? true,
                    ]
                );
            }
        );

        $this->app->singleton(
            OpenApiDocumentBuilder::class,
            function ($app) {
                return new OpenApiDocumentBuilder(
                    $app->make(BlueprintParser::class),
                    $app->make(BlueprintLocator::class)
                );
            }
        );

        $this->app->singleton(
            ApiLayerGenerator::class,
            function ($app) {
                $feature = $app['config']->get('blueprintx.features.api', []);

                $formRequests = $feature['form_requests'] ?? [];
                $resources = $feature['resources'] ?? [];

                $enabled = array_key_exists('enabled', $formRequests) ? (bool) $formRequests['enabled'] : true;
                $namespace = $formRequests['namespace'] ?? 'App\\Http\\Requests\\Api';
                $path = $formRequests['path'] ?? 'app/Http/Requests/Api';
                $authorizeByDefault = array_key_exists('authorize_by_default', $formRequests)
                    ? (bool) $formRequests['authorize_by_default']
                    : true;

                if (! is_string($namespace) || $namespace === '') {
                    $namespace = 'App\\Http\\Requests\\Api';
                }

                if (! is_string($path) || $path === '') {
                    $path = 'app/Http/Requests/Api';
                }

                $namespace = trim($namespace, '\\');
                $path = trim($path, '\\/');

                $resourcesEnabled = array_key_exists('enabled', $resources)
                    ? (bool) $resources['enabled']
                    : true;
                $resourcesNamespace = $resources['namespace'] ?? 'App\\Http\\Resources';
                $resourcesPath = $resources['path'] ?? 'app/Http/Resources';
                $preserveQuery = array_key_exists('preserve_query', $resources)
                    ? (bool) $resources['preserve_query']
                    : true;

                if (! is_string($resourcesNamespace) || $resourcesNamespace === '') {
                    $resourcesNamespace = 'App\\Http\\Resources';
                }

                if (! is_string($resourcesPath) || $resourcesPath === '') {
                    $resourcesPath = 'app/Http/Resources';
                }

                $resourcesNamespace = trim($resourcesNamespace, '\\');
                $resourcesPath = trim($resourcesPath, '\\/');

                $controllerTraits = $feature['controller_traits'] ?? [];

                if (! is_array($controllerTraits)) {
                    $controllerTraits = [];
                }

                $optimisticLocking = $feature['optimistic_locking'] ?? [];

                if (! is_array($optimisticLocking)) {
                    $optimisticLocking = [];
                }

                return new ApiLayerGenerator(
                    $app->make(TemplateEngine::class),
                    [
                        'enabled' => $enabled,
                        'namespace' => $namespace,
                        'path' => $path,
                        'authorize_by_default' => $authorizeByDefault,
                    ],
                    [
                        'enabled' => $resourcesEnabled,
                        'namespace' => $resourcesNamespace,
                        'path' => $resourcesPath,
                        'preserve_query' => $preserveQuery,
                    ],
                    $controllerTraits,
                    $optimisticLocking
                );
            }
        );

        $this->app->singleton(
            TestsLayerGenerator::class,
            function ($app) {
                $feature = $app['config']->get('blueprintx.features.api', []);

                $resources = $feature['resources'] ?? [];
                if (! is_array($resources)) {
                    $resources = [];
                }

                $optimisticLocking = $feature['optimistic_locking'] ?? [];
                if (! is_array($optimisticLocking)) {
                    $optimisticLocking = [];
                }

                return new TestsLayerGenerator(
                    $app->make(TemplateEngine::class),
                    $resources,
                    $optimisticLocking
                );
            }
        );

        $this->app->singleton(
            DocsLayerGenerator::class,
            function ($app) {
                $feature = $app['config']->get('blueprintx.features.openapi', []);

                $enabled = (bool) ($feature['enabled'] ?? false);
                $validate = array_key_exists('validate', $feature) ? (bool) $feature['validate'] : true;
                $validationMode = $feature['validation_mode'] ?? 'official';
                $schemaPath = $feature['schema_path'] ?? null;

                if (! is_string($schemaPath) || $schemaPath === '') {
                    $schemaPath = null;
                }

                if (! is_string($validationMode) || $validationMode === '') {
                    $validationMode = 'official';
                }

                $validationMode = strtolower($validationMode);
                if (! in_array($validationMode, ['official', 'schema'], true)) {
                    $validationMode = 'official';
                }

                return new DocsLayerGenerator(
                    $app->make(OpenApiDocumentBuilder::class),
                    $enabled,
                    $validate,
                    $schemaPath,
                    $validationMode
                );
            }
        );

        $this->app->singleton(
            PostmanLayerGenerator::class,
            function ($app) {
                $feature = $app['config']->get('blueprintx.features.postman', []);

                $enabled = (bool) ($feature['enabled'] ?? false);

                $defaultBaseUrl = config('app.url') ?: 'http://localhost';
                $baseUrl = $feature['base_url'] ?? $defaultBaseUrl;
                if (! is_string($baseUrl) || $baseUrl === '') {
                    $baseUrl = $defaultBaseUrl;
                }

                $apiPrefix = $feature['api_prefix'] ?? '/api';
                if (! is_string($apiPrefix) || $apiPrefix === '') {
                    $apiPrefix = '/api';
                }

                $collectionName = $feature['collection_name'] ?? 'Generated API';
                if (! is_string($collectionName) || $collectionName === '') {
                    $collectionName = 'Generated API';
                }

                $version = $feature['version'] ?? 'v1';
                if (! is_string($version) || $version === '') {
                    $version = 'v1';
                }

                return new PostmanLayerGenerator(
                    $app->make(OpenApiDocumentBuilder::class),
                    $enabled,
                    $baseUrl,
                    $apiPrefix,
                    $collectionName,
                    $version
                );
            }
        );

        $this->app->singleton(
            DriverManager::class,
            function ($app) {
                $config = $app['config']->get('blueprintx');
                $packageTemplates = __DIR__ . '/../resources/templates';
                $publishedTemplates = $config['paths']['templates'] ?? null;

                return new DriverManager(
                    $app->make(TemplateEngine::class),
                    $app,
                    $config['architectures'] ?? [],
                    [
                        'default_architecture' => $config['default_architecture'] ?? 'hexagonal',
                        'package_templates_path' => $packageTemplates,
                        'override_templates_path' => $publishedTemplates,
                    ]
                );
            }
        );

        $this->app->singleton(
            OutputWriter::class,
            function ($app) {
                $config = $app['config']->get('blueprintx');
                $outputPath = $config['paths']['output'] ?? base_path();

                return new OutputWriter($outputPath);
            }
        );

        $this->app->singleton(
            GenerationHistoryManager::class,
            function ($app) {
                $historyConfig = $app['config']->get('blueprintx.history', []);
                $enabled = array_key_exists('enabled', $historyConfig) ? (bool) $historyConfig['enabled'] : true;
                $path = $historyConfig['path'] ?? null;

                if (! is_string($path) || $path === '') {
                    if (is_object($app) && method_exists($app, 'storagePath')) {
                        $path = $app->storagePath('app/blueprintx/history');
                    } elseif (function_exists('storage_path')) {
                        $path = storage_path('app/blueprintx/history');
                    } else {
                        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'blueprintx-history';
                    }
                }

                return new GenerationHistoryManager($path, $enabled);
            }
        );

        $this->app->singleton(
            GenerationPipeline::class,
            function ($app) {
                $config = $app['config']->get('blueprintx');

                $pipeline = new GenerationPipeline(
                    $app->make(DriverManager::class),
                    $app->make(OutputWriter::class)
                );

                foreach ($config['generators'] ?? [] as $generatorClass) {
                    if (! is_string($generatorClass)) {
                        continue;
                    }

                    try {
                        $generator = $app->make($generatorClass);
                    } catch (Throwable) {
                        continue;
                    }

                    if ($generator instanceof LayerGenerator) {
                        $pipeline->registerGenerator($generator);
                    }
                }

                return $pipeline;
            }
        );

        $this->app->singleton(
            BlueprintValidatorContract::class,
            function ($app) {
                $config = $app['config']->get('blueprintx');
                $architectures = array_keys($config['architectures'] ?? []);

                $schemaValidator = new BlueprintSchemaValidator(
                    __DIR__ . '/../resources/schema/blueprint.schema.json',
                    $architectures,
                );

                $semanticValidator = new SemanticBlueprintValidator();

                return new DefaultBlueprintValidator($schemaValidator, $semanticValidator);
            }
        );
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../resources/config/blueprintx.php' => config_path('blueprintx.php'),
            ], 'blueprintx-config');

            $this->publishes([
                __DIR__ . '/../resources/templates' => resource_path('vendor/blueprintx/templates'),
            ], 'blueprintx-templates');
        }

        $this->commands([
            GenerateBlueprintsCommand::class,
            ValidateBlueprintsCommand::class,
            ListBlueprintsCommand::class,
            PublishTenancyAssetsCommand::class,
            RollbackBlueprintsCommand::class,
        ]);
    }
}
