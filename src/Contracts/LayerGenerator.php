<?php

namespace BlueprintX\Contracts;

use BlueprintX\Blueprint\Blueprint;
use BlueprintX\Kernel\Generation\GenerationResult;

interface LayerGenerator
{
    public function layer(): string;

    /**
     * @param array<string, mixed> $options
     */
    public function generate(Blueprint $blueprint, ArchitectureDriver $driver, array $options = []): GenerationResult;
}
