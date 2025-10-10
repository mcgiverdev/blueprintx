<?php

namespace BlueprintX\Kernel\Drivers;

use BlueprintX\Contracts\ArchitectureDriver;

class HexagonalDriver implements ArchitectureDriver
{
    /**
     * @param array{package_path:string,override_path:?string,layers?:array<int,string>,metadata?:array<string,mixed>} $options
     */
    public function __construct(private readonly array $options = [])
    {
    }

    public function name(): string
    {
        return 'hexagonal';
    }

    public function layers(): array
    {
        return $this->options['layers'] ?? [
            'domain',
            'application',
            'infrastructure',
            'api',
            'database',
            'tests',
            'docs',
            'postman',
        ];
    }

    public function templateNamespaces(): array
    {
        $paths = [];

        $overridePath = $this->options['override_path'] ?? null;
        if (is_string($overridePath) && is_dir($overridePath)) {
            $paths[] = $overridePath;
        }

        $packagePath = $this->options['package_path'] ?? dirname(__DIR__, 3) . '/resources/templates/hexagonal';
        if (is_string($packagePath) && is_dir($packagePath)) {
            $paths[] = $packagePath;
        }

        return [
            $this->name() => $paths,
        ];
    }

    public function metadata(): array
    {
        return $this->options['metadata'] ?? [
            'description' => 'Arquitectura hexagonal con separación de capas domain/application/infrastructure/api/tests/docs.',
        ];
    }
}
