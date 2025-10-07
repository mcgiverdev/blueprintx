<?php

namespace BlueprintX\Tests\Unit;

use BlueprintX\Blueprint\Blueprint;
use BlueprintX\Contracts\ArchitectureDriver;
use BlueprintX\Generators\InfrastructureLayerGenerator;
use BlueprintX\Kernel\Drivers\HexagonalDriver;
use BlueprintX\Kernel\TemplateEngine;
use BlueprintX\Tests\Concerns\InteractsWithSnapshots;
use BlueprintX\Tests\Support\BlueprintFactory;
use PHPUnit\Framework\TestCase;

class InfrastructureLayerGeneratorTest extends TestCase
{
    use InteractsWithSnapshots;

    public function test_it_generates_repository_file(): void
    {
        $engine = $this->makeTemplateEngine();
        $driver = $this->makeHexagonalDriver();
        $this->registerDriverNamespaces($engine, $driver);

        $generator = new InfrastructureLayerGenerator($engine);
        $blueprint = $this->makeBlueprint('User');

        $result = $generator->generate($blueprint, $driver);

        $this->assertCount(1, $result->files());
        $file = $result->files()[0];

    $this->assertSame('app/Infrastructure/Persistence/Eloquent/Repositories/EloquentUserRepository.php', $file->path);
    $this->assertStringContainsString('namespace App\\Infrastructure\\Persistence\\Eloquent\\Repositories;', $file->contents);
    $this->assertStringContainsString('class EloquentUserRepository', $file->contents);
        $this->assertCount(0, $result->warnings());
    }

    public function test_it_supports_module_overrides(): void
    {
        $engine = $this->makeTemplateEngine();
        $driver = $this->makeHexagonalDriver();
        $this->registerDriverNamespaces($engine, $driver);

        $generator = new InfrastructureLayerGenerator($engine);
        $blueprint = $this->makeBlueprint('Order', 'sales');

        $options = [
            'paths' => ['infrastructure' => 'app/Modules'],
            'namespaces' => ['infrastructure' => 'App\\Modules'],
        ];

        $result = $generator->generate($blueprint, $driver, $options);

        $file = $result->files()[0];
    $this->assertSame('app/Modules/Sales/Repositories/EloquentOrderRepository.php', $file->path);
    $this->assertStringContainsString('namespace App\\Modules\\Sales\\Repositories;', $file->contents);
    $this->assertStringContainsString('class EloquentOrderRepository', $file->contents);
    }

    public function test_it_warns_when_template_missing(): void
    {
        $engine = $this->makeTemplateEngine();
        $driver = new class implements ArchitectureDriver {
            public function name(): string
            {
                return 'custom';
            }

            public function layers(): array
            {
                return ['infrastructure'];
            }

            public function templateNamespaces(): array
            {
                return [];
            }

            public function metadata(): array
            {
                return [];
            }
        };

        $generator = new InfrastructureLayerGenerator($engine);
        $result = $generator->generate($this->makeBlueprint('User'), $driver);

        $this->assertCount(0, $result->files());
        $this->assertNotEmpty($result->warnings());
    }

    public function test_generated_repository_matches_snapshot(): void
    {
        $engine = $this->makeTemplateEngine();
        $driver = $this->makeHexagonalDriver();
        $this->registerDriverNamespaces($engine, $driver);

        $generator = new InfrastructureLayerGenerator($engine);
        $blueprint = BlueprintFactory::employee();

        $result = $generator->generate($blueprint, $driver);

        $this->assertCount(1, $result->files());

        $file = $result->files()[0];

        $this->assertSame('app/Infrastructure/Persistence/Eloquent/Hr/Repositories/EloquentEmployeeRepository.php', $file->path);
        $this->assertSame($this->snapshot('infrastructure/EmployeeRepository.snap'), $this->normalizeNewLines($file->contents));
    }

    private function makeTemplateEngine(): TemplateEngine
    {
        return new TemplateEngine([], ['debug' => false, 'cache' => false]);
    }

    private function makeHexagonalDriver(): HexagonalDriver
    {
        $basePath = dirname(__DIR__, 2) . '/resources/templates/hexagonal';

        return new HexagonalDriver([
            'package_path' => $basePath,
            'override_path' => $basePath,
        ]);
    }

    private function registerDriverNamespaces(TemplateEngine $engine, ArchitectureDriver $driver): void
    {
        foreach ($driver->templateNamespaces() as $namespace => $paths) {
            $engine->registerNamespace($namespace, $paths);
        }
    }

    private function makeBlueprint(string $entity, ?string $module = null): Blueprint
    {
        return Blueprint::fromArray([
            'path' => 'blueprints/' . strtolower($entity) . '.yaml',
            'module' => $module,
            'entity' => $entity,
            'table' => strtolower($entity) . 's',
            'architecture' => 'hexagonal',
            'fields' => [
                ['name' => 'name', 'type' => 'string'],
            ],
            'relations' => [],
            'options' => [],
            'api' => [
                'base_path' => '/api/' . strtolower($entity) . 's',
                'middleware' => [],
                'endpoints' => [],
            ],
            'docs' => [],
            'errors' => [],
            'metadata' => [],
        ]);
    }
}
