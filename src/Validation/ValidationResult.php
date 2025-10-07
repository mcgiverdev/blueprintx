<?php

namespace BlueprintX\Validation;

class ValidationResult
{
    /**
     * @param ValidationMessage[] $errors
     * @param ValidationMessage[] $warnings
     */
    public function __construct(
        private array $errors = [],
        private array $warnings = []
    ) {
    }

    /**
     * @return ValidationMessage[]
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @return ValidationMessage[]
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    public function addError(ValidationMessage $message): void
    {
        $this->errors[] = $message;
    }

    public function addWarning(ValidationMessage $message): void
    {
        $this->warnings[] = $message;
    }

    public function merge(self $other): self
    {
        $this->errors = array_merge($this->errors, $other->errors());
        $this->warnings = array_merge($this->warnings, $other->warnings());

        return $this;
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }

    public function toArray(): array
    {
        return [
            'errors' => array_map(static fn (ValidationMessage $message): array => $message->toArray(), $this->errors),
            'warnings' => array_map(static fn (ValidationMessage $message): array => $message->toArray(), $this->warnings),
            'valid' => $this->isValid(),
        ];
    }
}
