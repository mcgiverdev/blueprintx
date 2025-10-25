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
use BlueprintX\Support\Concerns\ResolvesModelNamespaces;
use Illuminate\Support\Str;

class DomainLayerGenerator implements LayerGenerator
{
    use ResolvesModelNamespaces;

    public function __construct(private readonly TemplateEngine $templates)
    {
    }

    private function isPasswordField(Field $field): bool
    {
        return $field->name === 'password';
    }

    public function layer(): string
    {
        return 'domain';
    }

    /**
     * @param array<string, mixed> $options
     */
    public function generate(Blueprint $blueprint, ArchitectureDriver $driver, array $options = []): GenerationResult
    {
        $result = new GenerationResult();

        $paths = $this->derivePaths($blueprint, $options);
        $context = $this->buildContext($blueprint, $driver, $options, $paths);

        $templates = [
            [
                'template' => sprintf('@%s/domain/entity.stub.twig', $driver->name()),
                'path' => $paths['model'],
                'context' => $context,
            ],
            [
                'template' => sprintf('@%s/domain/repository_interface.stub.twig', $driver->name()),
                'path' => $paths['repository_interface'],
                'context' => $context,
            ],
            [
                'template' => sprintf('@%s/domain/exceptions/domain_exception.stub.twig', $driver->name()),
                'path' => $paths['shared_domain_exception'],
                'context' => $context,
            ],
            [
                'template' => sprintf('@%s/domain/exceptions/domain_not_found_exception.stub.twig', $driver->name()),
                'path' => $paths['shared_domain_not_found_exception'],
                'context' => $context,
            ],
            [
                'template' => sprintf('@%s/domain/exceptions/domain_conflict_exception.stub.twig', $driver->name()),
                'path' => $paths['shared_domain_conflict_exception'],
                'context' => $context,
            ],
            [
                'template' => sprintf('@%s/domain/exceptions/domain_validation_exception.stub.twig', $driver->name()),
                'path' => $paths['shared_domain_validation_exception'],
                'context' => $context,
            ],
        ];

        foreach ($context['errors']['custom'] as $customError) {
            $templates[] = [
                'template' => sprintf('@%s/domain/exceptions/custom_exception.stub.twig', $driver->name()),
                'path' => $customError['path'],
                'context' => array_merge($context, ['error' => $customError]),
            ];
        }

        if (($context['tenancy']['generate_trait'] ?? false) === true) {
            $templates[] = [
                'template' => sprintf('@%s/tenancy/tenant_aware_entity.stub.twig', $driver->name()),
                'path' => $paths['shared_domain_tenant_trait'],
                'context' => $context,
                'overwrite' => true,
            ];
        }

        foreach ($templates as $item) {
            if (! $this->templates->exists($item['template'])) {
                $result->addWarning(sprintf('No se encontró la plantilla "%s" para la capa domain en "%s".', $item['template'], $driver->name()));
                continue;
            }

            $result->addFile(new GeneratedFile(
                $item['path'],
                $this->templates->render($item['template'], $item['context']),
                (bool) ($item['overwrite'] ?? false)
            ));
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, string> $paths
     * @return array<string, mixed>
     */
    private function buildContext(Blueprint $blueprint, ArchitectureDriver $driver, array $options, array $paths): array
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
        $errors = [
            'custom' => $this->buildCustomErrors($blueprint, $namespaces, $paths),
        ];
        $tenancy = $this->buildTenancyContext($blueprint, $options, $paths, $namespaces);

        $security = is_array($options['security'] ?? null) ? $options['security'] : [];
        $rolesConfig = is_array($security['roles'] ?? null) ? $security['roles'] : [];

        $rolesDriver = strtolower((string) ($rolesConfig['driver'] ?? 'none'));

        if (! in_array($rolesDriver, ['none', 'spatie'], true)) {
            $rolesDriver = 'none';
        }

        $spatieGuard = null;

        if (isset($rolesConfig['guard']) && is_string($rolesConfig['guard'])) {
            $candidateGuard = strtolower(trim($rolesConfig['guard']));

            if ($candidateGuard !== '') {
                $spatieGuard = $candidateGuard;
            }
        }

        $auth = [
            'is_auth_model' => $this->isAuthModel($blueprint),
            'roles_driver' => $rolesDriver,
            'spatie_guard' => $spatieGuard,
        ];

        return [
            'blueprint' => $blueprint->toArray(),
            'entity' => $entity,
            'module' => $this->moduleSegment($blueprint),
            'namespaces' => $namespaces,
            'naming' => $this->namingContext($blueprint),
            'model' => $this->deriveModelContext($blueprint, $namespaces),
            'errors' => $errors,
            'auth' => $auth,
            'tenancy' => $tenancy,
            'security' => $security,
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
        $base = trim($options['namespaces']['domain'] ?? 'App\\Domain', '\\');
        $module = $this->moduleSegment($blueprint);
        $domainRoot = $base;

        if ($module !== null) {
            $domainRoot .= '\\' . $module;
        }

        $sharedBase = rtrim($options['paths']['domain'] ?? 'app/Domain', '/');

        return [
            'domain_root' => $domainRoot,
            'domain_models' => $domainRoot . '\\Models',
            'domain_repositories' => $domainRoot . '\\Repositories',
            'domain_exceptions' => $domainRoot . '\\Exceptions',
            'shared_root' => $base,
            'shared_exceptions' => trim($options['namespaces']['domain_shared_exceptions'] ?? $base . '\\Shared\\Exceptions', '\\'),
            'domain_shared_concerns' => trim($options['namespaces']['domain_shared_concerns'] ?? $base . '\\Shared\\Concerns', '\\'),
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, string> $paths
     * @param array<string, string> $namespaces
     * @return array<string, mixed>
     */
    private function buildTenancyContext(Blueprint $blueprint, array $options, array $paths, array $namespaces): array
    {
        $tenancyOptions = is_array($options['tenancy'] ?? null) ? $options['tenancy'] : [];

        $enabled = (bool) ($tenancyOptions['enabled'] ?? false);
        $mode = strtolower((string) ($tenancyOptions['blueprint_mode'] ?? 'central'));

        if (! in_array($mode, ['central', 'tenant', 'shared'], true)) {
            $mode = 'central';
        }

        $applies = $enabled && in_array($mode, ['tenant', 'shared'], true);

        return [
            'enabled' => $enabled,
            'mode' => $mode,
            'driver' => $tenancyOptions['driver'] ?? null,
            'trait_namespace' => $namespaces['domain_shared_concerns'],
            'trait_path' => $paths['shared_domain_tenant_trait'],
            'foreign_key' => $this->resolveTenantForeignKey($blueprint),
            'generate_trait' => $applies,
            'applies_to_entity' => $applies,
        ];
    }

    private function resolveTenantForeignKey(Blueprint $blueprint): string
    {
        foreach ($blueprint->fields() as $field) {
            $name = $field->name;

            if ($name === 'tenant_id') {
                return 'tenant_id';
            }

            if (str_contains($name, 'tenant') && str_ends_with($name, '_id')) {
                return $name;
            }
        }

        foreach ($blueprint->relations() as $relation) {
            $field = $relation->field;

            if ($field !== '' && str_contains($field, 'tenant')) {
                return $field;
            }
        }

        return 'tenant_id';
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, string>
     */
    private function derivePaths(Blueprint $blueprint, array $options): array
    {
        $basePath = rtrim($options['paths']['domain'] ?? 'app/Domain', '/');
        $module = $this->moduleSegment($blueprint);
        $entityName = Str::studly($blueprint->entity());

        $root = $basePath;

        if ($module !== null) {
            $root .= '/' . str_replace('\\', '/', $module);
        }

        $sharedRootPath = sprintf('%s/Shared/Exceptions', $basePath);
        $sharedConcernsPath = sprintf('%s/Shared/Concerns', $basePath);

        return [
            'model' => sprintf('%s/Models/%s.php', $root, $entityName),
            'repository_interface' => sprintf('%s/Repositories/%sRepositoryInterface.php', $root, $entityName),
            'domain_exceptions' => $root . '/Exceptions',
            'shared_domain_exception' => sprintf('%s/DomainException.php', $sharedRootPath),
            'shared_domain_not_found_exception' => sprintf('%s/DomainNotFoundException.php', $sharedRootPath),
            'shared_domain_conflict_exception' => sprintf('%s/DomainConflictException.php', $sharedRootPath),
            'shared_domain_validation_exception' => sprintf('%s/DomainValidationException.php', $sharedRootPath),
            'shared_domain_tenant_trait' => sprintf('%s/TenantAwareEntity.php', $sharedConcernsPath),
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

    private function namingContext(Blueprint $blueprint): array
    {
        $entityName = Str::studly($blueprint->entity());
        $entityPlural = Str::pluralStudly($entityName);
        $table = $blueprint->table();

        if (! is_string($table) || $table === '') {
            $table = Str::snake($entityPlural);
        }

        return [
            'entity_studly' => $entityName,
            'entity_variable' => Str::camel($blueprint->entity()),
            'entity_snake' => Str::snake($blueprint->entity()),
            'entity_plural_studly' => $entityPlural,
            'entity_plural_variable' => Str::camel(Str::plural($blueprint->entity())),
            'entity_table' => $table,
        ];
    }

    private function deriveModelContext(Blueprint $blueprint, array $namespaces): array
    {
        $fillable = [];
        $casts = [];
        $hidden = [];
        $hasPasswordField = false;
        $identifierField = 'id';
        $identifierPhpType = 'int';
        $identifierColumnType = 'increments';
        $identifierAutoIncrement = true;
        $identifierKeyType = null;

        foreach ($blueprint->fields() as $field) {
            $fillable[] = $field->name;

            $type = strtolower($field->type);
            $cast = null;

            if ($field->name === 'id') {
                $identifierField = $field->name;
                $identifierColumnType = $type;
                $identifierPhpType = match ($type) {
                    'uuid', 'guid', 'string' => 'string',
                    'ulid' => 'string',
                    'bigint', 'biginteger', 'unsignedbiginteger' => 'int',
                    default => in_array($type, ['integer', 'increments', 'bigincrements', 'id'], true) ? 'int' : 'mixed',
                };

                $identifierAutoIncrement = in_array($type, ['id', 'increments', 'integer', 'bigincrements', 'bigint', 'biginteger', 'unsignedbiginteger', 'unsignedbigint', 'unsignedinteger'], true);

                if (in_array($type, ['uuid', 'guid', 'ulid', 'string'], true)) {
                    $identifierKeyType = 'string';
                    $identifierAutoIncrement = false;
                } elseif ($identifierAutoIncrement) {
                    $identifierKeyType = null;
                }
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

            if ($this->isPasswordField($field)) {
                $hidden[] = $field->name;
                $hasPasswordField = true;
            }

            if ($field->name === 'remember_token') {
                $hidden[] = $field->name;
            }
        }

        $options = $blueprint->options();
        $softDeletes = (bool) ($options['softDeletes'] ?? false);
        $timestampsEnabled = array_key_exists('timestamps', $options)
            ? (bool) $options['timestamps']
            : true;

        if ($timestampsEnabled) {
            $casts['created_at'] = 'datetime';
            $casts['updated_at'] = 'datetime';
        }

        if ($softDeletes) {
            $casts['deleted_at'] = 'datetime';
        }

        $fillable = array_values(array_unique($fillable));
        ksort($casts);
        $hidden = array_values(array_unique($hidden));

        if ($identifierPhpType === 'mixed') {
            $identifierPhpType = 'int|string';
        }

        if ($identifierKeyType === null && in_array($identifierColumnType, ['uuid', 'guid', 'ulid', 'string'], true)) {
            $identifierKeyType = 'string';
        }

        $relationReturnTypes = [];
        $relationImports = [];
        $relations = [];
        $relationMethodNames = [];
        $selfClass = Str::studly($blueprint->entity());
        $domainModelsNamespace = $namespaces['domain_models'];
        $sharedRootNamespace = $namespaces['shared_root'] ?? 'App\\Domain';
        $selfFqcn = $domainModelsNamespace . '\\' . $selfClass;

        foreach ($blueprint->relations() as $relation) {
            $type = strtolower($relation->type);
            $target = $relation->target;

            if ($target === null || $target === '') {
                continue;
            }

            $parsedTarget = $this->parseRelationTarget($target);
            $relatedClass = $parsedTarget['entity'] ?? Str::studly($target);
            $targetModule = $parsedTarget['module'];
            $methodBaseName = $this->deriveRelationMethodBaseName($type, $relation, $target);

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

            if ($methodBaseName === null || $eloquentMethod === null || $returnType === null) {
                continue;
            }

            $methodName = $this->makeUniqueRelationMethodName($methodBaseName, $relationMethodNames);
            $relationMethodNames[] = $methodName;

            if (! in_array($returnType, $relationReturnTypes, true)) {
                $relationReturnTypes[] = $returnType;
            }

            $relatedNamespace = $this->resolveModelNamespace(
                $blueprint,
                $relatedClass,
                $targetModule,
                $domainModelsNamespace,
                $sharedRootNamespace
            );
            $relatedFqcn = $relatedNamespace . '\\' . $relatedClass;

            if ($relatedFqcn !== $selfFqcn && ! in_array($relatedFqcn, $relationImports, true)) {
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

        $relationReturnTypes = array_values(array_unique($relationReturnTypes));
        $relationImports = array_values(array_unique($relationImports));
        $hidden = array_values(array_unique($hidden));

        return [
            'identifier' => [
                'field' => $identifierField,
                'php_type' => $identifierPhpType,
                'column_type' => $identifierColumnType,
                'auto_increment' => $identifierAutoIncrement,
                'key_type' => $identifierKeyType,
            ],
            'fillable' => $fillable,
            'casts' => $casts,
            'hidden' => $hidden,
            'relations' => $relations,
            'relation_return_types' => $relationReturnTypes,
            'relation_imports' => $relationImports,
            'soft_deletes' => $softDeletes,
            'timestamps' => $timestampsEnabled,
            'authenticatable' => $this->isAuthModel($blueprint),
            'hash_password' => $hasPasswordField,
        ];
    }

    private function deriveRelationMethodBaseName(string $type, Relation $relation, string $target): ?string
    {
        return match ($type) {
            'belongsto' => $this->deriveBelongsToMethodName($relation, $target),
            'hasone' => $this->deriveBelongsToMethodName($relation, $target),
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

    private function isAuthModel(Blueprint $blueprint): bool
    {
        $metadata = $blueprint->metadata();
        $tags = [];

        if (isset($metadata['tags']) && is_array($metadata['tags'])) {
            foreach ($metadata['tags'] as $tag) {
                if (! is_string($tag)) {
                    continue;
                }

                $tags[] = strtolower($tag);
            }
        }

        if (in_array('auth', $tags, true) || in_array('authentication', $tags, true)) {
            return true;
        }

        $module = $blueprint->module();

        if (is_string($module) && $module !== '' && str_contains(strtolower($module), 'auth')) {
            return true;
        }

        $entity = strtolower($blueprint->entity());

        if (! in_array($entity, ['user', 'users'], true)) {
            return false;
        }

        foreach ($blueprint->fields() as $field) {
            if ($this->isPasswordField($field)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $existing
     */
    private function makeUniqueRelationMethodName(string $baseName, array $existing): string
    {
        $methodName = $baseName;
        $counter = 1;

        while (in_array($methodName, $existing, true)) {
            $counter++;
            $methodName = $baseName . $counter;
        }

        return $methodName;
    }

    /**
     * @param array<string, string> $namespaces
     * @param array<string, string> $paths
     * @return array<int, array<string, mixed>>
     */
    private function buildCustomErrors(Blueprint $blueprint, array $namespaces, array $paths): array
    {
        $errors = [];
        $namespace = $namespaces['domain_exceptions'];
        $extendsNamespace = $namespaces['shared_exceptions'];

        foreach ($blueprint->errors() as $error) {
            $exceptionClass = $error['exception_class'];
            $errors[] = [
                'name' => $error['name'],
                'code' => $error['code'],
                'message' => $error['message'],
                'status' => $error['status'],
                'extends' => $error['extends'],
                'extends_fqcn' => $extendsNamespace . '\\' . $error['extends'],
                'class' => $error['class'],
                'exception_class' => $exceptionClass,
                'namespace' => $namespace,
                'path' => sprintf('%s/%s.php', $paths['domain_exceptions'], $exceptionClass),
                'description' => $error['description'] ?? null,
            ];
        }

        return $errors;
    }
}
