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

class InfrastructureLayerGenerator implements LayerGenerator
{
    public function __construct(private readonly TemplateEngine $templates)
    {
    }

    public function layer(): string
    {
        return 'infrastructure';
    }

    /**
     * @param array<string, mixed> $options
     */
    public function generate(Blueprint $blueprint, ArchitectureDriver $driver, array $options = []): GenerationResult
    {
        $result = new GenerationResult();

        $context = $this->buildContext($blueprint, $driver, $options);
        $paths = $this->derivePaths($blueprint, $options);

        $template = sprintf('@%s/infrastructure/repository.stub.twig', $driver->name());

        if (! $this->templates->exists($template)) {
            $result->addWarning(sprintf('No se encontró la plantilla "%s" para la capa infrastructure en "%s".', $template, $driver->name()));

            return $result;
        }

        $result->addFile(new GeneratedFile(
            $paths['repository'],
            $this->templates->render($template, $context)
        ));

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
            'endpoints' => array_map(static fn (Endpoint $endpoint): array => $endpoint->toArray(), $blueprint->endpoints()),
            'options' => $blueprint->options(),
        ];

        $namespaces = $this->deriveNamespaces($blueprint, $options);
        $domainNamespaces = $this->deriveDomainNamespaces($blueprint, $options);
        $applicationNamespaces = $this->deriveApplicationNamespaces($blueprint, $options);

