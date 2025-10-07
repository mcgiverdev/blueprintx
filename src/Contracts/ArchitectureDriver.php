<?php

namespace BlueprintX\Contracts;

interface ArchitectureDriver
{
    public function name(): string;

    /**
     * @return array<int, string>
     */
    public function layers(): array;

    /**
     * @return array<string, array<int, string>>
     */
    public function templateNamespaces(): array;

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array;
}
