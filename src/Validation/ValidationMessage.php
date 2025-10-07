<?php

namespace BlueprintX\Validation;

class ValidationMessage
{
    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly ?string $path = null
    ) {
    }

    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'message' => $this->message,
            'path' => $this->path,
        ];
    }
}
