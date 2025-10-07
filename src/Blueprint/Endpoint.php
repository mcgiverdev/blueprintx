<?php

namespace BlueprintX\Blueprint;

class Endpoint
{
    /**
     * @param array<string> $fields
     */
    public function __construct(
        public readonly string $type,
        public readonly ?string $name = null,
        public readonly ?string $field = null,
        public readonly array $fields = [],
        public readonly ?string $by = null,
        public readonly ?string $method = null,
        public readonly ?string $path = null,
    ) {
    }

    /**
     * @param array{type:string,name?:string,field?:string,fields?:array<int,string>,by?:string,method?:string,path?:string} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['type'],
            $data['name'] ?? null,
            $data['field'] ?? null,
            array_values($data['fields'] ?? []),
            $data['by'] ?? null,
            $data['method'] ?? null,
            $data['path'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'name' => $this->name,
            'field' => $this->field,
            'fields' => $this->fields,
            'by' => $this->by,
            'method' => $this->method,
            'path' => $this->path,
        ];
    }
}
