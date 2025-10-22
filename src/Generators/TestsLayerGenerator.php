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
    $relationImports = $this->uniqueRelationImports($relations);
        $payloads = $this->prepareFieldPayloads($blueprint, $relations);

        $naming = $this->namingContext($blueprint);
        $optimisticLocking = $this->buildOptimisticLockingContext($blueprint);

        $payloads['includes'] = $this->resolveResourceIncludes($blueprint, $relations);
        $payloads['optimistic_locking_token_expression'] = $this->buildOptimisticLockingTokenExpression(
            $optimisticLocking,
            $naming['entity_variable'] ?? 'entity'
        );

        $moduleContext = [
            'raw' => $blueprint->module(),
            'segments' => $blueprint->moduleSegments(),
            'namespace' => $blueprint->moduleNamespace(),
            'path' => $blueprint->modulePath(),
            'class_prefix' => $blueprint->moduleClassPrefix(),
        ];

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
            'module' => $moduleContext['namespace'],
            'module_context' => $moduleContext,
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
            'relation_imports' => $relationImports,
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
        $moduleNamespace = $blueprint->moduleNamespace();

        if ($moduleNamespace !== null) {
            $base .= '\\' . $moduleNamespace;
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
        $modulePath = $blueprint->modulePath();

        if ($modulePath !== null) {
            $basePath .= '/' . $modulePath;
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
        $moduleNamespace = $blueprint->moduleNamespace();
        $prefix = $moduleNamespace !== null ? $moduleNamespace . '\\' : '';

        return sprintf('App\\Domain\\%sModels\\%s', $prefix, Str::studly($blueprint->entity()));
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
     * @return array<int, array{name:string,field:string,variable:string,import:string,class:string,method:string}>
     */
    private function prepareRelations(Blueprint $blueprint): array
    {
        $moduleNamespace = $blueprint->moduleNamespace();
        $modulePrefix = $moduleNamespace !== null ? $moduleNamespace . '\\' : '';

        $relations = [];
        $existingRelationFields = [];

        foreach ($blueprint->relations() as $relation) {
            if (strtolower($relation->type) !== 'belongsto') {
                continue;
            }

            $field = $relation->field;
            $variable = $this->relationVariableFromField($field, $relation->target);
            $targetStudly = Str::studly($relation->target);
            $class = sprintf('App\\Domain\\%sModels\\%s', $modulePrefix, $targetStudly);

            $relations[] = [
                'name' => $relation->target,
                'field' => $field,
                'variable' => $variable,
                'import' => $class,
                'class' => $class,
                'method' => Str::camel($relation->target),
            ];

            $existingRelationFields[] = $field;
        }

        $implicitRelations = $this->inferImplicitBelongsToRelations($blueprint, $existingRelationFields, $modulePrefix);

        return array_merge($relations, $implicitRelations);
    }

    /**
     * @param array<int, array<string, mixed>> $relations
     * @return array<int, string>
     */
    private function uniqueRelationImports(array $relations): array
    {
        $imports = [];

        foreach ($relations as $relation) {
            $import = $relation['import'] ?? null;

            if (! is_string($import) || $import === '') {
                continue;
            }

            if (! in_array($import, $imports, true)) {
                $imports[] = $import;
            }
        }

        return $imports;
    }

    private function relationVariableFromField(string $field, string $target): string
    {
        $candidate = $field;

        if (str_ends_with($field, '_id')) {
            $candidate = substr($field, 0, -3);
        }

        $variable = Str::camel($candidate);

        if ($variable === '') {
            $variable = Str::camel($target);
        }

        return $variable;
    }

    /**
     * @param array<int, string> $existingRelationFields
     * @return array<int, array{name:string,field:string,variable:string,import:string,class:string,method:string}>
     */
    private function inferImplicitBelongsToRelations(
        Blueprint $blueprint,
        array $existingRelationFields,
        string $modulePrefix
    ): array {
        $relations = [];

        foreach ($blueprint->fields() as $field) {
            $fieldName = $field->name;

            if (in_array($fieldName, $existingRelationFields, true)) {
                continue;
            }

            $existsRule = $this->extractExistsRule($field->rules ?? null);

            if ($existsRule === null) {
                continue;
            }

            $targetStudly = Str::studly(Str::singular($existsRule['table']));

            if ($targetStudly === '') {
                continue;
            }

            $variable = $this->relationVariableFromField($fieldName, $targetStudly);
            $class = sprintf('App\\Domain\\%sModels\\%s', $modulePrefix, $targetStudly);

            $relations[] = [
                'name' => $targetStudly,
                'field' => $fieldName,
                'variable' => $variable,
                'import' => $class,
                'class' => $class,
                'method' => $variable,
            ];
        }

        return $relations;
    }

    /**
     * @return array{table:string,column:string}|null
     */
    private function extractExistsRule(?string $rules): ?array
    {
        if (! is_string($rules) || trim($rules) === '') {
            return null;
        }

        $segments = array_filter(array_map('trim', explode('|', $rules)));

        foreach ($segments as $segment) {
            [$rule, $parameters] = array_pad(explode(':', $segment, 2), 2, null);

            if (Str::lower((string) $rule) !== 'exists') {
                continue;
            }

            if ($parameters === null || $parameters === '') {
                return null;
            }

            $parts = array_values(array_filter(array_map('trim', explode(',', $parameters))));

            $table = array_shift($parts);

            if (! is_string($table) || $table === '') {
                return null;
            }

            if (str_contains($table, '.')) {
                $tableSegments = array_filter(array_map('trim', explode('.', $table)));
                $table = (string) array_pop($tableSegments);
            }

            if ($table === '') {
                return null;
            }

            $column = $parts[0] ?? 'id';

            if (! is_string($column) || $column === '') {
                $column = 'id';
            }

            return [
                'table' => $table,
                'column' => $column,
            ];
        }

        return null;
    }

    /**
     * @param array<int, array{name:string,field:string,variable:string,import:string,class:string,method:string}> $relations
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
        $rules = is_string($field->rules) ? $field->rules : '';
        $name = $field->name;
        $ruleOptions = $this->parseRuleOptions($rules);

        if (str_contains($rules, 'email')) {
            return [
                var_export('john.doe@example.com', true),
                var_export('jane.doe@example.com', true),
            ];
        }

        if ($ruleOptions['url']) {
            return [
                var_export('https://example.com', true),
                var_export('https://example.org', true),
            ];
        }

        if ($ruleOptions['ip']) {
            return [
                var_export('192.168.1.10', true),
                var_export('192.168.1.20', true),
            ];
        }

        if ($ruleOptions['enum'] !== []) {
            $first = $ruleOptions['enum'][0];
            $second = $ruleOptions['enum'][1] ?? $first;

            if ($second === $first && count($ruleOptions['enum']) > 1) {
                $second = $ruleOptions['enum'][1];
            }

            return [
                var_export($first, true),
                var_export($second, true),
            ];
        }

        return match ($type) {
            'string', 'char', 'varchar', 'text', 'mediumtext', 'longtext' => [
                var_export($this->sampleStringValue($name, $ruleOptions, false), true),
                var_export($this->sampleStringValue($name, $ruleOptions, true), true),
            ],
            'boolean' => [
                var_export(true, true),
                var_export(false, true),
            ],
            'integer', 'bigint', 'biginteger', 'smallint', 'tinyint', 'unsignedbigint', 'unsignedinteger', 'unsignedsmallint', 'unsignedtinyint' => [
                var_export($this->sampleIntegerValue($ruleOptions, false), true),
                var_export($this->sampleIntegerValue($ruleOptions, true), true),
            ],
            'decimal', 'numeric', 'float', 'double' => [
                var_export($this->sampleDecimalValue($field, $ruleOptions, false), true),
                var_export($this->sampleDecimalValue($field, $ruleOptions, true), true),
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
            'json', 'jsonb' => [
                var_export(['sample' => 'payload'], true),
                var_export(['updated' => 'payload'], true),
            ],
            default => [
                var_export($this->sampleStringValue($name, $ruleOptions, false), true),
                var_export($this->sampleStringValue($name, $ruleOptions, true), true),
            ],
        };
    }

    /**
     * @return array{enum:array<int,string>,min:?float,max:?float,size:?int,url:bool,ip:bool}
     */
    private function parseRuleOptions(string $rules): array
    {
        $options = [
            'enum' => [],
            'min' => null,
            'max' => null,
            'size' => null,
            'url' => false,
            'ip' => false,
        ];

        if ($rules === '') {
            return $options;
        }

        foreach (explode('|', $rules) as $rule) {
            $rule = trim($rule);

            if ($rule === '') {
                continue;
            }

            if (str_starts_with($rule, 'in:')) {
                $values = explode(',', substr($rule, 3));
                $values = array_values(array_filter(array_map('trim', $values), static fn ($value): bool => $value !== ''));

                if ($values !== []) {
                    $options['enum'] = $values;
                }

                continue;
            }

            if (str_starts_with($rule, 'min:')) {
                $options['min'] = (float) substr($rule, 4);

                continue;
            }

            if (str_starts_with($rule, 'max:')) {
                $options['max'] = (float) substr($rule, 4);

                continue;
            }

            if (str_starts_with($rule, 'size:')) {
                $options['size'] = (int) substr($rule, 5);

                continue;
            }

            if ($rule === 'url') {
                $options['url'] = true;

                continue;
            }

            if ($rule === 'ip') {
                $options['ip'] = true;
            }
        }

        return $options;
    }

    /**
     * @param array{enum:array<int,string>,min:?float,max:?float,size:?int,url:bool,ip:bool} $ruleOptions
     */
    private function sampleStringValue(string $name, array $ruleOptions, bool $alternate): string
    {
        if ($ruleOptions['size'] !== null) {
            return $this->sampleSizedString($ruleOptions['size'], $alternate);
        }

        $label = Str::title(str_replace('_', ' ', $name));

        return ($alternate ? 'Updated ' : 'Sample ') . $label;
    }

    /**
     * @param array{enum:array<int,string>,min:?float,max:?float,size:?int,url:bool,ip:bool} $ruleOptions
     */
    private function sampleIntegerValue(array $ruleOptions, bool $alternate): int
    {
        $min = $ruleOptions['min'];
        $max = $ruleOptions['max'];

        if ($min !== null && $max !== null) {
            return (int) ($alternate ? $max : $min);
        }

        if ($min !== null) {
            return (int) ($alternate ? $min + 1 : $min);
        }

        if ($max !== null) {
            $base = (int) $max;

            return $alternate ? $base : max($base - 1, 0);
        }

        return $alternate ? 200 : 100;
    }

    /**
     * @param array{enum:array<int,string>,min:?float,max:?float,size:?int,url:bool,ip:bool} $ruleOptions
     */
    private function sampleDecimalValue(Field $field, array $ruleOptions, bool $alternate): string
    {
        $min = $ruleOptions['min'];
        $max = $ruleOptions['max'];
        $scale = $field->scale ?? 2;

        if ($min !== null && $max !== null) {
            $value = $alternate ? $max : $min;

            return $this->formatDecimalSample($value, $scale);
        }

        if ($min !== null) {
            $value = $alternate ? ($min + 1) : $min;

            return $this->formatDecimalSample($value, $scale);
        }

        if ($max !== null) {
            $value = $alternate ? $max : max($max - 1, 0);

            return $this->formatDecimalSample($value, $scale);
        }

        $defaults = $alternate ? 6500.50 : 4200.00;

        return $this->formatDecimalSample($defaults, $scale);
    }

    private function sampleSizedString(int $size, bool $alternate): string
    {
        if ($size === 3) {
            return $alternate ? 'EUR' : 'USD';
        }

        if ($size === 5) {
            return $alternate ? 'en-US' : 'es-ES';
        }

        $character = $alternate ? 'Z' : 'A';

        return str_repeat($character, max($size, 1));
    }

    private function formatDecimalSample(float $value, int $scale): string
    {
        return number_format($value, max($scale, 0), '.', '');
    }
}



