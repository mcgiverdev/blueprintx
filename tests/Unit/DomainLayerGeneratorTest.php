<?php

namespace BlueprintX\Tests\Unit;

use BlueprintX\Blueprint\Blueprint;
use BlueprintX\Contracts\ArchitectureDriver;
use BlueprintX\Generators\DomainLayerGenerator;
use BlueprintX\Kernel\Drivers\HexagonalDriver;
use BlueprintX\Kernel\TemplateEngine;
use BlueprintX\Tests\Concerns\InteractsWithSnapshots;
use BlueprintX\Tests\Support\BlueprintFactory;
use PHPUnit\Framework\TestCase;

class DomainLayerGeneratorTest extends TestCase
{
    use InteractsWithSnapshots;

    public function test_it_generates_domain_entity_file(): void
    {
        $engine = $this->makeTemplateEngine();
        $driver = $this->makeHexagonalDriver();
        $this->registerDriverNamespaces($engine, $driver);

        $generator = new DomainLayerGenerator($engine);
        $blueprint = $this->makeBlueprint('User');

        $result = $generator->generate($blueprint, $driver);

        $this->assertCount(6, $result->files());

        $files = [];
        foreach ($result->files() as $file) {
            $files[$file->path] = $file->contents;
        }

        $this->assertArrayHasKey('app/Domain/Models/User.php', $files);
        $this->assertArrayHasKey('app/Domain/Repositories/UserRepositoryInterface.php', $files);
        $this->assertArrayHasKey('app/Domain/Shared/Exceptions/DomainException.php', $files);
        $this->assertArrayHasKey('app/Domain/Shared/Exceptions/DomainNotFoundException.php', $files);

        $this->assertStringContainsString('namespace App\\Domain\\Models;', $files['app/Domain/Models/User.php']);
        $this->assertStringContainsString('class User', $files['app/Domain/Models/User.php']);
        $this->assertCount(0, $result->warnings());
    }

    public function test_it_uses_module_to_customize_paths_and_namespaces(): void
    {
        $engine = $this->makeTemplateEngine();
        $driver = $this->makeHexagonalDriver();
        $this->registerDriverNamespaces($engine, $driver);

        $generator = new DomainLayerGenerator($engine);
        $blueprint = $this->makeBlueprint('Order', 'sales');

        $options = [
            'paths' => ['domain' => 'app/Modules'],
            'namespaces' => ['domain' => 'App\\Modules'],
        ];

        $result = $generator->generate($blueprint, $driver, $options);

        $files = [];
        foreach ($result->files() as $file) {
            $files[$file->path] = $file->contents;
        }

        $this->assertArrayHasKey('app/Modules/Sales/Models/Order.php', $files);
        $this->assertStringContainsString('namespace App\\Modules\\Sales\\Models;', $files['app/Modules/Sales/Models/Order.php']);
    }

    public function test_it_adds_warning_when_template_missing(): void
    {
        $engine = $this->makeTemplateEngine();
        $driver = new class implements ArchitectureDriver {
            public function name(): string
            {
                return 'custom';
            }

            public function layers(): array
            {
                return ['domain'];
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

        $generator = new DomainLayerGenerator($engine);
        $blueprint = $this->makeBlueprint('User');

        $result = $generator->generate($blueprint, $driver);

        $this->assertCount(0, $result->files());
        $this->assertNotEmpty($result->warnings());
    }

    public function test_generated_domain_files_match_snapshots(): void
    {
        $engine = $this->makeTemplateEngine();
        $driver = $this->makeHexagonalDriver();
        $this->registerDriverNamespaces($engine, $driver);

        $generator = new DomainLayerGenerator($engine);
        $blueprint = BlueprintFactory::employee();

        $result = $generator->generate($blueprint, $driver);

        $this->assertGreaterThanOrEqual(6, count($result->files()));

        $files = [];

        foreach ($result->files() as $file) {
            $files[$file->path] = $this->normalizeNewLines($file->contents);
        }

        $this->assertArrayHasKey('app/Domain/Hr/Models/Employee.php', $files);
        $this->assertArrayHasKey('app/Domain/Hr/Repositories/EmployeeRepositoryInterface.php', $files);
        $this->assertArrayHasKey('app/Domain/Shared/Exceptions/DomainException.php', $files);
    $this->assertArrayHasKey('app/Domain/Hr/Exceptions/InactiveException.php', $files);

        $this->assertSame($this->snapshot('employee_domain_model.snap'), $files['app/Domain/Hr/Models/Employee.php']);
        $this->assertSame($this->snapshot('employee_domain_repository_interface.snap'), $files['app/Domain/Hr/Repositories/EmployeeRepositoryInterface.php']);
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
