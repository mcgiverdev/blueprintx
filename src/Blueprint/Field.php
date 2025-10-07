<?php

namespace BlueprintX\Blueprint;

class Field
{
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly ?string $rules = null,
        public readonly mixed $default = null,
        public readonly ?int $precision = null,
        public readonly ?int $scale = null,
        public readonly ?bool $nullable = null,
    ) {
    }

    /**
     * @param array{name:string,type:string,rules?:string,default?:mixed,precision?:int,scale?:int,nullable?:bool} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['name'],
            $data['type'],
            $data['rules'] ?? null,
            $data['default'] ?? null,
            $data['precision'] ?? null,
            $data['scale'] ?? null,
            $data['nullable'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'rules' => $this->rules,
            'default' => $this->default,
            'precision' => $this->precision,
            'scale' => $this->scale,
            'nullable' => $this->nullable,
        ];
    }
}
