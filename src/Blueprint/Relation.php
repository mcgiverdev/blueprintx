<?php

namespace BlueprintX\Blueprint;

class Relation
{
    public function __construct(
        public readonly string $type,
        public readonly string $target,
        public readonly string $field,
        public readonly ?string $rules = null,
    ) {
    }

    /**
     * @param array{type:string,target:string,field:string,rules?:string} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['type'],
            $data['target'],
            $data['field'],
            $data['rules'] ?? null,
        );
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'target' => $this->target,
            'field' => $this->field,
            'rules' => $this->rules,
        ];
    }
}
