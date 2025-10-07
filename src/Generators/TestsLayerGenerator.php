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

class TestsLayerGenerator implements LayerGenerator
{
    private array $resourceConfig;

    private array $optimisticLocking;

    public function __construct(
        private readonly TemplateEngine $templates,
        array $resourceConfig = [],
        array $optimisticLocking = [],
    ) {
        $this->resourceConfig = $this->normalizeResourceConfig($resourceConfig);
        $this->optimisticLocking = $this->normalizeOptimisticLockingConfig($optimisticLocking);
    }

    public function layer(): string
    {
        return 'tests';
    }

    /**
     * @param array<string, mixed> $options
     */
    public function generate(Blueprint $blueprint, ArchitectureDriver $driver, array $options = []): GenerationResult
    {
        $result = new GenerationResult();

        $template = sprintf('@%s/tests/feature.stub.twig', $driver->name());
        if (! $this->templates->exists($template)) {
            $result->addWarning(sprintf('No se encontró la plantilla para la capa tests en "%s".', $driver->name()));

            return $result;
        }

        $context = $this->buildContext($blueprint, $driver, $options);
        $contents = $this->templates->render($template, $context);
        $path = $this->buildPath($blueprint, $options);

        $result->addFile(new GeneratedFile($path, $contents));

        return $result;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function buildContext(Blueprint $blueprint, ArchitectureDriver $driver, array $options): array
    {
        $relations = $this->prepareRelations($blueprint);
        $payloads = $this->prepareFieldPayloads($blueprint, $relations);

        $naming = $this->namingContext($blueprint);
        $optimisticLocking = $this->buildOptimisticLockingContext($blueprint);

        $payloads['includes'] = $this->resolveResourceIncludes($blueprint, $relations);
        $payloads['optimistic_locking_token_expression'] = $this->buildOptimisticLockingTokenExpression(
            $optimisticLocking,
            $naming['entity_variable'] ?? 'entity'
        );

        $entity = [
            'name' => $blueprint->entity(),
            'module' => $blueprint->module(),
            'table' => $blueprint->table(),
            'fields' => array_map(static fn (Field $field): array => $field->toArray(), $blueprint->fields()),
            'relations' => array_map(static fn (Relation $relation): array => $relation->toArray(), $blueprint->relations()),
            'endpoints' => array_map(static fn (Endpoint $endpoint): array => $endpoint->toArray(), $blueprint->endpoints()),
            'class' => $this->resolveModelClass($blueprint),
        ];

        return [
            'blueprint' => $blueprint->toArray(),
            'entity' => $entity,
            'namespaces' => $this->deriveNamespaces($blueprint, $options),
            'naming' => $naming,
            'model' => $this->deriveModelContext($blueprint),
            'routes' => [
                'resource' => $this->resolveResourceRoute($blueprint),
                'api_resource' => $this->resolveApiResourceRoute($blueprint),
            ],
            'driver' => [
                'name' => $driver->name(),
                'layers' => $driver->layers(),
                'metadata' => $driver->metadata(),
            ],
            'relations' => $relations,
            'tests' => $payloads,
            'options' => $blueprint->options(),
            'optimistic_locking' => $optimisticLocking,
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, string>
     */
    private function deriveNamespaces(Blueprint $blueprint, array $options): array
    {
        $base = $options['namespaces']['tests'] ?? 'Tests\\Feature';
        $module = $blueprint->module();

        if ($module !== null && $module !== '') {
            $base .= '\\' . Str::studly($module);
        }

        return [
            'tests' => $base,
        ];
    }

    /**
     * @param array<string, mixed> $options
     */
    private function buildPath(Blueprint $blueprint, array $options): string
    {
        $basePath = $options['paths']['tests'] ?? 'tests/Feature';
        $module = $blueprint->module();

        if ($module !== null && $module !== '') {
            $basePath .= '/' . Str::studly($module);
        }

        $entityName = Str::studly($blueprint->entity());

        return sprintf('%s/%sFeatureTest.php', trim($basePath, '/'), $entityName);
    }

    private function namingContext(Blueprint $blueprint): array
    {
        $entityName = Str::studly($blueprint->entity());

        return [
            'entity_studly' => $entityName,
            'entity_variable' => Str::camel($blueprint->entity()),
        ];
    }

    private function resolveModelClass(Blueprint $blueprint): string
    {
        $module = $blueprint->module();
        $moduleNamespace = $module !== null && $module !== ''
            ? Str::studly($module) . '\\'
            : '';

        return sprintf('App\\Domain\\%sModels\\%s', $moduleNamespace, Str::studly($blueprint->entity()));
    }

    private function deriveModelContext(Blueprint $blueprint): array
    {
        $identifierType = 'int';
        $sampleValue = '1';

        foreach ($blueprint->fields() as $field) {
            if ($field->name !== 'id') {
                continue;
            }

            $type = strtolower($field->type);

            [$identifierType, $sampleValue] = match ($type) {
                'uuid', 'guid', 'string', 'ulid' => ['string', "'00000000-0000-0000-0000-000000000000'"],
                'integer', 'increments', 'bigincrements', 'id', 'bigint', 'biginteger', 'unsignedbiginteger' => ['int', '1'],
                default => ['int|string', '1'],
            };

            break;
        }

        return [
            'identifier' => [
                'php_type' => $identifierType,
                'sample' => $sampleValue,
            ],
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

    private function resolveApiResourceRoute(Blueprint $blueprint): string
    {
        $resource = $this->resolveResourceRoute($blueprint);

        return '/api' . $resource;
    }

    /**
     * @return array<int, array{name:string,field:string,variable:string,import:string,class:string}>
     */
    private function prepareRelations(Blueprint $blueprint): array
    {
        $module = $blueprint->module();
        $moduleNamespace = $module !== null && $module !== ''
            ? Str::studly($module) . '\\'
            : '';

        $relations = [];

        foreach ($blueprint->relations() as $relation) {
            if (strtolower($relation->type) !== 'belongsto') {
                continue;
            }

            $field = $relation->field;
            $variable = $this->relationVariableFromField($field, $relation->target);
            $targetStudly = Str::studly($relation->target);
            $class = sprintf('App\\Domain\\%sModels\\%s', $moduleNamespace, $targetStudly);

            $relations[] = [
                'name' => $relation->target,
                'field' => $field,
                'variable' => $variable,
                'import' => $class,
                'class' => $class,
                'method' => Str::camel($relation->target),
            ];
        }

        return $relations;
    }

    private function relationVariableFromField(string $field, string $target): string
    {
        if (str_ends_with($field, '_id')) {
            $base = substr($field, 0, -3);

            return Str::camel($base);
        }

        return Str::camel($target);
    }

    /**
     * @param array<int, array{name:string,field:string,variable:string,import:string,class:string}> $relations
     */
    private function prepareFieldPayloads(Blueprint $blueprint, array $relations): array
    {
        $relationMap = [];

        foreach ($relations as $relation) {
            $relationMap[$relation['field']] = $relation;
        }

        $storePayload = [];
        $updatePayload = [];
        $factoryOverrides = [];
        $storeResponseFragment = [];
        $updateResponseFragment = [];

        foreach ($blueprint->fields() as $field) {
            $fieldName = $field->name;

            if (in_array($fieldName, ['id', 'created_at', 'updated_at', 'deleted_at'], true)) {
                continue;
            }

            $isRelation = array_key_exists($fieldName, $relationMap);

            if ($isRelation) {
                $variable = '$' . $relationMap[$fieldName]['variable'] . '->id';

                $storePayload[] = [
                    'name' => $fieldName,
                    'value' => $variable,
                    'is_relation' => true,
                ];

                $updatePayload[] = [
                    'name' => $fieldName,
                    'value' => $variable,
                    'is_relation' => true,
                ];

                $factoryOverrides[] = [
                    'name' => $fieldName,
                    'value' => $variable,
                ];

                continue;
            }

            [$storeValue, $updateValue] = $this->sampleFieldValues($field);

            if ($storeValue === null) {
                continue;
            }

            $storePayload[] = [
                'name' => $fieldName,
                'value' => $storeValue,
                'is_relation' => false,
            ];

            if ($updateValue === null) {
                $updateValue = $storeValue;
            }

            $updatePayload[] = [
                'name' => $fieldName,
                'value' => $updateValue,
                'is_relation' => false,
            ];
        }

        foreach ($storePayload as $payload) {
            if ($payload['is_relation']) {
                continue;
            }

            $storeResponseFragment[] = [
                'name' => $payload['name'],
                'value' => $payload['value'],
            ];

            if (count($storeResponseFragment) >= 2) {
                break;
            }
        }

        foreach ($updatePayload as $payload) {
            if ($payload['is_relation']) {
                continue;
            }

            $updateResponseFragment[] = [
                'name' => $payload['name'],
                'value' => $payload['value'],
            ];

            if (count($updateResponseFragment) >= 2) {
                break;
            }
        }

        return [
            'store_payload' => $storePayload,
            'update_payload' => $updatePayload,
            'factory_overrides' => $factoryOverrides,
            'store_response_fragment' => $storeResponseFragment,
            'update_response_fragment' => $updateResponseFragment,
            'index_count' => 3,
            'uses_soft_deletes' => (bool) ($blueprint->options()['softDeletes'] ?? false),
        ];
    }

    private function resolveResourceIncludes(Blueprint $blueprint, array $relations): array
    {
        if (! ($this->resourceConfig['enabled'] ?? true)) {
            return [];
        }

        $config = $blueprint->apiResources();
        $includesConfig = $config['includes'] ?? [];

        if (! is_array($includesConfig) || $includesConfig === []) {
            return [];
        }

        $relationIndex = [];

        foreach ($relations as $relation) {
            $keys = [
                strtolower($relation['name']),
                strtolower($relation['variable']),
            ];

            if (isset($relation['method'])) {
                $keys[] = strtolower($relation['method']);
            }

            foreach ($keys as $key) {
                $relationIndex[$key] = $relation;
            }
        }

        $includes = [];

        foreach ($includesConfig as $definition) {
            if (! is_array($definition) || ! isset($definition['relation'])) {
                continue;
            }

            $relationName = strtolower(trim((string) $definition['relation']));

            if ($relationName === '') {
                continue;
            }

            $alias = $definition['alias'] ?? $definition['relation'];

            if (! is_string($alias) || ($alias = trim($alias)) === '') {
                $alias = $definition['relation'];
            }

            $aliasKey = strtolower($alias);
            $relation = $relationIndex[$relationName] ?? $relationIndex[$aliasKey] ?? null;

            $includes[] = [
                'alias' => $alias,
                'relation' => $relation,
                'index_path' => $relation !== null
                    ? sprintf('data.0.%s.id', $alias)
                    : sprintf('data.0.%s', $alias),
                'store_path' => $relation !== null
                    ? sprintf('data.%s.id', $alias)
                    : sprintf('data.%s', $alias),
            ];
        }

        return $includes;
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
            'header' => $this->optimisticLocking['header'] ?? 'If-Match',
            'response_header' => $this->optimisticLocking['response_header'] ?? 'ETag',
            'strategy' => $strategy,
            'version_field' => $versionField,
            'timestamp_column' => $this->optimisticLocking['timestamp_column'] ?? 'updated_at',
            'require_header' => (bool) ($this->optimisticLocking['require_header'] ?? true),
            'allow_wildcard' => (bool) ($this->optimisticLocking['allow_wildcard'] ?? true),
        ];
    }

    private function buildOptimisticLockingTokenExpression(array $optimisticLocking, string $entityVariable): ?string
    {
        if (! ($optimisticLocking['enabled'] ?? false)) {
            return null;
        }

        if (! ($optimisticLocking['require_header'] ?? false)) {
            return null;
        }

        if ($optimisticLocking['allow_wildcard'] ?? true) {
            return null;
        }

        $variable = '$' . $entityVariable;

        if (($optimisticLocking['strategy'] ?? 'timestamp') === 'version') {
            $field = $optimisticLocking['version_field'] ?? 'version';

            return sprintf('sprintf(\'W/"%%s"\', (string) %s->%s)', $variable, $field);
        }

        $column = $optimisticLocking['timestamp_column'] ?? 'updated_at';

        return sprintf('sprintf(\'W/"%%s"\', %s->%s?->format(\'U.u\'))', $variable, $column);
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

    private function normalizeResourceConfig(array $config): array
    {
        $normalized = [
            'enabled' => true,
        ];

        if (array_key_exists('enabled', $config)) {
            $normalized['enabled'] = (bool) $config['enabled'];
        }

        return $normalized;
    }

    private function normalizeOptimisticLockingConfig(array $config): array
    {
        $defaults = [
            'enabled' => false,
            'header' => 'If-Match',
            'response_header' => 'ETag',
            'timestamp_column' => 'updated_at',
            'version_field' => 'version',
            'require_header' => true,
            'allow_wildcard' => true,
        ];

        foreach ($defaults as $key => $value) {
            if (array_key_exists($key, $config)) {
                $defaults[$key] = $config[$key];
            }
        }

        $defaults['enabled'] = (bool) $defaults['enabled'];
        $defaults['require_header'] = (bool) $defaults['require_header'];
        $defaults['allow_wildcard'] = (bool) $defaults['allow_wildcard'];

        if (! is_string($defaults['header']) || $defaults['header'] === '') {
            $defaults['header'] = 'If-Match';
        }

        if (! is_string($defaults['response_header']) || $defaults['response_header'] === '') {
            $defaults['response_header'] = 'ETag';
        }

        if (! is_string($defaults['timestamp_column']) || $defaults['timestamp_column'] === '') {
            $defaults['timestamp_column'] = 'updated_at';
        }

        if (! is_string($defaults['version_field']) || $defaults['version_field'] === '') {
            $defaults['version_field'] = 'version';
        }

        return $defaults;
    }

    /**
     * @return array{0:?string,1:?string}
     */
    private function sampleFieldValues(Field $field): array
    {
        $type = strtolower($field->type);
        $rules = $field->rules ?? '';
        $name = $field->name;

        if (str_contains($rules, 'email')) {
            return [
                var_export('john.doe@example.com', true),
                var_export('jane.doe@example.com', true),
            ];
        }

        return match ($type) {
            'string', 'char', 'varchar', 'text', 'mediumtext', 'longtext' => [
                var_export('Sample ' . Str::title(str_replace('_', ' ', $name)), true),
                var_export('Updated ' . Str::title(str_replace('_', ' ', $name)), true),
            ],
            'boolean' => [
                var_export(true, true),
                var_export(false, true),
            ],
            'integer', 'bigint', 'biginteger', 'smallint', 'tinyint', 'unsignedbigint', 'unsignedinteger', 'unsignedsmallint', 'unsignedtinyint' => [
                var_export(100, true),
                var_export(200, true),
            ],
            'decimal', 'numeric', 'float', 'double' => [
                var_export('4200.00', true),
                var_export('6500.50', true),
            ],
            'uuid', 'guid', 'ulid' => [
                var_export('00000000-0000-0000-0000-000000000001', true),
                var_export('00000000-0000-0000-0000-000000000002', true),
            ],
            'date' => [
                var_export('2025-01-01', true),
                var_export('2025-12-31', true),
            ],
            'datetime', 'datetimetz', 'timestamp', 'timestamptz' => [
                var_export('2025-01-01T10:00:00Z', true),
                var_export('2025-12-31T15:30:00Z', true),
            ],
            default => [
                var_export('Sample ' . Str::title(str_replace('_', ' ', $name)), true),
                var_export('Updated ' . Str::title(str_replace('_', ' ', $name)), true),
            ],
        };
    }
}
