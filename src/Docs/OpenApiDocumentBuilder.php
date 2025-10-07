<?php

namespace BlueprintX\Docs;

use BlueprintX\Blueprint\Blueprint;
use BlueprintX\Blueprint\Endpoint;
use BlueprintX\Blueprint\Field;
use BlueprintX\Contracts\BlueprintParser;
use BlueprintX\Kernel\BlueprintLocator;
use Illuminate\Support\Str;
use stdClass;
use Throwable;

class OpenApiDocumentBuilder
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $additionalSchemas = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $relatedSchemaCache = [];

    /**
     * @var array<string, Blueprint>
     */
    private array $relatedBlueprintCache = [];

    public function __construct(
        private readonly ?BlueprintParser $parser = null,
        private readonly ?BlueprintLocator $locator = null,
    ) {
    }

    public function build(Blueprint $blueprint): array
    {
        $this->additionalSchemas = [];
        $this->relatedSchemaCache = [];
        $this->relatedBlueprintCache = [];

        $entityName = Str::studly($blueprint->entity());
        $tags = $this->resolveTags($blueprint, $entityName);

        $paths = $this->buildPaths($blueprint, $entityName, $tags[0] ?? $entityName);

        $schemas = $this->buildSchemas($blueprint, $entityName);

        if ($this->additionalSchemas !== []) {
            $schemas = array_merge($schemas, $this->additionalSchemas);
        }

        $document = [
            'openapi' => '3.1.0',
            'info' => array_filter([
                'title' => $entityName . ' API',
                'version' => '0.1.0',
                'description' => $blueprint->docs()['description'] ?? null,
            ]),
            'tags' => $this->buildTags($tags),
            'paths' => $paths === [] ? new stdClass() : $paths,
            'components' => $this->buildComponents($schemas),
            'x-domain-errors' => $this->buildDomainErrorCatalog($blueprint, $entityName),
        ];

        return $this->filterRecursive($document);
    }

    /**
     * @return string[]
     */
    private function resolveTags(Blueprint $blueprint, string $fallback): array
    {
        $docs = $blueprint->docs();

        if (isset($docs['tags']) && is_array($docs['tags']) && $docs['tags'] !== []) {
            return array_values(array_filter(array_map(static fn ($tag): ?string => is_string($tag) ? $tag : null, $docs['tags'])));
        }

        if ($blueprint->module()) {
            return [Str::title(str_replace(['-', '_'], ' ', $blueprint->module()))];
        }

        return [$fallback];
    }

    /**
     * @param string[] $tags
     */
    private function buildTags(array $tags): array
    {
        return array_map(static fn (string $tag): array => ['name' => $tag], $tags);
    }

    private function buildSchemas(Blueprint $blueprint, string $entityName): array
    {
        $resourceDataName = $entityName . 'Data';
        $resource = $this->buildEntityDataSchema($blueprint);
        $input = $this->buildEntityInputSchema($blueprint);

        $resourceEnvelope = [
            'type' => 'object',
            'required' => ['data'],
            'properties' => [
                'data' => ['$ref' => '#/components/schemas/' . $resourceDataName],
            ],
        ];

        $collection = [
            'type' => 'object',
            'properties' => [
                'data' => [
                    'type' => 'array',
                    'items' => ['$ref' => '#/components/schemas/' . $resourceDataName],
                ],
                'meta' => [
                    'type' => 'object',
                    'properties' => [
                        'current_page' => ['type' => 'integer'],
                        'from' => ['type' => 'integer', 'nullable' => true],
                        'per_page' => ['type' => 'integer'],
                        'to' => ['type' => 'integer', 'nullable' => true],
                        'last_page' => ['type' => 'integer', 'nullable' => true],
                        'total' => ['type' => 'integer', 'nullable' => true],
                    ],
                ],
            ],
            'required' => ['data', 'meta'],
        ];

        return [
            $entityName => $resourceEnvelope,
            $resourceDataName => $resource,
            $entityName . 'Input' => $input,
            $entityName . 'Collection' => $collection,
            'DomainError' => $this->buildDomainErrorSchema(),
        ];
    }

    private function buildDomainErrorSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'error' => [
                    'type' => 'object',
                    'properties' => [
                        'code' => ['type' => 'string'],
                        'message' => ['type' => 'string'],
                        'details' => [
                            'type' => 'object',
                            'additionalProperties' => [
                                'type' => 'array',
                                'items' => ['type' => 'string'],
                            ],
                        ],
                    ],
                    'required' => ['code', 'message'],
                ],
            ],
            'required' => ['error'],
        ];
    }

    private function buildEntityDataSchema(Blueprint $blueprint, bool $includeRelationships = true): array
    {
        $properties = [];
        $required = [];

        foreach ($blueprint->fields() as $field) {
            $schema = $this->mapFieldToSchema($field, 'resource');

            if ($this->isReadOnlyField($field)) {
                $schema['readOnly'] = true;
            }

            $properties[$field->name] = $schema;

            if (! $this->isNullableField($field)) {
                $required[] = $field->name;
            }
        }

        $properties = $this->ensureIdentifierProperty($properties, $required);
        $this->appendTimestampProperties($properties, $required, $blueprint);
        $this->appendSoftDeleteProperty($properties, $blueprint);

        if ($includeRelationships) {
            $this->appendRelationshipIncludes($properties, $blueprint);
        }

        $entitySchema = [
            'type' => 'object',
            'properties' => $properties,
        ];

        if ($required !== []) {
            $entitySchema['required'] = array_values(array_unique($required));
        }

        return $entitySchema;
    }

    /**
     * @param array<string, array<string, mixed>> $properties
     * @param list<string> $required
     * @return array<string, array<string, mixed>>
     */
    private function ensureIdentifierProperty(array $properties, array &$required): array
    {
        if (! isset($properties['id'])) {
            $properties['id'] = [
                'type' => 'integer',
                'format' => 'int64',
                'readOnly' => true,
            ];
            $required[] = 'id';
        } else {
            $properties['id']['readOnly'] = true;
            if (! in_array('id', $required, true)) {
                $required[] = 'id';
            }
        }

        return $properties;
    }

    /**
     * @param array<string, array<string, mixed>> $properties
     * @param list<string> $required
     */
    private function appendTimestampProperties(array &$properties, array &$required, Blueprint $blueprint): void
    {
        $options = $blueprint->options();
        $timestampsEnabled = $options['timestamps'] ?? true;

        if (! $timestampsEnabled) {
            return;
        }

        foreach (['created_at', 'updated_at'] as $timestampField) {
            if (! isset($properties[$timestampField])) {
                $properties[$timestampField] = [
                    'type' => 'string',
                    'format' => 'date-time',
                    'readOnly' => true,
                ];
            } else {
                $properties[$timestampField]['readOnly'] = true;
            }

            if (! in_array($timestampField, $required, true)) {
                $required[] = $timestampField;
            }
        }
    }

    /**
     * @param array<string, array<string, mixed>> $properties
     */
    private function appendSoftDeleteProperty(array &$properties, Blueprint $blueprint): void
    {
        $options = $blueprint->options();
        $softDeletesEnabled = $options['softDeletes'] ?? false;

        if (! $softDeletesEnabled) {
            return;
        }

        if (! isset($properties['deleted_at'])) {
            $properties['deleted_at'] = [
                'type' => 'string',
                'format' => 'date-time',
                'nullable' => true,
                'readOnly' => true,
            ];
        } else {
            $properties['deleted_at']['nullable'] = true;
            $properties['deleted_at']['readOnly'] = true;
        }
    }

    /**
     * @param array<string, array<string, mixed>> $properties
     */
    private function appendRelationshipIncludes(array &$properties, Blueprint $blueprint): void
    {
        $relationships = $blueprint->apiResources()['includes'] ?? [];

        if (! is_array($relationships) || $relationships === []) {
            return;
        }

        $relationMap = $this->buildRelationMap($blueprint);

        foreach ($relationships as $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $alias = $this->extractIncludeAlias($definition);

            if ($alias === '' || isset($properties[$alias])) {
                continue;
            }

            $relationName = isset($definition['relation']) ? Str::camel((string) $definition['relation']) : null;

            if ($relationName === null || $relationName === '') {
                $properties[$alias] = $this->buildFallbackIncludeSchema();

                continue;
            }

            $relation = $relationMap[$relationName] ?? null;

            if ($relation === null) {
                $properties[$alias] = $this->buildFallbackIncludeSchema();

                continue;
            }

            if ($relation['type'] !== 'belongsto') {
                $properties[$alias] = $this->buildFallbackIncludeSchema();

                continue;
            }

            $schemaName = $this->ensureRelatedSchema($blueprint, $relation['target']);

            if ($schemaName === null) {
                $properties[$alias] = $this->buildFallbackIncludeSchema();

                continue;
            }

            $properties[$alias] = [
                'allOf' => [
                    ['$ref' => '#/components/schemas/' . $schemaName],
                ],
                'nullable' => true,
                'readOnly' => true,
                'description' => 'Recurso relacionado embebido.',
            ];
        }
    }

    private function extractIncludeAlias(array $definition): string
    {
        if (isset($definition['alias']) && is_string($definition['alias'])) {
            $alias = trim($definition['alias']);

            if ($alias !== '') {
                return $alias;
            }
        }

        if (isset($definition['relation']) && is_string($definition['relation'])) {
            $relation = trim($definition['relation']);

            if ($relation !== '') {
                return $relation;
            }
        }

        return '';
    }

    private function buildFallbackIncludeSchema(): array
    {
        return [
            'type' => 'object',
            'nullable' => true,
            'readOnly' => true,
            'description' => 'Recurso relacionado embebido.',
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

    private function ensureRelatedSchema(Blueprint $context, string $target): ?string
    {
        $schemaName = Str::studly($target) . 'Data';

        if (isset($this->additionalSchemas[$schemaName])) {
            return $schemaName;
        }

        if (isset($this->relatedSchemaCache[$schemaName])) {
            $this->additionalSchemas[$schemaName] = $this->relatedSchemaCache[$schemaName];

            return $schemaName;
        }

        $relatedBlueprint = $this->loadRelatedBlueprint($context, $target);

        if ($relatedBlueprint === null) {
            return null;
        }

        $schema = $this->buildEntityDataSchema($relatedBlueprint, false);

        $this->relatedSchemaCache[$schemaName] = $schema;
        $this->additionalSchemas[$schemaName] = $schema;

        return $schemaName;
    }

    private function loadRelatedBlueprint(Blueprint $blueprint, string $targetEntity): ?Blueprint
    {
        if ($this->parser === null) {
            return null;
        }

        $path = $this->discoverRelatedBlueprintPath($blueprint, $targetEntity);

        if ($path === null) {
            return null;
        }

        if (isset($this->relatedBlueprintCache[$path])) {
            return $this->relatedBlueprintCache[$path];
        }

        try {
            $related = $this->parser->parse($path);
        } catch (Throwable) {
            return null;
        }

        $this->relatedBlueprintCache[$path] = $related;

        return $related;
    }

    private function discoverRelatedBlueprintPath(Blueprint $blueprint, string $targetEntity): ?string
    {
        if ($this->locator === null) {
            return null;
        }

        $basePath = $this->resolveBlueprintBasePath($blueprint);

        if ($basePath === null) {
            return null;
        }

        $entity = Str::snake($targetEntity);

        $paths = $this->locator->discover($basePath, $blueprint->module(), $entity);

        if ($paths === []) {
            $paths = $this->locator->discover($basePath, null, $entity);
        }

        return $paths[0] ?? null;
    }

    private function resolveBlueprintBasePath(Blueprint $blueprint): ?string
    {
        $path = $blueprint->path();

        if (! is_string($path) || $path === '') {
            return null;
        }

        $levels = 1;
        $module = $blueprint->module();

        if (is_string($module) && $module !== '') {
            $segments = array_values(array_filter(explode('/', str_replace('\\', '/', $module))));
            $levels += count($segments);
        }

        $resolved = dirname($path, max(1, $levels));

        if ($resolved === '' || $resolved === '.') {
            return null;
        }

        return rtrim($resolved, '\\/');
    }

    private function buildEntityInputSchema(Blueprint $blueprint): array
    {
        $properties = [];
        $required = [];

        foreach ($blueprint->fields() as $field) {
            if ($this->isReadOnlyField($field)) {
                continue;
            }

            $schema = $this->mapFieldToSchema($field, 'input');

            $properties[$field->name] = $schema;

            if ($this->isRequiredInputField($field)) {
                $required[] = $field->name;
            }
        }

        $inputSchema = [
            'type' => 'object',
            'properties' => $properties,
        ];

        if ($required !== []) {
            $inputSchema['required'] = array_values(array_unique($required));
        }

        return $inputSchema;
    }

    private function mapFieldToSchema(Field $field, string $context = 'resource'): array
    {
        $schema = match ($field->type) {
            'string', 'text' => ['type' => 'string'],
            'integer' => ['type' => 'integer', 'format' => 'int32'],
            'bigInteger' => ['type' => 'integer', 'format' => 'int64'],
            'decimal' => $context === 'resource'
                ? $this->mapDecimalForResource($field)
                : ['type' => 'number', 'format' => 'double'],
            'float' => ['type' => 'number', 'format' => 'float'],
            'boolean' => ['type' => 'boolean'],
            'date' => ['type' => 'string', 'format' => 'date'],
            'datetime' => ['type' => 'string', 'format' => 'date-time'],
            'json' => ['type' => 'object'],
            'uuid' => ['type' => 'string', 'format' => 'uuid'],
            default => ['type' => 'string'],
        };

        if (in_array($field->type, ['string', 'text'], true)) {
            $maxLength = $this->extractMaxLengthRule($field->rules);
            if ($maxLength !== null) {
                $schema['maxLength'] = $maxLength;
            }

            if ($this->rulesContain($field->rules, 'email')) {
                $schema['format'] = 'email';
            }
        }

        if ($field->nullable === true || $this->rulesContainNullable($field->rules)) {
            $schema['nullable'] = true;
        }

        if ($context === 'resource' && $field->default !== null) {
            $schema['default'] = $field->default;
        }

        return $schema;
    }

    private function mapDecimalForResource(Field $field): array
    {
        $scale = $field->scale ?? 2;

        if ($scale === 0) {
            return [
                'type' => 'string',
                'pattern' => '^\\d+$',
                'description' => 'Importe decimal sin decimales representado como cadena.',
            ];
        }

        return [
            'type' => 'string',
            'pattern' => sprintf('^\\d+\\.\\d{%d}$', $scale),
            'description' => sprintf('Importe decimal con %d decimales representado como cadena.', $scale),
        ];
    }

    private function extractMaxLengthRule(?string $rules): ?int
    {
        if ($rules === null) {
            return null;
        }

        foreach (explode('|', $rules) as $segment) {
            $segment = trim($segment);

            if (str_starts_with($segment, 'max:')) {
                $value = substr($segment, 4);
                if (ctype_digit($value)) {
                    return (int) $value;
                }
            }
        }

        return null;
    }

    private function rulesContain(?string $rules, string $needle): bool
    {
        if ($rules === null) {
            return false;
        }

        $segments = array_map(static fn (string $segment): string => strtolower(trim($segment)), explode('|', $rules));

        return in_array(strtolower($needle), $segments, true);
    }

    private function rulesContainNullable(?string $rules): bool
    {
        return $this->rulesContain($rules, 'nullable');
    }

    private function isNullableField(Field $field): bool
    {
        return $field->nullable === true || $this->rulesContainNullable($field->rules);
    }

    private function isRequiredInputField(Field $field): bool
    {
        if ($this->isReadOnlyField($field)) {
            return false;
        }

        if ($this->rulesContain($field->rules, 'required')) {
            return true;
        }

        if ($field->default !== null) {
            return false;
        }

        return ! $this->isNullableField($field);
    }

    private function isReadOnlyField(Field $field): bool
    {
        return in_array($field->name, ['id', 'created_at', 'updated_at', 'deleted_at'], true);
    }

    private function buildPaths(Blueprint $blueprint, string $entityName, string $tag): array
    {
        $paths = [];
        $collectionPath = $this->normalizePath($blueprint->apiBasePath(), $blueprint);
        $itemPath = $collectionPath . '/{id}';

        $collectionOperations = [];
        $itemOperations = [];
        $additionalPaths = [];

        foreach ($blueprint->endpoints() as $endpoint) {
            switch ($endpoint->type) {
                case 'crud':
                    $collectionOperations += $this->buildCrudCollectionOperations($entityName, $tag);
                    $itemOperations += $this->buildCrudItemOperations($entityName, $tag, $this->buildIdParameter($blueprint));
                    break;
                case 'search':
                    $path = $collectionPath . '/search';
                    $additionalPaths[$path]['get'] = $this->buildSearchOperation($entityName, $tag, $endpoint);
                    break;
                case 'stats':
                    $path = $collectionPath . '/stats';
                    $additionalPaths[$path]['get'] = $this->buildStatsOperation($entityName, $tag, $endpoint);
                    break;
                case 'restore':
                    $path = $itemPath . '/restore';
                    $additionalPaths[$path]['post'] = $this->buildRestoreOperation($entityName, $tag, $this->buildIdParameter($blueprint));
                    break;
                default:
                    break;
            }
        }

        if ($collectionOperations !== []) {
            $paths[$collectionPath] = $collectionOperations;
        }

        if ($itemOperations !== []) {
            $paths[$itemPath] = $itemOperations;
        }

        foreach ($additionalPaths as $path => $operations) {
            $paths[$path] = $operations;
        }

        foreach ($paths as $path => $operations) {
            $paths[$path] = $this->filterRecursive($operations);
        }

        return $paths;
    }

    private function buildCrudCollectionOperations(string $entityName, string $tag): array
    {
        return [
            'get' => [
                'tags' => [$tag],
                'summary' => 'Listar ' . Str::plural($entityName),
                'operationId' => 'list' . Str::plural($entityName),
                'responses' => [
                    '200' => [
                        'description' => 'Listado paginado devuelto correctamente.',
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => '#/components/schemas/' . $entityName . 'Collection'],
                            ],
                        ],
                    ],
                ],
            ],
            'post' => [
                'tags' => [$tag],
                'summary' => 'Crear ' . $entityName,
                'operationId' => 'create' . $entityName,
                'requestBody' => $this->buildRequestBody($entityName),
                'responses' => [
                    '201' => [
                        'description' => $entityName . ' creado correctamente.',
                        'headers' => [
                            'ETag' => $this->buildEtagHeaderReference(),
                        ],
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => '#/components/schemas/' . $entityName],
                            ],
                        ],
                    ],
                    '409' => $this->buildDomainErrorResponse('Conflicto al crear el recurso.', 'domain.conflict'),
                    '422' => $this->buildDomainErrorResponse('Error de validación.', 'domain.validation_failed', $this->buildValidationErrorExample()),
                ],
            ],
        ];
    }

    private function buildCrudItemOperations(string $entityName, string $tag, array $idParameter): array
    {
        return [
            'get' => [
                'tags' => [$tag],
                'summary' => 'Mostrar ' . $entityName,
                'operationId' => 'show' . $entityName,
                'parameters' => [$idParameter],
                'responses' => [
                    '200' => [
                        'description' => $entityName . ' encontrado.',
                        'headers' => [
                            'ETag' => $this->buildEtagHeaderReference(),
                        ],
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => '#/components/schemas/' . $entityName],
                            ],
                        ],
                    ],
                    '404' => $this->buildDomainErrorResponse('Recurso no encontrado.', 'domain.not_found'),
                ],
            ],
            'put' => [
                'tags' => [$tag],
                'summary' => 'Actualizar ' . $entityName,
                'operationId' => 'update' . $entityName,
                'parameters' => [$idParameter, $this->buildIfMatchHeaderParameterReference()],
                'requestBody' => $this->buildRequestBody($entityName),
                'responses' => [
                    '200' => [
                        'description' => $entityName . ' actualizado.',
                        'headers' => [
                            'ETag' => $this->buildEtagHeaderReference(),
                        ],
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => '#/components/schemas/' . $entityName],
                            ],
                        ],
                    ],
                    '404' => $this->buildDomainErrorResponse('Recurso no encontrado.', 'domain.not_found'),
                    '409' => $this->buildDomainErrorResponse('Conflicto al actualizar el recurso.', 'domain.conflict'),
                    '422' => $this->buildDomainErrorResponse('Error de validación.', 'domain.validation_failed', $this->buildValidationErrorExample()),
                ],
            ],
            'delete' => [
                'tags' => [$tag],
                'summary' => 'Eliminar ' . $entityName,
                'operationId' => 'delete' . $entityName,
                'parameters' => [$idParameter, $this->buildIfMatchHeaderParameterReference()],
                'responses' => [
                    '204' => ['description' => $entityName . ' eliminado.'],
                    '404' => $this->buildDomainErrorResponse('Recurso no encontrado.', 'domain.not_found'),
                    '409' => $this->buildDomainErrorResponse('Conflicto al eliminar el recurso.', 'domain.conflict'),
                ],
            ],
        ];
    }

    private function buildRequestBody(string $entityName): array
    {
        return [
            'required' => true,
            'content' => [
                'application/json' => [
                    'schema' => ['$ref' => '#/components/schemas/' . $entityName . 'Input'],
                ],
            ],
        ];
    }

    private function buildComponents(array $schemas): array
    {
        return [
            'schemas' => $schemas,
            'parameters' => [
                'IfMatchHeader' => $this->buildIfMatchHeaderComponent(),
            ],
            'headers' => [
                'ETag' => $this->buildEtagHeaderComponent(),
            ],
        ];
    }

    private function buildEtagHeaderComponent(): array
    {
        return [
            'description' => 'Versión del recurso en formato W/"<timestamp>".',
            'schema' => ['type' => 'string'],
            'example' => 'W/"1730146317.123456"',
        ];
    }

    private function buildEtagHeaderReference(): array
    {
        return ['$ref' => '#/components/headers/ETag'];
    }

    private function buildIfMatchHeaderParameterReference(): array
    {
        return ['$ref' => '#/components/parameters/IfMatchHeader'];
    }

    private function buildIfMatchHeaderComponent(): array
    {
        return [
            'name' => 'If-Match',
            'in' => 'header',
            'required' => true,
            'description' => 'Versión débil devuelta en el encabezado ETag. Utilice "*" para aceptar cualquier versión.',
            'schema' => ['type' => 'string'],
            'example' => 'W/"1730146317.123456"',
        ];
    }

    private function buildDomainErrorResponse(string $description, string $errorCode, ?array $example = null): array
    {
        $response = [
            'description' => $description,
            'x-error-code' => $errorCode,
            'content' => [
                'application/json' => [
                    'schema' => ['$ref' => '#/components/schemas/DomainError'],
                ],
            ],
        ];

        if ($example !== null) {
            $response['content']['application/json']['example'] = $example;
        }

        return $response;
    }

    private function buildValidationErrorExample(): array
    {
        return [
            'error' => [
                'code' => 'domain.validation_failed',
                'message' => 'Los datos proporcionados no son válidos.',
                'details' => [
                    'campo' => ['Este valor no es válido.'],
                ],
            ],
        ];
    }

    private function buildSearchOperation(string $entityName, string $tag, Endpoint $endpoint): array
    {
        return [
            'tags' => [$tag],
            'summary' => 'Buscar ' . Str::plural($entityName),
            'operationId' => 'search' . Str::plural($entityName),
            'parameters' => array_map(fn (string $field): array => [
                'name' => $field,
                'in' => 'query',
                'required' => false,
                'schema' => ['type' => 'string'],
            ], $endpoint->fields),
            'responses' => [
                '200' => [
                    'description' => 'Resultados de la búsqueda.',
                    'content' => [
                        'application/json' => [
                            'schema' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/' . $entityName . 'Data']],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function buildStatsOperation(string $entityName, string $tag, Endpoint $endpoint): array
    {
        return [
            'tags' => [$tag],
            'summary' => 'Estadísticas de ' . Str::plural($entityName),
            'operationId' => 'stats' . Str::plural($entityName),
            'parameters' => $endpoint->by ? [[
                'name' => $endpoint->by,
                'in' => 'query',
                'required' => false,
                'schema' => ['type' => 'string'],
            ]] : [],
            'responses' => [
                '200' => [
                    'description' => 'Estadísticas generadas correctamente.',
                    'content' => [
                        'application/json' => [
                            'schema' => ['type' => 'object'],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function buildRestoreOperation(string $entityName, string $tag, array $idParameter): array
    {
        return [
            'tags' => [$tag],
            'summary' => 'Restaurar ' . $entityName,
            'operationId' => 'restore' . $entityName,
            'parameters' => [$idParameter],
            'responses' => [
                '200' => [
                    'description' => $entityName . ' restaurado.',
                    'content' => [
                        'application/json' => [
                            'schema' => ['$ref' => '#/components/schemas/' . $entityName],
                        ],
                    ],
                ],
                '404' => $this->buildDomainErrorResponse('Recurso no encontrado.', 'domain.not_found'),
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildDomainErrorCatalog(Blueprint $blueprint, string $entityName): array
    {
        $baseErrors = [
            [
                'code' => 'domain.not_found',
                'message' => 'El recurso solicitado no existe.',
                'status' => 404,
            ],
            [
                'code' => 'domain.conflict',
                'message' => 'No se pudo completar la operación por un conflicto de dominio.',
                'status' => 409,
            ],
            [
                'code' => 'domain.conflict.missing_version',
                'message' => 'Se requiere el encabezado de control de versión para completar la operación.',
                'status' => 409,
            ],
            [
                'code' => 'domain.conflict.invalid_version',
                'message' => 'El encabezado de control de versión tiene un formato inválido.',
                'status' => 409,
            ],
            [
                'code' => 'domain.conflict.stale_resource',
                'message' => 'El recurso fue modificado por otro proceso.',
                'status' => 409,
            ],
            [
                'code' => 'domain.validation_failed',
                'message' => 'Los datos proporcionados no son válidos.',
                'status' => 422,
            ],
        ];

        $catalog = [];

        foreach ($baseErrors as $error) {
            $catalog[$error['code']] = $error + ['entity' => $entityName];
        }

        foreach ($blueprint->errors() as $error) {
            $catalog[$error['code']] = array_filter([
                'code' => $error['code'],
                'message' => $error['message'],
                'status' => $error['status'],
                'description' => $error['description'] ?? null,
                'entity' => $entityName,
            ], static fn ($value) => $value !== null);
        }

        return array_values($catalog);
    }

    private function buildIdParameter(Blueprint $blueprint): array
    {
        $field = $this->findIdentifierField($blueprint);
        $schema = $field ? $this->mapFieldToSchema($field, 'resource') : ['type' => 'integer', 'format' => 'int64'];

        unset($schema['nullable']);
        unset($schema['readOnly']);
        unset($schema['default']);

        return [
            'name' => 'id',
            'in' => 'path',
            'required' => true,
            'description' => 'Identificador del recurso ' . Str::studly($blueprint->entity()),
            'schema' => $schema,
        ];
    }

    private function findIdentifierField(Blueprint $blueprint): ?Field
    {
        foreach ($blueprint->fields() as $field) {
            if ($field->name === 'id') {
                return $field;
            }
        }

        return null;
    }

    private function normalizePath(?string $basePath, Blueprint $blueprint): string
    {
        $path = $basePath ?: '/' . Str::kebab(Str::pluralStudly($blueprint->entity()));
        $path = '/' . ltrim($path, '/');

        return rtrim($path, '/') ?: '/';
    }

    private function filterRecursive(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->filterRecursive($item);

                if ($value[$key] === []) {
                    unset($value[$key]);
                }
            } elseif ($item === null) {
                unset($value[$key]);
            }
        }

        return $value;
    }
}
