<?php

namespace BlueprintX\Contracts;

use BlueprintX\Blueprint\Blueprint;
use BlueprintX\Validation\ValidationResult;

interface BlueprintValidator
{
    public function validate(Blueprint $blueprint): ValidationResult;
}
