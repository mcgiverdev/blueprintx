<?php

namespace BlueprintX\Console\Commands;

use BlueprintX\Blueprint\Blueprint;
use BlueprintX\Blueprint\Field;
use BlueprintX\Contracts\BlueprintParser;
use BlueprintX\Contracts\BlueprintValidator;
use BlueprintX\Exceptions\BlueprintParseException;
use BlueprintX\Exceptions\BlueprintValidationException;
use BlueprintX\Kernel\BlueprintLocator;
use BlueprintX\Kernel\History\GenerationHistoryManager;
use BlueprintX\Kernel\Generation\PipelineResult;
use BlueprintX\Kernel\GenerationPipeline;
use BlueprintX\Support\Auth\AuthScaffoldingCreator;
use BlueprintX\Support\Tenancy\TenancyScaffoldingCreator;
use BlueprintX\Validation\ValidationMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;
use Symfony\Component\Process\Process;

class GenerateBlueprintsCommand extends Command
{
    protected $signature = <<<SIGNATURE
    blueprintx:generate
        {module? : Modulo del blueprint (ej. hr)}
        {entity? : Entidad del blueprint (ej. employee)}
        {--module= : Filtra por modulo (alternativa al argumento)}
        {--entity= : Filtra por entidad (alternativa al argumento)}
        {--architecture= : Sobrescribe la arquitectura declarada en el blueprint}
        {--only= : Lista de capas a generar separadas por coma}
        {--dry-run : Previsualiza cambios sin escribir archivos}
        {--force : Sobrescribe archivos existentes sin preguntar}
        {--force-auth : Fuerza la regeneracion del scaffolding de autenticacion}
        {--with-openapi : Fuerza la generacion del documento OpenAPI}
        {--without-openapi : Omite la generacion del documento OpenAPI}
        {--validate-openapi : Fuerza la validacion del documento OpenAPI}
        {--skip-openapi-validation : Omite la validacion del documento OpenAPI}
        {--with-postman : Fuerza la generación de la coleccion de Postman}
        {--without-postman : Omite la generación de la coleccion de Postman}
SIGNATURE;

    protected $description = 'Genera artefactos a partir de blueprints YAML usando el pipeline configurado.';

    public function __construct(
        private readonly BlueprintParser $parser,
        private readonly BlueprintValidator $validator,
        private readonly GenerationPipeline $pipeline,
        private readonly BlueprintLocator $locator,
        private readonly AuthScaffoldingCreator $authScaffolding,
        private readonly TenancyScaffoldingCreator $tenancyScaffolding,
        private readonly GenerationHistoryManager $history,
    ) {
        parent::__construct();
    }

    /**
     * @var array<string, mixed>|null
     */
    private ?array $composerConfig = null;

    /**
     * @var array<string, bool>
     */
    private array $tenancyDriverConfigWarnings = [];

    /**
     * @var array<string, array{label:string,package:?string,commands:array<int,string>,guide:?string,blueprints:array<string,array{path:string,mode:string}>}>
     */
    private array $tenancyDriverInstallQueue = [];

    private bool $tenancyFeatureDisabledWarning = false;

