<?php

namespace BlueprintX\Generators;

use BlueprintX\Blueprint\Blueprint;
use BlueprintX\Blueprint\Field;
use BlueprintX\Blueprint\Relation;
use BlueprintX\Contracts\ArchitectureDriver;
use BlueprintX\Contracts\LayerGenerator;
use BlueprintX\Kernel\Generation\GeneratedFile;
use BlueprintX\Kernel\Generation\GenerationResult;
use BlueprintX\Kernel\TemplateEngine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class DatabaseLayerGenerator implements LayerGenerator
{
    private ?array $history = null;

    private ?string $lastGeneratedTimestamp = null;

    public function __construct(private readonly TemplateEngine $templates)
    {
    }

    public function layer(): string
    {
        return 'database';
    }

    /**
     * @param array<string, mixed> $options
     */
    public function generate(Blueprint $blueprint, ArchitectureDriver $driver, array $options = []): GenerationResult
    {
        $result = new GenerationResult();

        $template = sprintf('@%s/database/migration.stub.twig', $driver->name());
        $factoryTemplate = sprintf('@%s/database/factory.stub.twig', $driver->name());
        $seederTemplate = sprintf('@%s/database/seeder.stub.twig', $driver->name());
        $moduleSeederTemplate = sprintf('@%s/database/module-seeder.stub.twig', $driver->name());

        $context = $this->buildContext($blueprint, $driver, $options);
        $paths = $this->derivePaths($blueprint, $options);

        $relationsByField = $context['relations_by_field'] ?? $this->indexRelationsByField($blueprint);
        $seederContext = $this->buildSeederContext($blueprint, $context['namespaces'], $relationsByField);
        $context['seeder'] = $seederContext['context'];

        if ($this->templates->exists($template)) {
            $result->addFile(new GeneratedFile(
                $paths['migration'],
                $this->templates->render($template, $context)
            ));
        } else {
            $result->addWarning(sprintf('No se encontró la plantilla "%s" para la capa database en "%s".', $template, $driver->name()));
        }

        if ($this->templates->exists($factoryTemplate)) {
            $result->addFile(new GeneratedFile(
                $paths['factory'],
                $this->templates->render($factoryTemplate, $context)
            ));
        } else {
            $result->addWarning(sprintf('No se encontró la plantilla "%s" para la capa database en "%s".', $factoryTemplate, $driver->name()));
        }

        if ($this->templates->exists($seederTemplate)) {
            $result->addFile(new GeneratedFile(
                $paths['seeder'],
                $this->templates->render($seederTemplate, $context),
                true
            ));
        } else {
            $result->addWarning(sprintf('No se encontró la plantilla "%s" para la capa database en "%s".', $seederTemplate, $driver->name()));
        }

        $this->storeSeederHistory($blueprint, $seederContext['metadata']);

        if (($paths['module_seeder'] ?? null) !== null && $this->templates->exists($moduleSeederTemplate)) {
            $moduleSeederContext = $this->buildModuleSeederContext($blueprint);

            if ($moduleSeederContext !== null) {
                $moduleContext = $context;
                $moduleContext['module_seeder'] = $moduleSeederContext;

                $result->addFile(new GeneratedFile(
                    $paths['module_seeder'],
                    $this->templates->render($moduleSeederTemplate, $moduleContext),
                    true
                ));
            }
        }

        $databaseSeeder = $this->buildDatabaseSeederContent();

        if ($databaseSeeder !== null) {
            $result->addFile(new GeneratedFile(
                'database/seeders/DatabaseSeeder.php',
                $databaseSeeder,
                true
            ));
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function buildContext(Blueprint $blueprint, ArchitectureDriver $driver, array $options): array
    {
        $entity = [
            'name' => $blueprint->entity(),
            'module' => $blueprint->module(),
            'table' => $blueprint->table(),
            'fields' => array_map(static fn (Field $field): array => $field->toArray(), $blueprint->fields()),
            'relations' => array_map(static fn (Relation $relation): array => $relation->toArray(), $blueprint->relations()),
            'options' => $blueprint->options(),
        ];

        $module = $this->moduleSegment($blueprint);
        $namespaces = $this->deriveNamespaces($blueprint, $options);
        $relationsByField = $this->indexRelationsByField($blueprint);

        return [
            'blueprint' => $blueprint->toArray(),
            'entity' => $entity,
            'module' => $module,
            'namespaces' => $namespaces,
            'driver' => [
                'name' => $driver->name(),
                'layers' => $driver->layers(),
                'metadata' => $driver->metadata(),
            ],
            'migration' => $this->buildMigrationContext($blueprint, $relationsByField),
            'factory' => $this->buildFactoryContext($blueprint, $namespaces, $relationsByField),
            'relations_by_field' => $relationsByField,
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, string>
     */
    private function derivePaths(Blueprint $blueprint, array $options): array
    {
        $entity = Str::studly($blueprint->entity());
        $module = $this->moduleSegment($blueprint);

        $migrationsRoot = rtrim($options['paths']['database']['migrations'] ?? 'database/migrations', '/');
        $factoriesRoot = rtrim($options['paths']['database']['factories'] ?? 'database/factories/Domain', '/');
        $seedersRoot = rtrim($options['paths']['database']['seeders'] ?? 'database/seeders', '/');

        $migrationFilename = sprintf('%s_create_%s_table.php', $this->migrationPrefix($blueprint, $options), $this->tableName($blueprint));

        $factoryPath = $factoriesRoot;
        if ($module !== null) {
            $factoryPath .= '/' . $module;
        }
        $factoryPath .= '/Models/' . $entity . 'Factory.php';

        $seederPath = $seedersRoot;
        if ($module !== null) {
            $seederPath .= '/' . $module;
        }
        $seederPath .= '/' . $entity . 'Seeder.php';

        $moduleSeederPath = null;
        if ($module !== null) {
            $moduleSeederPath = $seedersRoot . '/' . $module . 'Seeder.php';
        }

        return [
            'migration' => $migrationsRoot . '/' . $migrationFilename,
            'factory' => $factoryPath,
            'seeder' => $seederPath,
            'module_seeder' => $moduleSeederPath,
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, string>
     */
    private function deriveNamespaces(Blueprint $blueprint, array $options): array
    {
        $module = $this->moduleSegment($blueprint);
        $baseFactories = trim($options['namespaces']['database']['factories'] ?? 'Database\\Factories\\Domain', '\\');
        $factories = $baseFactories;
        $baseSeeders = trim($options['namespaces']['database']['seeders'] ?? 'Database\\Seeders', '\\');
        $seedersNamespace = $baseSeeders;

        if ($module !== null) {
            $factories .= '\\' . $module;
            $seedersNamespace .= '\\' . $module;
        }

        return [
            'factories_root' => $factories,
            'factories_models' => $factories . '\\Models',
            'seeders_root' => $baseSeeders,
            'seeders_module' => $seedersNamespace,
        ];
    }

    private function migrationPrefix(Blueprint $blueprint, array $options): string
    {
        $history = $this->loadHistory();
        $key = $this->historyKey($blueprint);

        if (isset($history['migrations'][$key]['prefix'])) {
            return $history['migrations'][$key]['prefix'];
        }

        $existing = $this->findExistingMigration($blueprint, $options);

        if ($existing !== null) {
            if ($existing['style'] === 'legacy') {
                $newPrefix = $this->generateNextTimestamp();
                $renamedPrefix = $this->renameMigrationFile(
                    $existing['path'],
                    $newPrefix,
                    $this->tableName($blueprint)
                );

                $prefix = $renamedPrefix ?? $newPrefix;
            } else {
                $prefix = $existing['prefix'];
                $this->lastGeneratedTimestamp = $prefix;
            }

            $this->storeMigrationHistory($blueprint, $prefix);

            return $prefix;
        }

        $prefix = $this->generateNextTimestamp();
        $this->storeMigrationHistory($blueprint, $prefix);

        return $prefix;
    }

    private function tableName(Blueprint $blueprint): string
    {
        $table = $blueprint->table();

        if (! is_string($table) || $table === '') {
            return Str::snake(Str::pluralStudly($blueprint->entity()));
        }

        return $table;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function indexRelationsByField(Blueprint $blueprint): array
    {
        $index = [];

        foreach ($blueprint->relations() as $relation) {
            $field = $relation->field;

            if (! is_string($field) || $field === '') {
                continue;
            }

            $index[$field] = $relation->toArray();
        }

        foreach ($blueprint->fields() as $field) {
            $name = $field->name ?? null;

            if (! is_string($name) || $name === '' || isset($index[$name])) {
                continue;
            }

            $inferred = $this->inferBelongsToRelation($field);

            if ($inferred !== null) {
                $index[$name] = $inferred;
            }
        }

        return $index;
    }

    private function inferBelongsToRelation(Field $field): ?array
    {
        $rules = $this->parseRules($field->rules ?? null);
        $exists = $rules['exists'] ?? null;

        if (! is_string($exists) || $exists === '') {
            return null;
        }

        $target = $this->targetFromExistsRule($exists);

        if ($target === null) {
            return null;
        }

        return [
            'type' => 'belongsTo',
            'target' => $target,
            'field' => $field->name,
            'inferred' => true,
        ];
    }

    private function targetFromExistsRule(string $rule): ?string
    {
        $segments = array_map('trim', explode(',', $rule));

        if ($segments === []) {
            return null;
        }

        $table = array_shift($segments);

        if ($table === null || $table === '') {
            return null;
        }

        if (str_contains($table, '.')) {
            $parts = explode('.', $table);
            $table = end($parts) ?: $table;
        }

        $column = $segments[0] ?? 'id';
        $column = is_string($column) ? trim($column) : 'id';

        if ($column !== '' && ! in_array(Str::lower($column), ['id', 'uuid', 'ulid'], true)) {
            return null;
        }

        $singular = Str::singular($table);

        if ($singular === '') {
            return null;
        }

        return Str::studly($singular);
    }

    /**
     * @param array<string, array<string, mixed>> $relationsByField
     * @return array<string, mixed>
     */
    private function buildMigrationContext(Blueprint $blueprint, array $relationsByField): array
    {
        $table = $this->tableName($blueprint);
        $className = 'Create' . Str::studly($table) . 'Table';

        $columns = [];

        $columns[] = '$table->id();';

        foreach ($blueprint->fields() as $field) {
            if ($field->name === 'id') {
                continue;
            }

            $columns[] = $this->buildColumnDefinition($field, $relationsByField[$field->name] ?? null);
        }

        $columns = array_filter($columns);

        $options = $blueprint->options();
        $timestamps = array_key_exists('timestamps', $options) ? (bool) $options['timestamps'] : true;
        $softDeletes = (bool) ($options['softDeletes'] ?? false);

        if ($timestamps) {
            $columns[] = '$table->timestamps();';
        }

        if ($softDeletes) {
            $columns[] = '$table->softDeletes();';
        }

        return [
            'class_name' => $className,
            'table' => $table,
            'columns' => $columns,
        ];
    }

    /**
     * @param array<string, mixed>|null $relation
     */
    private function buildColumnDefinition(Field $field, ?array $relation): string
    {
        $rules = $this->parseRules($field->rules ?? null);
        $nullable = $field->nullable ?? in_array('nullable', $rules, true);
        $default = $field->default ?? null;

        if ($relation !== null && ($relation['type'] ?? null) === 'belongsTo') {
            return $this->buildForeignColumnDefinition($field, $relation, $nullable, $default);
        }

        $method = $this->resolveColumnMethod($field);
        $parameters = $this->resolveColumnParameters($field, $rules);
        $modifiers = $this->resolveColumnModifiers($field, $rules, $nullable, $default);

        return $this->compileColumn($method, $field->name, $parameters, $modifiers);
    }

    /**
     * @param array<string, mixed> $relation
     */
    private function buildForeignColumnDefinition(Field $field, array $relation, bool $nullable, mixed $default): string
    {
        $target = (string) ($relation['target'] ?? '');
        $relatedTable = $target !== '' ? Str::snake(Str::pluralStudly($target)) : Str::snake($field->name);
        $rules = $this->parseRules($field->rules ?? null);

    $method = 'foreignId';
        $parameters = [];
        $modifiers = [];

        if ($nullable) {
            $modifiers[] = 'nullable()';
        }

        if ($default !== null) {
            $modifiers[] = 'default(' . $this->exportDefault($default) . ')';
        }

        $modifiers[] = 'constrained(\'' . $relatedTable . '\')';

        if (! array_key_exists('nullable', $rules)) {
            $modifiers[] = 'cascadeOnDelete()';
        }

        if (array_key_exists('unique', $rules)) {
            $modifiers[] = 'unique()';
        }

        return $this->compileColumn($method, $field->name, $parameters, $modifiers);
    }

    /**
     * @param array<string, mixed> $relationsByField
     * @return array<string, mixed>
     */
    private function buildFactoryContext(Blueprint $blueprint, array $namespaces, array $relationsByField): array
    {
        $entity = Str::studly($blueprint->entity());
        $moduleNamespace = $this->moduleSegment($blueprint);

        $modelNamespace = trim('App\\Domain', '\\');
        if ($moduleNamespace !== null) {
            $modelNamespace .= '\\' . $moduleNamespace;
        }
        $modelNamespace .= '\\Models\\' . $entity;

        $factoryNamespace = $namespaces['factories_models'] ?? 'Database\\Factories';

        $properties = [];
        $imports = [$modelNamespace];
        $needsStr = false;

        foreach ($blueprint->fields() as $field) {
            if ($field->name === 'id' || in_array($field->name, ['created_at', 'updated_at', 'deleted_at'], true)) {
                continue;
            }

            $relation = $relationsByField[$field->name] ?? null;
            $value = $this->factoryValueForField($field, $relation, $needsStr);
            $properties[] = [
                'name' => $field->name,
                'value' => $value['expression'],
                'is_expression' => $value['is_expression'],
            ];

            if ($relation !== null && ($relation['type'] ?? null) === 'belongsTo') {
                $target = (string) ($relation['target'] ?? '');
                if ($target !== '') {
                    $imports[] = $this->modelNamespaceForRelation($blueprint, $target);
                }
            }
        }

        $imports = array_values(array_unique($imports));

        return [
            'namespace' => $factoryNamespace,
            'class_name' => $entity . 'Factory',
            'model_fqcn' => $modelNamespace,
            'model_class' => class_basename($modelNamespace),
            'model_doc_type' => '\\' . ltrim($modelNamespace, '\\'),
            'imports' => $imports,
            'properties' => $properties,
            'needs_str' => $needsStr,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $relationsByField
     * @return array{context: array<string, mixed>, metadata: array<string, mixed>}
     */
    private function buildSeederContext(Blueprint $blueprint, array $namespaces, array $relationsByField): array
    {
        $entity = Str::studly($blueprint->entity());
        $moduleSegment = $this->moduleSegment($blueprint);
        $namespace = $namespaces['seeders_module'] ?? 'Database\\Seeders';
        $modelFqcn = $this->modelFqcn($blueprint);

        $options = $blueprint->options();
        $seedOptions = is_array($options['seeders'] ?? null) ? $options['seeders'] : [];
        $countOption = is_array($seedOptions) && isset($seedOptions['count']) ? $seedOptions['count'] : null;
        $count = is_numeric($countOption) ? max(1, (int) $countOption) : 10;

        $dependencies = [];

        foreach ($blueprint->relations() as $relation) {
            if (Str::lower($relation->type) !== 'belongsto') {
                continue;
            }

            $target = Str::studly($relation->target);

            if ($target === '') {
                continue;
            }

            $dependencies[] = $target;
        }

        $dependencies = array_values(array_unique($dependencies));

        $context = [
            'namespace' => $namespace,
            'class_name' => $entity . 'Seeder',
            'imports' => [$modelFqcn],
            'model_class' => class_basename($modelFqcn),
            'count' => $count,
        ];

        $metadata = [
            'entity' => $entity,
            'module_studly' => $moduleSegment,
            'model_fqcn' => $modelFqcn,
            'count' => $count,
            'dependencies' => $dependencies,
        ];

        return [
            'context' => $context,
            'metadata' => $metadata,
        ];
    }

    private function modelFqcn(Blueprint $blueprint): string
    {
        $entity = Str::studly($blueprint->entity());
        $moduleNamespace = $this->moduleSegment($blueprint);

        $namespace = 'App\\Domain';
        if ($moduleNamespace !== null) {
            $namespace .= '\\' . $moduleNamespace;
        }

        return $namespace . '\\Models\\' . $entity;
    }

    private function modelNamespaceForRelation(Blueprint $blueprint, string $target): string
    {
        $module = $this->moduleSegment($blueprint);
        $namespace = 'App\\Domain';

        if ($module !== null) {
            $namespace .= '\\' . $module;
        }

        $namespace .= '\\Models\\' . Str::studly($target);

        return $namespace;
    }

    /**
     * @param array<string, string> $rules
     * @return array<string, mixed>
     */
    private function factoryValueForField(Field $field, ?array $relation, bool &$needsStr): array
    {
        $type = Str::lower((string) $field->type);
        $rules = $this->parseRules($field->rules ?? null);
        $default = $field->default ?? null;
        $nullable = ($field->nullable === true)
            || array_key_exists('nullable', $rules)
            || array_key_exists('sometimes', $rules);

        if (array_key_exists('required', $rules)) {
            $nullable = false;
        }

        $defaultValue = $default !== null
            ? [
                'expression' => $this->exportDefault($default),
                'is_expression' => false,
            ]
            : null;

        if ($relation !== null && ($relation['type'] ?? null) === 'belongsTo') {
            $target = (string) ($relation['target'] ?? '');

            if ($target !== '') {
                if ($defaultValue !== null) {
                    return $defaultValue;
                }

                if ($nullable) {
                    return [
                        'expression' => 'null',
                        'is_expression' => false,
                    ];
                }

                $class = Str::studly($target);

                return [
                    'expression' => $class . '::factory()',
                    'is_expression' => true,
                ];
            }
        }

        if ($defaultValue !== null) {
            return $defaultValue;
        }

        if ($type === 'uuid') {
            $needsStr = true;

            return [
                'expression' => 'Str::uuid()->toString()',
                'is_expression' => true,
            ];
        }

        return match ($type) {
            'string', 'text' => $this->stringFactoryValue($field->name, $rules),
            'integer', 'biginteger', 'bigint' => [
                'expression' => 'fake()->numberBetween(1, 1000)',
                'is_expression' => true,
            ],
            'decimal', 'float', 'double' => [
                'expression' => 'fake()->randomFloat(' . ($field->scale ?? 2) . ', 0, 10000)',
                'is_expression' => true,
            ],
            'boolean' => [
                'expression' => 'fake()->boolean()',
                'is_expression' => true,
            ],
            'date' => [
                'expression' => 'fake()->date()',
                'is_expression' => true,
            ],
            'datetime' => [
                'expression' => 'fake()->dateTime()',
                'is_expression' => true,
            ],
            'json' => [
                'expression' => '[]',
                'is_expression' => false,
            ],
            default => [
                'expression' => 'fake()->word()',
                'is_expression' => true,
            ],
        };
    }

    private function storeMigrationHistory(Blueprint $blueprint, string $prefix): void
    {
        $history = $this->loadHistory();

        if (! isset($history['migrations']) || ! is_array($history['migrations'])) {
            $history['migrations'] = [];
        }

        $history['migrations'][$this->historyKey($blueprint)] = [
            'prefix' => $prefix,
            'table' => $this->tableName($blueprint),
            'entity' => $blueprint->entity(),
            'module' => $blueprint->module(),
        ];

        $this->history = $history;
        $this->persistHistory();
    }

    private function storeSeederHistory(Blueprint $blueprint, array $metadata): void
    {
        $history = $this->loadHistory();

        if (! isset($history['seeders']) || ! is_array($history['seeders'])) {
            $history['seeders'] = [];
        }

        $moduleKey = $this->moduleKey($blueprint);
        $moduleStudly = $metadata['module_studly'] ?? $this->moduleSegment($blueprint);

        if (! is_string($moduleStudly) || $moduleStudly === '') {
            $moduleStudly = null;
        }

        if (! isset($history['seeders'][$moduleKey]) || ! is_array($history['seeders'][$moduleKey])) {
            $history['seeders'][$moduleKey] = [
                'module' => $blueprint->module(),
                'module_studly' => $moduleStudly,
                'entities' => [],
            ];
        }

        $history['seeders'][$moduleKey]['module'] = $blueprint->module();
    $history['seeders'][$moduleKey]['module_studly'] = $moduleStudly;

        $entity = (string) ($metadata['entity'] ?? Str::studly($blueprint->entity()));

        $history['seeders'][$moduleKey]['entities'][$entity] = [
            'entity' => $entity,
            'model' => (string) ($metadata['model_fqcn'] ?? $this->modelFqcn($blueprint)),
            'count' => (int) ($metadata['count'] ?? 10),
            'dependencies' => array_values(array_unique($metadata['dependencies'] ?? [])),
        ];

        $this->history = $history;
        $this->persistHistory();
    }

    private function historyKey(Blueprint $blueprint): string
    {
        $module = $blueprint->module();
        $moduleKey = $module !== null && $module !== '' ? Str::lower($module) : '_';

        return $moduleKey . ':' . Str::lower($this->tableName($blueprint));
    }

    private function moduleKey(Blueprint $blueprint): string
    {
        $module = $blueprint->module();

        return $module !== null && $module !== '' ? Str::lower($module) : '_';
    }

    private function buildModuleSeederContext(Blueprint $blueprint): ?array
    {
        $history = $this->loadHistory();
        $moduleKey = $this->moduleKey($blueprint);
        $moduleData = $history['seeders'][$moduleKey] ?? null;

        if (! is_array($moduleData)) {
            return null;
        }

        $entities = $moduleData['entities'] ?? [];
        if (! is_array($entities) || $entities === []) {
            return null;
        }

        $sorted = $this->sortSeedersByDependencies($entities);

        if ($sorted === []) {
            return null;
        }

        $imports = [];
        $calls = [];

        foreach ($sorted as $meta) {
            $model = (string) ($meta['model'] ?? null);
            if ($model === '') {
                continue;
            }

            $imports[] = $model;
            $calls[] = [
                'model_class' => class_basename($model),
                'count' => (int) ($meta['count'] ?? 10),
            ];
        }

        if ($calls === []) {
            return null;
        }

        $imports = array_values(array_unique($imports));
        $moduleStudly = $moduleData['module_studly'] ?? $this->moduleSegment($blueprint);

        if (! is_string($moduleStudly) || $moduleStudly === '') {
            return null;
        }

        return [
            'namespace' => 'Database\\Seeders',
            'class_name' => $moduleStudly . 'Seeder',
            'imports' => $imports,
            'seed_calls' => $calls,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $entities
     * @return array<int, array<string, mixed>>
     */
    private function sortSeedersByDependencies(array $entities): array
    {
        $remaining = [];

        foreach ($entities as $name => $meta) {
            if (! is_array($meta)) {
                continue;
            }

            $meta['entity'] = is_string($meta['entity'] ?? null) ? $meta['entity'] : $name;
            $remaining[$meta['entity']] = $meta;
        }

        $sorted = [];

        while ($remaining !== []) {
            $progress = false;

            foreach ($remaining as $name => $meta) {
                $dependencies = array_filter(
                    array_map(static fn ($dependency): string => (string) $dependency, $meta['dependencies'] ?? []),
                    static fn ($dependency): bool => $dependency !== ''
                );

                $unresolved = array_filter($dependencies, static function (string $dependency) use ($remaining, $sorted): bool {
                    if (isset($remaining[$dependency])) {
                        return true;
                    }

                    foreach ($sorted as $item) {
                        if (($item['entity'] ?? null) === $dependency) {
                            return false;
                        }
                    }

                    return false;
                });

                if ($unresolved !== []) {
                    continue;
                }

                $sorted[] = $meta;
                unset($remaining[$name]);
                $progress = true;
            }

            if (! $progress) {
                foreach ($remaining as $meta) {
                    $sorted[] = $meta;
                }

                break;
            }
        }

        return $sorted;
    }

    private function buildDatabaseSeederContent(): ?string
    {
        $history = $this->loadHistory();
        $seeders = $history['seeders'] ?? [];

        if (! is_array($seeders) || $seeders === []) {
            return null;
        }

        $moduleSeeders = [];

        foreach ($seeders as $moduleData) {
            if (! is_array($moduleData)) {
                continue;
            }

            $moduleStudly = $moduleData['module_studly'] ?? null;
            $entities = $moduleData['entities'] ?? [];

            if (! is_string($moduleStudly) || $moduleStudly === '' || ! is_array($entities) || $entities === []) {
                continue;
            }

            $moduleSeeders[] = 'Database\\Seeders\\' . $moduleStudly . 'Seeder';
        }

        $moduleSeeders = array_values(array_unique($moduleSeeders));

        if ($moduleSeeders === []) {
            return null;
        }

        $path = $this->toAbsolutePath('database/seeders/DatabaseSeeder.php');
        $existing = is_file($path) ? @file_get_contents($path) : null;

        if ($existing === false) {
            $existing = null;
        }

        $existingUses = $existing !== null ? $this->extractUseStatements($existing) : [];
        $uses = $this->mergeUseStatements($existingUses, $moduleSeeders);

        $existingBody = $existing !== null ? $this->extractRunBody($existing) : '';
        $customBody = $this->removeSeederCalls($existingBody);
        $customBodyIndented = $this->indentRunBody($customBody);

        $lines = [];
        $lines[] = '<?php';
        $lines[] = '';
        $lines[] = 'namespace Database\\Seeders;';
        $lines[] = '';

        foreach ($uses as $use) {
            $lines[] = 'use ' . $use . ';';
        }

        if ($uses !== []) {
            $lines[] = '';
        }

        $lines[] = 'class DatabaseSeeder extends Seeder';
        $lines[] = '{';
        $lines[] = '    public function run(): void';
        $lines[] = '    {';
        $lines[] = '        // @blueprintx:seeders:start';
        $lines[] = '        $this->call([';

        foreach ($moduleSeeders as $class) {
            $lines[] = '            ' . class_basename($class) . '::class,';
        }

        $lines[] = '        ]);';
        $lines[] = '        // @blueprintx:seeders:end';

        if ($customBodyIndented !== '') {
            $lines[] = '';

            foreach (preg_split('/\r?\n/', $customBodyIndented) as $customLine) {
                $lines[] = $customLine;
            }
        }

        $lines[] = '    }';
        $lines[] = '}';
        $lines[] = '';

        $contents = implode(PHP_EOL, $lines);

        if ($existing !== null && $this->normalizeWhitespace($existing) === $this->normalizeWhitespace($contents)) {
            return null;
        }

        return $contents;
    }

    private function extractRunBody(string $contents): string
    {
        if ($contents === '') {
            return '';
        }

        if (preg_match('/public function run\(\): void\s*\{\s*(.*?)\s*\}\s*\}/s', $contents, $matches)) {
            return $matches[1] ?? '';
        }

        if (preg_match('/public function run\(\): void\s*\{\s*(.*)/s', $contents, $matches)) {
            return $matches[1] ?? '';
        }

        return '';
    }

    private function removeSeederCalls(string $body): string
    {
        if ($body === '') {
            return '';
        }

        $lines = preg_split('/\r?\n/', $body) ?: [];
        $result = [];
        $skipBlock = false;

        foreach ($lines as $line) {
            $trim = trim($line);

            if ($trim === '// @blueprintx:seeders:start') {
                $skipBlock = true;
                continue;
            }

            if ($trim === '// @blueprintx:seeders:end') {
                $skipBlock = false;
                continue;
            }

            if (str_starts_with($trim, '$this->call(')) {
                if (str_contains($trim, '[') && ! str_contains($trim, '];')) {
                    $skipBlock = true;
                }

                continue;
            }

            if ($skipBlock) {
                if (str_contains($trim, '];') || str_contains($trim, ');')) {
                    $skipBlock = false;
                }

                continue;
            }

            $result[] = $line;
        }

        return implode(PHP_EOL, $result);
    }

    private function indentRunBody(string $body): string
    {
        $trimmed = trim($body);

        if ($trimmed === '') {
            return '';
        }

        $lines = preg_split('/\r?\n/', $body) ?: [];
        $indented = [];

        foreach ($lines as $line) {
            $clean = rtrim($line);

            if ($clean === '') {
                $indented[] = '';
                continue;
            }

            $indented[] = '        ' . ltrim($clean);
        }

        while ($indented !== [] && $indented[0] === '') {
            array_shift($indented);
        }

        while ($indented !== [] && end($indented) === '') {
            array_pop($indented);
        }

        return implode(PHP_EOL, $indented);
    }

    /**
     * @return array<int, string>
     */
    private function extractUseStatements(string $contents): array
    {
        if ($contents === '') {
            return [];
        }

        if (! preg_match_all('/^use\s+([^;]+);/m', $contents, $matches)) {
            return [];
        }

        $uses = [];

        foreach ($matches[1] as $use) {
            $use = trim($use);

            if ($use === '') {
                continue;
            }

            $uses[] = $use;
        }

        return $uses;
    }

    /**
     * @param array<int, string> $existingUses
     * @param array<int, string> $moduleSeeders
     * @return array<int, string>
     */
    private function mergeUseStatements(array $existingUses, array $moduleSeeders): array
    {
        $set = [];

        foreach ($existingUses as $use) {
            $set[$use] = true;
        }

        $set['Illuminate\\Database\\Seeder'] = true;

        foreach ($moduleSeeders as $class) {
            $set[$class] = true;
        }

        $uses = array_keys($set);

        usort($uses, static function (string $a, string $b): int {
            if ($a === 'Illuminate\\Database\\Seeder') {
                return -1;
            }

            if ($b === 'Illuminate\\Database\\Seeder') {
                return 1;
            }

            return strcmp($a, $b);
        });

        return $uses;
    }

    private function normalizeWhitespace(string $value): string
    {
        $normalized = preg_replace('/\r\n?/', "\n", $value);

        if ($normalized === null) {
            $normalized = $value;
        }

        return trim($normalized);
    }

    private function loadHistory(): array
    {
        if ($this->history !== null) {
            return $this->history;
        }

        $file = $this->historyFilePath();

        if (! is_file($file)) {
            $this->history = [
                'migrations' => [],
                'seeders' => [],
                'meta' => [],
            ];

            return $this->history;
        }

        $contents = @file_get_contents($file);

        if ($contents === false) {
            $this->history = [
                'migrations' => [],
                'seeders' => [],
                'meta' => [],
            ];

            return $this->history;
        }

        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            $this->history = [
                'migrations' => [],
                'seeders' => [],
                'meta' => [],
            ];

            return $this->history;
        }

        $migrations = $decoded['migrations'] ?? [];
        $seeders = $decoded['seeders'] ?? [];
        $meta = $decoded['meta'] ?? [];

        if (! is_array($migrations)) {
            $migrations = [];
        }

        if (! is_array($seeders)) {
            $seeders = [];
        }

        if (! is_array($meta)) {
            $meta = [];
        }

        $this->history = [
            'migrations' => $migrations,
            'seeders' => $seeders,
            'meta' => $meta,
        ];

        if (isset($meta['last_generated']) && is_string($meta['last_generated'])) {
            $this->lastGeneratedTimestamp = $meta['last_generated'];
        }

        return $this->history;
    }

    private function persistHistory(): void
    {
        $history = $this->history ?? [
            'migrations' => [],
            'seeders' => [],
            'meta' => [],
        ];

        if (! isset($history['meta']) || ! is_array($history['meta'])) {
            $history['meta'] = [];
        }

        if (! isset($history['seeders']) || ! is_array($history['seeders'])) {
            $history['seeders'] = [];
        }

        if ($this->lastGeneratedTimestamp !== null) {
            $history['meta']['last_generated'] = $this->lastGeneratedTimestamp;
        }

        $this->history = $history;

        $file = $this->historyFilePath();
        $directory = dirname($file);

        if (! is_dir($directory)) {
            @mkdir($directory, 0777, true);
        }

        @file_put_contents($file, json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function historyFilePath(): string
    {
        if (function_exists('storage_path')) {
            return rtrim(storage_path('blueprintx'), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'history.json';
        }

        if (function_exists('base_path')) {
            return rtrim(base_path('storage/blueprintx'), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'history.json';
        }

        $root = $this->projectRoot();

        return $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'blueprintx' . DIRECTORY_SEPARATOR . 'history.json';
    }

    private function projectRoot(): string
    {
        if (function_exists('base_path')) {
            return base_path();
        }

        $cwd = getcwd();

        if (is_string($cwd) && $cwd !== '') {
            return $cwd;
        }

        return dirname(__DIR__, 4);
    }

    private function findExistingMigration(Blueprint $blueprint, array $options): ?array
    {
        $migrationsRoot = rtrim($options['paths']['database']['migrations'] ?? 'database/migrations', '/');
        $absoluteRoot = $this->toAbsolutePath($migrationsRoot);
        $table = $this->tableName($blueprint);

        $pattern = $absoluteRoot . DIRECTORY_SEPARATOR . '*create_' . $table . '_table.php';
        $matches = glob($pattern);

        if ($matches === false || $matches === []) {
            return null;
        }

        $laravel = [];
        $legacy = [];

        foreach ($matches as $match) {
            $basename = basename($match);

            if (preg_match('/^(\d{4}_\d{2}_\d{2}_\d{6})_create_/', $basename, $parts)) {
                $laravel[] = [
                    'path' => $match,
                    'prefix' => $parts[1],
                    'style' => 'laravel',
                ];

                continue;
            }

            if (preg_match('/^(\d{14})_create_/', $basename, $parts)) {
                $legacy[] = [
                    'path' => $match,
                    'prefix' => $parts[1],
                    'style' => 'legacy',
                ];
            }
        }

        if ($laravel !== []) {
            usort($laravel, static fn (array $a, array $b): int => strcmp($a['prefix'], $b['prefix']));

            return end($laravel) ?: $laravel[0];
        }

        if ($legacy !== []) {
            usort($legacy, static fn (array $a, array $b): int => strcmp($a['prefix'], $b['prefix']));

            return end($legacy) ?: $legacy[0];
        }

        return null;
    }

    private function toAbsolutePath(string $path): string
    {
        if ($path === '') {
            return $this->projectRoot();
        }

        if ($this->isAbsolutePath($path)) {
            return $path;
        }

        return $this->projectRoot() . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
    }

    private function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if ($path[0] === '/' || $path[0] === '\\') {
            return true;
        }

        return (bool) preg_match('/^[A-Za-z]:\\\\/', $path);
    }

    private function generateNextTimestamp(): string
    {
        $now = Carbon::now();
        $candidate = $now->format('Y_m_d_His');

        if ($this->lastGeneratedTimestamp !== null && $candidate <= $this->lastGeneratedTimestamp) {
            try {
                $candidate = Carbon::createFromFormat('Y_m_d_His', $this->lastGeneratedTimestamp)
                    ->addSecond()
                    ->format('Y_m_d_His');
            } catch (\Throwable) {
                $candidate = $now->addSecond()->format('Y_m_d_His');
            }
        }

        $this->lastGeneratedTimestamp = $candidate;

        return $candidate;
    }

    private function renameMigrationFile(string $currentPath, string $desiredPrefix, string $table): ?string
    {
        if (! is_file($currentPath)) {
            return null;
        }

        $directory = dirname($currentPath);
        $prefix = $desiredPrefix;
        $targetPath = $directory . DIRECTORY_SEPARATOR . $prefix . '_create_' . $table . '_table.php';

        while (is_file($targetPath)) {
            try {
                $prefix = Carbon::createFromFormat('Y_m_d_His', $prefix)
                    ->addSecond()
                    ->format('Y_m_d_His');
            } catch (\Throwable) {
                $prefix = Carbon::now()->addSecond()->format('Y_m_d_His');
            }

            $targetPath = $directory . DIRECTORY_SEPARATOR . $prefix . '_create_' . $table . '_table.php';
        }

        if (@rename($currentPath, $targetPath)) {
            $this->lastGeneratedTimestamp = $prefix;

            return $prefix;
        }

        return null;
    }

    /**
     * @param array<string, string> $rules
     * @return array<string, mixed>
     */
    private function stringFactoryValue(string $name, array $rules): array
    {
        $lower = Str::lower($name);

        if (str_contains($lower, 'email')) {
            $expression = array_key_exists('unique', $rules)
                ? 'fake()->unique()->safeEmail()'
                : 'fake()->safeEmail()';

            return [
                'expression' => $expression,
                'is_expression' => true,
            ];
        }

        if (str_contains($lower, 'first_name')) {
            return [
                'expression' => 'fake()->firstName()',
                'is_expression' => true,
            ];
        }

        if (str_contains($lower, 'last_name')) {
            return [
                'expression' => 'fake()->lastName()',
                'is_expression' => true,
            ];
        }

        if (str_contains($lower, 'name')) {
            return [
                'expression' => 'fake()->words(3, true)',
                'is_expression' => true,
            ];
        }

        return [
            'expression' => 'fake()->word()',
            'is_expression' => true,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function parseRules(mixed $rules): array
    {
        if (! is_string($rules) || trim($rules) === '') {
            return [];
        }

        $segments = array_filter(array_map('trim', explode('|', $rules)));
        $result = [];

        foreach ($segments as $segment) {
            [$rule, $parameter] = array_pad(explode(':', $segment, 2), 2, null);
            $ruleLower = Str::lower($rule);
            $result[$ruleLower] = $parameter;
        }

        return $result;
    }

    /**
     * @param array<string, string> $rules
     * @return array<int, string>
     */
    private function resolveColumnModifiers(Field $field, array $rules, bool $nullable, mixed $default): array
    {
        $modifiers = [];

        if ($nullable) {
            $modifiers[] = 'nullable()';
        }

        if ($default !== null) {
            $modifiers[] = 'default(' . $this->exportDefault($default) . ')';
        }

        if (array_key_exists('unique', $rules)) {
            $modifiers[] = 'unique()';
        }

        return $modifiers;
    }

    /**
     * @param array<string, string> $rules
     * @return array<int, string>
     */
    private function resolveColumnParameters(Field $field, array $rules): array
    {
        $type = Str::lower((string) $field->type);

        return match ($type) {
            'string' => [$this->stringLengthFromRules($rules)],
            'decimal' => [$field->precision ?? 8, $field->scale ?? 2],
            'float', 'double' => [$field->precision ?? 8, $field->scale ?? 2],
            default => [],
        };
    }

    /**
     * @param array<string, string> $rules
     */
    private function stringLengthFromRules(array $rules): int
    {
        $max = $rules['max'] ?? null;

        if ($max !== null && is_numeric($max)) {
            return (int) $max;
        }

        return 255;
    }

    private function resolveColumnMethod(Field $field): string
    {
        return match (Str::lower((string) $field->type)) {
            'string' => 'string',
            'text' => 'text',
            'integer' => 'integer',
            'biginteger', 'bigint' => 'bigInteger',
            'decimal' => 'decimal',
            'float' => 'float',
            'double' => 'double',
            'boolean' => 'boolean',
            'date' => 'date',
            'datetime' => 'dateTime',
            'json' => 'json',
            'uuid' => 'uuid',
            default => 'string',
        };
    }

    /**
     * @param array<int, string> $parameters
     * @param array<int, string> $modifiers
     */
    private function compileColumn(string $method, string $name, array $parameters, array $modifiers): string
    {
        $args = array_merge(['\'' . $name . '\''], $parameters);
        $definition = '$table->' . $method . '(' . implode(', ', $args) . ')';

        foreach ($modifiers as $modifier) {
            $definition .= '->' . $modifier;
        }

        return $definition . ';';
    }

    private function exportDefault(mixed $value): string
    {
        if (is_string($value)) {
            return '\'' . addslashes($value) . '\'';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        return (string) $value;
    }

    private function moduleSegment(Blueprint $blueprint): ?string
    {
        $module = $blueprint->module();

        if ($module === null || $module === '') {
            return null;
        }

        return Str::studly($module);
    }
}
