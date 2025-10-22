<?php

namespace BlueprintX\Blueprint;

class Blueprint
{
    /**
     * @param Field[] $fields
     * @param Relation[] $relations
     * @param Endpoint[] $endpoints
     * @param array<string, mixed> $options
     * @param array<string, mixed> $docs
     * @param array<int, array<string, mixed>> $errors
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $tenancy
     * @param array<int, string> $middleware
     */
    public function __construct(
        private readonly string $path,
        private readonly ?string $module,
        private readonly string $entity,
        private readonly string $table,
        private readonly string $architecture,
        private readonly array $fields,
        private readonly array $relations,
        private readonly array $options,
        private readonly ?string $apiBasePath,
        private readonly array $apiMiddleware,
        private readonly array $apiResources,
        private readonly array $endpoints,
        private readonly array $docs,
        private readonly array $errors,
        private readonly array $metadata,
        private readonly array $tenancy,
    ) {
    }

    /**
     * @param array{
     *     path:string,
     *     module?:?string,
     *     entity:string,
     *     table:string,
     *     architecture:string,
     *     fields:array<int,array>,
     *     relations:array<int,array>,
     *     options:array<string,mixed>,
    *     api:array{base_path:?string,middleware:array<int,string>,resources:array<string,mixed>,endpoints:array<int,array>},
    *     docs:array<string,mixed>,
    *     errors:array<int,array<string,mixed>>,
    *     metadata:array<string,mixed>,
    *     tenancy?:array<string,mixed>
     * } $data
     */
    public static function fromArray(array $data): self
    {
        $fields = array_map(static fn (array $field): Field => Field::fromArray($field), $data['fields']);
        $relations = array_map(static fn (array $relation): Relation => Relation::fromArray($relation), $data['relations']);
        $endpoints = array_map(static fn (array $endpoint): Endpoint => Endpoint::fromArray($endpoint), $data['api']['endpoints']);
        $tenancy = $data['tenancy'] ?? [];
        if (! is_array($tenancy)) {
            $tenancy = [];
        }

        return new self(
            $data['path'],
            $data['module'] ?? null,
            $data['entity'],
            $data['table'],
            $data['architecture'],
            $fields,
            $relations,
            $data['options'],
            $data['api']['base_path'],
            $data['api']['middleware'],
            $data['api']['resources'] ?? ['includes' => []],
            $endpoints,
            $data['docs'],
            $data['errors'],
            $data['metadata'],
            $tenancy,
        );
    }

    public function path(): string
    {
        return $this->path;
    }

    public function module(): ?string
    {
        return $this->module;
    }

    public function entity(): string
    {
        return $this->entity;
    }

    public function table(): string
    {
        return $this->table;
    }

    public function architecture(): string
    {
        return $this->architecture;
    }

    /**
     * @return Field[]
     */
    public function fields(): array
    {
        return $this->fields;
    }

    /**
     * @return Relation[]
     */
    public function relations(): array
    {
        return $this->relations;
    }

    /**
     * @return array<string, mixed>
     */
    public function options(): array
    {
        return $this->options;
    }

    public function apiBasePath(): ?string
    {
        return $this->apiBasePath;
    }

    /**
     * @return array<int, string>
     */
    public function apiMiddleware(): array
    {
        return $this->apiMiddleware;
    }

    /**
     * @return Endpoint[]
     */
    public function endpoints(): array
    {
        return $this->endpoints;
    }

    /**
     * @return array<string, mixed>
     */
    public function docs(): array
    {
        return $this->docs;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }

    /**
     * @return array<string, mixed>
     */
    public function tenancy(): array
    {
        return $this->tenancy;
    }

    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'module' => $this->module,
            'entity' => $this->entity,
            'table' => $this->table,
            'architecture' => $this->architecture,
            'fields' => array_map(static fn (Field $field): array => $field->toArray(), $this->fields),
            'relations' => array_map(static fn (Relation $relation): array => $relation->toArray(), $this->relations),
            'options' => $this->options,
            'api' => [
                'base_path' => $this->apiBasePath,
                'middleware' => $this->apiMiddleware,
                'resources' => $this->apiResources,
                'endpoints' => array_map(static fn (Endpoint $endpoint): array => $endpoint->toArray(), $this->endpoints),
            ],
            'docs' => $this->docs,
            'errors' => $this->schemaErrors(),
            'metadata' => $this->metadata,
            'tenancy' => $this->tenancy,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function apiResources(): array
    {
        return $this->apiResources;
    }

    private function schemaErrors(): array
    {
        if ($this->errors === []) {
            return [];
        }

        $result = [];

        foreach ($this->errors as $error) {
            if (! isset($error['key']) || ! is_string($error['key']) || $error['key'] === '') {
                continue;
            }

            $definition = [
                'message' => $error['message'] ?? null,
                'code' => $error['code'] ?? null,
                'status' => $error['status'] ?? null,
                'extends' => $error['extends'] ?? null,
                'description' => $error['description'] ?? null,
            ];

            $definition = array_filter(
                $definition,
                static fn ($value) => $value !== null && $value !== ''
            );

            $result[$error['key']] = $definition === [] ? new \stdClass() : $definition;
        }

        return $result;
    }
}
