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
use BlueprintX\Validation\ValidationMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

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
        private readonly GenerationHistoryManager $history,
    ) {
        parent::__construct();
    }

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
                ],
                'form_requests' => [
                    'enabled' => $formRequestsEnabled,
                    'namespace' => $requestsNamespace,
                    'path' => $formRequestsPath,
                    'authorize_by_default' => $authorizeByDefault,
                ],
                'auth_model_fields' => $authModelFields,
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
        $path = base_path('composer.json');

        if (! is_file($path)) {
            return false;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            return false;
        }

        $data = json_decode($contents, true);

        if (! is_array($data)) {
            return false;
        }

        $require = $data['require'] ?? [];
        $requireDev = $data['require-dev'] ?? [];

        return (is_array($require) && array_key_exists('laravel/sanctum', $require))
            || (is_array($requireDev) && array_key_exists('laravel/sanctum', $requireDev));
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




