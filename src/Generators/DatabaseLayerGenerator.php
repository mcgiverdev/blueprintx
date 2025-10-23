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

    /**
     * Tracks which seeder modules were refreshed during the current generation run.
     *
     * @var array<string, bool>
     */
    private array $refreshedSeederModules = [];

    private ?string $currentMigrationsRoot = null;

    private const RESERVED_TABLE_BASELINES = [
        'users' => [
            'fields' => ['name', 'email', 'email_verified_at', 'password', 'remember_token'],
        ],
    ];

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
        $this->currentMigrationsRoot = $this->resolveMigrationsRoot($blueprint, $options);
        $result = new GenerationResult();

        try {
            $template = sprintf('@%s/database/migration.stub.twig', $driver->name());
            $factoryTemplate = sprintf('@%s/database/factory.stub.twig', $driver->name());
            $seederTemplate = sprintf('@%s/database/seeder.stub.twig', $driver->name());
            $moduleSeederTemplate = sprintf('@%s/database/module-seeder.stub.twig', $driver->name());

            $context = $this->buildContext($blueprint, $driver, $options);
            $paths = $this->derivePaths($blueprint, $options, $this->currentMigrationsRoot);
            $reservedMigration = $this->isMigrationReserved($blueprint);

            $relationsByField = $context['relations_by_field'] ?? $this->indexRelationsByField($blueprint);
            $seederContext = $this->buildSeederContext($blueprint, $context['namespaces'], $relationsByField);
            $context['seeder'] = $seederContext['context'];

            $reservedAlter = null;

            if ($reservedMigration) {
                $this->applyReservedBaseMigrationAdjustments($blueprint, $paths['migration'] ?? null);
                $reservedAlter = $this->buildReservedAlterMigration($blueprint, $relationsByField);
            }

            if (! $reservedMigration) {
                if ($this->templates->exists($template)) {
                    $result->addFile(new GeneratedFile(
                        $paths['migration'],
                        $this->templates->render($template, $context)
                    ));
                } else {
                    $result->addWarning(sprintf('No se encontró la plantilla "%s" para la capa database en "%s".', $template, $driver->name()));
                }
            } elseif ($reservedAlter !== null) {
                if ($this->templates->exists($template)) {
                    $alterContext = $context;
                    $alterContext['migration'] = $reservedAlter['context'];

                    $alterPath = $this->resolveReservedAlterPath($blueprint, $options, $this->currentMigrationsRoot);

                    $result->addFile(new GeneratedFile(
                        $alterPath['path'],
                        $this->templates->render($template, $alterContext),
                        true
                    ));

                    $this->storeReservedAlterHistory($blueprint, $alterPath['prefix'], $reservedAlter['metadata']);
                } else {
                    $result->addWarning(sprintf('No se encontró la plantilla "%s" para la capa database en "%s".', $template, $driver->name()));
                }
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
        } finally {
            $this->currentMigrationsRoot = null;
        }
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
    private function derivePaths(Blueprint $blueprint, array $options, ?string $migrationsRootOverride = null): array
    {
        $entity = Str::studly($blueprint->entity());
        $module = $this->moduleSegment($blueprint);
        $modulePath = $module !== null ? str_replace('\\', '/', $module) : null;

        $migrationsRoot = $migrationsRootOverride ?? $this->resolveMigrationsRoot($blueprint, $options);
        $migrationsRoot = rtrim(str_replace('\\', '/', $migrationsRoot), '/');
        $factoriesRoot = rtrim($options['paths']['database']['factories'] ?? 'database/factories/Domain', '/');
        $seedersRoot = rtrim($options['paths']['database']['seeders'] ?? 'database/seeders', '/');

        $migrationFilename = sprintf('%s_create_%s_table.php', $this->migrationPrefix($blueprint, $options), $this->tableName($blueprint));

        $factoryPath = $factoriesRoot;
        if ($modulePath !== null) {
            $factoryPath .= '/' . $modulePath;
        }
        $factoryPath .= '/Models/' . $entity . 'Factory.php';

        $seederPath = $seedersRoot;
        if ($modulePath !== null) {
            $seederPath .= '/' . $modulePath;
        }
        $seederPath .= '/' . $entity . 'Seeder.php';

        $moduleSeederPath = null;
        if ($modulePath !== null) {
            $moduleSeederPath = $seedersRoot . '/' . $modulePath . 'Seeder.php';
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
        $customPrefix = $this->customMigrationPrefix($blueprint);
        $history = $this->loadHistory();
        $key = $this->historyKey($blueprint);

        if (isset($history['migrations'][$key]) && is_array($history['migrations'][$key])) {
            $storedPrefix = $history['migrations'][$key]['prefix'] ?? null;

            if ($storedPrefix !== null) {
                $isReserved = ($history['migrations'][$key]['reserved'] ?? false) === true;

                if ($customPrefix !== null && ! $isReserved && $customPrefix !== $storedPrefix) {
                    $existing = $this->findExistingMigration($blueprint, $options, $this->currentMigrationsRoot);

                    if ($existing !== null && $existing['prefix'] !== $customPrefix) {
                        $renamedPrefix = $this->renameMigrationFile(
                            $existing['path'],
                            $customPrefix,
                            $this->tableName($blueprint)
                        );

                        if ($renamedPrefix !== null) {
                            $customPrefix = $renamedPrefix;
                        }
                    }

                    if ($this->lastGeneratedTimestamp === null || strcmp($customPrefix, $this->lastGeneratedTimestamp) > 0) {
                        $this->lastGeneratedTimestamp = $customPrefix;
                    }

                    $this->storeMigrationHistory($blueprint, $customPrefix);

                    return $customPrefix;
                }

                return $storedPrefix;
            }
        }

        $existing = $this->findExistingMigration($blueprint, $options, $this->currentMigrationsRoot);

        if ($existing !== null) {
            $isReserved = $this->shouldReserveMigration($blueprint, $existing);
            $prefix = $existing['prefix'];

            $customApplied = false;

            if ($customPrefix !== null && ! $isReserved) {
                if ($existing['prefix'] !== $customPrefix) {
                    $renamedPrefix = $this->renameMigrationFile(
                        $existing['path'],
                        $customPrefix,
                        $this->tableName($blueprint)
                    );

                    $prefix = $renamedPrefix ?? $customPrefix;
                } else {
                    $prefix = $customPrefix;
                }

                $customApplied = true;
            } elseif ($existing['style'] === 'legacy') {
                $newPrefix = $this->generateNextTimestamp();
                $renamedPrefix = $this->renameMigrationFile(
                    $existing['path'],
                    $newPrefix,
                    $this->tableName($blueprint)
                );

                $prefix = $renamedPrefix ?? $newPrefix;
            } elseif (! $isReserved && $this->lastGeneratedTimestamp !== null && $prefix <= $this->lastGeneratedTimestamp) {
                $desiredPrefix = $this->generateNextTimestamp();
                $renamedPrefix = $this->renameMigrationFile(
                    $existing['path'],
                    $desiredPrefix,
                    $this->tableName($blueprint)
                );

                $prefix = $renamedPrefix ?? $desiredPrefix;
            }

            if (! $isReserved) {
                if ($customApplied) {
                    if ($this->lastGeneratedTimestamp === null || strcmp($prefix, $this->lastGeneratedTimestamp) > 0) {
                        $this->lastGeneratedTimestamp = $prefix;
                    }
                } elseif ($this->lastGeneratedTimestamp === null || strcmp($prefix, $this->lastGeneratedTimestamp) > 0) {
                    $this->lastGeneratedTimestamp = $prefix;
                }
            }

            $this->storeMigrationHistory($blueprint, $prefix);

            return $prefix;
        }

        if ($customPrefix !== null) {
            if ($this->lastGeneratedTimestamp === null || strcmp($customPrefix, $this->lastGeneratedTimestamp) > 0) {
                $this->lastGeneratedTimestamp = $customPrefix;
            }

            $this->storeMigrationHistory($blueprint, $customPrefix);

            return $customPrefix;
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
        $primaryField = null;

        foreach ($blueprint->fields() as $field) {
            if ($field->name === 'id') {
                $primaryField = $field;
                continue;
            }

            $columns[] = $this->buildColumnDefinition($field, $relationsByField[$field->name] ?? null);
        }

        if ($primaryField !== null) {
            $columns = array_merge([
                $this->buildPrimaryKeyColumnDefinition($primaryField, $relationsByField['id'] ?? null),
            ], $columns);
        } else {
            array_unshift($columns, '$table->id();');
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
            'drops' => [],
            'mode' => 'create',
            'raw_up' => [],
            'raw_down' => [],
            'use_db' => false,
        ];
    }

    private function buildPrimaryKeyColumnDefinition(Field $field, ?array $relation): string
    {
        $type = Str::lower((string) $field->type);

        if ($type === 'id' && Str::lower($field->name) === 'id') {
            return '$table->id();';
        }

        $definition = $this->buildColumnDefinition($field, $relation);

        if (! preg_match('/->\s*primary\s*\(/i', $definition)) {
            $definition = preg_replace('/;$/', '->primary();', $definition) ?? $definition;
        }

        return $definition;
    }

    /**
     * @param array<string, array<string, mixed>> $relationsByField
     * @return array{context: array<string, mixed>, metadata: array<string, mixed>}|null
     */
    private function buildReservedAlterMigration(Blueprint $blueprint, array $relationsByField): ?array
    {
        $augmented = $this->reservedAugmentations($blueprint, $relationsByField);

        $customStatements = $this->reservedCustomStatements($blueprint);

        if (
            $augmented['fields'] === []
            && ! $augmented['soft_deletes']
            && $customStatements['up'] === []
            && $customStatements['down'] === []
        ) {
            return null;
        }

        $table = $this->tableName($blueprint);
        $className = 'Alter' . Str::studly($table) . 'Table';

        $columns = [];
        $drops = [];
        $fieldNames = [];

        foreach ($augmented['fields'] as $item) {
            /** @var Field $field */
            $field = $item['field'];
            $relation = $item['relation'];

            $columns[] = $this->buildColumnDefinition($field, $relation);
            $fieldNames[] = $field->name;

            if ($relation !== null && Str::lower($relation['type'] ?? '') === 'belongsto') {
                $drops[] = sprintf('$table->dropForeign([\'%s\']);', $field->name);
            }

            $drops[] = sprintf('$table->dropColumn(\'%s\');', $field->name);
        }

        if ($augmented['soft_deletes']) {
            $columns[] = '$table->softDeletes();';
            $drops[] = '$table->dropSoftDeletes();';
        }

        $columns = array_values(array_unique($columns));
        $drops = array_values(array_unique($drops));

        $context = [
            'class_name' => $className,
            'table' => $table,
            'columns' => $columns,
            'drops' => $drops,
            'mode' => 'alter',
            'raw_up' => $customStatements['up'],
            'raw_down' => $customStatements['down'],
            'use_db' => $this->statementsRequireDb(array_merge($customStatements['up'], $customStatements['down'])),
        ];

        $metadata = [
            'fields' => $fieldNames,
            'soft_deletes' => $augmented['soft_deletes'],
        ];

        return [
            'context' => $context,
            'metadata' => $metadata,
        ];
    }

    private function applyReservedBaseMigrationAdjustments(Blueprint $blueprint, ?string $migrationPath): void
    {
        if ($migrationPath === null || ! file_exists($migrationPath)) {
            return;
        }

        $idField = null;

        foreach ($blueprint->fields() as $field) {
            if (Str::lower((string) $field->name) === 'id') {
                $idField = $field;
                break;
            }
        }

        if ($idField === null) {
            return;
        }

        $type = Str::lower((string) ($idField->type ?? ''));

        if (! in_array($type, ['uuid', 'ulid'], true)) {
            return;
        }

        $contents = file_get_contents($migrationPath);

        if ($contents === false) {
            return;
        }

        $needle = $type === 'ulid' ? "->ulid('id')" : "->uuid('id')";

        if (str_contains($contents, $needle)) {
            return;
        }

        $replacement = $type === 'ulid'
            ? "\$table->ulid('id')->primary();"
            : "\$table->uuid('id')->primary();";

        $updated = preg_replace('/\\$table->id\(\);/', $replacement, $contents, 1, $replacements);

        if ($updated === null || $replacements === 0) {
            return;
        }

        $updated = str_replace("\$table->foreignId('user_id')->nullable()->index();", "\$table->foreignUuid('user_id')->nullable()->index();", $updated);

        file_put_contents($migrationPath, $updated);
    }

    /**
     * @param array<string, array<string, mixed>> $relationsByField
     * @return array{fields: array<int, array{field: Field, relation: array<string, mixed>|null}>, soft_deletes: bool}
     */
    private function reservedAugmentations(Blueprint $blueprint, array $relationsByField): array
    {
        $history = $this->loadHistory();
        $entry = $history['migrations'][$this->historyKey($blueprint)] ?? [];

        $baseline = $this->reservedBaselineFields($blueprint, $entry);
        $recorded = [];

        if (isset($entry['reserved_fields']) && is_array($entry['reserved_fields'])) {
            foreach ($entry['reserved_fields'] as $field) {
                if (! is_string($field)) {
                    continue;
                }

                $lower = Str::lower($field);

                if ($lower !== '') {
                    $recorded[] = $lower;
                }
            }
        }

        $appliedFields = $this->reservedFieldsFromExistingAlterFiles($blueprint, $entry);

        if ($appliedFields !== []) {
            $recorded = array_values(array_intersect($recorded, $appliedFields));
        } elseif (isset($entry['reserved_alters'])) {
            $recorded = [];
        }

        $registered = array_unique(array_merge($baseline, $recorded));

        $fields = [];

        foreach ($blueprint->fields() as $field) {
            $name = $field->name;

            if ($name === '') {
                continue;
            }

            $lower = Str::lower($name);

            if (in_array($lower, $registered, true)) {
                continue;
            }

            $fields[] = [
                'field' => $field,
                'relation' => $relationsByField[$name] ?? null,
            ];
        }

        $softDeletesRequested = $this->reservedWantsSoftDeletes($blueprint);
        $softDeletesRecorded = $this->interpretBoolean($entry['reserved_soft_deletes'] ?? null);

        return [
            'fields' => $fields,
            'soft_deletes' => $softDeletesRequested && ! $softDeletesRecorded,
        ];
    }

    /**
     * @param array<string, mixed> $historyEntry
     * @return array<int, string>
     */
    private function reservedBaselineFields(Blueprint $blueprint, array $historyEntry): array
    {
        $table = Str::lower($this->tableName($blueprint));

        $baseline = ['id', 'created_at', 'updated_at'];

        $configured = self::RESERVED_TABLE_BASELINES[$table]['fields'] ?? [];
        $baseline = array_merge($baseline, $this->collectFieldList($configured));

        $baseline = array_merge($baseline, $this->reservedBaselineFromBlueprint($blueprint));

        $historyBaseline = [];

        if (isset($historyEntry['reserved_baseline']) && is_array($historyEntry['reserved_baseline'])) {
            foreach ($historyEntry['reserved_baseline'] as $field) {
                if (! is_string($field)) {
                    continue;
                }

                $field = Str::lower($field);

                if ($field !== '') {
                    $historyBaseline[] = $field;
                }
            }
        }

        $appliedFields = $this->reservedFieldsFromExistingAlterFiles($blueprint, $historyEntry);

        if ($appliedFields !== []) {
            $historyBaseline = array_values(array_intersect($historyBaseline, $appliedFields));
        } elseif (isset($historyEntry['reserved_alters'])) {
            $historyBaseline = [];
        }

        $baseline = array_merge($baseline, $historyBaseline);

        return array_values(array_unique($baseline));
    }

    private function reservedFieldsFromExistingAlterFiles(Blueprint $blueprint, array $historyEntry): array
    {
        if (! isset($historyEntry['reserved_alters']) || ! is_array($historyEntry['reserved_alters'])) {
            return [];
        }

        $table = $this->tableName($blueprint);
        $existing = [];

        foreach ($historyEntry['reserved_alters'] as $alter) {
            if (! is_array($alter)) {
                continue;
            }

            $prefix = $alter['prefix'] ?? null;

            if (! is_string($prefix) || $prefix === '') {
                continue;
            }

            $root = $this->currentMigrationsRoot ?? 'database/migrations';
            $root = rtrim(str_replace('\\', '/', $root), '/');
            $path = $this->toAbsolutePath($root . '/' . $prefix . '_alter_' . $table . '_table.php');

            if (! is_file($path)) {
                continue;
            }

            if (! isset($alter['fields']) || ! is_array($alter['fields'])) {
                continue;
            }

            foreach ($alter['fields'] as $field) {
                if (! is_string($field)) {
                    continue;
                }

                $lower = Str::lower($field);

                if ($lower !== '') {
                    $existing[] = $lower;
                }
            }
        }

        return array_values(array_unique($existing));
    }

    private function reservedBaselineFromBlueprint(Blueprint $blueprint): array
    {
        $fields = [];

        $optionPaths = [
            ['reserved_baseline'],
            ['migration', 'reserved_baseline'],
            ['migration', 'baseline'],
            ['reserved_migration', 'baseline'],
        ];

        $metadataPaths = [
            ['reserved_baseline'],
            ['migration', 'reserved_baseline'],
            ['migration', 'baseline'],
            ['migration_reserved', 'baseline'],
        ];

        foreach ($this->extractNestedValues($blueprint->options(), $optionPaths) as $value) {
            $fields = array_merge($fields, $this->collectFieldList($value));
        }

        foreach ($this->extractNestedValues($blueprint->metadata(), $metadataPaths) as $value) {
            $fields = array_merge($fields, $this->collectFieldList($value));
        }

        return array_values(array_unique($fields));
    }

    /**
     * @param array<string, mixed> $source
     * @param array<int, array<int, string>> $paths
     * @return array<int, mixed>
     */
    private function extractNestedValues(array $source, array $paths): array
    {
        $results = [];

        foreach ($paths as $path) {
            $cursor = $source;
            $found = true;

            foreach ($path as $segment) {
                if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                    $found = false;
                    break;
                }

                $cursor = $cursor[$segment];
            }

            if ($found) {
                $results[] = $cursor;
            }
        }

        return $results;
    }

    private function reservedCustomStatements(Blueprint $blueprint): array
    {
        $up = [];
        $down = [];

        $optionPaths = [
            ['migration', 'raw'],
            ['migration', 'reserved_raw'],
            ['migration', 'reserved_statements'],
        ];

        $metadataPaths = [
            ['migration', 'raw'],
            ['migration', 'reserved_raw'],
            ['migration', 'reserved_statements'],
        ];

        foreach ($this->extractNestedValues($blueprint->options(), $optionPaths) as $value) {
            $this->mergeStatementConfig($value, $up, $down);
        }

        foreach ($this->extractNestedValues($blueprint->metadata(), $metadataPaths) as $value) {
            $this->mergeStatementConfig($value, $up, $down);
        }

        return [
            'up' => array_values(array_unique($up)),
            'down' => array_values(array_unique($down)),
        ];
    }

    private function mergeStatementConfig(mixed $value, array &$up, array &$down): void
    {
        if (! is_array($value)) {
            return;
        }

        $up = array_merge($up, $this->collectStatementList($value['up'] ?? null));
        $down = array_merge($down, $this->collectStatementList($value['down'] ?? null));
    }

    private function collectStatementList(mixed $value): array
    {
        $statements = [];

        if (is_string($value)) {
            $statement = trim($value);

            if ($statement !== '') {
                $statements[] = $statement;
            }

            return $statements;
        }

        if (! is_array($value)) {
            return $statements;
        }

        foreach ($value as $item) {
            $statements = array_merge($statements, $this->collectStatementList($item));
        }

        return $statements;
    }

    private function statementsRequireDb(array $statements): bool
    {
        foreach ($statements as $statement) {
            if (! is_string($statement)) {
                continue;
            }

            if (str_contains($statement, 'DB::')) {
                return true;
            }
        }

        return false;
    }

    private function reservedAlterCustomPrefix(Blueprint $blueprint): ?string
    {
        $paths = [
            ['migration', 'reserved_prefix'],
            ['migration', 'alter_prefix'],
        ];

        foreach ($this->extractNestedValues($blueprint->options(), $paths) as $value) {
            $prefix = $this->normalizeTimestampPrefix($value);

            if ($prefix !== null) {
                return $prefix;
            }
        }

        foreach ($this->extractNestedValues($blueprint->metadata(), $paths) as $value) {
            $prefix = $this->normalizeTimestampPrefix($value);

            if ($prefix !== null) {
                return $prefix;
            }
        }

        return null;
    }

    private function customMigrationPrefix(Blueprint $blueprint): ?string
    {
        $paths = [
            ['migration', 'prefix'],
            ['migration', 'custom_prefix'],
        ];

        foreach ($this->extractNestedValues($blueprint->options(), $paths) as $value) {
            $prefix = $this->normalizeTimestampPrefix($value);

            if ($prefix !== null) {
                return $prefix;
            }
        }

        foreach ($this->extractNestedValues($blueprint->metadata(), $paths) as $value) {
            $prefix = $this->normalizeTimestampPrefix($value);

            if ($prefix !== null) {
                return $prefix;
            }
        }

        return null;
    }

    private function normalizeTimestampPrefix(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $prefix = trim($value);

        if ($prefix === '') {
            return null;
        }

        if (! preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}$/', $prefix)) {
            return null;
        }

        return $prefix;
    }

    private function collectFieldList(mixed $value): array
    {
        $fields = [];

        if (is_string($value)) {
            $normalized = Str::lower(trim($value));

            if ($normalized !== '') {
                $fields[] = $normalized;
            }

            return $fields;
        }

        if (! is_array($value)) {
            return $fields;
        }

        foreach ($value as $item) {
            $fields = array_merge($fields, $this->collectFieldList($item));
        }

        return $fields;
    }

    private function reservedWantsSoftDeletes(Blueprint $blueprint): bool
    {
        $options = $blueprint->options();

        if (array_key_exists('softDeletes', $options) && $this->interpretBoolean($options['softDeletes'])) {
            return true;
        }

        foreach ($blueprint->fields() as $field) {
            if (Str::lower($field->name) === 'deleted_at') {
                return true;
            }
        }

        return false;
    }

    private function resolveReservedAlterPath(Blueprint $blueprint, array $options, ?string $migrationsRootOverride = null): array
    {
        $migrationsRoot = $migrationsRootOverride ?? $this->resolveMigrationsRoot($blueprint, $options);
        $migrationsRoot = rtrim(str_replace('\\', '/', $migrationsRoot), '/');
        $customPrefix = $this->reservedAlterCustomPrefix($blueprint);

        if ($customPrefix !== null) {
            $prefix = $customPrefix;

            if ($this->lastGeneratedTimestamp === null || strcmp($prefix, $this->lastGeneratedTimestamp) > 0) {
                $this->lastGeneratedTimestamp = $prefix;
            }
        } else {
            $prefix = $this->generateNextTimestamp();
        }
        $filename = sprintf('%s_alter_%s_table.php', $prefix, $this->tableName($blueprint));

        return [
            'path' => $migrationsRoot . '/' . $filename,
            'prefix' => $prefix,
        ];
    }

    private function storeReservedAlterHistory(Blueprint $blueprint, string $prefix, array $metadata): void
    {
        $history = $this->loadHistory();
        $key = $this->historyKey($blueprint);

        if (! isset($history['migrations'][$key]) || ! is_array($history['migrations'][$key])) {
            return;
        }

        $entry = $history['migrations'][$key];

        $fields = [];

        if (isset($entry['reserved_fields']) && is_array($entry['reserved_fields'])) {
            $fields = $entry['reserved_fields'];
        }

        foreach ($metadata['fields'] ?? [] as $field) {
            if (! is_string($field) || $field === '') {
                continue;
            }

            $fields[] = Str::lower($field);
        }

        $history['migrations'][$key]['reserved_fields'] = array_values(array_unique($fields));

        $baseline = [];

        if (isset($entry['reserved_baseline']) && is_array($entry['reserved_baseline'])) {
            $baseline = $entry['reserved_baseline'];
        }

        foreach ($metadata['fields'] ?? [] as $field) {
            if (! is_string($field) || $field === '') {
                continue;
            }

            $baseline[] = Str::lower($field);
        }

        $history['migrations'][$key]['reserved_baseline'] = array_values(array_unique($baseline));

        if (($metadata['soft_deletes'] ?? false) === true) {
            $history['migrations'][$key]['reserved_soft_deletes'] = true;
        }

        $alters = [];

        if (isset($entry['reserved_alters']) && is_array($entry['reserved_alters'])) {
            $alters = $entry['reserved_alters'];
        }

        $alters[] = [
            'prefix' => $prefix,
            'fields' => array_values(array_filter(
                array_map(static fn ($field): ?string => is_string($field) ? $field : null, $metadata['fields'] ?? []),
                static fn ($field): bool => $field !== null
            )),
            'soft_deletes' => (bool) ($metadata['soft_deletes'] ?? false),
        ];

        $history['migrations'][$key]['reserved_alters'] = $alters;

        $this->history = $history;
        $this->persistHistory();
    }

    /**
     * @param array<string, mixed>|null $relation
     */
    private function buildColumnDefinition(Field $field, ?array $relation): string
    {
    $rules = $this->parseRules($field->rules ?? null);
    $nullable = $field->nullable ?? array_key_exists('nullable', $rules);
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

        $type = Str::lower((string) $field->type);
        $method = match ($type) {
            'uuid' => 'foreignUuid',
            'ulid' => 'foreignUlid',
            default => 'foreignId',
        };

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
            $name = $field->name;

            if (! is_string($name) || $name === '') {
                continue;
            }

            $type = Str::lower((string) $field->type);

            if ($name === 'id' && ! in_array($type, ['uuid', 'ulid'], true)) {
                continue;
            }

            if ($name !== 'id' && in_array($name, ['created_at', 'updated_at', 'deleted_at'], true)) {
                continue;
            }

            $relation = $relationsByField[$name] ?? null;
            $value = $this->factoryValueForField($field, $relation, $needsStr);
            $properties[] = [
                'name' => $name,
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
            'string', 'text' => $this->stringFactoryValue($field, $rules, $needsStr),
            'integer', 'biginteger', 'bigint', 'id' => [
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

        $key = $this->historyKey($blueprint);
        $existing = $history['migrations'][$key] ?? [];

        if (! is_array($existing)) {
            $existing = [];
        }

        $history['migrations'][$key] = array_merge($existing, [
            'prefix' => $prefix,
            'table' => $this->tableName($blueprint),
            'entity' => $blueprint->entity(),
            'module' => $blueprint->module(),
            'reserved' => $this->shouldReserveMigration($blueprint, $existing),
        ]);

        if (($history['migrations'][$key]['reserved'] ?? false) === true) {
            $baseline = [];

            if (isset($history['migrations'][$key]['reserved_baseline']) && is_array($history['migrations'][$key]['reserved_baseline'])) {
                foreach ($history['migrations'][$key]['reserved_baseline'] as $field) {
                    if (! is_string($field) || $field === '') {
                        continue;
                    }

                    $baseline[] = Str::lower($field);
                }
            }

            $baseline = array_merge($baseline, $this->reservedBaselineFields($blueprint, []));

            if ($baseline === []) {
                foreach ($blueprint->fields() as $field) {
                    $name = Str::lower($field->name);

                    if ($name === '') {
                        continue;
                    }

                    $baseline[] = $name;
                }
            }

            $history['migrations'][$key]['reserved_baseline'] = array_values(array_unique($baseline));

            if (! isset($history['migrations'][$key]['reserved_fields']) || ! is_array($history['migrations'][$key]['reserved_fields'])) {
                $history['migrations'][$key]['reserved_fields'] = [];
            }

            if (! array_key_exists('reserved_soft_deletes', $history['migrations'][$key])) {
                $history['migrations'][$key]['reserved_soft_deletes'] = false;
            }
        }

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

        if (! isset($this->refreshedSeederModules[$moduleKey])) {
            if (isset($history['seeders'][$moduleKey]) && is_array($history['seeders'][$moduleKey])) {
                $history['seeders'][$moduleKey]['entities'] = [];
            }

            $this->refreshedSeederModules[$moduleKey] = true;
        }

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

    private function shouldReserveMigration(Blueprint $blueprint, array $existing = []): bool
    {
        if (isset($existing['reserved']) && $this->interpretBoolean($existing['reserved'])) {
            return true;
        }

        $options = $blueprint->options();

        $flat = $options['migration_reserved'] ?? $options['reserved_migration'] ?? null;
        if ($this->interpretBoolean($flat)) {
            return true;
        }

        $nested = $options['migration'] ?? null;
        if (is_array($nested) && $this->interpretBoolean($nested['reserved'] ?? null)) {
            return true;
        }

        $metadata = $blueprint->metadata();

        if (is_array($metadata)) {
            $metaFlag = $metadata['migration_reserved'] ?? null;

            if ($this->interpretBoolean($metaFlag)) {
                return true;
            }

            $metaNested = $metadata['migration'] ?? null;

            if (is_array($metaNested) && $this->interpretBoolean($metaNested['reserved'] ?? null)) {
                return true;
            }
        }

        if (Str::lower($this->tableName($blueprint)) === 'users') {
            return true;
        }

        return false;
    }

    private function interpretBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    private function isMigrationReserved(Blueprint $blueprint): bool
    {
        $history = $this->loadHistory();
        $entry = $history['migrations'][$this->historyKey($blueprint)] ?? null;

        if (! is_array($entry)) {
            return $this->shouldReserveMigration($blueprint);
        }

        return $this->shouldReserveMigration($blueprint, $entry);
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

        $segments = array_values(array_filter(explode('\\', $moduleStudly), static fn (string $segment): bool => $segment !== ''));

        if ($segments === []) {
            return null;
        }

        $classSegment = array_pop($segments);
        $namespace = 'Database\Seeders';

        if ($segments !== []) {
            $namespace .= '\\' . implode('\\', $segments);
        }

        return [
            'namespace' => $namespace,
            'class_name' => $classSegment . 'Seeder',
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
        $imports = $this->prepareDatabaseSeederImports($existingUses, $moduleSeeders);
        $uses = $imports['uses'];
        $callIdentifiers = $imports['identifiers'];

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

        foreach ($callIdentifiers as $identifier) {
            $lines[] = '            ' . $identifier . '::class,';
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
     * @return array{uses: array<int, string>, identifiers: array<int, string>}
     */
    private function prepareDatabaseSeederImports(array $existingUses, array $moduleSeeders): array
    {
        $moduleSeeders = array_values(array_unique($moduleSeeders));

        $moduleMap = [];
        foreach ($moduleSeeders as $class) {
            $moduleMap[strtolower($class)] = true;
        }

        $entries = [];
        $usedIdentifiers = [];

        foreach ($existingUses as $use) {
            $parsed = $this->parseUseStatement($use);

            if ($parsed === null) {
                continue;
            }

            $key = strtolower($parsed['fqcn']);

            if (isset($moduleMap[$key])) {
                continue;
            }

            if (! isset($entries[$key])) {
                $entries[$key] = [
                    'fqcn' => $parsed['fqcn'],
                    'alias' => $parsed['alias'],
                ];

                $identifier = $parsed['alias'] ?? class_basename($parsed['fqcn']);

                if ($identifier !== '') {
                    $usedIdentifiers[$identifier] = true;
                }
            }
        }

        $seederKey = strtolower('Illuminate\\Database\\Seeder');

        if (! isset($entries[$seederKey])) {
            $entries[$seederKey] = [
                'fqcn' => 'Illuminate\\Database\\Seeder',
                'alias' => null,
            ];
            $usedIdentifiers['Seeder'] = true;
        } else {
            $identifier = $entries[$seederKey]['alias'] ?? 'Seeder';

            if ($identifier !== '') {
                $usedIdentifiers[$identifier] = true;
            }
        }

        $baseCounts = [];

        foreach ($moduleSeeders as $class) {
            $base = class_basename($class);
            $baseCounts[$base] = ($baseCounts[$base] ?? 0) + 1;
        }

        $moduleIdentifiers = [];

        foreach ($moduleSeeders as $class) {
            $base = class_basename($class);
            $key = strtolower($class);

            $alias = null;

            if (($baseCounts[$base] ?? 0) > 1 || isset($usedIdentifiers[$base])) {
                $alias = $this->deriveSeederAlias($class, $usedIdentifiers);
            } else {
                $usedIdentifiers[$base] = true;
            }

            $entries[$key] = [
                'fqcn' => $class,
                'alias' => $alias,
            ];

            $moduleIdentifiers[$class] = $alias ?? $base;
        }

        $allEntries = array_values($entries);

        usort($allEntries, static function (array $a, array $b): int {
            if ($a['fqcn'] === 'Illuminate\\Database\\Seeder') {
                return $b['fqcn'] === 'Illuminate\\Database\\Seeder' ? 0 : -1;
            }

            if ($b['fqcn'] === 'Illuminate\\Database\\Seeder') {
                return 1;
            }

            return strcmp($a['fqcn'], $b['fqcn']);
        });

        $uses = [];

        foreach ($allEntries as $entry) {
            $statement = $entry['fqcn'];

            if ($entry['alias'] !== null) {
                $statement .= ' as ' . $entry['alias'];
            }

            $uses[] = $statement;
        }

        $identifiers = [];

        foreach ($moduleSeeders as $class) {
            $identifiers[] = $moduleIdentifiers[$class] ?? class_basename($class);
        }

        return [
            'uses' => $uses,
            'identifiers' => $identifiers,
        ];
    }

    private function parseUseStatement(string $statement): ?array
    {
        $trimmed = trim($statement);

        if ($trimmed === '') {
            return null;
        }

        $parts = preg_split('/\s+as\s+/i', $trimmed);

        if ($parts === false || $parts === []) {
            return null;
        }

        $fqcn = trim($parts[0], " \\ ");

        if ($fqcn === '') {
            return null;
        }

        $alias = null;

        if (isset($parts[1])) {
            $aliasPart = trim($parts[1]);

            if ($aliasPart !== '') {
                $alias = $aliasPart;
            }
        }

        return [
            'fqcn' => ltrim($fqcn, '\\'),
            'alias' => $alias,
        ];
    }

    /**
     * @param array<string, bool> $usedIdentifiers
     */
    private function deriveSeederAlias(string $fqcn, array &$usedIdentifiers): string
    {
        $segments = array_values(array_filter(explode('\\', trim($fqcn, '\\'))));

        if ($segments === []) {
            $baseAlias = 'ModuleSeederAlias';

            $alias = $baseAlias;
            $suffix = 1;

            while (isset($usedIdentifiers[$alias])) {
                $suffix++;
                $alias = $baseAlias . $suffix;
            }

            $usedIdentifiers[$alias] = true;

            return $alias;
        }

        $base = array_pop($segments);
        $qualifiers = [];

        foreach ($segments as $segment) {
            if (strcasecmp($segment, 'Database') === 0 || strcasecmp($segment, 'Seeders') === 0) {
                continue;
            }

            $qualifiers[] = $segment;
        }

        if ($qualifiers === []) {
            $qualifiers[] = 'Module';
        }

        $candidate = implode('', $qualifiers) . $base;

        if ($candidate === $base) {
            $candidate = 'Module' . $base;
        }

        $alias = $candidate;
        $suffix = 1;

        while (isset($usedIdentifiers[$alias])) {
            $suffix++;
            $alias = $candidate . $suffix;
        }

        $usedIdentifiers[$alias] = true;

        return $alias;
    }

    private function resolveMigrationsRoot(Blueprint $blueprint, array $options): string
    {
        $configured = $options['paths']['database']['migrations'] ?? null;
        $root = is_string($configured) && $configured !== '' ? $configured : 'database/migrations';
        $root = str_replace('\\', '/', $root);
        $root = rtrim($root, '/');

        if ($root === '') {
            $root = 'database/migrations';
        }

        $scope = $this->determineMigrationScope($blueprint, $options);

        if ($scope === 'central' || $scope === '') {
            return $root;
        }

        if ($this->pathEndsWithSegment($root, $scope)) {
            return $root;
        }

        return $root . '/' . $scope;
    }

    private function determineMigrationScope(Blueprint $blueprint, array $options): string
    {
        $tenancy = $blueprint->tenancy();
        $storage = null;

        if (isset($tenancy['storage']) && is_string($tenancy['storage'])) {
            $storage = strtolower(trim($tenancy['storage']));
        }

        $mode = $this->resolveTenancyMode($blueprint, $options);

        if ($storage === 'central' || $storage === 'tenant') {
            return $storage;
        }

        if ($storage === 'both') {
            return match ($mode) {
                'tenant' => 'tenant',
                'shared' => 'shared',
                default => 'central',
            };
        }

        return match ($mode) {
            'tenant' => 'tenant',
            'shared' => 'shared',
            default => 'central',
        };
    }

    private function resolveTenancyMode(Blueprint $blueprint, array $options): string
    {
        $optionMode = $options['tenancy']['blueprint_mode'] ?? null;

        if (is_string($optionMode)) {
            $normalized = strtolower(trim($optionMode));

            if ($normalized !== '') {
                return $normalized;
            }
        }

        $tenancy = $blueprint->tenancy();

        if (isset($tenancy['mode']) && is_string($tenancy['mode'])) {
            $normalized = strtolower(trim($tenancy['mode']));

            if ($normalized !== '') {
                return $normalized;
            }
        }

        $module = $blueprint->module();

        if (is_string($module) && $module !== '') {
            $segments = preg_split('#[\\/]+#', $module) ?: [];
            $mode = $this->detectTenancyModeFromSegments($segments);

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

    private function pathEndsWithSegment(string $path, string $segment): bool
    {
        $normalizedPath = rtrim(str_replace('\\', '/', $path), '/');
        $normalizedSegment = trim(str_replace('\\', '/', $segment), '/');

        if ($normalizedSegment === '') {
            return false;
        }

        $parts = explode('/', $normalizedPath);
        $last = end($parts);

        if ($last === false) {
            return false;
        }

        return strcasecmp($last, $normalizedSegment) === 0;
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
            if ($this->lastGeneratedTimestamp === null || strcmp($meta['last_generated'], $this->lastGeneratedTimestamp) > 0) {
                $this->lastGeneratedTimestamp = $meta['last_generated'];
            }
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

    private function findExistingMigration(Blueprint $blueprint, array $options, ?string $migrationsRootOverride = null): ?array
    {
        $migrationsRoot = $migrationsRootOverride ?? $this->resolveMigrationsRoot($blueprint, $options);
        $migrationsRoot = rtrim($migrationsRoot, '/\\');
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
    private function stringFactoryValue(Field $field, array $rules, bool &$needsStr): array
    {
        $name = is_string($field->name) ? $field->name : '';
        $lower = Str::lower($name);
        $unique = array_key_exists('unique', $rules);
        $maxLength = $this->stringLengthFromRules($rules);
        $decorate = static function (string $expression, bool $allowUnique = true) use ($unique): string {
            if (! $unique || ! $allowUnique) {
                return $expression;
            }

            if (Str::startsWith($expression, 'fake()->')) {
                return 'fake()->unique()->' . substr($expression, strlen('fake()->'));
            }

            return $expression;
        };

        if ($lower === 'remember_token') {
            $needsStr = true;
            $length = max(1, min($maxLength, 40));

            return [
                'expression' => 'Str::random(' . $length . ')',
                'is_expression' => true,
            ];
        }

        if (str_contains($lower, 'locale')) {
            $locales = ['es', 'es_ES', 'en', 'en_US', 'pt_BR', 'fr', 'de'];
            $filtered = array_values(array_filter($locales, static function (string $locale) use ($maxLength): bool {
                return strlen($locale) <= $maxLength;
            }));

            if ($filtered === []) {
                $filtered = ['en'];
            }

            return [
                'expression' => $decorate('fake()->randomElement(' . $this->inlineArrayLiteral($filtered) . ')', false),
                'is_expression' => true,
            ];
        }

        if (str_contains($lower, 'timezone')) {
            $timezones = ['UTC', 'America/Mexico_City', 'America/Bogota', 'America/Santiago', 'America/Sao_Paulo', 'Europe/Madrid'];
            $filtered = array_values(array_filter($timezones, static function (string $timezone) use ($maxLength): bool {
                return strlen($timezone) <= $maxLength;
            }));

            if ($filtered === []) {
                $filtered = ['UTC'];
            }

            return [
                'expression' => $decorate('fake()->randomElement(' . $this->inlineArrayLiteral($filtered) . ')', false),
                'is_expression' => true,
            ];
        }

        if (str_contains($lower, 'email')) {
            $expression = $unique ? 'fake()->unique()->safeEmail()' : 'fake()->safeEmail()';

            return [
                'expression' => $expression,
                'is_expression' => true,
            ];
        }

        if (str_contains($lower, 'first_name')) {
            return [
                'expression' => $decorate('fake()->firstName()'),
                'is_expression' => true,
            ];
        }

        if (str_contains($lower, 'last_name')) {
            return [
                'expression' => $decorate('fake()->lastName()'),
                'is_expression' => true,
            ];
        }

        if (str_contains($lower, 'name')) {
            return [
                'expression' => $decorate('fake()->words(3, true)'),
                'is_expression' => true,
            ];
        }

        if ($maxLength <= 10) {
            $pattern = str_repeat('?', max(1, $maxLength));

            return [
                'expression' => $decorate('fake()->lexify(\'' . $pattern . '\')'),
                'is_expression' => true,
            ];
        }

        if ($maxLength <= 60) {
            return [
                'expression' => $decorate('fake()->words(2, true)'),
                'is_expression' => true,
            ];
        }

        return [
            'expression' => $decorate('fake()->text(' . min(120, $maxLength) . ')'),
            'is_expression' => true,
        ];
    }

    /**
     * @param array<int, string> $values
     */
    private function inlineArrayLiteral(array $values): string
    {
        $encoded = array_map(static function (string $value): string {
            return '\'' . addslashes($value) . '\'';
        }, $values);

        return '[' . implode(', ', $encoded) . ']';
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

        $normalized = str_replace('\\', '/', $module);
        $segments = array_filter(array_map('trim', explode('/', $normalized)), static fn (string $part): bool => $part !== '');

        if ($segments === []) {
            return null;
        }

        $studlySegments = array_map(static fn (string $part): string => Str::studly($part), $segments);

        return implode('\\', $studlySegments);
    }
}

