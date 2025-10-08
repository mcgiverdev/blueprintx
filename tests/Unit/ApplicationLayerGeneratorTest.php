<?php

namespace BlueprintX\Tests\Unit;

use BlueprintX\Blueprint\Blueprint;
use BlueprintX\Contracts\ArchitectureDriver;
use BlueprintX\Generators\ApplicationLayerGenerator;
use BlueprintX\Kernel\Drivers\HexagonalDriver;
use BlueprintX\Kernel\TemplateEngine;
use BlueprintX\Tests\Concerns\InteractsWithSnapshots;
use BlueprintX\Tests\Support\BlueprintFactory;
use PHPUnit\Framework\TestCase;

class ApplicationLayerGeneratorTest extends TestCase
{
    use InteractsWithSnapshots;

    public function test_it_generates_application_service_file(): void
    {
        $engine = $this->makeTemplateEngine();
        $driver = $this->makeHexagonalDriver();
        $this->registerDriverNamespaces($engine, $driver);

        $generator = new ApplicationLayerGenerator($engine);
        $blueprint = $this->makeBlueprint('User');

        $result = $generator->generate($blueprint, $driver);

    $this->assertCount(7, $result->files());

        $files = [];
        foreach ($result->files() as $file) {
            $files[$file->path] = $file->contents;
        }

        $this->assertArrayHasKey('app/Application/Commands/CreateUserCommand.php', $files);
        $this->assertArrayHasKey('app/Application/Commands/UpdateUserCommand.php', $files);
        $this->assertArrayHasKey('app/Application/Queries/ListUsersQuery.php', $files);
    $this->assertArrayHasKey('app/Application/Queries/Filters/UserFilter.php', $files);
    $this->assertArrayHasKey('app/Application/Shared/Filters/QueryFilter.php', $files);

        $this->assertStringContainsString('namespace App\\Application\\Commands;', $files['app/Application/Commands/CreateUserCommand.php']);
        $this->assertStringContainsString('class CreateUserCommand', $files['app/Application/Commands/CreateUserCommand.php']);
    $this->assertStringContainsString('namespace App\\Application\\Shared\\Filters;', $files['app/Application/Shared/Filters/QueryFilter.php']);
    $this->assertStringContainsString('abstract class QueryFilter', $files['app/Application/Shared/Filters/QueryFilter.php']);
        $this->assertCount(0, $result->warnings());
    }

    public function test_it_respects_module_paths_and_namespaces(): void
    {
        $engine = $this->makeTemplateEngine();
        $driver = $this->makeHexagonalDriver();
        $this->registerDriverNamespaces($engine, $driver);

        $generator = new ApplicationLayerGenerator($engine);
        $blueprint = $this->makeBlueprint('Invoice', 'billing');

        $options = [
            'paths' => ['application' => 'app/Modules'],
            'namespaces' => ['application' => 'App\\Modules'],
        ];

        $result = $generator->generate($blueprint, $driver, $options);

        $files = [];
        foreach ($result->files() as $file) {
            $files[$file->path] = $file->contents;
        }

    $this->assertArrayHasKey('app/Modules/Billing/Commands/CreateInvoiceCommand.php', $files);
    $this->assertArrayHasKey('app/Modules/Shared/Filters/QueryFilter.php', $files);
    $this->assertStringContainsString('namespace App\\Modules\\Billing\\Commands;', $files['app/Modules/Billing/Commands/CreateInvoiceCommand.php']);
    $this->assertStringContainsString('namespace App\\Modules\\Shared\\Filters;', $files['app/Modules/Shared/Filters/QueryFilter.php']);
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
                return ['application'];
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

        $generator = new ApplicationLayerGenerator($engine);
        $blueprint = $this->makeBlueprint('User');

        $result = $generator->generate($blueprint, $driver);

        $this->assertCount(0, $result->files());
        $this->assertNotEmpty($result->warnings());
    }

    public function test_generated_application_files_match_snapshots(): void
    {
        $engine = $this->makeTemplateEngine();
        $driver = $this->makeHexagonalDriver();
        $this->registerDriverNamespaces($engine, $driver);

        $generator = new ApplicationLayerGenerator($engine);
        $blueprint = BlueprintFactory::employee();

        $result = $generator->generate($blueprint, $driver);

    $this->assertGreaterThanOrEqual(7, count($result->files()));

        $files = [];

        foreach ($result->files() as $file) {
            $files[$file->path] = $this->normalizeNewLines($file->contents);
        }

        $this->assertArrayHasKey('app/Application/Hr/Commands/CreateEmployeeCommand.php', $files);
        $this->assertArrayHasKey('app/Application/Hr/Commands/UpdateEmployeeCommand.php', $files);
        $this->assertArrayHasKey('app/Application/Hr/Queries/Filters/EmployeeFilter.php', $files);

        $this->assertSame($this->snapshot('application/CreateEmployeeCommand.snap'), $files['app/Application/Hr/Commands/CreateEmployeeCommand.php']);
        $this->assertSame($this->snapshot('application/UpdateEmployeeCommand.snap'), $files['app/Application/Hr/Commands/UpdateEmployeeCommand.php']);
        $this->assertSame($this->snapshot('application/EmployeeFilter.snap'), $files['app/Application/Hr/Queries/Filters/EmployeeFilter.php']);
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