    public function handle(): int
    {
        $module = $this->normalizeNullableString($this->option('module') ?: $this->argument('module'));
        $entity = $this->normalizeNullableString($this->option('entity') ?: $this->argument('entity'));
        $architectureOverride = $this->normalizeNullableString($this->option('architecture'));
        $only = $this->normalizeNullableString($this->option('only'));
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $forceAuth = $force || (bool) $this->option('force-auth');

        $config = $this->laravel['config']->get('blueprintx', []);
        $featureConfig = $config['features']['openapi'] ?? [];
        $defaultOpenApiEnabled = (bool) ($featureConfig['enabled'] ?? false);
        $defaultOpenApiValidation = array_key_exists('validate', $featureConfig) ? (bool) $featureConfig['validate'] : true;

        $postmanFeature = $config['features']['postman'] ?? [];
        $defaultPostmanEnabled = (bool) ($postmanFeature['enabled'] ?? false);
        $defaultPostmanBaseUrl = $this->normalizeUrl($postmanFeature['base_url'] ?? null, 'http://localhost');
        $defaultPostmanApiPrefix = $this->normalizeApiPrefixOption($postmanFeature['api_prefix'] ?? null, '/api');
        $postmanTenancyFeature = isset($postmanFeature['tenancy']) && is_array($postmanFeature['tenancy'])
            ? $postmanFeature['tenancy']
            : [];

        $pathsConfig = $config['paths'] ?? [];
        $defaultArchitecture = $this->normalizeArchitecture($config['default_architecture'] ?? null, 'hexagonal');
        $formRequestFeature = $config['features']['api']['form_requests'] ?? [];
        $resourcesFeature = $config['features']['api']['resources'] ?? [];

        $apiControllersPath = $this->normalizeRelativePath($pathsConfig['api'] ?? null, 'app/Http/Controllers/Api');
        $defaultRequestsPath = $this->normalizeRelativePath($pathsConfig['api_requests'] ?? null, 'app/Http/Requests/Api');
        $defaultResourcesPath = $this->normalizeRelativePath($pathsConfig['api_resources'] ?? null, 'app/Http/Resources');
        $formRequestsPath = $this->normalizeRelativePath($formRequestFeature['path'] ?? null, $defaultRequestsPath);
        $resourcesPath = $this->normalizeRelativePath($resourcesFeature['path'] ?? null, $defaultResourcesPath);
        $postmanPath = $this->normalizeRelativePath($pathsConfig['postman'] ?? null, 'docs/postman');
        $tenancyRuntime = $this->resolveTenancyRuntime($config['features']['tenancy'] ?? []);
        $tenancyEnabled = $tenancyRuntime['enabled'];
        $tenancyConfiguredDriver = $tenancyRuntime['configured_driver'];
        $tenancyDriver = $tenancyRuntime['driver'];
        $tenancyDriverInstalled = $tenancyRuntime['driver_installed'];
        $tenancyDriverPackage = $tenancyRuntime['driver_package'];
        $tenancyInstallCommands = $tenancyRuntime['install_commands'];
        $tenancyGuideUrl = $tenancyRuntime['guide_url'];
        $tenancyMiddlewareAlias = $tenancyRuntime['middleware_alias'];
        $tenancyScaffoldEnabled = $tenancyRuntime['scaffold_enabled'];
        $tenancyBlueprintRelative = $tenancyRuntime['scaffold_blueprint'];
        $tenancyAutoDetect = $tenancyRuntime['auto_detect'];
        $tenancyTenantHeader = $tenancyRuntime['tenant_header'];
        $tenancyCentralBaseUrl = $tenancyRuntime['central_base_url'];
        $tenancyTenantBaseUrl = $tenancyRuntime['tenant_base_url'];
        $tenancyDrivers = $tenancyRuntime['drivers'];
        $tenancyDetectedDriver = $tenancyRuntime['detected_driver'];
        $tenancyDriverLabel = $tenancyRuntime['driver_label']
            ?? ($tenancyDrivers[$tenancyDriver]['label'] ?? ucfirst($tenancyDriver));
        $tenancyDetectedDriverLabel = $tenancyDetectedDriver !== null
            ? ($tenancyDrivers[$tenancyDetectedDriver]['label'] ?? ucfirst($tenancyDetectedDriver))
            : null;

        $formRequestsEnabled = array_key_exists('enabled', $formRequestFeature)
            ? (bool) $formRequestFeature['enabled']
            : true;

        $requestsNamespace = $this->normalizeNamespace($formRequestFeature['namespace'] ?? 'App\\Http\\Requests\\Api', 'App\\Http\\Requests\\Api');
        $resourcesNamespace = $this->normalizeNamespace($resourcesFeature['namespace'] ?? 'App\\Http\\Resources', 'App\\Http\\Resources');
        $controllersNamespace = $this->normalizeNamespace($pathsConfig['api_namespace'] ?? $apiControllersPath, 'App\\Http\\Controllers\\Api');

        $authorizeByDefault = array_key_exists('authorize_by_default', $formRequestFeature)
            ? (bool) $formRequestFeature['authorize_by_default']
            : true;

        $blueprintsPath = $config['paths']['blueprints'] ?? null;
        $authModelEntity = $this->resolveAuthModelEntity();

        if (! is_string($blueprintsPath) || $blueprintsPath === '') {
            $this->error('No se encontro la configuracion "blueprintx.paths.blueprints".');

            return self::FAILURE;
        }

        if (! is_dir($blueprintsPath)) {
            $this->error(sprintf('El directorio de blueprints no existe: %s', $blueprintsPath));

            return self::FAILURE;
        }

        $withOpenApiOption = (bool) $this->option('with-openapi');
        $withoutOpenApiOption = (bool) $this->option('without-openapi');
        $validateOpenApiOption = (bool) $this->option('validate-openapi');
        $skipOpenApiValidation = (bool) $this->option('skip-openapi-validation');
        $withPostmanOption = (bool) $this->option('with-postman');
        $withoutPostmanOption = (bool) $this->option('without-postman');

        if ($withOpenApiOption && $withoutOpenApiOption) {
            $this->error('No se puede usar "--with-openapi" junto con "--without-openapi".');

            return self::FAILURE;
        }

        if ($validateOpenApiOption && $skipOpenApiValidation) {
            $this->error('No se puede usar "--validate-openapi" junto con "--skip-openapi-validation".');

            return self::FAILURE;
        }

        if ($withPostmanOption && $withoutPostmanOption) {
            $this->error('No se puede usar "--with-postman" junto con "--without-postman".');

            return self::FAILURE;
        }

        $withOpenApi = $withOpenApiOption ? true : ($withoutOpenApiOption ? false : $defaultOpenApiEnabled);
        $validateOpenApi = $skipOpenApiValidation ? false : ($validateOpenApiOption ? true : $defaultOpenApiValidation);
        $withPostman = $withPostmanOption ? true : ($withoutPostmanOption ? false : $defaultPostmanEnabled);

        $postmanCentralBaseUrl = '';
        if (isset($postmanTenancyFeature['central_base_url']) && is_string($postmanTenancyFeature['central_base_url'])) {
            $postmanCentralBaseUrl = rtrim(trim($postmanTenancyFeature['central_base_url']), '/');
        }
        if ($postmanCentralBaseUrl === '') {
            $postmanCentralBaseUrl = $tenancyCentralBaseUrl;
        }

        $postmanTenantBaseUrl = '';
        if (isset($postmanTenancyFeature['tenant_base_url']) && is_string($postmanTenancyFeature['tenant_base_url'])) {
            $postmanTenantBaseUrl = rtrim(trim($postmanTenancyFeature['tenant_base_url']), '/');
        }
        if ($postmanTenantBaseUrl === '') {
            $postmanTenantBaseUrl = $tenancyTenantBaseUrl;
        }

        $blueprintPaths = $this->locator->discover($blueprintsPath, $module, $entity);

        if ($blueprintPaths === []) {
            $message = $module || $entity
                ? 'No se encontraron blueprints con los filtros proporcionados.'
                : 'No se encontraron blueprints para generar.';

            $this->warn($message);

            return self::SUCCESS;
        }

        $this->info(sprintf('Se encontraron %d blueprint(s) para generar.', count($blueprintPaths)));
        $this->newLine();

        $hasErrors = false;
        $summary = [
            'written' => 0,
            'overwritten' => 0,
            'skipped' => 0,
            'preview' => 0,
            'errors' => 0,
            'warnings' => 0,
        ];
        $lastArchitecture = null;
        $executionId = (string) Str::uuid();

    $queue = $this->prepareBlueprintQueue($blueprintPaths);
    $authModelBlueprint = $this->findAuthModelBlueprint($queue, $authModelEntity);
    $authModelFields = $this->serializeAuthModelFields($authModelBlueprint);
    $sanctumInstalled = $this->isSanctumInstalled();

        foreach ($queue as $entry) {
            $path = $entry['path'];
            $relative = $this->locator->relativePath($blueprintsPath, $path);
            $this->line(sprintf('<comment>Blueprint:</comment> %s', $relative));

            $parseException = $entry['parse_exception'];
            if ($parseException instanceof BlueprintParseException) {
                $this->error(sprintf('  Error al parsear "%s": %s', $relative, $parseException->getMessage()));
                $hasErrors = true;

                continue;
            }

            $unexpectedParseException = $entry['unexpected_exception'];
            if ($unexpectedParseException instanceof Throwable) {
                $this->error(sprintf('  Error inesperado al parsear "%s": %s', $relative, $unexpectedParseException->getMessage()));
                $hasErrors = true;

                continue;
            }

            /** @var Blueprint $blueprint */
            $blueprint = $entry['blueprint'];

            if ($architectureOverride !== null) {
                $blueprint = $this->overrideArchitecture($blueprint, $architectureOverride);
                $this->line(sprintf('  > Arquitectura forzada a "%s".', $architectureOverride));
            }

            $lastArchitecture = strtolower($blueprint->architecture());

            try {
                $validation = $this->validator->validate($blueprint);
            } catch (BlueprintValidationException $exception) {
                $this->error(sprintf('  Fallo la validacion del blueprint "%s": %s', $relative, $exception->getMessage()));
                $hasErrors = true;

                continue;
            } catch (Throwable $exception) {
                $this->error(sprintf('  Error inesperado al validar "%s": %s', $relative, $exception->getMessage()));
                $hasErrors = true;

                continue;
            }

            if (! $validation->isValid()) {
                $this->error('  La validacion produjo errores:');
                $this->renderValidationMessages($validation->errors(), 'error');
                $hasErrors = true;

                continue;
            }

            if ($validation->warnings() !== []) {
                $this->warn('  La validacion devolvio warnings:');
                $this->renderValidationMessages($validation->warnings(), 'warning');
            }

            $tenancyMode = $this->resolveBlueprintTenancyMode($blueprint);
            $blueprintRequiresTenancyDriver = $this->blueprintRequiresTenancyDriver($tenancyMode);

            if ($blueprintRequiresTenancyDriver) {
                if (! $tenancyEnabled) {
                    $this->renderTenancyFeatureDisabledWarning($relative, $tenancyMode);
                } elseif ($tenancyDriver === 'none') {
                    $this->renderTenancyDriverNotConfiguredWarning(
                        $relative,
                        $tenancyMode,
                        $tenancyConfiguredDriver,
                        $tenancyAutoDetect,
                        $tenancyDrivers
                    );
                } elseif (! $tenancyDriverInstalled) {
                    $this->renderTenancyDriverInstallReminder(
                        $relative,
                        $tenancyMode,
                        $tenancyDriver,
                        $tenancyDriverLabel,
                        $tenancyDriverPackage,
                        $tenancyInstallCommands,
                        $tenancyGuideUrl
                    );
                }
            }

            $pipelineOptions = [
                'dry_run' => $dryRun,
                'force' => $force,
                'with_openapi' => $withOpenApi,
                'validate_openapi' => $validateOpenApi,
                'with_postman' => $withPostman,
                'paths' => [
                    'api' => $apiControllersPath,
                    'api_requests' => $formRequestsPath,
                    'api_resources' => $resourcesPath,
                    'postman' => $postmanPath,
                ],
                'namespaces' => [
                    'api_requests' => $requestsNamespace,
                    'api_resources' => $resourcesNamespace,
                ],
                'postman' => [
                    'base_url' => $defaultPostmanBaseUrl,
                    'api_prefix' => $defaultPostmanApiPrefix,
                    'tenancy' => [
                        'central_base_url' => $postmanCentralBaseUrl,
                        'tenant_base_url' => $postmanTenantBaseUrl,
                        'tenant_header' => $tenancyTenantHeader,
                    ],
                ],
                'form_requests' => [
                    'enabled' => $formRequestsEnabled,
                    'namespace' => $requestsNamespace,
                    'path' => $formRequestsPath,
                    'authorize_by_default' => $authorizeByDefault,
                ],
                'auth_model_fields' => $authModelFields,
                'tenancy' => [
                    'enabled' => $tenancyEnabled,
                    'configured_driver' => $tenancyConfiguredDriver,
                    'driver' => $tenancyDriver,
                    'driver_label' => $tenancyDriverLabel,
                    'driver_installed' => $tenancyDriverInstalled,
                    'driver_package' => $tenancyDriverPackage,
                    'middleware_alias' => $tenancyMiddlewareAlias,
                    'auto_detect' => $tenancyAutoDetect,
                    'scaffold_enabled' => $tenancyScaffoldEnabled,
                    'blueprint_path' => $tenancyBlueprintRelative,
                    'install_commands' => $tenancyInstallCommands,
                    'guide_url' => $tenancyGuideUrl,
                    'drivers' => $tenancyDrivers,
                    'detected_driver' => $tenancyDetectedDriver,
                    'detected_driver_label' => $tenancyDetectedDriverLabel,
                    'blueprint_mode' => $tenancyMode,
                    'requires_driver' => $blueprintRequiresTenancyDriver,
                    'tenant_header' => $tenancyTenantHeader,
                    'central_base_url' => $tenancyCentralBaseUrl,
                    'tenant_base_url' => $tenancyTenantBaseUrl,
                ],
            ];

            if ($only !== null) {
                $pipelineOptions['only'] = $only;
            }

            try {
                $result = $this->pipeline->generate($blueprint, $pipelineOptions);
            } catch (Throwable $exception) {
                $this->error(sprintf('  Error inesperado al generar "%s": %s', $relative, $exception->getMessage()));
                $hasErrors = true;

                continue;
            }

            if (! $dryRun && $this->hasTrackableChanges($result)) {
                $historyContext = [
                    'options' => [
                        'force' => $force,
                        'only' => $only,
                        'architecture_override' => $architectureOverride,
                        'with_openapi' => $withOpenApi,
                        'validate_openapi' => $validateOpenApi,
                        'with_postman' => $withPostman,
                    ],
                    'filters' => [
                        'module' => $module,
                        'entity' => $entity,
                    ],
                    'warnings' => $result->warnings(),
                    'execution_id' => $executionId,
                ];

                if ($this->history->record($blueprint, $relative, $result, $historyContext) === null) {
                    $this->warn('  [historial] No se pudo registrar el historial de generación.');
                }
            }

            $hasErrors = $this->renderPipelineResult($result, $summary) || $hasErrors;

            $this->newLine();
        }

        if (! $dryRun && ! $hasErrors) {
            $tenancyScaffoldResult = $this->tenancyScaffolding->ensure([
                'enabled' => $tenancyScaffoldEnabled,
                'blueprints_path' => $blueprintsPath,
                'relative_path' => $tenancyBlueprintRelative,
                'middleware_alias' => $tenancyMiddlewareAlias,
                'driver_label' => $tenancyDriverLabel,
                'dry_run' => $dryRun,
                'force' => $force,
            ]);

            if (is_array($tenancyScaffoldResult)) {
                $status = (string) ($tenancyScaffoldResult['status'] ?? '');

                if (in_array($status, ['written', 'overwritten'], true)) {
                    $this->line(sprintf(
                        '  [tenancy] Blueprint base %s en "%s".',
                        $status === 'overwritten' ? 'actualizado' : 'generado',
                        $tenancyScaffoldResult['path'] ?? $tenancyBlueprintRelative
                    ));
                } elseif ($status === 'error') {
                    $this->warn(sprintf(
                        '  [tenancy] No se pudo scaffoldear el blueprint base: %s',
                        (string) ($tenancyScaffoldResult['message'] ?? 'error desconocido')
                    ));
                }
            }

            $authModelData = $authModelBlueprint instanceof Blueprint ? $authModelBlueprint->toArray() : null;
            $scaffoldingArchitecture = $architectureOverride !== null
                ? $this->normalizeArchitecture($architectureOverride, $defaultArchitecture)
                : ($lastArchitecture ?? $defaultArchitecture);

            $authOptions = [
                'architecture' => $scaffoldingArchitecture,
                'controllers_path' => $apiControllersPath,
                'controllers_namespace' => $controllersNamespace,
                'requests_path' => $formRequestsPath,
                'requests_namespace' => $requestsNamespace,
                'resources_path' => $resourcesPath,
                'resources_namespace' => $resourcesNamespace,
                'force' => $forceAuth,
                'dry_run' => $dryRun,
                'sanctum_installed' => $sanctumInstalled,
                'execution_id' => $executionId,
            ];

            if ($authModelData !== null) {
                $authOptions['model'] = $authModelData;
            }

            $this->authScaffolding->ensure($authOptions);

            if ($this->authScaffoldingRequiresSanctum($authModelBlueprint) && ! $sanctumInstalled) {
                $this->renderSanctumReminder();
            }
        }

        $totalProcessed = $summary['written'] + $summary['overwritten'] + $summary['skipped'] + $summary['preview'];
        $parts = [];

        if ($summary['written'] > 0) {
            $parts[] = sprintf('nuevos: %d', $summary['written']);
        }
        if ($summary['overwritten'] > 0) {
            $parts[] = sprintf('sobrescritos: %d', $summary['overwritten']);
        }
        if ($summary['skipped'] > 0) {
            $parts[] = sprintf('omitidos: %d', $summary['skipped']);
        }
        if ($summary['preview'] > 0) {
            $parts[] = sprintf('previews: %d', $summary['preview']);
        }

        $parts[] = sprintf('warnings: %d', $summary['warnings']);
        $parts[] = sprintf('errores: %d', $summary['errors']);

        $this->info(sprintf(
            'Resumen: %d archivo(s) procesados - %s',
            $totalProcessed,
            implode(' - ', $parts),
        ));

        $this->renderTenancyInstallationSuggestions($dryRun);

        if ($hasErrors || $summary['errors'] > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function renderSanctumReminder(): void
    {
        $this->warn('Laravel Sanctum no está instalado y es necesario para el scaffolding de autenticación.');
        $this->line('  composer require laravel/sanctum');
        $this->line('  php artisan vendor:publish --provider="Laravel\\Sanctum\\SanctumServiceProvider" --tag=migrations');
        $this->line('  php artisan migrate');
        $this->line('  # Opcional: publicar configuración con --tag=config');
    }

    private function authScaffoldingRequiresSanctum(?Blueprint $blueprint): bool
    {
        return $blueprint instanceof Blueprint;
    }

    private function isSanctumInstalled(): bool
    {
        return $this->isComposerPackageInstalled('laravel/sanctum');
    }

    /**
     * @param array<string, mixed> $config
     * @return array{
     *     enabled:bool,
     *     configured_driver:string,
     *     driver:string,
     *     driver_installed:bool,
     *     driver_package:?string,
     *     driver_label:string,
     *     install_commands:array<int, string>,
     *     guide_url:?string,
     *     middleware_alias:string,
     *     scaffold_enabled:bool,
     *     scaffold_blueprint:string,
    *     auto_detect:bool,
    *     tenant_header:string,
    *     central_base_url:string,
    *     tenant_base_url:string,
     *     drivers:array<string, array{label:string,package:?string,install:array<int,string>,guide_url:?string}>,
     *     detected_driver:?string
     * }
     */
    private function resolveTenancyRuntime(array $config): array
    {
        $enabled = array_key_exists('enabled', $config) ? (bool) $config['enabled'] : true;
        $autoDetect = array_key_exists('auto_detect', $config) ? (bool) $config['auto_detect'] : true;

        $configuredDriver = isset($config['driver']) && is_string($config['driver'])
            ? strtolower(trim($config['driver']))
            : 'auto';

        if ($configuredDriver === '') {
            $configuredDriver = 'auto';
        }

        $middlewareAlias = isset($config['middleware_alias']) && is_string($config['middleware_alias'])
            ? trim($config['middleware_alias'])
            : 'tenant';

        if ($middlewareAlias === '') {
            $middlewareAlias = 'tenant';
        }

        $tenantHeader = isset($config['tenant_header']) && is_string($config['tenant_header'])
            ? trim($config['tenant_header'])
            : (isset($config['header']) && is_string($config['header']) ? trim($config['header']) : 'X-Tenant');

        if ($tenantHeader === '') {
            $tenantHeader = 'X-Tenant';
        }

        $centralBaseUrl = '';

        if (isset($config['central_base_url']) && is_string($config['central_base_url'])) {
            $centralBaseUrl = rtrim(trim($config['central_base_url']), '/');
        }

        $tenantBaseUrl = '';

        if (isset($config['tenant_base_url']) && is_string($config['tenant_base_url'])) {
            $tenantBaseUrl = rtrim(trim($config['tenant_base_url']), '/');
        }

        $scaffoldConfig = isset($config['scaffold']) && is_array($config['scaffold'])
            ? $config['scaffold']
            : [];

        $scaffoldEnabled = array_key_exists('enabled', $scaffoldConfig)
            ? (bool) $scaffoldConfig['enabled']
            : true;

        $scaffoldBlueprint = isset($scaffoldConfig['blueprint_path']) && is_string($scaffoldConfig['blueprint_path'])
            ? trim($scaffoldConfig['blueprint_path'])
            : '';

        if ($scaffoldBlueprint === '') {
            $scaffoldBlueprint = 'central/tenancy/tenants.yaml';
        }

        $driversConfig = isset($config['drivers']) && is_array($config['drivers'])
            ? $config['drivers']
            : [];

        $drivers = [];

        foreach ($driversConfig as $name => $driverConfig) {
            if (! is_string($name) || $name === '') {
                continue;
            }

            $key = strtolower($name);
            $definition = is_array($driverConfig) ? $driverConfig : [];

            $label = isset($definition['label']) && is_string($definition['label'])
                ? trim($definition['label'])
                : $name;

            $package = isset($definition['package']) && is_string($definition['package'])
                ? trim($definition['package'])
                : null;

            $installCommands = [];

            if (isset($definition['install']) && is_array($definition['install'])) {
                foreach ($definition['install'] as $command) {
                    if (! is_string($command)) {
                        continue;
                    }

                    $commandsNormalized = trim($command);

                    if ($commandsNormalized !== '') {
                        $installCommands[] = $commandsNormalized;
                    }
                }
            }

            $guideUrl = isset($definition['guide_url']) && is_string($definition['guide_url'])
                ? trim($definition['guide_url'])
                : null;

            $drivers[$key] = [
                'label' => $label,
                'package' => $package !== '' ? $package : null,
                'install' => $installCommands,
                'guide_url' => $guideUrl !== '' ? $guideUrl : null,
            ];
        }

        $detectedDriver = null;

        if ($autoDetect) {
            foreach ($drivers as $key => $driverDefinition) {
                $package = $driverDefinition['package'] ?? null;

                if ($package !== null && $package !== '' && $this->isComposerPackageInstalled($package)) {
                    $detectedDriver = $key;

                    break;
                }
            }
        }

        $resolvedDriver = $this->selectTenancyDriver($configuredDriver, $detectedDriver, $drivers);

        $driverDefinition = $drivers[$resolvedDriver] ?? null;
        $driverPackage = $driverDefinition['package'] ?? null;

        $driverInstalled = $driverPackage !== null
            ? $this->isComposerPackageInstalled($driverPackage)
            : $resolvedDriver !== 'none';

        $installCommands = $driverDefinition['install'] ?? [];
        if ($installCommands === [] && $driverPackage !== null) {
            $installCommands = [sprintf('composer require %s', $driverPackage)];
        }

        $guideUrl = $driverDefinition['guide_url'] ?? null;

        return [
            'enabled' => $enabled,
            'configured_driver' => $configuredDriver,
            'driver' => $resolvedDriver,
            'driver_installed' => $driverInstalled,
            'driver_package' => $driverPackage,
            'driver_label' => $driverDefinition['label'] ?? ucfirst($resolvedDriver),
            'install_commands' => $installCommands,
            'guide_url' => $guideUrl,
            'middleware_alias' => $middlewareAlias,
            'scaffold_enabled' => $scaffoldEnabled,
            'scaffold_blueprint' => $scaffoldBlueprint,
            'auto_detect' => $autoDetect,
            'tenant_header' => $tenantHeader,
            'central_base_url' => $centralBaseUrl,
            'tenant_base_url' => $tenantBaseUrl,
            'drivers' => $drivers,
            'detected_driver' => $detectedDriver,
        ];
    }

    /**
     * @param array<string, array{label:string,package:?string,install:array<int,string>,guide_url:?string}> $drivers
     */
    private function selectTenancyDriver(string $configuredDriver, ?string $detectedDriver, array $drivers): string
    {
        if ($configuredDriver === 'auto') {
            return $detectedDriver ?? 'none';
        }

        if ($configuredDriver === 'detected') {
            return $detectedDriver ?? 'none';
        }

        if ($configuredDriver === 'none') {
            return 'none';
        }

        if ($configuredDriver === 'custom') {
            return 'custom';
        }

        return array_key_exists($configuredDriver, $drivers)
            ? $configuredDriver
            : ($detectedDriver ?? $configuredDriver);
    }

    private function resolveBlueprintTenancyMode(Blueprint $blueprint): string
    {
        $tenancy = $blueprint->tenancy();

        if (isset($tenancy['mode']) && is_string($tenancy['mode'])) {
            $mode = strtolower(trim($tenancy['mode']));

            if ($mode !== '') {
                return $mode;
            }
        }

        $module = $blueprint->module();

        if (is_string($module) && $module !== '') {
            $mode = $this->detectTenancyModeFromSegments(preg_split('#[\\/]+#', $module) ?: []);

            if ($mode !== null) {
                return $mode;
            }
        }

        $path = $blueprint->path();

        if ($path !== '') {
            $segments = array_filter(
                preg_split('#[\\/]+#', $path) ?: [],
                static fn ($segment) => is_string($segment) && $segment !== ''
            );

            $mode = $this->detectTenancyModeFromSegments($segments);

            if ($mode !== null) {
                return $mode;
            }
        }

        return 'central';
    }

    /**
     * @param iterable<int, string> $segments
     */
    private function detectTenancyModeFromSegments(iterable $segments): ?string
    {
        foreach ($segments as $segment) {
            if (! is_string($segment)) {
                continue;
            }

            $candidate = strtolower(trim($segment));

            if (in_array($candidate, ['central', 'tenant', 'shared'], true)) {
                return $candidate;
            }
        }

        return null;
    }

    private function blueprintRequiresTenancyDriver(string $mode): bool
    {
        return in_array($mode, ['tenant', 'shared'], true);
    }

    private function renderTenancyFeatureDisabledWarning(string $relative, string $mode): void
    {
        if ($this->tenancyFeatureDisabledWarning) {
            return;
        }

        $this->tenancyFeatureDisabledWarning = true;

        $this->warn(sprintf(
            '  [tenancy] El blueprint "%s" está en modo "%s", pero la característica tenancy está deshabilitada en config/blueprintx.php.',
            $relative,
            $mode
        ));

        $this->line('    > Establece "features.tenancy.enabled" en true para habilitar la generación multi-tenant.');
    }

    private function renderTenancyDriverNotConfiguredWarning(
        string $relative,
        string $mode,
        ?string $configuredDriver,
        bool $autoDetect,
        array $availableDrivers
    ): void
    {
        $driverKey = $configuredDriver ?? 'null';
        $key = $driverKey . ':' . ($autoDetect ? 'auto' : 'manual');

        if (isset($this->tenancyDriverConfigWarnings[$key])) {
            return;
        }

        $this->tenancyDriverConfigWarnings[$key] = true;

        if ($configuredDriver === 'none') {
            $this->warn(sprintf(
                '  [tenancy] El blueprint "%s" usa modo "%s" pero "features.tenancy.driver" está establecido en "none".',
                $relative,
                $mode
            ));
            $this->line('    > Define un driver válido, por ejemplo "stancl", en config/blueprintx.php.');

            $this->queueTenancyDriverSuggestionForConfiguredDriver(
                $relative,
                $mode,
                'stancl',
                $availableDrivers
            );

            return;
        }

        if ($configuredDriver === null || $configuredDriver === 'auto') {
            if ($autoDetect) {
                $this->warn(sprintf(
                    '  [tenancy] No se detectó ningún paquete tenancy instalado para el blueprint "%s" (modo "%s").',
                    $relative,
                    $mode
                ));
                $this->line('    > Instala un driver soportado (p. ej. stancl/tenancy) o configura "features.tenancy.driver" manualmente.');
            } else {
                $this->warn(sprintf(
                    '  [tenancy] La autodetección está deshabilitada y no se configuró un driver para el blueprint "%s" (modo "%s").',
                    $relative,
                    $mode
                ));
                $this->line('    > Activa "features.tenancy.auto_detect" o asigna un driver en "features.tenancy.driver".');
            }

            $this->queueTenancyDriverSuggestionForConfiguredDriver(
                $relative,
                $mode,
                $configuredDriver,
                $availableDrivers,
                $autoDetect
            );

            return;
        }

        $this->warn(sprintf(
            '  [tenancy] El driver configurado "%s" no está disponible para el blueprint "%s" (modo "%s").',
            $configuredDriver,
            $relative,
            $mode
        ));
        $this->line('    > Verifica que el driver esté instalado o ajusta "features.tenancy.driver".');

        if ($configuredDriver !== null) {
            $this->queueTenancyDriverSuggestion(
                $configuredDriver,
                $availableDrivers[$configuredDriver]['label'] ?? ucfirst($configuredDriver),
                $availableDrivers[$configuredDriver]['package'] ?? null,
                $availableDrivers[$configuredDriver]['install'] ?? [],
                $availableDrivers[$configuredDriver]['guide_url'] ?? null,
                $relative,
                $mode
            );
        }
    }

    /**
     * @param array<int, string> $installCommands
     */
    private function renderTenancyDriverInstallReminder(
        string $relative,
        string $mode,
        string $driverKey,
        string $driverLabel,
        ?string $package,
        array $installCommands,
        ?string $guideUrl
    ): void {
        $this->queueTenancyDriverSuggestion(
            $driverKey,
            $driverLabel,
            $package,
            $installCommands,
            $guideUrl,
            $relative,
            $mode
        );
    }

    private function queueTenancyDriverSuggestion(
        string $driverKey,
        string $driverLabel,
        ?string $package,
        array $installCommands,
        ?string $guideUrl,
        string $relative,
        string $mode
    ): void {
        $normalizedCommands = array_values(array_filter(
            array_map('trim', $installCommands),
            static fn (string $command): bool => $command !== ''
        ));

        if (! isset($this->tenancyDriverInstallQueue[$driverKey])) {
            $this->tenancyDriverInstallQueue[$driverKey] = [
                'label' => $driverLabel,
                'package' => $package,
                'commands' => $normalizedCommands,
                'guide' => $guideUrl,
                'blueprints' => [],
            ];
        } else {
            if ($package !== null && $this->tenancyDriverInstallQueue[$driverKey]['package'] === null) {
                $this->tenancyDriverInstallQueue[$driverKey]['package'] = $package;
            }

            if ($normalizedCommands !== []) {
                $this->tenancyDriverInstallQueue[$driverKey]['commands'] = $normalizedCommands;
            }

            if ($guideUrl !== null && $guideUrl !== '' && ($this->tenancyDriverInstallQueue[$driverKey]['guide'] ?? null) === null) {
                $this->tenancyDriverInstallQueue[$driverKey]['guide'] = $guideUrl;
            }
        }

        $blueprintKey = $relative . '|' . $mode;
        $this->tenancyDriverInstallQueue[$driverKey]['blueprints'][$blueprintKey] = [
            'path' => $relative,
            'mode' => $mode,
        ];
    }

    private function queueTenancyDriverSuggestionForConfiguredDriver(
        string $relative,
        string $mode,
        ?string $configuredDriver,
        array $availableDrivers,
        bool $autoDetect = true
    ): void {
        $driverKey = null;

        if (is_string($configuredDriver) && $configuredDriver !== '' && ! in_array($configuredDriver, ['none', 'auto'], true)) {
            $driverKey = $configuredDriver;
        } elseif ($autoDetect && $availableDrivers !== []) {
            $driverKey = array_key_first($availableDrivers);
        }

        if ($driverKey === null || ! isset($availableDrivers[$driverKey])) {
            return;
        }

        $config = $availableDrivers[$driverKey];

        $this->queueTenancyDriverSuggestion(
            $driverKey,
            $config['label'] ?? ucfirst($driverKey),
            $config['package'] ?? null,
            $config['install'] ?? [],
            $config['guide_url'] ?? null,
            $relative,
            $mode
        );
    }

    private function renderTenancyInstallationSuggestions(bool $dryRun): void
    {
        if ($this->tenancyDriverInstallQueue === []) {
            return;
        }

        $this->newLine();

        foreach ($this->tenancyDriverInstallQueue as $driverKey => $info) {
            $label = $info['label'];
            $package = $info['package'];
            $commands = $info['commands'];
            $guide = $info['guide'];
            $blueprints = array_values($info['blueprints']);

            $this->warn(sprintf(
                '[tenancy] Se detectaron blueprints que requieren el driver "%s" pero no está instalado.',
                $label
            ));

            foreach ($blueprints as $blueprint) {
                $this->line(sprintf('  - %s (modo %s)', $blueprint['path'], $blueprint['mode']));
            }

            if ($package !== null && $package !== '') {
                $this->line(sprintf('  Paquete recomendado: %s', $package));
            }

            if ($dryRun || $commands === []) {
                if ($commands !== []) {
                    $this->line('  Ejecuta manualmente:');
                    foreach ($commands as $command) {
                        $this->line(sprintf('    $ %s', $command));
                    }
                }

                if ($guide) {
                    $this->line(sprintf('  Guía: %s', $guide));
                }

                if ($dryRun) {
                    $this->line('  (modo dry-run: no se instalaron dependencias automáticamente)');
                }

                $this->newLine();

                continue;
            }

            $shouldInstall = $this->confirm(sprintf('¿Deseas instalar "%s" ahora?', $label), false);

            if (! $shouldInstall) {
                $this->line('  Ejecuta manualmente:');
                foreach ($commands as $command) {
                    $this->line(sprintf('    $ %s', $command));
                }

                if ($guide) {
                    $this->line(sprintf('  Guía: %s', $guide));
                }

                $this->newLine();

                continue;
            }

            $this->line(sprintf('  Instalando "%s"...', $label));
            $installed = $this->runTenancyInstallCommands($commands);

            if ($installed) {
                $this->info(sprintf('  Instalación de "%s" completada.', $label));
            } else {
                $this->error(sprintf('  La instalación de "%s" falló. Revisa los comandos anteriores.', $label));
            }

            if ($guide) {
                $this->line(sprintf('  Guía: %s', $guide));
            }

            $this->newLine();
        }
    }

    private function runTenancyInstallCommands(array $commands): bool
    {
        $basePath = function_exists('base_path') ? base_path() : getcwd();

        foreach ($commands as $command) {
            $this->line(sprintf('    $ %s', $command));

            $process = Process::fromShellCommandline($command, $basePath ?: null);
            $process->setTimeout(null);

            $exitCode = $process->run(function ($type, $buffer): void {
                $this->output->write($buffer);
            });

            if ($exitCode !== 0) {
                return false;
            }
        }

        return true;
    }

    private function isComposerPackageInstalled(string $package): bool
    {
        $manifest = $this->composerManifest();

        if (isset($manifest['require'][$package]) || isset($manifest['require-dev'][$package])) {
            return true;
        }

        if (isset($manifest['packages'][$package]) || isset($manifest['packages-dev'][$package])) {
            return true;
        }

        return false;
    }

    /**
     * @return array{
     *     require: array<string, string>,
     *     'require-dev': array<string, string>,
     *     packages: array<string, array<string, mixed>>,
     *     'packages-dev': array<string, array<string, mixed>>
     * }
     */
    private function composerManifest(): array
    {
        if ($this->composerConfig !== null) {
            return $this->composerConfig;
        }

        $manifest = [
            'require' => [],
            'require-dev' => [],
            'packages' => [],
            'packages-dev' => [],
        ];

        $basePath = function_exists('base_path') ? base_path() : getcwd();

        if (is_string($basePath) && $basePath !== '') {
            $jsonPath = $basePath . DIRECTORY_SEPARATOR . 'composer.json';

            if (is_file($jsonPath)) {
                $contents = file_get_contents($jsonPath);

                if ($contents !== false) {
                    $data = json_decode($contents, true);

                    if (is_array($data)) {
                        $require = $data['require'] ?? [];
                        $requireDev = $data['require-dev'] ?? [];

                        if (is_array($require)) {
                            $manifest['require'] = array_filter($require, static fn ($value, $key): bool => is_string($key), ARRAY_FILTER_USE_BOTH);
                        }

                        if (is_array($requireDev)) {
                            $manifest['require-dev'] = array_filter($requireDev, static fn ($value, $key): bool => is_string($key), ARRAY_FILTER_USE_BOTH);
                        }
                    }
                }
            }

            $lockPath = $basePath . DIRECTORY_SEPARATOR . 'composer.lock';

            if (is_file($lockPath)) {
                $contents = file_get_contents($lockPath);

                if ($contents !== false) {
                    $data = json_decode($contents, true);

                    if (is_array($data)) {
                        foreach (['packages', 'packages-dev'] as $section) {
                            if (! isset($data[$section]) || ! is_array($data[$section])) {
                                continue;
                            }

                            foreach ($data[$section] as $package) {
                                if (! is_array($package) || ! isset($package['name']) || ! is_string($package['name'])) {
                                    continue;
                                }

                                $manifest[$section][$package['name']] = $package;
                            }
                        }
                    }
                }
            }
        }

        $this->composerConfig = $manifest;

        return $this->composerConfig;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function normalizeRelativePath(mixed $value, string $default): string
    {
        if (! is_string($value)) {
            return trim($default, '\\/');
        }

        $value = trim($value);

        if ($value === '') {
            return trim($default, '\\/');
        }

        return trim($value, '\\/');
    }

    private function normalizeNamespace(mixed $value, string $default): string
    {
        if (! is_string($value)) {
            return $default;
        }

        $normalized = trim(str_replace('/', '\\', $value), '\\');

        if ($normalized === '') {
            return $default;
        }

        if (strncasecmp($normalized, 'app\\', 4) === 0) {
            $normalized = 'App\\' . substr($normalized, 4);
        }

        if (! str_starts_with($normalized, 'App\\')) {
            $normalized = 'App\\' . ltrim($normalized, '\\');
        }

        return $normalized !== '' ? $normalized : $default;
    }

    private function normalizeArchitecture(mixed $value, string $default): string
    {
        if (is_string($value) && trim($value) !== '') {
            return strtolower(trim($value));
        }

        return strtolower($default);
    }

    private function normalizeUrl(mixed $value, string $default): string
    {
        if (! is_string($value)) {
            return rtrim($default, '/');
        }

        $normalized = trim($value);

        if ($normalized === '') {
            return rtrim($default, '/');
        }

        return rtrim($normalized, '/');
    }

    private function normalizeApiPrefixOption(mixed $value, string $default): string
    {
        if (! is_string($value)) {
            return $this->ensureLeadingSlash($default);
        }

        $normalized = trim($value);

        if ($normalized === '') {
            return '';
        }

        return $this->ensureLeadingSlash($normalized);
    }

    private function resolveAuthModelEntity(): ?string
    {
        $model = $this->laravel['config']->get('auth.providers.users.model');

        if (! is_string($model) || $model === '') {
            return null;
        }

        $basename = class_basename($model);

        if ($basename === '') {
            return null;
        }

        return Str::lower($basename);
    }

    private function matchesAuthModelBlueprint(Blueprint $blueprint, ?string $authModelEntity): bool
    {
        if ($authModelEntity === null) {
            return false;
        }

        return Str::lower($blueprint->entity()) === $authModelEntity;
    }

    private function findAuthModelBlueprint(array $queue, ?string $authModelEntity): ?Blueprint
    {
        if ($authModelEntity === null) {
            return null;
        }

        foreach ($queue as $entry) {
            if (! ($entry['blueprint'] ?? null) instanceof Blueprint) {
                continue;
            }

            if ($this->matchesAuthModelBlueprint($entry['blueprint'], $authModelEntity)) {
                return $entry['blueprint'];
            }
        }

        return null;
    }

    private function serializeAuthModelFields(?Blueprint $blueprint): ?array
    {
        if (! $blueprint instanceof Blueprint) {
            return null;
        }

        $fields = [];

        foreach ($blueprint->fields() as $field) {
            if ($field instanceof Field) {
                $fields[] = $field->toArray();
            }
        }

        return $fields !== [] ? $fields : null;
    }

    /**
     * @param array<int, string> $paths
     * @return array<int, array<string, mixed>>
     */
    private function prepareBlueprintQueue(array $paths): array
    {
        $entries = [];

        foreach ($paths as $index => $path) {
            $entry = [
                'path' => $path,
                'index' => $index,
                'blueprint' => null,
                'parse_exception' => null,
                'unexpected_exception' => null,
            ];

            try {
                $entry['blueprint'] = $this->parser->parse($path);
            } catch (BlueprintParseException $exception) {
                $entry['parse_exception'] = $exception;
            } catch (Throwable $exception) {
                $entry['unexpected_exception'] = $exception;
            }

            $entries[] = $entry;
        }

        $validEntries = array_values(array_filter(
            $entries,
            static fn (array $entry): bool => $entry['blueprint'] instanceof Blueprint,
        ));

        if ($validEntries === []) {
            return $entries;
        }

        $sortedValid = $this->topologicallySortBlueprintEntries($validEntries);

        $sorted = [];
        $validIndex = 0;

        foreach ($entries as $entry) {
            if (! ($entry['blueprint'] instanceof Blueprint)) {
                $sorted[] = $entry;
                continue;
            }

            $sorted[] = $sortedValid[$validIndex] ?? $entry;
            $validIndex++;
        }

        return $sorted;
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @return array<int, array<string, mixed>>
     */
    private function topologicallySortBlueprintEntries(array $entries): array
    {
        $nodes = [];

        foreach ($entries as $entry) {
            /** @var Blueprint $blueprint */
            $blueprint = $entry['blueprint'];
            $key = $this->blueprintNodeKey($blueprint);

            $nodes[$key] = [
                'entry' => $entry,
                'index' => $entry['index'],
                'dependencies' => [],
            ];
        }

        foreach ($nodes as $key => $node) {
            /** @var Blueprint $currentBlueprint */
            $currentBlueprint = $node['entry']['blueprint'];
            $dependencies = $this->extractBlueprintDependencies($currentBlueprint);
            $filtered = [];

            foreach ($dependencies as $dependencyKey) {
                if (! isset($nodes[$dependencyKey]) || $dependencyKey === $key) {
                    continue;
                }

                $filtered[] = $dependencyKey;
            }

            $nodes[$key]['dependencies'] = array_values(array_unique($filtered));
        }

        $adjacency = [];
        $inDegree = [];

        foreach ($nodes as $key => $node) {
            $inDegree[$key] = 0;
            $adjacency[$key] = [];
        }

        foreach ($nodes as $key => $node) {
            foreach ($node['dependencies'] as $dependencyKey) {
                $adjacency[$dependencyKey][] = $key;
                $inDegree[$key]++;
            }
        }

        $queue = [];

        foreach ($nodes as $key => $node) {
            if ($inDegree[$key] === 0) {
                $queue[] = $key;
            }
        }

        usort($queue, static function (string $a, string $b) use ($nodes): int {
            return $nodes[$a]['index'] <=> $nodes[$b]['index'];
        });

        $sortedKeys = [];

        while ($queue !== []) {
            $current = array_shift($queue);
            $sortedKeys[] = $current;

            foreach ($adjacency[$current] as $next) {
                $inDegree[$next]--;

                if ($inDegree[$next] === 0) {
                    $queue[] = $next;
                }
            }

            usort($queue, static function (string $a, string $b) use ($nodes): int {
                return $nodes[$a]['index'] <=> $nodes[$b]['index'];
            });
        }

        if (count($sortedKeys) < count($nodes)) {
            $remaining = array_diff(array_keys($nodes), $sortedKeys);
            usort($remaining, static function (string $a, string $b) use ($nodes): int {
                return $nodes[$a]['index'] <=> $nodes[$b]['index'];
            });

            $sortedKeys = array_merge($sortedKeys, $remaining);
        }

        $sorted = [];

        foreach ($sortedKeys as $key) {
            $sorted[] = $nodes[$key]['entry'];
        }

        return $sorted;
    }

    private function blueprintNodeKey(Blueprint $blueprint): string
    {
        $module = $blueprint->module();
        $moduleKey = $module !== null && $module !== '' ? Str::lower($module) : '_';

        return $moduleKey . ':' . Str::lower($blueprint->entity());
    }

    private function blueprintNodeKeyFromParts(?string $module, string $entity): string
    {
        $moduleKey = $module !== null && $module !== '' ? Str::lower($module) : '_';

        return $moduleKey . ':' . Str::lower($entity);
    }

    /**
     * @return array<int, string>
     */
    private function extractBlueprintDependencies(Blueprint $blueprint): array
    {
        $dependencies = [];

        foreach ($blueprint->relations() as $relation) {
            if (Str::lower($relation->type) !== 'belongsto') {
                continue;
            }

            $target = trim($relation->target);

            if ($target === '') {
                continue;
            }

            $dependencies[] = $this->blueprintNodeKeyFromParts($blueprint->module(), $target);
        }

        return array_values(array_unique($dependencies));
    }

    private function ensureLeadingSlash(string $value): string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return '';
        }

        return '/' . ltrim($trimmed, '/');
    }

    /**
     * @param ValidationMessage[] $messages
     */
    private function renderValidationMessages(array $messages, string $type): void
    {
        foreach ($messages as $message) {
            $text = sprintf('[%s] %s%s', $message->code, $message->message, $message->path ? sprintf(' (%s)', $message->path) : '');

            if ($type === 'error') {
                $this->error('    ' . $text);
            } else {
                $this->warn('    ' . $text);
            }
        }
    }

    private function renderPipelineResult(PipelineResult $result, array &$summary): bool
    {
        $hasErrors = false;
        $rows = [];

        foreach ($result->files() as $file) {
            $status = (string) ($file['status'] ?? 'unknown');
            $layer = (string) ($file['layer'] ?? '');
            $path = (string) ($file['path'] ?? '-');
            $details = $this->formatFileDetails($file);

            $rows[] = [
                Str::upper($layer),
                Str::upper($status),
                $path,
                $details,
            ];

            if ($status === 'error') {
                $summary['errors']++;
                $hasErrors = true;
            }

            $key = match ($status) {
                'written' => 'written',
                'overwritten' => 'overwritten',
                'skipped' => 'skipped',
                'preview' => 'preview',
                default => null,
            };

            if ($key !== null) {
                $summary[$key]++;
            }
        }

        if ($rows !== []) {
            $this->table(['Capa', 'Estado', 'Ruta', 'Detalles'], $rows);
        } else {
            $this->line('  (Sin archivos generados)');
        }

        foreach ($result->warnings() as $warning) {
            $this->warn(sprintf('  [warning] %s', $warning));
            $summary['warnings']++;
        }

        return $hasErrors;
    }

    private function hasTrackableChanges(PipelineResult $result): bool
    {
        foreach ($result->files() as $file) {
            $status = (string) ($file['status'] ?? '');

            if ($status === 'written' || $status === 'overwritten') {
                return true;
            }
        }

        return false;
    }

    private function formatFileDetails(array $file): string
    {
        if (isset($file['message']) && is_string($file['message']) && $file['message'] !== '') {
            return $file['message'];
        }

        $status = $file['status'] ?? null;

        if ($status === 'preview' && isset($file['preview']) && is_string($file['preview'])) {
            $length = strlen($file['preview']);

            return sprintf('preview (%d bytes)', $length);
        }

        if (isset($file['full_path']) && is_string($file['full_path']) && $file['full_path'] !== '') {
            return $file['full_path'];
        }

        return '';
    }

    private function overrideArchitecture(Blueprint $blueprint, string $architecture): Blueprint
    {
        $data = $blueprint->toArray();
        $data['path'] = $blueprint->path();
        $data['architecture'] = $architecture;

        return Blueprint::fromArray($data);
    }
}