        return [
            'blueprint' => $blueprint->toArray(),
            'entity' => $entity,
            'module' => $this->moduleSegment($blueprint),
            'namespaces' => $namespaces,
            'domain' => $domainNamespaces,
            'application' => $applicationNamespaces,
            'naming' => $this->namingContext($blueprint),
            'model' => $this->deriveModelContext($blueprint, $domainNamespaces),
            'driver' => [
                'name' => $driver->name(),
                'layers' => $driver->layers(),
                'metadata' => $driver->metadata(),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, string>
     */
    private function deriveNamespaces(Blueprint $blueprint, array $options): array
    {
        $base = trim($options['namespaces']['infrastructure'] ?? 'App\\Infrastructure\\Persistence\\Eloquent', '\\');
        $module = $this->moduleSegment($blueprint);
        $root = $base;

        if ($module !== null) {
            $root .= '\\' . $module;
        }

        return [
            'infrastructure_root' => $root,
            'repositories' => $root . '\\Repositories',
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, string>
     */
    private function derivePaths(Blueprint $blueprint, array $options): array
    {
        $basePath = rtrim($options['paths']['infrastructure'] ?? 'app/Infrastructure/Persistence/Eloquent', '/');
        $module = $this->moduleSegment($blueprint);
        $entityName = Str::studly($blueprint->entity());

        $root = $basePath;

        if ($module !== null) {
            $root .= '/' . $module;
        }

        return [
            'repository' => sprintf('%s/Repositories/Eloquent%sRepository.php', $root, $entityName),
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, string>
     */
    private function deriveDomainNamespaces(Blueprint $blueprint, array $options): array
    {
        $base = trim($options['namespaces']['domain'] ?? 'App\\Domain', '\\');
        $module = $this->moduleSegment($blueprint);
        $root = $base;

        if ($module !== null) {
            $root .= '\\' . $module;
        }

        return [
            'models' => $root . '\\Models',
            'repositories' => $root . '\\Repositories',
        ];
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
            'filters' => $root . '\\Queries\\Filters',
        ];
    }

    private function namingContext(Blueprint $blueprint): array
    {
        $entityName = Str::studly($blueprint->entity());

        return [
            'entity_studly' => $entityName,
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

    private function deriveModelContext(Blueprint $blueprint, array $domainNamespaces): array
    {
        $fillable = [];
        $casts = [];
        $identifierField = 'id';
        $identifierPhpType = 'int';

        foreach ($blueprint->fields() as $field) {
            $fillable[] = $field->name;

            $type = strtolower($field->type);
            $cast = null;

            if ($field->name === 'id') {
                $identifierField = $field->name;
                $identifierPhpType = match ($type) {
                    'uuid', 'guid', 'string', 'ulid' => 'string',
                    'bigint', 'biginteger', 'unsignedbiginteger' => 'int',
                    default => in_array($type, ['integer', 'increments', 'bigincrements', 'id'], true) ? 'int' : 'mixed',
                };
            }

            if ($type === 'boolean') {
                $cast = 'boolean';
            } elseif (in_array($type, ['integer', 'bigint', 'biginteger', 'unsignedinteger', 'unsignedbiginteger', 'tinyint', 'smallint'], true)) {
                $cast = 'integer';
            } elseif (in_array($type, ['decimal', 'float', 'double'], true)) {
                $scale = $field->scale ?? 2;
                $cast = 'decimal:' . $scale;
            } elseif (in_array($type, ['datetime', 'timestamp', 'timestamptz'], true)) {
                $cast = 'datetime';
            } elseif ($type === 'date') {
                $cast = 'date';
            } elseif (in_array($type, ['json', 'array', 'object'], true)) {
                $cast = 'array';
            } elseif (in_array($type, ['uuid', 'guid'], true)) {
                $cast = 'string';
            }

            if ($cast !== null) {
                $casts[$field->name] = $cast;
            }
        }

        $options = $blueprint->options();
        $softDeletes = (bool) ($options['softDeletes'] ?? false);
        $timestampsEnabled = array_key_exists('timestamps', $options) ? (bool) $options['timestamps'] : true;

        if ($timestampsEnabled) {
            $casts['created_at'] = 'datetime';
            $casts['updated_at'] = 'datetime';
        }

        if ($softDeletes) {
            $casts['deleted_at'] = 'datetime';
        }

        $relationReturnTypes = [];
        $relationImports = [];
        $relations = [];
        $selfClass = Str::studly($blueprint->entity());
        $domainModelsNamespace = $domainNamespaces['models'];

        foreach ($blueprint->relations() as $relation) {
            $type = strtolower($relation->type);
            $target = $relation->target;

            if ($target === null || $target === '') {
                continue;
            }

            $relatedClass = Str::studly($target);
            $methodName = match ($type) {
                'belongsto' => Str::camel($target),
                'hasmany', 'belongstomany' => Str::camel(Str::plural($target)),
                'hasone' => Str::camel($target),
                default => null,
            };

            $eloquentMethod = match ($type) {
                'belongsto' => 'belongsTo',
                'hasmany' => 'hasMany',
                'hasone' => 'hasOne',
                'belongstomany' => 'belongsToMany',
                default => null,
            };

            $returnType = match ($type) {
                'belongsto' => 'BelongsTo',
                'hasmany' => 'HasMany',
                'hasone' => 'HasOne',
                'belongstomany' => 'BelongsToMany',
                default => null,
            };

            if ($methodName === null || $eloquentMethod === null || $returnType === null) {
                continue;
            }

            if (! in_array($returnType, $relationReturnTypes, true)) {
                $relationReturnTypes[] = $returnType;
            }

            $relatedFqcn = $domainModelsNamespace . '\\' . $relatedClass;

            if ($relatedClass !== $selfClass && ! in_array($relatedFqcn, $relationImports, true)) {
                $relationImports[] = $relatedFqcn;
            }

            $relations[] = [
                'method' => $methodName,
                'eloquent_method' => $eloquentMethod,
                'return_type' => $returnType,
                'related_class' => $relatedClass,
                'foreign_key' => $relation->field,
            ];
        }

        $fillable = array_values(array_unique($fillable));
        ksort($casts);

        if ($identifierPhpType === 'mixed') {
            $identifierPhpType = 'int|string';
        }

        return [
            'fillable' => $fillable,
            'casts' => $casts,
            'soft_deletes' => $softDeletes,
            'timestamps' => $timestampsEnabled,
            'relation_return_types' => $relationReturnTypes,
            'relation_imports' => $relationImports,
            'relations' => $relations,
            'identifier' => [
                'field' => $identifierField,
                'php_type' => $identifierPhpType,
            ],
        ];
    }
}
