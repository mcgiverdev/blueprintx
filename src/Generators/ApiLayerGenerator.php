<?php

namespace BlueprintX\Generators;

use BlueprintX\Blueprint\Blueprint;
use BlueprintX\Blueprint\Endpoint;
use BlueprintX\Blueprint\Field;
use BlueprintX\Blueprint\Relation;
use BlueprintX\Contracts\ArchitectureDriver;
use BlueprintX\Contracts\LayerGenerator;
use BlueprintX\Kernel\Generation\GeneratedFile;
use BlueprintX\Kernel\Generation\GenerationResult;
use BlueprintX\Kernel\TemplateEngine;
use Illuminate\Support\Str;

class ApiLayerGenerator implements LayerGenerator
{
    private const ROLE_MIDDLEWARE_NAME = 'role';
    private const ROLE_MIDDLEWARE_PROVIDER_FQN = 'App\\Providers\\RoleMiddlewareServiceProvider';

    private array $formRequestConfig;

    private array $resourceConfig;

    private array $controllerTraits;

    private array $optimisticLocking;

    private bool $roleMiddlewareDetected = false;

    private bool $roleMiddlewareEnsured = false;

    public function __construct(private readonly TemplateEngine $templates, array $formRequestConfig = [], array $resourceConfig = [], array $controllerTraits = [], array $optimisticLocking = [])
    {
        $this->formRequestConfig = $this->normalizeFormRequestConfig($formRequestConfig);
        $this->resourceConfig = $this->normalizeResourceConfig($resourceConfig);
        $this->controllerTraits = $this->normalizeControllerTraits($controllerTraits);
        $this->optimisticLocking = $this->normalizeOptimisticLockingConfig($optimisticLocking);
    }

    public function layer(): string
    {
        return 'api';
    }

