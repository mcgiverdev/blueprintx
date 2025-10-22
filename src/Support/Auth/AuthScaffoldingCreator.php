<?php

namespace BlueprintX\Support\Auth;

use BlueprintX\Blueprint\Blueprint;
use BlueprintX\Kernel\Generation\PipelineResult;
use BlueprintX\Kernel\History\GenerationHistoryManager;
use BlueprintX\Kernel\TemplateEngine;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class AuthScaffoldingCreator
{
    /** @var array<int, array<string, mixed>> */
    private array $historyEntries = [];

    private const REGISTER_EXCLUDED_FIELDS = [
        'id',
        'created_at',
        'updated_at',
        'deleted_at',
        'email_verified_at',
        'remember_token',
    ];

    private const DEFAULT_DEVICE_NAME_RULES = ['nullable', 'string', 'max:120'];

    public function __construct(
        private readonly Filesystem $files,
        private readonly TemplateEngine $templates,
        private readonly GenerationHistoryManager $history,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function ensure(array $options = []): void
    {
        $this->historyEntries = [];

        $architecture = $this->normalizeArchitecture($options['architecture'] ?? null);

        $controllersPath = $this->normalizeRelativePath($options['controllers_path'] ?? 'app/Http/Controllers/Api');
        $requestsPath = $this->normalizeRelativePath($options['requests_path'] ?? 'app/Http/Requests/Api');
        $resourcesPath = $this->normalizeRelativePath($options['resources_path'] ?? 'app/Http/Resources');

        $controllersNamespace = $this->deriveNamespace($options['controllers_namespace'] ?? $controllersPath, 'App\\Http\\Controllers\\Api');
        $requestsNamespace = $this->deriveNamespace($options['requests_namespace'] ?? 'App\\Http\\Requests\\Api', 'App\\Http\\Requests\\Api');
        $resourcesNamespace = $this->deriveNamespace($options['resources_namespace'] ?? 'App\\Http\\Resources', 'App\\Http\\Resources');

        $force = (bool) ($options['force'] ?? false);
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $model = $options['model'] ?? null;
        $sanctumInstalled = (bool) ($options['sanctum_installed'] ?? false);

        if (! is_array($model)) {
            $model = null;
        }

        $this->ensureAuthController($architecture, $controllersPath, $controllersNamespace, $requestsNamespace, $resourcesNamespace, $force, $dryRun);
        $this->ensureLoginRequest($architecture, $requestsPath, $requestsNamespace, $force, $dryRun);
        $this->ensureRegisterRequest($architecture, $requestsPath, $requestsNamespace, $model, $force, $dryRun);
        $this->ensureUserResource($architecture, $resourcesPath, $resourcesNamespace, $model, $force, $dryRun);
        $this->ensureApplicationUserModel($architecture, $model, $force, $dryRun);
        $this->ensurePersonalAccessTokensMigration($model, $force, $dryRun);
        $this->ensureSanctumGuide($architecture, $sanctumInstalled, $force, $dryRun);

        if (! $dryRun) {
            $this->ensureRoutes($controllersNamespace);
        }

        if (! $dryRun) {
            $this->persistHistory($architecture, $options);
        } else {
            $this->historyEntries = [];
        }
    }

    private function ensureAuthController(
        string $architecture,
        string $controllersPath,
        string $controllersNamespace,
        string $requestsNamespace,
        string $resourcesNamespace,
        bool $force,
        bool $dryRun,
    ): void {
        $path = $this->resolveAbsolutePath($controllersPath, 'Auth/AuthController.php');

        if ($dryRun) {
            return;
        }

        if ($this->files->exists($path) && ! $force) {
            return;
        }

        $this->files->ensureDirectoryExists((string) dirname($path));

        $contents = $this->renderTemplate($architecture, 'controller.stub.twig', [
            'namespace' => $this->appendNamespace($controllersNamespace, 'Auth'),
            'login_request_fqn' => $this->appendNamespace($requestsNamespace, 'Auth\\LoginRequest'),
            'register_request_fqn' => $this->appendNamespace($requestsNamespace, 'Auth\\RegisterRequest'),
            'user_resource_fqn' => $this->appendNamespace($resourcesNamespace, 'Crm\\UserResource'),
        ]);

        $previous = $this->getExistingContents($path);

        if ($this->files->put($path, $contents) !== false) {
            $this->recordWrittenFile($path, $contents, $previous);
        }
    }

    private function ensureLoginRequest(string $architecture, string $requestsPath, string $requestsNamespace, bool $force, bool $dryRun): void
    {
        $path = $this->resolveAbsolutePath($requestsPath, 'Auth/LoginRequest.php');

        if ($dryRun) {
            return;
        }

        if ($this->files->exists($path) && ! $force) {
            return;
        }

        $this->files->ensureDirectoryExists((string) dirname($path));

        $contents = $this->renderTemplate($architecture, 'login-request.stub.twig', [
            'namespace' => $this->appendNamespace($requestsNamespace, 'Auth'),
        ]);

        $previous = $this->getExistingContents($path);

        if ($this->files->put($path, $contents) !== false) {
            $this->recordWrittenFile($path, $contents, $previous);
        }
    }

    private function ensureRegisterRequest(string $architecture, string $requestsPath, string $requestsNamespace, ?array $model, bool $force, bool $dryRun): void
    {
        $path = $this->resolveAbsolutePath($requestsPath, 'Auth/RegisterRequest.php');

        if ($dryRun) {
            return;
        }

        if ($this->files->exists($path) && ! $force) {
            return;
        }

        $this->files->ensureDirectoryExists((string) dirname($path));

        $rules = $this->prepareRegisterValidationRules($model);

        $contents = $this->renderTemplate($architecture, 'register-request.stub.twig', [
            'namespace' => $this->appendNamespace($requestsNamespace, 'Auth'),
            'has_rules' => $rules !== [],
            'rules' => $rules,
        ]);

        $previous = $this->getExistingContents($path);

        if ($this->files->put($path, $contents) !== false) {
            $this->recordWrittenFile($path, $contents, $previous);
        }
    }

    private function ensureUserResource(string $architecture, string $resourcesPath, string $resourcesNamespace, ?array $model, bool $force, bool $dryRun): void
    {
        $path = $this->resolveAbsolutePath($resourcesPath, 'Crm/UserResource.php');

        if ($dryRun) {
            return;
        }

        if ($this->files->exists($path) && ! $force) {
            return;
        }

        $this->files->ensureDirectoryExists((string) dirname($path));

        $resourceNamespace = $this->appendNamespace($resourcesNamespace, 'Crm');
        $resourceContext = $this->prepareUserResourceContext($model, $resourceNamespace);

        $contents = $this->renderTemplate($architecture, 'user-resource.stub.twig', [
            'namespace' => $resourceNamespace,
            'attributes' => $resourceContext['attributes'],
            'relationships' => $resourceContext['relationships'],
            'imports' => $resourceContext['imports'],
        ]);

        $previous = $this->getExistingContents($path);

        if ($this->files->put($path, $contents) !== false) {
            $this->recordWrittenFile($path, $contents, $previous);
        }
    }

    private function ensureRoutes(string $controllersNamespace): void
    {
        $path = base_path('routes/api.php');

        if (! $this->files->exists($path)) {
            return;
        }

        $original = $this->files->get($path);
        $normalized = str_replace(["\r\n", "\r"], "\n", $original);

        $useStatement = sprintf('use %s\\Auth\\AuthController;', trim($controllersNamespace, '\\'));

        if (! str_contains($normalized, $useStatement)) {
            $normalized = $this->insertUseStatement($normalized, $useStatement);
        }

        if (! str_contains($normalized, "Route::prefix('auth')")) {
            $authBlock = "\nRoute::prefix('auth')->group(function (): void {\n" .
                "    Route::post('login', [AuthController::class, 'login']);\n" .
                "    Route::post('register', [AuthController::class, 'register']);\n\n" .
                "    Route::middleware(['auth:sanctum'])->group(function (): void {\n" .
                "        Route::post('logout', [AuthController::class, 'logout']);\n" .
                "        Route::get('me', [AuthController::class, 'me']);\n" .
                "    });\n" .
                "});\n";

            $needle = "Route::prefix('crm')";
            $position = strpos($normalized, $needle);

            if ($position !== false) {
                $normalized = substr($normalized, 0, $position) . $authBlock . "\n" . substr($normalized, $position);
            } else {
                $normalized = rtrim($normalized) . $authBlock . "\n";
            }
        }

        $updated = $this->restoreLineEndings($original, $normalized);

        if ($updated !== $original) {
            if ($this->files->put($path, $updated) !== false) {
                $this->recordWrittenFile($path, $updated, $original);
            }
        }
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function prepareRegisterValidationRules(?array $model): array
    {
        $ruleMap = $this->deriveRegisterRuleMap($model);

        if ($ruleMap === []) {
            return [];
        }

        return $this->formatRulesForTemplate($ruleMap);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function deriveRegisterRuleMap(?array $model): array
    {
        if ($model === null) {
            $defaults = $this->defaultRegisterRuleMap();
            $defaults['device_name'] = self::DEFAULT_DEVICE_NAME_RULES;

            return $defaults;
        }

        $rules = [];
        $order = [];
        $fields = $model['fields'] ?? [];

        if (is_array($fields)) {
            foreach ($fields as $definition) {
                if (! is_array($definition)) {
                    continue;
                }

                $name = $definition['name'] ?? null;

                if (! is_string($name) || $name === '') {
                    continue;
                }

                if (in_array($name, self::REGISTER_EXCLUDED_FIELDS, true)) {
                    continue;
                }

                $parts = $this->splitRules($definition['rules'] ?? null);

                if ($parts === []) {
                    $parts = $this->inferRulesFromFieldDefinition($definition);
                }

                if ($name === 'password' && ! $this->containsRule($parts, 'confirmed')) {
                    $parts[] = 'confirmed';
                }

                if ($parts === []) {
                    continue;
                }

                $rules[$name] = $this->uniqueRules($parts);
                $order[] = $name;
            }
        }

        foreach ($this->defaultRegisterRuleMap() as $field => $parts) {
            if (! array_key_exists($field, $rules)) {
                $rules[$field] = $parts;
                $order[] = $field;
            }
        }

        if (! array_key_exists('device_name', $rules)) {
            $rules['device_name'] = self::DEFAULT_DEVICE_NAME_RULES;
            $order[] = 'device_name';
        } else {
            $rules['device_name'] = $this->uniqueRules($rules['device_name']);
        }

        if (isset($rules['password'])) {
            $rules['password'] = $this->uniqueRules($rules['password']);
        }

        $ordered = [];

        foreach (array_unique($order) as $field) {
            $ordered[$field] = $rules[$field];
        }

        return $ordered;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function defaultRegisterRuleMap(): array
    {
        return [
            'tenant_id' => ['required', 'uuid', 'exists:tenants,id'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:120', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'full_name' => ['required', 'string', 'max:120'],
            'role' => ['nullable', 'string'],
            'locale' => ['nullable', 'string'],
            'timezone' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    private function inferRulesFromFieldDefinition(array $definition): array
    {
        $rules = [];
        $nullable = array_key_exists('nullable', $definition) ? (bool) $definition['nullable'] : false;
        $rules[] = $nullable ? 'nullable' : 'required';

        $type = $definition['type'] ?? null;

        if (! is_string($type)) {
            return $rules;
        }

        $type = strtolower($type);

        switch ($type) {
            case 'uuid':
                $rules[] = 'uuid';
                break;
            case 'string':
            case 'text':
                $rules[] = 'string';
                break;
            case 'boolean':
                $rules[] = 'boolean';
                break;
            case 'integer':
                $rules[] = 'integer';
                break;
            case 'decimal':
            case 'float':
                $rules[] = 'numeric';
                break;
            case 'datetime':
            case 'timestamp':
                $rules[] = 'date';
                break;
        }

        return $rules;
    }

    /**
     * @param array<string, array<int, string>> $rules
     * @return array<int, array<string, string>>
     */
    private function formatRulesForTemplate(array $rules): array
    {
        $formatted = [];

        foreach ($rules as $field => $parts) {
            $formatted[] = [
                'field_code' => var_export($field, true),
                'parts_code' => implode(', ', array_map(static fn ($rule) => var_export($rule, true), $parts)),
            ];
        }

        return $formatted;
    }

    /**
     * @return array{attributes: array<int, string>, relationships: array<int, array<string, string>>, imports: array<int, string>}
     */
    private function prepareUserResourceContext(?array $model, string $resourceNamespace): array
    {
        if ($model === null) {
            return $this->defaultUserResourceContext($resourceNamespace);
        }

        $attributes = $this->buildResourceAttributesFromModel($model);
        $relationships = $this->buildResourceRelationshipsFromModel($model, $resourceNamespace);

        $fallback = $this->defaultUserResourceContext($resourceNamespace);

        if ($attributes === []) {
            $attributes = $fallback['attributes'];
        }

        if ($relationships['items'] === []) {
            $relationships = [
                'items' => $fallback['relationships'],
                'imports' => $fallback['imports'],
            ];
        }

        return [
            'attributes' => $attributes,
            'relationships' => $relationships['items'],
            'imports' => array_values(array_unique($relationships['imports'])),
        ];
    }

    /**
     * @return array{attributes: array<int, string>, relationships: array<int, array<string, string>>, imports: array<int, string>}
     */
    private function defaultUserResourceContext(string $resourceNamespace): array
    {
        $tenantResourceFqn = $this->appendNamespace($resourceNamespace, 'TenantResource');

        return [
            'attributes' => [
                'id',
                'tenant_id',
                'name',
                'email',
                'password',
                'full_name',
                'role',
                'locale',
                'timezone',
                'is_active',
                'last_login_at',
                'created_at',
                'updated_at',
            ],
            'relationships' => [
                [
                    'property' => 'tenant',
                    'expression' => 'TenantResource::make($this->whenLoaded(\'tenant\'))',
                ],
            ],
            'imports' => [$tenantResourceFqn],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function buildResourceAttributesFromModel(array $model): array
    {
        $attributes = ['id'];
        $fields = $model['fields'] ?? [];

        if (is_array($fields)) {
            foreach ($fields as $definition) {
                if (! is_array($definition)) {
                    continue;
                }

                $name = $definition['name'] ?? null;

                if (is_string($name) && $name !== '') {
                    $attributes[] = $name;
                }
            }
        }

        $options = $model['options'] ?? [];
        $timestampsEnabled = true;

        if (is_array($options) && array_key_exists('timestamps', $options)) {
            $timestampsEnabled = (bool) $options['timestamps'];
        }

        if ($timestampsEnabled) {
            $attributes[] = 'created_at';
            $attributes[] = 'updated_at';
        }

        if (is_array($options) && (bool) ($options['softDeletes'] ?? false)) {
            $attributes[] = 'deleted_at';
        }

        $attributes = array_values(array_unique(array_filter($attributes, static fn ($value): bool => is_string($value) && $value !== '')));

        return $attributes;
    }

    /**
     * @return array{items: array<int, array<string, string>>, imports: array<int, string>}
     */
    private function buildResourceRelationshipsFromModel(array $model, string $resourceNamespace): array
    {
        $api = $model['api'] ?? [];
        $includes = [];

        if (is_array($api) && isset($api['includes']) && is_array($api['includes'])) {
            $includes = $api['includes'];
        }

        if ($includes === []) {
            return [
                'items' => [],
                'imports' => [],
            ];
        }

        $relationMap = $this->buildRelationMapFromModel($model);

        if ($relationMap === []) {
            return [
                'items' => [],
                'imports' => [],
            ];
        }

        $items = [];
        $imports = [];
        $namespace = trim($resourceNamespace, '\\');

        foreach ($includes as $definition) {
            $relationName = null;
            $alias = null;
            $resourceOverride = null;

            if (is_string($definition)) {
                $relationName = Str::camel($definition);
                $alias = $definition;
            } elseif (is_array($definition)) {
                if (! isset($definition['relation'])) {
                    continue;
                }

                $relationName = Str::camel((string) $definition['relation']);
                $alias = $definition['alias'] ?? $definition['relation'];

                if (isset($definition['resource']) && is_string($definition['resource']) && $definition['resource'] !== '') {
                    $resourceOverride = $definition['resource'];
                }
            } else {
                continue;
            }

            if ($relationName === '') {
                continue;
            }

            $relation = $relationMap[$relationName] ?? null;

            if ($relation === null) {
                continue;
            }

            $alias = is_string($alias) && $alias !== '' ? $alias : $relation['method'];

            $resourceFqcn = $resourceOverride !== null ? $this->normalizeResourceFqcn($resourceOverride, $namespace) : null;

            if (in_array($relation['type'], ['belongsto', 'hasone'], true)) {
                if ($resourceFqcn === null) {
                    $resourceFqcn = $this->guessResourceFqcn($namespace, $relation['target']);
                }
            } elseif (in_array($relation['type'], ['hasmany', 'belongstomany'], true)) {
                if ($resourceFqcn === null) {
                    $resourceFqcn = $this->guessCollectionFqcn($namespace, $relation['target']);
                }
            } else {
                continue;
            }

            if ($resourceFqcn === null) {
                continue;
            }

            $resourceClass = Str::afterLast($resourceFqcn, '\\');

            $items[] = [
                'property' => $alias,
                'expression' => sprintf('%s::make($this->whenLoaded(\'%s\'))', $resourceClass, $relation['method']),
            ];

            if (! in_array($resourceFqcn, $imports, true)) {
                $imports[] = $resourceFqcn;
            }
        }

        return [
            'items' => $items,
            'imports' => array_values(array_unique($imports)),
        ];
    }

    /**
     * @return array<string, array{method: string, type: string, target: string}>
     */
    private function buildRelationMapFromModel(array $model): array
    {
        $relations = $model['relations'] ?? [];

        if (! is_array($relations) || $relations === []) {
            return [];
        }

        $map = [];

        foreach ($relations as $relation) {
            if (! is_array($relation)) {
                continue;
            }

            $type = strtolower((string) ($relation['type'] ?? ''));
            $target = $relation['target'] ?? '';

            if (! is_string($target) || $target === '') {
                continue;
            }

            $method = $this->deriveRelationMethod($relation);

            if ($method === null || $method === '') {
                continue;
            }

            $map[$method] = [
                'method' => $method,
                'type' => $type,
                'target' => $target,
            ];
        }

        return $map;
    }

    private function deriveRelationMethod(array $relation): ?string
    {
        $type = strtolower((string) ($relation['type'] ?? ''));
        $target = (string) ($relation['target'] ?? '');

        return match ($type) {
            'belongsto', 'hasone' => $this->deriveBelongsToMethodName($relation, $target),
            'hasmany', 'belongstomany' => Str::camel(Str::plural($target)),
            default => null,
        };
    }

    private function deriveBelongsToMethodName(array $relation, string $target): string
    {
        $candidate = $this->normalizeRelationFieldName($relation['field'] ?? null);

        if ($candidate !== null) {
            return Str::camel($candidate);
        }

        return Str::camel($target);
    }

    private function normalizeRelationFieldName(mixed $field): ?string
    {
        if (! is_string($field) || $field === '') {
            return null;
        }

        $normalized = preg_replace('/_(id|uuid|ulid|guid)$/', '', $field);
        $normalized = preg_replace('/_fk$/', '', (string) $normalized);
        $normalized = trim((string) $normalized, '_');

        if ($normalized === '') {
            return null;
        }

        return $normalized;
    }

    private function normalizeResourceFqcn(string $value, string $fallbackNamespace): string
    {
        $value = trim($value, '\\');

        if ($value === '') {
            return $this->appendNamespace($fallbackNamespace, 'Resource');
        }

        if (! str_contains($value, '\\')) {
            return $this->appendNamespace($fallbackNamespace, $value);
        }

        return $value;
    }

    private function guessResourceFqcn(string $resourceNamespace, string $target): ?string
    {
        $namespace = trim($resourceNamespace, '\\');

        if ($namespace === '') {
            return null;
        }

        $class = Str::studly($target) . 'Resource';

        return $namespace . '\\' . $class;
    }

    private function guessCollectionFqcn(string $resourceNamespace, string $target): ?string
    {
        $namespace = trim($resourceNamespace, '\\');

        if ($namespace === '') {
            return null;
        }

        $class = Str::studly($target) . 'Collection';

        return $namespace . '\\' . $class;
    }

    private function ensureApplicationUserModel(string $architecture, ?array $model, bool $force, bool $dryRun): void
    {
        if ($dryRun) {
            return;
        }

        $context = $this->prepareApplicationUserModelContext($model);

        if ($context === null) {
            return;
        }

        $path = base_path('app/Models/User.php');
        $this->files->ensureDirectoryExists((string) dirname($path));

        $contents = $this->renderTemplate($architecture, 'user-model.stub.twig', $context);

        $previous = $this->getExistingContents($path);

        if ($previous !== null && ! $force) {
            if ($this->normalizePhpContents($previous) === $this->normalizePhpContents($contents)) {
                return;
            }
        }

        if ($this->files->put($path, $contents) !== false) {
            $this->recordWrittenFile($path, $contents, $previous);
        }
    }

    private function prepareApplicationUserModelContext(?array $model): ?array
    {
        $domainFqn = $this->resolveDomainUserFqn($model);

        if ($domainFqn === null) {
            return null;
        }

        return [
            'namespace' => 'App\\Models',
            'domain_fqn' => $domainFqn,
            'domain_alias' => $this->deriveDomainAlias($domainFqn),
        ];
    }

    private function resolveDomainUserFqn(?array $model): ?string
    {
        if ($model === null) {
            return null;
        }

        $module = $model['module'] ?? null;
        $entity = $model['entity'] ?? null;

        if (! is_string($entity) || $entity === '') {
            return null;
        }

        if (! is_string($module) || $module === '') {
            $module = 'Shared';
        }

        $moduleSegments = array_filter(
            array_map('trim', preg_split('/[\\\\\/]+/', (string) $module) ?: []),
            static fn (string $segment): bool => $segment !== ''
        );

        $moduleSegment = $moduleSegments === []
            ? 'Shared'
            : implode('\', array_map(static fn (string $segment): string => Str::studly($segment), $moduleSegments));
        $entitySegment = Str::studly($entity);

        return sprintf('App\\Domain\\%s\\Models\\%s', $moduleSegment, $entitySegment);
    }

    private function deriveDomainAlias(string $domainFqn): string
    {
        $basename = Str::afterLast($domainFqn, '\\');

        if ($basename === '') {
            return 'DomainUser';
        }

        $alias = 'Domain' . $basename;

        if ($alias === $basename) {
            $alias .= 'Base';
        }

        return $alias;
    }

    private function normalizePhpContents(string $contents): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $contents);

        return trim($normalized);
    }

    private function ensureSanctumGuide(string $architecture, bool $sanctumInstalled, bool $force, bool $dryRun): void
    {
        if ($dryRun || $sanctumInstalled) {
            return;
        }

        $path = base_path('docs/README_SANCTUM.md');

        if ($this->files->exists($path) && ! $force) {
            return;
        }

        $this->files->ensureDirectoryExists((string) dirname($path));

        $contents = $this->renderTemplate($architecture, 'sanctum-guide.stub.twig', []);

        $previous = $this->getExistingContents($path);

        if ($this->files->put($path, $contents) !== false) {
            $this->recordWrittenFile($path, $contents, $previous);
        }
    }

    private function ensurePersonalAccessTokensMigration(?array $model, bool $force, bool $dryRun): void
    {
        if ($dryRun || ! $this->authModelUsesUuidPrimaryKey($model)) {
            return;
        }

        $paths = glob(base_path('database/migrations/*create_personal_access_tokens_table.php')) ?: [];

        foreach ($paths as $path) {
            if (! is_string($path) || $path === '') {
                continue;
            }

            if (! $this->files->exists($path)) {
                continue;
            }

            $original = $this->files->get($path);

            if (str_contains($original, 'uuidMorphs(')) {
                continue;
            }

            if (! $force && ! str_contains($original, 'morphs(')) {
                continue;
            }

            $updated = preg_replace(
                '/(->)\s*(nullableMorphs|morphs)\s*\(\s*([\'\"])tokenable\3\s*\)/',
                '$1 uuidMorphs($3tokenable$3)',
                $original,
            );

            if ($updated === null || $updated === $original) {
                continue;
            }

            if ($this->files->put($path, $updated) !== false) {
                $this->recordWrittenFile($path, $updated, $original);
            }
        }
    }

    private function authModelUsesUuidPrimaryKey(?array $model): bool
    {
        if ($model === null) {
            return false;
        }

        $fields = $model['fields'] ?? [];

        if (! is_array($fields)) {
            return false;
        }

        foreach ($fields as $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $name = $definition['name'] ?? null;

            if ($name !== 'id') {
                continue;
            }

            $type = strtolower((string) ($definition['type'] ?? ''));

            if (in_array($type, ['uuid', 'ulid'], true)) {
                return true;
            }
        }

        return false;
    }

    private function splitRules(?string $rules): array
    {
        if ($rules === null) {
            return [];
        }

        $segments = array_map('trim', explode('|', $rules));
        $segments = array_filter($segments, static fn (string $segment): bool => $segment !== '');

        return array_values($segments);
    }

    private function containsRule(array $rules, string $needle): bool
    {
        $needle = strtolower($needle);

        foreach ($rules as $rule) {
            if (strtolower($rule) === $needle) {
                return true;
            }
        }

        return false;
    }

    private function uniqueRules(array $rules): array
    {
        $unique = [];

        foreach ($rules as $rule) {
            if (! in_array($rule, $unique, true)) {
                $unique[] = $rule;
            }
        }

        return $unique;
    }

    private function insertUseStatement(string $normalized, string $statement): string
    {
        $lastUsePos = strrpos($normalized, "\nuse ");

        if ($lastUsePos === false) {
            $openingTagPos = strpos($normalized, "<?php\n");

            if ($openingTagPos === false) {
                return $statement . "\n\n" . ltrim($normalized);
            }

            $offset = $openingTagPos + 6;

            return substr($normalized, 0, $offset) . $statement . "\n" . substr($normalized, $offset);
        }

        $semicolonPos = strpos($normalized, ';', $lastUsePos);

        if ($semicolonPos === false) {
            return $normalized . "\n" . $statement;
        }

        $semicolonPos++;

        return substr($normalized, 0, $semicolonPos) . "\n" . $statement . substr($normalized, $semicolonPos);
    }

    private function restoreLineEndings(string $original, string $normalized): string
    {
        if (str_contains($original, "\r\n")) {
            return str_replace("\n", "\r\n", $normalized);
        }

        return $normalized;
    }

    private function getExistingContents(string $path): ?string
    {
        if (! $this->files->exists($path)) {
            return null;
        }

        try {
            return $this->files->get($path);
        } catch (Throwable) {
            return null;
        }
    }

    private function recordWrittenFile(string $absolutePath, string $contents, ?string $previous): void
    {
        $entry = [
            'layer' => 'auth_scaffolding',
            'path' => $this->relativeToBasePath($absolutePath),
            'full_path' => $this->normalizeAbsolutePath($absolutePath),
            'status' => $previous === null ? 'written' : 'overwritten',
            'bytes' => strlen($contents),
            'checksum' => hash('sha256', $contents),
        ];

        if ($previous !== null) {
            $entry['previous_contents'] = $previous;
            $entry['previous_checksum'] = hash('sha256', $previous);
            $entry['previous_bytes'] = strlen($previous);
        }

        $this->historyEntries[] = $entry;
    }

    private function relativeToBasePath(string $absolutePath): string
    {
        $base = str_replace('\\', '/', base_path());
        $normalized = str_replace('\\', '/', $absolutePath);

        if (! str_ends_with($base, '/')) {
            $base .= '/';
        }

        if (str_starts_with($normalized, $base)) {
            $relative = substr($normalized, strlen($base));
        } else {
            $relative = ltrim($normalized, '/');
        }

        return trim(str_replace('\\', '/', $relative), '/');
    }

    private function normalizeAbsolutePath(string $path): string
    {
        $resolved = realpath($path);

        if ($resolved !== false) {
            return $resolved;
        }

        return $this->normalizePathSeparators($path);
    }

    private function normalizePathSeparators(string $path): string
    {
        return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    private function makeVirtualBlueprint(string $architecture): Blueprint
    {
        $architecture = $architecture !== '' ? $architecture : 'hexagonal';

        return Blueprint::fromArray([
            'path' => 'blueprints/virtual/auth_scaffolding.yaml',
            'module' => 'auth',
            'entity' => 'auth_scaffolding',
            'table' => 'users',
            'architecture' => $architecture,
            'fields' => [],
            'relations' => [],
            'options' => ['virtual' => true],
            'api' => [
                'base_path' => null,
                'middleware' => [],
                'resources' => ['includes' => []],
                'endpoints' => [],
            ],
            'docs' => [],
            'errors' => [],
            'metadata' => [
                'origin' => 'auth_scaffolding',
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function persistHistory(string $architecture, array $options): void
    {
        if ($this->historyEntries === []) {
            return;
        }

        if (! $this->history->isEnabled()) {
            $this->historyEntries = [];

            return;
        }

        $result = new PipelineResult($this->historyEntries);
        $blueprint = $this->makeVirtualBlueprint($architecture);

        $contextOptions = [
            'force' => (bool) ($options['force'] ?? false),
        ];

        if (array_key_exists('sanctum_installed', $options)) {
            $contextOptions['sanctum_installed'] = (bool) $options['sanctum_installed'];
        }

        $context = [
            'execution_id' => $options['execution_id'] ?? null,
            'options' => $contextOptions,
            'filters' => [],
            'warnings' => [],
        ];

        $this->history->record($blueprint, 'virtual/auth_scaffolding.yaml', $result, $context);

        $this->historyEntries = [];
    }

    private function resolveAbsolutePath(string $relative, string $append): string
    {
        $relative = trim(str_replace('\\', '/', $relative), '/');
        $append = trim(str_replace('\\', '/', $append), '/');

        $path = $relative === '' ? $append : $relative . '/' . $append;

        return base_path($path);
    }

    private function normalizeRelativePath(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return '';
        }

        return trim(str_replace('\\', '/', $value), '/');
    }

    private function deriveNamespace(mixed $value, string $fallback): string
    {
        if (! is_string($value) || $value === '') {
            return $fallback;
        }

        $normalized = trim($value);

        $normalized = str_replace('/', '\\', $normalized);
        $normalized = trim($normalized, '\\');

        if ($normalized === '') {
            return $fallback;
        }

        if (strncasecmp($normalized, 'app\\', 4) === 0) {
            $normalized = 'App\\' . substr($normalized, 4);
        }

        if (! str_starts_with($normalized, 'App\\')) {
            $normalized = 'App\\' . ltrim($normalized, '\\');
        }

        return $normalized !== '' ? $normalized : $fallback;
    }

    private function appendNamespace(string $base, string $suffix): string
    {
        $base = trim(str_replace('/', '\\', $base), '\\');

        if ($base === '') {
            return trim($suffix, '\\');
        }

        return $base . '\\' . trim($suffix, '\\');
    }

    private function normalizeArchitecture(mixed $value): string
    {
        if (is_string($value) && $value !== '') {
            return strtolower(trim($value));
        }

        return 'hexagonal';
    }

    private function renderTemplate(string $architecture, string $template, array $context): string
    {
        $candidates = array_unique([$architecture, 'hexagonal']);

        foreach ($candidates as $candidate) {
            $path = sprintf('%s/auth/%s', $candidate, $template);

            if ($this->templates->exists($path)) {
                return $this->templates->render($path, $context);
            }
        }

        throw new RuntimeException(sprintf('No se encontró la plantilla "%s" para el scaffolding de auth.', $template));
    }
}
