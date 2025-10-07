<?php

namespace BlueprintX\Tests\Unit;

use BlueprintX\Blueprint\Blueprint;
use BlueprintX\Contracts\ArchitectureDriver;
use BlueprintX\Generators\TestsLayerGenerator;
use BlueprintX\Kernel\Drivers\HexagonalDriver;
use BlueprintX\Kernel\TemplateEngine;
use BlueprintX\Tests\Concerns\InteractsWithSnapshots;
use BlueprintX\Tests\Support\BlueprintFactory;
use PHPUnit\Framework\TestCase;

class TestsLayerGeneratorTest extends TestCase
{
    use InteractsWithSnapshots;

    public function test_it_generates_feature_test(): void
    {
        $engine = $this->makeTemplateEngine();
        $driver = $this->makeHexagonalDriver();
        $this->registerDriverNamespaces($engine, $driver);

    $generator = $this->makeTestsGenerator($engine);
        $blueprint = $this->makeBlueprint('User');

        $result = $generator->generate($blueprint, $driver);

        $this->assertCount(1, $result->files());
        $file = $result->files()[0];

        $this->assertSame('tests/Feature/UserFeatureTest.php', $file->path);
        $this->assertStringContainsString('namespace Tests\\Feature;', $file->contents);
        $this->assertStringContainsString('class UserFeatureTest', $file->contents);
    }

    public function test_generated_feature_test_matches_snapshot(): void
    {
        $engine = $this->makeTemplateEngine();
        $driver = $this->makeHexagonalDriver();
        $this->registerDriverNamespaces($engine, $driver);

    $generator = $this->makeTestsGenerator($engine);
        $blueprint = BlueprintFactory::employee();

    $result = $generator->generate($blueprint, $driver);
    $file = $result->files()[0];

    $expected = $this->snapshot('employee_feature_test.snap');

    $this->assertSame($expected, $this->normalizeNewLines($file->contents));
    }

    public function test_it_respects_module_overrides(): void
    {
        $engine = $this->makeTemplateEngine();
        $driver = $this->makeHexagonalDriver();
        $this->registerDriverNamespaces($engine, $driver);

    $generator = $this->makeTestsGenerator($engine);
        $blueprint = $this->makeBlueprint('Order', 'sales');

        $options = [
            'paths' => ['tests' => 'tests/Modules'],
            'namespaces' => ['tests' => 'Tests\\Modules'],
        ];

        $result = $generator->generate($blueprint, $driver, $options);

        $file = $result->files()[0];
        $this->assertSame('tests/Modules/Sales/OrderFeatureTest.php', $file->path);
        $this->assertStringContainsString('namespace Tests\\Modules\\Sales;', $file->contents);
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
                return ['tests'];
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

    $generator = $this->makeTestsGenerator($engine);
        $result = $generator->generate($this->makeBlueprint('User'), $driver);

        $this->assertCount(0, $result->files());
        $this->assertNotEmpty($result->warnings());
    }

    private function makeTemplateEngine(): TemplateEngine
    {
        return new TemplateEngine([], ['debug' => false, 'cache' => false]);
    }

    private function makeTestsGenerator(
        TemplateEngine $engine,
        ?array $resourceConfig = null,
        ?array $optimisticLocking = null
    ): TestsLayerGenerator {
        $resourceConfig ??= ['enabled' => true];

        $optimisticLocking ??= [
            'enabled' => true,
            'header' => 'If-Match',
            'response_header' => 'ETag',
            'timestamp_column' => 'updated_at',
            'version_field' => 'version',
            'require_header' => true,
            'allow_wildcard' => true,
        ];

        return new TestsLayerGenerator($engine, $resourceConfig, $optimisticLocking);
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