    /**
     * @param array<string, mixed> $options
     */
    public function generate(Blueprint $blueprint, ArchitectureDriver $driver, array $options = []): GenerationResult
    {
        $this->roleMiddlewareDetected = false;

        $result = new GenerationResult();

        $controllerTemplate = sprintf('@%s/api/controller.stub.twig', $driver->name());
        if (! $this->templates->exists($controllerTemplate)) {
            $result->addWarning(sprintf('No se encontró la plantilla para la capa api en "%s".', $driver->name()));

            return $result;
        }

        $formRequestTemplate = sprintf('@%s/api/form-request.stub.twig', $driver->name());
        $resourceTemplate = sprintf('@%s/api/resource.stub.twig', $driver->name());
        $resourceCollectionTemplate = sprintf('@%s/api/resource-collection.stub.twig', $driver->name());

        $formRequestOptions = $this->resolveFormRequestOptions($options);
        $resourceOptions = $this->resolveResourceOptions($options);

        $formRequestDefinitions = null;

        if ($formRequestOptions['enabled']) {
            if (! $this->templates->exists($formRequestTemplate)) {
                $result->addWarning(sprintf('No se encontró la plantilla para los FormRequests en "%s".', $driver->name()));
            } else {
                $formRequestDefinitions = $this->buildFormRequestDefinitions($blueprint, $options, $formRequestOptions);
            }
        }

        $resourceTemplateExists = $this->templates->exists($resourceTemplate);
        $collectionTemplateExists = $this->templates->exists($resourceCollectionTemplate);

        if ($resourceOptions['enabled']) {
            if (! $resourceTemplateExists) {
                $result->addWarning(sprintf('No se encontró la plantilla para los Resources en "%s".', $driver->name()));
            }

            if (! $collectionTemplateExists) {
                $result->addWarning(sprintf('No se encontró la plantilla para las ResourceCollections en "%s".', $driver->name()));
            }
        }

        $resourceDefinitions = $this->buildResourceDefinitions(
            $blueprint,
            $options,
            $resourceOptions,
            $resourceTemplateExists && $collectionTemplateExists
        );

        $context = $this->buildContext(
            $blueprint,
            $driver,
            $options,
            $formRequestDefinitions['controller'] ?? ['enabled' => false],
            $resourceDefinitions['controller']
        );

        $controllerContents = $this->templates->render($controllerTemplate, $context);
        $controllerPath = $this->buildPath($blueprint, $options);

        $result->addFile(new GeneratedFile($controllerPath, $controllerContents));

        if ($resourceDefinitions['controller']['enabled']) {
            foreach ($resourceDefinitions['files'] as $definition) {
                $templateKey = $definition['template'];
                $templatePath = match ($templateKey) {
                    'resource' => $resourceTemplate,
                    'collection' => $resourceCollectionTemplate,
                    default => null,
                };

                if ($templatePath === null) {
                    continue;
                }

                $resourceContents = $this->templates->render($templatePath, $definition['context']);
                $result->addFile(new GeneratedFile($definition['path'], $resourceContents));
            }
        }

        if ($formRequestDefinitions !== null) {
            foreach ($formRequestDefinitions['files'] as $definition) {
                $requestContents = $this->templates->render($formRequestTemplate, $definition['context']);
                $result->addFile(new GeneratedFile($definition['path'], $requestContents));
            }
        }

        $routeFile = $this->updateRouteFile($blueprint, $context);

        if ($routeFile !== null) {
            $result->addFile($routeFile);
        }

        if ($this->roleMiddlewareDetected && ! $this->roleMiddlewareEnsured) {
            foreach ($this->ensureRoleMiddlewareArtifacts() as $artifact) {
                $result->addFile($artifact);
            }

            $this->roleMiddlewareEnsured = true;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function buildContext(Blueprint $blueprint, ArchitectureDriver $driver, array $options, array $formRequests = [], array $resources = []): array
    {
        $entity = [
            'name' => $blueprint->entity(),
            'module' => $blueprint->module(),
            'table' => $blueprint->table(),
            'fields' => array_map(static fn (Field $field): array => $field->toArray(), $blueprint->fields()),
            'relations' => array_map(static fn (Relation $relation): array => $relation->toArray(), $blueprint->relations()),
            'endpoints' => array_map(static fn (Endpoint $endpoint): array => $endpoint->toArray(), $blueprint->endpoints()),
        ];

        $namespaces = $this->deriveNamespaces($blueprint, $options);
        $optimisticLocking = $this->buildOptimisticLockingContext($blueprint);
        $controllerTraits = $this->finalizeControllerTraits([
            'optimistic_locking' => $optimisticLocking['enabled'] ?? false,
        ]);

        return [
            'blueprint' => $blueprint->toArray(),
            'entity' => $entity,
            'module' => $this->moduleSegment($blueprint),
            'namespaces' => $namespaces,
            'application' => $this->deriveApplicationNamespaces($blueprint, $options),
            'naming' => $this->namingContext($blueprint),
            'model' => $this->deriveModelContext($blueprint),
            'routes' => [
                'resource' => $this->resolveResourceRoute($blueprint),
            ],
            'driver' => [
                'name' => $driver->name(),
                'layers' => $driver->layers(),
                'metadata' => $driver->metadata(),
            ],
            'controller' => [
                'traits' => $controllerTraits,
            ],
            'form_requests' => array_merge(['enabled' => false, 'imports' => []], $formRequests),
            'resources' => array_merge(
                [
                    'enabled' => false,
                    'imports' => [],
                    'resource_class' => null,
                    'resource_fqcn' => null,
                    'collection_class' => null,
                    'collection_fqcn' => null,
                    'preserve_query' => true,
                    'includes' => [],
                    'includes_literal' => '[]',
                ],
                $resources
            ),
            'optimistic_locking' => $optimisticLocking,
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, string>
     */
    private function deriveNamespaces(Blueprint $blueprint, array $options): array
    {
        $base = trim($options['namespaces']['api'] ?? 'App\\Http\\Controllers\\Api', '\\');
        $module = $this->moduleSegment($blueprint);
        $root = $base;

        if ($module !== null) {
            $root .= '\\' . $module;
        }

        return [
            'api_root' => $root,
        ];
    }

    private function resolveResourceRoute(Blueprint $blueprint): string
    {
        $basePath = $blueprint->apiBasePath();

        if (is_string($basePath) && $basePath !== '') {
            return '/' . ltrim($basePath, '/');
        }

        $resource = Str::kebab(Str::pluralStudly($blueprint->entity()));

        return '/' . $resource;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function buildPath(Blueprint $blueprint, array $options): string
    {
        $basePath = rtrim($options['paths']['api'] ?? 'app/Http/Controllers/Api', '/');
        $module = $this->moduleSegment($blueprint);
        $modulePath = $module !== null ? str_replace('\\', '/', $module) : null;

        $root = $basePath;

        if ($modulePath !== null) {
            $root .= '/' . $modulePath;
        }

        $entityName = Str::studly($blueprint->entity());

        return sprintf('%s/%sController.php', $root, $entityName);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, string>
     */
    private function deriveApplicationNamespaces(Blueprint $blueprint, array $options): array
    {
        $base = trim($options['namespaces']['application'] ?? 'App\\Application', '\\');
        $module = $this->moduleSegment($blueprint);
        $root = $base;

        if ($module !== null) {
            $root .= '\\' . $module;
        }

        return [
            'commands' => $root . '\\Commands',
            'queries' => $root . '\\Queries',
        ];
    }

    private function namingContext(Blueprint $blueprint): array
    {
        $entityName = Str::studly($blueprint->entity());

        return [
            'entity_studly' => $entityName,
            'entity_plural_studly' => Str::pluralStudly($entityName),
            'entity_variable' => Str::camel($blueprint->entity()),
        ];
    }

    private function moduleSegment(Blueprint $blueprint): ?string
    {
        $module = $blueprint->module();

        if ($module === null || $module === '') {
            return null;
        }

        $normalized = str_replace('\\', '/', $module);
        $segments = array_filter(array_map('trim', explode('/', $normalized)), static fn (string $part): bool => $part !== '');

        if ($segments === []) {
            return null;
        }

        $studlySegments = array_map(static fn (string $part): string => Str::studly($part), $segments);

        return implode('\\', $studlySegments);
    }

    private function deriveModelContext(Blueprint $blueprint): array
    {
    $identifierField = 'id';
    $identifierPhpType = 'int';

        foreach ($blueprint->fields() as $field) {
            if ($field->name !== 'id') {
                continue;
            }

            $type = strtolower($field->type);

            $identifierPhpType = match ($type) {
                'uuid', 'guid', 'string', 'ulid' => 'string',
                'integer', 'increments', 'bigincrements', 'id', 'bigint', 'biginteger', 'unsignedbiginteger' => 'int',
                default => 'int|string',
            };

            break;
        }

        return [
            'identifier' => [
                'field' => $identifierField,
                'php_type' => $identifierPhpType,
            ],
        ];
    }

    private function buildOptimisticLockingContext(Blueprint $blueprint): array
    {
        if (! ($this->optimisticLocking['enabled'] ?? false)) {
            return ['enabled' => false];
        }

        $options = $blueprint->options();

        $timestamps = array_key_exists('timestamps', $options) ? (bool) $options['timestamps'] : true;
        $versioned = array_key_exists('versioned', $options) ? (bool) $options['versioned'] : false;

        $versionField = $this->optimisticLocking['version_field'] ?? 'version';
        $strategy = null;

        if ($versioned && $this->hasField($blueprint, $versionField)) {
            $strategy = 'version';
        } elseif ($timestamps) {
            $strategy = 'timestamp';
        }

        if ($strategy === null) {
            return ['enabled' => false];
        }

        return [
            'enabled' => true,
            'header' => $this->optimisticLocking['header'],
            'response_header' => $this->optimisticLocking['response_header'],
            'strategy' => $strategy,
            'version_field' => $versionField,
            'timestamp_column' => $this->optimisticLocking['timestamp_column'],
            'require_header' => $this->optimisticLocking['require_header'],
            'allow_wildcard' => $this->optimisticLocking['allow_wildcard'],
        ];
    }

    private function hasField(Blueprint $blueprint, string $name): bool
    {
        foreach ($blueprint->fields() as $field) {
            if ($field->name === $name) {
                return true;
            }
        }

        return false;
    }

    private function normalizeControllerTraits(array $config): array
    {
        $defaults = [
            'handles_domain_exceptions' => 'BlueprintX\\Support\\Http\\Controllers\\Concerns\\HandlesDomainExceptions',
            'formats_pagination' => 'BlueprintX\\Support\\Http\\Controllers\\Concerns\\FormatsPagination',
            'optimistic_locking' => 'BlueprintX\\Support\\Http\\Controllers\\Concerns\\HandlesOptimisticLocking',
        ];

        $map = [];

        foreach ($defaults as $key => $value) {
            $enabledByDefault = $key !== 'optimistic_locking';
            $fqcn = $value;

            if (array_key_exists($key, $config)) {
                $candidate = $config[$key];

                if (is_string($candidate) && $candidate !== '') {
                    $fqcn = trim($candidate, '\\');
                } elseif ($candidate === null || $candidate === false || $candidate === '') {
                    $fqcn = null;
                }
            }

            if (! is_string($fqcn) || $fqcn === '') {
                $map[$key] = [
                    'enabled' => false,
                    'fqcn' => null,
                    'class' => null,
                ];

                continue;
            }

            $normalized = trim($fqcn, '\\');
            $map[$key] = [
                'enabled' => $enabledByDefault,
                'fqcn' => $normalized,
                'class' => Str::afterLast($normalized, '\\'),
            ];
        }

        return $this->finalizeTraitMap($map);
    }

    private function finalizeControllerTraits(array $overrides = []): array
    {
        $map = $this->controllerTraits['map'];

        foreach ($overrides as $key => $enabled) {
            if (! isset($map[$key])) {
                continue;
            }

            $map[$key]['enabled'] = (bool) $enabled && is_string($map[$key]['fqcn']);
        }

        return $this->finalizeTraitMap($map);
    }

    private function finalizeTraitMap(array $map): array
    {
        $imports = [];
        $uses = [];

        foreach ($map as $definition) {
            if (($definition['enabled'] ?? false) && isset($definition['fqcn']) && is_string($definition['fqcn']) && $definition['fqcn'] !== '') {
                $imports[] = $definition['fqcn'];
                $uses[] = $definition['class'];
            }
        }

        return [
            'imports' => array_values(array_unique($imports)),
            'uses' => array_values(array_unique($uses)),
            'map' => $map,
        ];
    }

    private function normalizeFormRequestConfig(array $config): array
    {
        $defaults = [
            'enabled' => false,
            'namespace' => 'App\\Http\\Requests\\Api',
            'path' => 'app/Http/Requests/Api',
            'authorize_by_default' => true,
        ];

        if (array_key_exists('enabled', $config)) {
            $defaults['enabled'] = (bool) $config['enabled'];
        }

        if (array_key_exists('namespace', $config) && is_string($config['namespace']) && $config['namespace'] !== '') {
            $defaults['namespace'] = $config['namespace'];
        }

        if (array_key_exists('path', $config) && is_string($config['path']) && $config['path'] !== '') {
            $defaults['path'] = $config['path'];
        }

        if (array_key_exists('authorize_by_default', $config)) {
            $defaults['authorize_by_default'] = (bool) $config['authorize_by_default'];
        }

        $defaults['namespace'] = $this->normalizeNamespace($defaults['namespace']);
        $defaults['path'] = $this->normalizePath($defaults['path']);

        return $defaults;
    }

    private function normalizeOptimisticLockingConfig(array $config): array
    {
        $defaults = [
            'enabled' => true,
            'header' => 'If-Match',
            'response_header' => 'ETag',
            'timestamp_column' => 'updated_at',
            'version_field' => 'version',
            'require_header' => true,
            'allow_wildcard' => true,
        ];

        foreach ($config as $key => $value) {
            if (! array_key_exists($key, $defaults)) {
                continue;
            }

            if (in_array($key, ['enabled', 'require_header', 'allow_wildcard'], true)) {
                $defaults[$key] = (bool) $value;

                continue;
            }

            if (is_string($value) && $value !== '') {
                $defaults[$key] = $value;
            }
        }

        return $defaults;
    }

    private function resolveFormRequestOptions(array $options): array
    {
        $resolved = $this->formRequestConfig;

        if (isset($options['form_requests']) && is_array($options['form_requests'])) {
            $incoming = $options['form_requests'];

            if (array_key_exists('enabled', $incoming)) {
                $resolved['enabled'] = (bool) $incoming['enabled'];
            }

            if (isset($incoming['namespace']) && is_string($incoming['namespace']) && $incoming['namespace'] !== '') {
                $resolved['namespace'] = $this->normalizeNamespace($incoming['namespace']);
            }

            if (isset($incoming['path']) && is_string($incoming['path']) && $incoming['path'] !== '') {
                $resolved['path'] = $this->normalizePath($incoming['path']);
            }

            if (array_key_exists('authorize_by_default', $incoming)) {
                $resolved['authorize_by_default'] = (bool) $incoming['authorize_by_default'];
            }
        }

        return $resolved;
    }

    private function normalizeResourceConfig(array $config): array
    {
        $defaults = [
            'enabled' => true,
            'namespace' => 'App\\Http\\Resources',
            'path' => 'app/Http/Resources',
            'preserve_query' => true,
        ];

        if (array_key_exists('enabled', $config)) {
            $defaults['enabled'] = (bool) $config['enabled'];
        }

        if (array_key_exists('namespace', $config) && is_string($config['namespace']) && $config['namespace'] !== '') {
            $defaults['namespace'] = $config['namespace'];
        }

        if (array_key_exists('path', $config) && is_string($config['path']) && $config['path'] !== '') {
            $defaults['path'] = $config['path'];
        }

        if (array_key_exists('preserve_query', $config)) {
            $defaults['preserve_query'] = (bool) $config['preserve_query'];
        }

        $defaults['namespace'] = $this->normalizeNamespace($defaults['namespace'], 'App\\Http\\Resources');
        $defaults['path'] = $this->normalizePath($defaults['path'], 'app/Http/Resources');

        return $defaults;
    }

    private function resolveResourceOptions(array $options): array
    {
        $resolved = $this->resourceConfig;

        if (isset($options['resources']) && is_array($options['resources'])) {
            $incoming = $options['resources'];

            if (array_key_exists('enabled', $incoming)) {
                $resolved['enabled'] = (bool) $incoming['enabled'];
            }

            if (isset($incoming['namespace']) && is_string($incoming['namespace']) && $incoming['namespace'] !== '') {
                $resolved['namespace'] = $this->normalizeNamespace($incoming['namespace'], 'App\\Http\\Resources');
            }

            if (isset($incoming['path']) && is_string($incoming['path']) && $incoming['path'] !== '') {
                $resolved['path'] = $this->normalizePath($incoming['path'], 'app/Http/Resources');
            }

            if (array_key_exists('preserve_query', $incoming)) {
                $resolved['preserve_query'] = (bool) $incoming['preserve_query'];
            }
        }

        if (isset($options['namespaces']['api_resources']) && is_string($options['namespaces']['api_resources']) && $options['namespaces']['api_resources'] !== '') {
            $resolved['namespace'] = $this->normalizeNamespace($options['namespaces']['api_resources'], 'App\\Http\\Resources');
        }

        if (isset($options['paths']['api_resources']) && is_string($options['paths']['api_resources']) && $options['paths']['api_resources'] !== '') {
            $resolved['path'] = $this->normalizePath($options['paths']['api_resources'], 'app/Http/Resources');
        }

        return $resolved;
    }

    private function buildResourceDefinitions(Blueprint $blueprint, array $options, array $resourceOptions, bool $templatesAvailable): array
    {
        $enabled = (bool) $resourceOptions['enabled'] && $templatesAvailable;
        $preserveQuery = (bool) ($resourceOptions['preserve_query'] ?? true);

        if (! $enabled) {
            return [
                'controller' => [
                    'enabled' => false,
                    'imports' => [],
                    'resource_class' => null,
                    'resource_fqcn' => null,
                    'collection_class' => null,
                    'collection_fqcn' => null,
                    'preserve_query' => $preserveQuery,
                ],
                'files' => [],
            ];
        }

        $namespace = $this->deriveResourceNamespace($blueprint, $options, $resourceOptions);
        $pathRoot = $this->deriveResourcePath($blueprint, $options, $resourceOptions);
        $entityStudly = Str::studly($blueprint->entity());

        $resourceClass = $entityStudly . 'Resource';
        $collectionClass = $entityStudly . 'Collection';

        $resourceAttributes = $this->buildResourceAttributes($blueprint);
        $resourceRelationships = $this->buildResourceRelationships($blueprint, $namespace);

        $resourceContext = [
            'resource' => [
                'namespace' => $namespace,
                'class' => $resourceClass,
                'attributes' => $resourceAttributes,
                'relationships' => $resourceRelationships['items'],
                'imports' => $resourceRelationships['imports'],
            ],
        ];

        $collectionContext = [
            'collection' => [
                'namespace' => $namespace,
                'class' => $collectionClass,
                'resource_class' => $resourceClass,
                'preserve_query' => $preserveQuery,
            ],
        ];

        $files = [
            [
                'template' => 'resource',
                'path' => sprintf('%s/%s.php', $pathRoot, $resourceClass),
                'context' => $resourceContext,
            ],
            [
                'template' => 'collection',
                'path' => sprintf('%s/%s.php', $pathRoot, $collectionClass),
                'context' => $collectionContext,
            ],
        ];

        $imports = [
            $namespace . '\\' . $resourceClass,
            $namespace . '\\' . $collectionClass,
        ];

        return [
            'controller' => [
                'enabled' => true,
                'imports' => array_values(array_unique($imports)),
                'resource_class' => $resourceClass,
                'resource_fqcn' => $namespace . '\\' . $resourceClass,
                'collection_class' => $collectionClass,
                'collection_fqcn' => $namespace . '\\' . $collectionClass,
                'preserve_query' => $preserveQuery,
                'includes' => $resourceRelationships['includes'],
                'includes_literal' => $resourceRelationships['includes_literal'],
            ],
            'files' => $files,
        ];
    }

    private function deriveResourceNamespace(Blueprint $blueprint, array $options, array $resourceOptions): string
    {
        $base = $resourceOptions['namespace'];

        if (isset($options['namespaces']['api_resources']) && is_string($options['namespaces']['api_resources']) && $options['namespaces']['api_resources'] !== '') {
            $base = $this->normalizeNamespace($options['namespaces']['api_resources'], 'App\\Http\\Resources');
        }

        $module = $this->moduleSegment($blueprint);

        if ($module !== null) {
            $base .= '\\' . $module;
        }

        return $base;
    }

    private function deriveResourcePath(Blueprint $blueprint, array $options, array $resourceOptions): string
    {
        $base = $resourceOptions['path'];

        if (isset($options['paths']['api_resources']) && is_string($options['paths']['api_resources']) && $options['paths']['api_resources'] !== '') {
            $base = $this->normalizePath($options['paths']['api_resources'], 'app/Http/Resources');
        }

        $module = $this->moduleSegment($blueprint);

        if ($module !== null) {
            $base .= '/' . str_replace('\\', '/', $module);
        }

        return $base;
    }

    /**
     * @return array<int, string>
     */
    private function buildResourceAttributes(Blueprint $blueprint): array
    {
        $attributes = ['id'];

        foreach ($blueprint->fields() as $field) {
            $attributes[] = $field->name;
        }

        $options = $blueprint->options();
        $timestampsEnabled = array_key_exists('timestamps', $options)
            ? (bool) $options['timestamps']
            : true;

        if ($timestampsEnabled) {
            $attributes[] = 'created_at';
            $attributes[] = 'updated_at';
        }

        if ((bool) ($options['softDeletes'] ?? false)) {
            $attributes[] = 'deleted_at';
        }

        $attributes = array_values(array_unique(array_filter($attributes, static fn ($attribute): bool => is_string($attribute) && $attribute !== '')));

        return $attributes;
    }

    /**
     * @return array{
     *     items: array<int, array<string, string>>,
     *     imports: array<int, string>,
     *     includes: array<int, string>,
     *     includes_literal: string
     * }
     */
    private function buildResourceRelationships(Blueprint $blueprint, string $resourceNamespace): array
    {
        $config = $blueprint->apiResources();
        $includesConfig = $config['includes'] ?? [];

        if (! is_array($includesConfig) || $includesConfig === []) {
            return [
                'items' => [],
                'imports' => [],
                'includes' => [],
                'includes_literal' => '[]',
            ];
        }

        $relationMap = $this->buildRelationMap($blueprint);

        if ($relationMap === []) {
            return [
                'items' => [],
                'imports' => [],
                'includes' => [],
                'includes_literal' => '[]',
            ];
        }

        $items = [];
        $imports = [];
        $includes = [];
        $namespace = trim($resourceNamespace, '\\');

        foreach ($includesConfig as $definition) {
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
                    $resourceOverride = trim($definition['resource'], '\\');
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

            $resourceFqcn = $resourceOverride;

            if (in_array($relation['type'], ['belongsto', 'hasone'], true)) {
                if ($resourceFqcn === null) {
                    $resourceFqcn = $this->guessResourceFqcn($namespace, $relation['target']);
                }

                if ($resourceFqcn === null) {
                    continue;
                }

                $resourceClass = Str::afterLast($resourceFqcn, '\\');

                $items[] = [
                    'property' => $alias,
                    'relation' => $relation['method'],
                    'resource_class' => $resourceClass,
                    'expression' => sprintf('%s::make($this->whenLoaded(\'%s\'))', $resourceClass, $relation['method']),
                ];

                if (! in_array($resourceFqcn, $imports, true)) {
                    $imports[] = $resourceFqcn;
                }
            } elseif (in_array($relation['type'], ['hasmany', 'belongstomany'], true)) {
                if ($resourceFqcn === null) {
                    $resourceFqcn = $this->guessCollectionFqcn($namespace, $relation['target']);
                }

                if ($resourceFqcn === null) {
                    continue;
                }

                $resourceClass = Str::afterLast($resourceFqcn, '\\');

                $items[] = [
                    'property' => $alias,
                    'relation' => $relation['method'],
                    'resource_class' => $resourceClass,
                    'expression' => sprintf('%s::make($this->whenLoaded(\'%s\'))', $resourceClass, $relation['method']),
                ];

                if (! in_array($resourceFqcn, $imports, true)) {
                    $imports[] = $resourceFqcn;
                }
            } else {
                continue;
            }

            if (! in_array($relation['method'], $includes, true)) {
                $includes[] = $relation['method'];
            }
        }

        $includes = array_values(array_unique($includes));

        return [
            'items' => $items,
            'imports' => $imports,
            'includes' => $includes,
            'includes_literal' => $this->formatArrayLiteral($includes),
        ];
    }

    /**
     * @return array<string, array{method:string,type:string,target:string}>
     */
    private function buildRelationMap(Blueprint $blueprint): array
    {
        $map = [];

        foreach ($blueprint->relations() as $relation) {
            $type = strtolower($relation->type);
            $target = $relation->target;

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

    private function deriveRelationMethod(Relation $relation): ?string
    {
        $type = strtolower($relation->type);
        $target = $relation->target;

        return match ($type) {
            'belongsto', 'hasone' => $this->deriveBelongsToMethodName($relation, $target),
            'hasmany', 'belongstomany' => Str::camel(Str::plural($target)),
            default => null,
        };
    }

    private function deriveBelongsToMethodName(Relation $relation, string $target): string
    {
        $candidate = $this->normalizeRelationField($relation->field);

        if ($candidate !== null) {
            return Str::camel($candidate);
        }

        return Str::camel($target);
    }

    private function normalizeRelationField(?string $field): ?string
    {
        if ($field === null || $field === '') {
            return null;
        }

        $normalized = preg_replace('/_(id|uuid|ulid|guid)$/', '', $field);
        $normalized = preg_replace('/_fk$/', '', $normalized ?? '');
        $normalized = trim((string) $normalized, '_');

        if ($normalized === '') {
            return null;
        }

        return $normalized;
    }

    private function formatArrayLiteral(array $values): string
    {
        if ($values === []) {
            return '[]';
        }

        $filtered = array_values(array_filter($values, static fn ($value): bool => is_string($value) && $value !== ''));

        if ($filtered === []) {
            return '[]';
        }

        $encoded = array_map(static function (string $value): string {
            $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], $value);

            return "'" . $escaped . "'";
        }, $filtered);

        return '[' . implode(', ', $encoded) . ']';
    }

    private function normalizeNamespace(string $namespace, string $default = 'App\\Http\\Requests\\Api'): string
    {
        $namespace = trim($namespace);

        if ($namespace === '') {
            return $default;
        }

        return trim($namespace, '\\');
    }

    private function normalizePath(string $path, string $default = 'app/Http/Requests/Api'): string
    {
        $path = str_replace('\\', '/', trim($path));

        if ($path === '') {
            return $default;
        }

        return trim($path, '/');
    }

    private function buildFormRequestDefinitions(Blueprint $blueprint, array $options, array $formRequestOptions): array
    {
        $namespace = $this->deriveRequestNamespace($blueprint, $options, $formRequestOptions);
        $pathRoot = $this->deriveRequestPath($blueprint, $options, $formRequestOptions);
        $entityStudly = Str::studly($blueprint->entity());

        $storeRules = $this->buildStoreRules($blueprint);
        $updateRules = $this->buildUpdateRules($blueprint);

        $definitions = [];
        $imports = [];

        $storeDefinition = $this->makeRequestDefinition($namespace, $pathRoot, 'Store' . $entityStudly . 'Request', $storeRules, (bool) $formRequestOptions['authorize_by_default']);
        $definitions[] = $storeDefinition;
        $imports[] = $storeDefinition['fqcn'];

        $updateDefinition = $this->makeRequestDefinition($namespace, $pathRoot, 'Update' . $entityStudly . 'Request', $updateRules, (bool) $formRequestOptions['authorize_by_default']);
        $definitions[] = $updateDefinition;
        $imports[] = $updateDefinition['fqcn'];

        $files = [];

        foreach ($definitions as $definition) {
            $files[] = [
                'path' => $definition['path'],
                'context' => [
                    'request' => [
                        'namespace' => $namespace,
                        'class' => $definition['class'],
                        'authorize' => $definition['authorize'],
                        'rules' => $definition['rules_for_template'],
                        'has_rules' => $definition['has_rules'],
                    ],
                ],
            ];
        }

        return [
            'controller' => [
                'enabled' => true,
                'imports' => array_values(array_unique($imports)),
                'store' => [
                    'class' => $storeDefinition['class'],
                    'fqcn' => $storeDefinition['fqcn'],
                ],
                'update' => [
                    'class' => $updateDefinition['class'],
                    'fqcn' => $updateDefinition['fqcn'],
                ],
            ],
            'files' => $files,
        ];
    }

    private function deriveRequestNamespace(Blueprint $blueprint, array $options, array $formRequestOptions): string
    {
        $base = $formRequestOptions['namespace'];

        if (isset($options['namespaces']['api_requests']) && is_string($options['namespaces']['api_requests']) && $options['namespaces']['api_requests'] !== '') {
            $base = $this->normalizeNamespace($options['namespaces']['api_requests']);
        }

        $module = $this->moduleSegment($blueprint);

        if ($module !== null) {
            $base .= '\\' . $module;
        }

        return $base;
    }

    private function deriveRequestPath(Blueprint $blueprint, array $options, array $formRequestOptions): string
    {
        $base = $formRequestOptions['path'];

        if (isset($options['paths']['api_requests']) && is_string($options['paths']['api_requests']) && $options['paths']['api_requests'] !== '') {
            $base = $this->normalizePath($options['paths']['api_requests']);
        }

        $module = $this->moduleSegment($blueprint);

        if ($module !== null) {
            $base .= '/' . str_replace('\\', '/', $module);
        }

        return trim($base, '/');
    }

    private function makeRequestDefinition(string $namespace, string $pathRoot, string $class, array $rules, bool $authorize): array
    {
        $pathRoot = trim($pathRoot, '/');
        $path = $pathRoot === '' ? $class . '.php' : $pathRoot . '/' . $class . '.php';

        return [
            'class' => $class,
            'fqcn' => $namespace . '\\' . $class,
            'path' => $path,
            'authorize' => $authorize,
            'rules' => $rules,
            'rules_for_template' => $this->formatRulesForTemplate($rules),
            'has_rules' => $rules !== [],
        ];
    }

    private function buildStoreRules(Blueprint $blueprint): array
    {
        $rules = [];

        foreach ($blueprint->fields() as $field) {
            $fieldRules = $this->rulesFromField($field, false);

            if ($fieldRules !== []) {
                $rules[$field->name] = $fieldRules;
            }
        }

        foreach ($blueprint->relations() as $relation) {
            $relationRules = $this->rulesFromRelation($relation, false);

            if ($relationRules === []) {
                continue;
            }

            if (isset($rules[$relation->field])) {
                $rules[$relation->field] = $this->mergeRuleSet($rules[$relation->field], $relationRules);
            } else {
                $rules[$relation->field] = $relationRules;
            }
        }

        return $rules;
    }

    private function buildUpdateRules(Blueprint $blueprint): array
    {
        $rules = [];

        foreach ($blueprint->fields() as $field) {
            $fieldRules = $this->rulesFromField($field, true);

            if ($fieldRules !== []) {
                $rules[$field->name] = $fieldRules;
            }
        }

        foreach ($blueprint->relations() as $relation) {
            $relationRules = $this->rulesFromRelation($relation, true);

            if ($relationRules === []) {
                continue;
            }

            if (isset($rules[$relation->field])) {
                $rules[$relation->field] = $this->mergeRuleSet($rules[$relation->field], $relationRules);
            } else {
                $rules[$relation->field] = $relationRules;
            }
        }

        return $rules;
    }

    private function rulesFromField(Field $field, bool $forUpdate): array
    {
        $rules = $this->splitRules($field->rules);

        if ($field->nullable === true && ! $this->containsRule($rules, 'nullable')) {
            $rules[] = 'nullable';
        }

        if ($forUpdate && ! $this->containsRule($rules, 'sometimes')) {
            array_unshift($rules, 'sometimes');
        }

        return $this->uniqueRules($rules);
    }

    private function rulesFromRelation(Relation $relation, bool $forUpdate): array
    {
        $rules = $this->splitRules($relation->rules);

        if ($forUpdate) {
            if (! $this->containsRule($rules, 'sometimes')) {
                array_unshift($rules, 'sometimes');
            }
        }

        return $this->uniqueRules($rules);
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

    private function mergeRuleSet(array $current, array $additional): array
    {
        foreach ($additional as $rule) {
            if (! in_array($rule, $current, true)) {
                $current[] = $rule;
            }
        }

        return $current;
    }

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

    private function updateRouteFile(Blueprint $blueprint, array $context): ?GeneratedFile
    {
        $routeTarget = $this->determineRouteTarget($blueprint);
        $routesRelative = $routeTarget['relative'];
        $routesPath = $this->resolveRoutesFilePath($routesRelative);

        $existingContents = null;

        if ($routesPath !== null && is_file($routesPath)) {
            $existingContents = @file_get_contents($routesPath) ?: '';
        }

        $original = $existingContents ?? $this->defaultRoutesFileStub();
        $isNewFile = $existingContents === null;

        $normalized = str_replace(["\r\n", "\r"], "\n", $original);

        if (! $this->hasRouteFacadeImport($normalized)) {
            $normalized = $this->insertUseStatement($normalized, 'use Illuminate\\Support\\Facades\\Route;');
        }

        $controllerNamespace = trim($context['namespaces']['api_root'] ?? '', '\\');
        $controllerBase = $context['naming']['entity_studly'] ?? null;

        if (! is_string($controllerBase) || $controllerBase === '') {
            return null;
        }

        $controllerShort = $controllerBase . 'Controller';
        $controllerFqcn = $controllerNamespace !== '' ? $controllerNamespace . '\\' . $controllerShort : $controllerShort;

        $importResult = $this->ensureClassImport($normalized, $controllerFqcn, $routeTarget['mode']);
        $normalized = $importResult['contents'];
        $controllerAlias = $importResult['alias'];

        $resourcePath = $context['routes']['resource'] ?? null;

        if (! is_string($resourcePath) || $resourcePath === '') {
            $resourcePath = $this->resolveResourceRoute($blueprint);
        }

        $routeUri = ltrim((string) $resourcePath, '/');

        if ($routeUri === '') {
            return null;
        }

        $middleware = $this->sanitizeMiddleware($blueprint->apiMiddleware());

        $existingRoute = $this->findRouteRegistration($normalized, $controllerAlias);
        $normalizedTargetUri = $this->normalizeRouteUri($routeUri);
        $hasChanges = false;

        if ($existingRoute !== null) {
            $existingUri = $this->normalizeRouteUri($existingRoute['uri']);

            if ($existingUri !== $normalizedTargetUri) {
                $replacement = $this->buildRouteLine($controllerAlias, $routeUri, $existingRoute['indent']);
                $normalized = substr_replace($normalized, $replacement, $existingRoute['offset'], $existingRoute['length']);
                $hasChanges = true;
            }

            $updated = $this->restoreLineEndings($original, $normalized);

            if (! $hasChanges && $updated === $original) {
                return null;
            }

            return new GeneratedFile($routesRelative, $updated, ! $isNewFile);
        }

        $routeBlock = $this->buildRouteBlock($routeUri, $controllerAlias, $middleware);

        $normalized = rtrim($normalized, "\n") . "\n\n" . $routeBlock . "\n";

        $updated = $this->restoreLineEndings($original, $normalized);

        if ($updated === $original) {
            return null;
        }

        return new GeneratedFile($routesRelative, $updated, ! $isNewFile);
    }

    private function resolveRoutesFilePath(string $routesRelative): ?string
    {
        if (function_exists('app')) {
            $application = app();

            if (is_object($application) && method_exists($application, 'basePath')) {
                try {
                    $resolved = $application->basePath($routesRelative);

                    if (is_string($resolved) && $resolved !== '') {
                        return $resolved;
                    }
                } catch (\Throwable) {
                    return null;
                }
            }
        }

        if (function_exists('base_path')) {
            try {
                $resolved = base_path($routesRelative);

                return is_string($resolved) && $resolved !== '' ? $resolved : null;
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * @return array<int, GeneratedFile>
     */
    private function ensureRoleMiddlewareArtifacts(): array
    {
        $artifacts = [];

        $middlewareRelative = 'app/Http/Middleware/EnsureUserHasRole.php';
        $middlewarePath = $this->resolveRoutesFilePath($middlewareRelative);
        $middlewareContents = ($middlewarePath !== null && is_file($middlewarePath)) ? @file_get_contents($middlewarePath) : false;
        $middlewareSource = is_string($middlewareContents) ? $middlewareContents : null;

        if ($middlewareSource === null || ! str_contains($middlewareSource, 'EnsureUserHasRole')) {
            $artifacts[] = new GeneratedFile($middlewareRelative, $this->roleMiddlewareStub(), $middlewareSource !== null);
        }

        $providerRelative = 'app/Providers/RoleMiddlewareServiceProvider.php';
        $providerPath = $this->resolveRoutesFilePath($providerRelative);
        $providerContents = ($providerPath !== null && is_file($providerPath)) ? @file_get_contents($providerPath) : false;
        $providerSource = is_string($providerContents) ? $providerContents : null;

        if ($providerSource === null || ! str_contains($providerSource, 'RoleMiddlewareServiceProvider')) {
            $artifacts[] = new GeneratedFile($providerRelative, $this->roleMiddlewareProviderStub(), $providerSource !== null);
        }

        $providersConfigRelative = 'bootstrap/providers.php';
        $providersConfigPath = $this->resolveRoutesFilePath($providersConfigRelative);

        if ($providersConfigPath !== null && is_file($providersConfigPath)) {
            $providersOriginal = @file_get_contents($providersConfigPath);
            $providersOriginal = is_string($providersOriginal) ? $providersOriginal : '';

            $providersUpdated = $this->appendRoleProviderRegistration($providersOriginal);

            if ($providersUpdated !== null) {
                $artifacts[] = new GeneratedFile($providersConfigRelative, $providersUpdated, true);
            }
        }

        return $artifacts;
    }

    private function roleMiddlewareStub(): string
    {
        return <<<'PHP'
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        $required = $this->normalizeRoles($roles);

        if ($required === []) {
            return $next($request);
        }

        if ($this->userHasRequiredRole($user, $required)) {
            return $next($request);
        }

        abort(Response::HTTP_FORBIDDEN, 'Forbidden.');
    }

    /**
     * @param array<int, string> $roles
     * @return array<int, string>
     */
    private function normalizeRoles(array $roles): array
    {
        $normalized = [];

        foreach ($roles as $chunk) {
            $parts = preg_split('/(?:,|\|)+/', $chunk) ?: [];

            foreach ($parts as $part) {
                $role = trim($part);

                if ($role !== '' && ! in_array($role, $normalized, true)) {
                    $normalized[] = $role;
                }
            }
        }

        return $normalized;
    }

    /**
     * @param array<int, string> $required
     */
    private function userHasRequiredRole(object $user, array $required): bool
    {
        if (method_exists($user, 'hasAnyRole') && $user->hasAnyRole($required)) {
            return true;
        }

        if (method_exists($user, 'hasRole')) {
            foreach ($required as $role) {
                if ($user->hasRole($role)) {
                    return true;
                }
            }
        }

        if (property_exists($user, 'role') && in_array((string) $user->role, $required, true)) {
            return true;
        }

        if (property_exists($user, 'roles')) {
            $roles = $user->roles;

            if (is_iterable($roles)) {
                foreach ($roles as $candidate) {
                    if (is_string($candidate) && in_array($candidate, $required, true)) {
                        return true;
                    }

                    if (is_object($candidate) && property_exists($candidate, 'name') && in_array((string) $candidate->name, $required, true)) {
                        return true;
                    }
                }
            }
        }

        $token = method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;

        if ($token !== null) {
            $abilities = is_array($token->abilities ?? null) ? $token->abilities : [];

            if (in_array('*', $abilities, true)) {
                return true;
            }

            foreach ($required as $role) {
                if (in_array($role, $abilities, true) || in_array('role:' . $role, $abilities, true)) {
                    return true;
                }
            }
        }

        foreach ($required as $role) {
            if (Gate::has('role:' . $role) && Gate::allows('role:' . $role)) {
                return true;
            }

            if (Gate::has($role) && Gate::allows($role)) {
                return true;
            }
        }

        return false;
    }
}

PHP;
    }

    private function roleMiddlewareProviderStub(): string
    {
        return <<<'PHP'
<?php

namespace App\Providers;

use App\Http\Middleware\EnsureUserHasRole;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

class RoleMiddlewareServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $driver = config('blueprintx-security.roles.driver');

        if (! is_string($driver) || $driver === '' || strtolower($driver) === 'auto') {
            $driver = config('blueprintx-security.roles.auto_detected', 'none');
        }

        $driver = is_string($driver) ? strtolower($driver) : 'none';

        if ($driver === 'spatie') {
            return;
        }

        $router = $this->app->make(Router::class);
        $router->aliasMiddleware('role', EnsureUserHasRole::class);
    }
}

PHP;
    }

    private function appendRoleProviderRegistration(string $contents): ?string
    {
        if (str_contains($contents, self::ROLE_MIDDLEWARE_PROVIDER_FQN . '::class')) {
            return null;
        }

        $pattern = '/(\r?\n)(\s*)\];\s*$/';

        $updated = preg_replace_callback(
            $pattern,
            function (array $matches) {
                if (count($matches) < 3) {
                    return $matches[0];
                }

                $lineEnding = $matches[1];
                $indent = $matches[2] !== '' ? $matches[2] : '    ';

                return $lineEnding . $indent . self::ROLE_MIDDLEWARE_PROVIDER_FQN . '::class,' . $matches[0];
            },
            $contents,
            1
        );

        if (! is_string($updated) || $updated === $contents) {
            return null;
        }

        return $updated;
    }

    /**
     * @param array<int, mixed> $middleware
     * @return array<int, string>
     */
    private function sanitizeMiddleware(array $middleware): array
    {
        $normalized = [];

        foreach ($middleware as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            $value = trim($entry);

            if ($value === '') {
                continue;
            }

            if ($value === self::ROLE_MIDDLEWARE_NAME || str_starts_with($value, self::ROLE_MIDDLEWARE_NAME . ':')) {
                $this->roleMiddlewareDetected = true;
            }

            if (! in_array($value, $normalized, true)) {
                $normalized[] = $value;
            }
        }

        return $normalized;
    }

    /**
     * @param array<int, string> $middleware
     */
    private function buildRouteBlock(string $routeUri, string $controllerShort, array $middleware): string
    {
        $routeLine = sprintf("Route::apiResource('%s', %s::class);", $routeUri, $controllerShort);

        if ($middleware === []) {
            return $routeLine;
        }

        $middlewareLiteral = $this->formatMiddlewareArray($middleware);

        return sprintf(
            "Route::middleware(%s)->group(function (): void {\n    %s\n});",
            $middlewareLiteral,
            $routeLine
        );
    }

    /**
     * @param array<int, string> $middleware
     */
    private function formatMiddlewareArray(array $middleware): string
    {
        $items = array_map(
            static fn (string $item): string => "'" . str_replace("'", "\\'", $item) . "'",
            $middleware
        );

        return '[' . implode(', ', $items) . ']';
    }

    private function buildRouteLine(string $controllerShort, string $routeUri, string $indent = ''): string
    {
        return $indent . sprintf("Route::apiResource('%s', %s::class);", $routeUri, $controllerShort);
    }

    private function normalizeRouteUri(string $routeUri): string
    {
        $trimmed = trim($routeUri);

        if ($trimmed === '') {
            return '';
        }

        return ltrim($trimmed, '/');
    }

    /**
     * @return array{uri:string, indent:string, offset:int, length:int}|null
     */
    private function findRouteRegistration(string $contents, string $controllerShort): ?array
    {
        $pattern = sprintf(
            '/(?P<indent>^[ \t]*)Route::apiResource\(\s*[\'\"](?P<uri>[^\'\"]+)[\'\"],\s*%s::class\s*\);/m',
            preg_quote($controllerShort, '/'),
        );

        if (! preg_match($pattern, $contents, $matches, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        return [
            'uri' => $matches['uri'][0],
            'indent' => $matches['indent'][0],
            'offset' => $matches[0][1],
            'length' => strlen($matches[0][0]),
        ];
    }

    /**
     * @return array{relative:string, mode:string}
     */
    private function determineRouteTarget(Blueprint $blueprint): array
    {
        $tenancy = $blueprint->tenancy();
        $mode = strtolower((string) ($tenancy['mode'] ?? ''));

        if ($mode === '') {
            $mode = $this->inferTenancyModeFromPath($blueprint->path());
        }

        $mode = match ($mode) {
            'tenant' => 'tenant',
            'shared' => 'shared',
            default => 'central',
        };

        $relative = match ($mode) {
            'tenant' => 'routes/tenant.php',
            default => 'routes/api.php',
        };

        return [
            'relative' => $relative,
            'mode' => $mode,
        ];
    }

    private function inferTenancyModeFromPath(string $path): string
    {
        $lower = strtolower($path);

        if (str_contains($lower, '/tenant/') || str_contains($lower, '\\tenant\\')) {
            return 'tenant';
        }

        if (str_contains($lower, '/shared/') || str_contains($lower, '\\shared\\')) {
            return 'shared';
        }

        if (str_contains($lower, '/central/') || str_contains($lower, '\\central\\')) {
            return 'central';
        }

        return '';
    }

    private function defaultRoutesFileStub(): string
    {
        return "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n";
    }

    private function hasRouteFacadeImport(string $contents): bool
    {
        foreach ($this->parseUseStatements($contents) as $import) {
            if ($import['fqcn'] === 'Illuminate\\Support\\Facades\\Route') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array{fqcn:string, alias:?string, start:int, length:int, statement:string}>
     */
    private function parseUseStatements(string $contents): array
    {
        $matches = [];
        preg_match_all('/^use\s+([^;]+);/m', $contents, $matches, PREG_OFFSET_CAPTURE);

        $imports = [];

        $count = count($matches[0]);

        for ($index = 0; $index < $count; $index++) {
            $statementText = $matches[0][$index][0];
            $statementOffset = $matches[0][$index][1];
            $value = $matches[1][$index][0];

            $alias = null;
            $fqcn = $value;

            if (stripos($value, ' as ') !== false) {
                [$fqcn, $alias] = array_map('trim', explode(' as ', $value, 2));
            }

            $imports[] = [
                'fqcn' => $this->normalizeClassName($fqcn),
                'alias' => isset($alias) && $alias !== '' ? $alias : null,
                'start' => $statementOffset,
                'length' => strlen($statementText),
                'statement' => $statementText,
            ];
        }

        return $imports;
    }

    /**
     * @param array<string, bool> $usedAliases
     * @return array{contents:string, alias:string}
     */
    private function ensureClassImport(string $contents, string $fqcn, string $tenancyMode, array $usedAliases = []): array
    {
        $imports = $this->parseUseStatements($contents);
        $normalizedFqcn = $this->normalizeClassName($fqcn);

        $aliasCounts = [];
        foreach ($imports as $import) {
            $aliasName = $import['alias'] ?? $this->classBasename($import['fqcn']);
            $aliasCounts[$aliasName] = ($aliasCounts[$aliasName] ?? 0) + 1;
        }

        foreach ($imports as $index => $import) {
            if ($import['fqcn'] === $normalizedFqcn) {
                $currentAlias = $import['alias'] ?? $this->classBasename($normalizedFqcn);

                if ($aliasCounts[$currentAlias] <= 1) {
                    return ['contents' => $contents, 'alias' => $currentAlias];
                }

                $aliasCounts[$currentAlias]--;

                if ($aliasCounts[$currentAlias] <= 0) {
                    unset($aliasCounts[$currentAlias]);
                }

                $usedAliases = [];

                foreach ($aliasCounts as $aliasName => $count) {
                    if ($count > 0) {
                        $usedAliases[$aliasName] = true;
                    }
                }

                $alias = $this->determineControllerAlias($normalizedFqcn, $tenancyMode, $usedAliases);

                if ($alias === $currentAlias) {
                    return ['contents' => $contents, 'alias' => $alias];
                }

                $replacement = $alias === $this->classBasename($normalizedFqcn)
                    ? sprintf('use %s;', $normalizedFqcn)
                    : sprintf('use %s as %s;', $normalizedFqcn, $alias);

                $contents = substr_replace($contents, $replacement, $import['start'], $import['length']);

                return ['contents' => $contents, 'alias' => $alias];
            }
        }

        $usedAliases = [];

        foreach ($aliasCounts as $aliasName => $count) {
            if ($count > 0) {
                $usedAliases[$aliasName] = true;
            }
        }

    $alias = $this->determineControllerAlias($normalizedFqcn, $tenancyMode, $usedAliases);

        $statement = $alias === $this->classBasename($normalizedFqcn)
            ? sprintf('use %s;', $normalizedFqcn)
            : sprintf('use %s as %s;', $normalizedFqcn, $alias);

        if (! str_contains($contents, $statement)) {
            $contents = $this->insertUseStatement($contents, $statement);
        }

        return ['contents' => $contents, 'alias' => $alias];
    }

    /**
     * @param array<string, bool> $usedAliases
     */
    private function determineControllerAlias(string $fqcn, string $tenancyMode, array $usedAliases): string
    {
        $base = $this->classBasename($fqcn);

        if (! isset($usedAliases[$base])) {
            return $base;
        }

        $candidates = [];

        if ($tenancyMode !== '') {
            $candidates[] = Str::studly($tenancyMode) . $base;
        }

        if ($prefix = $this->detectContextPrefixFromFqcn($fqcn)) {
            $candidates[] = $prefix . $base;
        }

        if ($hint = $this->deriveNamespaceHint($fqcn)) {
            $candidates[] = $hint . $base;
        }

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && ! isset($usedAliases[$candidate])) {
                return $candidate;
            }
        }

        $index = 2;

        do {
            $candidate = $base . $index;
            ++$index;
        } while (isset($usedAliases[$candidate]));

        return $candidate;
    }

    private function detectContextPrefixFromFqcn(string $fqcn): ?string
    {
        if (preg_match('/\\\\(Central|Tenant|Shared)\\\\/i', $fqcn, $matches) === 1) {
            return Str::studly($matches[1]);
        }

        return null;
    }

    private function deriveNamespaceHint(string $fqcn): ?string
    {
        $segments = explode('\\', trim($fqcn, '\\'));
        array_pop($segments);

        while ($segments !== []) {
            $segment = array_pop($segments);
            $segment = trim($segment);

            if ($segment === '' || in_array(strtolower($segment), ['controllers', 'api'], true)) {
                continue;
            }

            return Str::studly($segment);
        }

        return null;
    }

    private function normalizeClassName(string $class): string
    {
        return ltrim(trim($class), '\\');
    }

    private function classBasename(string $class): string
    {
        $class = $this->normalizeClassName($class);
        $position = strrpos($class, '\\');

        if ($position === false) {
            return $class;
        }

        return substr($class, $position + 1);
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
}
