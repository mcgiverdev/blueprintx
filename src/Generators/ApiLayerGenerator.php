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
    private array $formRequestConfig;

    private array $resourceConfig;

    private array $controllerTraits;

    private array $optimisticLocking;

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

        $root = $basePath;

        if ($module !== null) {
            $root .= '/' . $module;
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

        return Str::studly($module);
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
            $base .= '/' . $module;
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
            if (! is_array($definition) || ! isset($definition['relation'])) {
                continue;
            }

            $relationName = Str::camel((string) $definition['relation']);

            if ($relationName === '') {
                continue;
            }

            $relation = $relationMap[$relationName] ?? null;

            if ($relation === null) {
                continue;
            }

            if ($relation['type'] !== 'belongsto') {
                continue;
            }

            $alias = $definition['alias'] ?? $relationName;

            if (! is_string($alias) || ($alias = trim($alias)) === '') {
                $alias = $relationName;
            }

            $resourceFqcn = null;

            if (isset($definition['resource']) && is_string($definition['resource']) && $definition['resource'] !== '') {
                $resourceFqcn = trim($definition['resource'], '\\');
            }

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

            $method = match ($type) {
                'belongsto' => Str::camel($target),
                default => null,
            };

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
            $base .= '/' . $module;
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
}
