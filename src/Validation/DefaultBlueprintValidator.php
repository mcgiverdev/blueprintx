<?php

namespace BlueprintX\Validation;

use BlueprintX\Blueprint\Blueprint;
use BlueprintX\Contracts\BlueprintValidator;

class DefaultBlueprintValidator implements BlueprintValidator
{
    public function __construct(
        private readonly BlueprintSchemaValidator $schemaValidator,
        private readonly SemanticBlueprintValidator $semanticValidator
    ) {
    }

    public function validate(Blueprint $blueprint): ValidationResult
    {
        $result = new ValidationResult();

        foreach ($this->schemaValidator->validate($blueprint->toArray()) as $message) {
            $result->addError($message);
        }

        if (! $result->isValid()) {
            return $result;
        }

        $semantic = $this->semanticValidator->validate($blueprint);
        foreach ($semantic['errors'] as $message) {
            $result->addError($message);
        }

        foreach ($semantic['warnings'] as $message) {
            $result->addWarning($message);
        }

        return $result;
    }
}
