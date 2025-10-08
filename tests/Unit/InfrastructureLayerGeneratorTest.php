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

        $this->assertCount(2, $result->files());

        $repositoryFile = $result->files()[0];
        $providerFile = $result->files()[1];

        $this->assertSame('app/Infrastructure/Persistence/Eloquent/Repositories/EloquentUserRepository.php', $repositoryFile->path);
        $this->assertStringContainsString('namespace App\\Infrastructure\\Persistence\\Eloquent\\Repositories;', $repositoryFile->contents);
        $this->assertStringContainsString('class EloquentUserRepository', $repositoryFile->contents);

        $this->assertSame('app/Providers/AppServiceProvider.php', $providerFile->path);
        $this->assertStringContainsString('use App\\Domain\\Repositories\\UserRepositoryInterface;', $providerFile->contents);
        $this->assertStringContainsString('use App\\Infrastructure\\Persistence\\Eloquent\\Repositories\\EloquentUserRepository;', $providerFile->contents);
        $this->assertStringContainsString('$this->app->bind(UserRepositoryInterface::class, EloquentUserRepository::class);', $providerFile->contents);

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

        $this->assertCount(2, $result->files());

        $repositoryFile = $result->files()[0];
        $providerFile = $result->files()[1];

        $this->assertSame('app/Modules/Sales/Repositories/EloquentOrderRepository.php', $repositoryFile->path);
        $this->assertStringContainsString('namespace App\\Modules\\Sales\\Repositories;', $repositoryFile->contents);
        $this->assertStringContainsString('class EloquentOrderRepository', $repositoryFile->contents);

        $this->assertSame('app/Providers/AppServiceProvider.php', $providerFile->path);
        $this->assertStringContainsString('use App\\Domain\\Sales\\Repositories\\OrderRepositoryInterface;', $providerFile->contents);
        $this->assertStringContainsString('use App\\Modules\\Sales\\Repositories\\EloquentOrderRepository;', $providerFile->contents);
        $this->assertStringContainsString('$this->app->bind(OrderRepositoryInterface::class, EloquentOrderRepository::class);', $providerFile->contents);
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

    $this->assertCount(2, $result->files());

    $repositoryFile = $result->files()[0];
    $providerFile = $result->files()[1];

    $this->assertSame('app/Infrastructure/Persistence/Eloquent/Hr/Repositories/EloquentEmployeeRepository.php', $repositoryFile->path);
    $this->assertSame($this->snapshot('infrastructure/EmployeeRepository.snap'), $this->normalizeNewLines($repositoryFile->contents));

    $this->assertSame('app/Providers/AppServiceProvider.php', $providerFile->path);
    $this->assertStringContainsString('$this->app->bind(EmployeeRepositoryInterface::class, EloquentEmployeeRepository::class);', $providerFile->contents);
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
