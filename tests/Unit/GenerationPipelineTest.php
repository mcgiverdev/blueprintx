<?php

namespace BlueprintX\Tests\Unit;

use BlueprintX\Blueprint\Blueprint;
use BlueprintX\Docs\OpenApiDocumentBuilder;
use BlueprintX\Generators\ApiLayerGenerator;
use BlueprintX\Generators\ApplicationLayerGenerator;
use BlueprintX\Generators\DocsLayerGenerator;
use BlueprintX\Generators\DomainLayerGenerator;
use BlueprintX\Generators\InfrastructureLayerGenerator;
use BlueprintX\Generators\TestsLayerGenerator;
use BlueprintX\Kernel\DriverManager;
use BlueprintX\Kernel\Drivers\HexagonalDriver;
use BlueprintX\Kernel\Generation\GenerationResult;
use BlueprintX\Kernel\Generation\PipelineResult;
use BlueprintX\Kernel\GenerationPipeline;
use BlueprintX\Kernel\OutputWriter;
use BlueprintX\Kernel\TemplateEngine;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

class GenerationPipelineTest extends TestCase
{
    public function test_it_runs_registered_generators_with_dry_run(): void
    {
        $pipeline = $this->makePipeline();
        $blueprint = $this->makeBlueprint();

        $result = $pipeline->generate($blueprint, ['dry_run' => true, 'only' => 'domain']);

        $this->assertInstanceOf(PipelineResult::class, $result);
        $this->assertCount(2, $result->files());

        $paths = array_column($result->files(), 'path');
        $this->assertContains('app/Domain/Models/User.php', $paths);
        $this->assertContains('app/Domain/Repositories/UserRepositoryInterface.php', $paths);

        foreach ($result->files() as $record) {
            $this->assertSame('domain', $record['layer']);
            $this->assertSame('preview', $record['status']);

            if ($record['path'] === 'app/Domain/Models/User.php') {
                $this->assertStringContainsString('namespace App\\Domain\\Models;', $record['preview']);
                $this->assertStringContainsString('class User', $record['preview']);
            }
        }

        $this->assertCount(0, $result->warnings());
    }

    public function test_it_adds_warning_when_generator_is_missing(): void
    {
        $pipeline = $this->makePipeline(false);
        $blueprint = $this->makeBlueprint();

        $result = $pipeline->generate($blueprint, ['dry_run' => true, 'only' => 'application']);

        $this->assertCount(0, $result->files());
        $this->assertNotEmpty($result->warnings());
        $this->assertStringContainsString('application', $result->warnings()[0]);
    }

    public function test_it_respects_only_filter_for_api_layer(): void
    {
        $pipeline = $this->makePipeline();
        $blueprint = $this->makeBlueprint();

        $result = $pipeline->generate($blueprint, ['dry_run' => true, 'only' => 'api']);

        $records = $result->files();
        $this->assertCount(3, $records);

        $paths = array_column($records, 'path');
        $this->assertContains('app/Http/Controllers/Api/UserController.php', $paths);
        $this->assertContains('app/Http/Resources/UserResource.php', $paths);
        $this->assertContains('app/Http/Resources/UserCollection.php', $paths);

    $controller = $this->findRecordByPath($records, 'app/Http/Controllers/Api/UserController.php');
    $this->assertNotNull($controller);
    $this->assertSame('api', $controller['layer']);
    $this->assertSame('preview', $controller['status']);
    $this->assertStringContainsString('namespace App\Http\Controllers\Api;', $controller['preview']);
    $this->assertStringContainsString('class UserController', $controller['preview']);

    $resource = $this->findRecordByPath($records, 'app/Http/Resources/UserResource.php');
    $this->assertNotNull($resource);
    $this->assertSame('api', $resource['layer']);
    $this->assertStringContainsString('class UserResource', $resource['preview']);

    $collection = $this->findRecordByPath($records, 'app/Http/Resources/UserCollection.php');
    $this->assertNotNull($collection);
    $this->assertSame('api', $collection['layer']);
    $this->assertStringContainsString('class UserCollection', $collection['preview']);
    }

    public function test_it_runs_before_and_after_hooks(): void
    {
        $pipeline = $this->makePipeline();
        $blueprint = $this->makeBlueprint();

        $calls = [];
        $self = $this;

        $pipeline->registerBeforeHook('domain', function (Blueprint $bp, $driver, string $layer, array $options) use (&$calls, $blueprint, $self): void {
            $self->assertSame($blueprint, $bp);
            $self->assertSame('domain', $layer);
            $self->assertArrayHasKey('dry_run', $options);
            $calls[] = 'before';
        });

        $pipeline->registerAfterHook('*', function (GenerationResult $generation, PipelineResult $pipelineResult, string $layer) use (&$calls, $self): void {
            $self->assertSame('domain', $layer);
            $self->assertCount(2, $generation->files());
            $self->assertSame(2, count($pipelineResult->files()));
            $calls[] = 'after';
        });

        $result = $pipeline->generate($blueprint, ['dry_run' => true, 'only' => 'domain']);

        $this->assertCount(2, $result->files());
        $this->assertSame(['before', 'after'], $calls);
    }

    public function test_it_generates_application_layer_when_registered(): void
    {
        $pipeline = $this->makePipeline();
        $blueprint = $this->makeBlueprint();

        $result = $pipeline->generate($blueprint, ['dry_run' => true, 'only' => 'application']);

        $this->assertCount(6, $result->files());

        $paths = array_column($result->files(), 'path');
        $this->assertContains('app/Application/Commands/CreateUserCommand.php', $paths);

        foreach ($result->files() as $record) {
            $this->assertSame('application', $record['layer']);
            $this->assertSame('preview', $record['status']);
        }

        $createCommand = array_values(array_filter($result->files(), static fn (array $record): bool => $record['path'] === 'app/Application/Commands/CreateUserCommand.php'))[0] ?? null;
        $this->assertNotNull($createCommand);
        $this->assertStringContainsString('namespace App\\Application\\Commands;', $createCommand['preview']);
        $this->assertStringContainsString('class CreateUserCommand', $createCommand['preview']);
        $this->assertCount(0, $result->warnings());
    }

    /**
     * @param array<int, array<string, mixed>> $records
     */
    private function findRecordByPath(array $records, string $path): ?array
    {
        foreach ($records as $record) {
            if (($record['path'] ?? null) === $path) {
                return $record;
            }
        }

        return null;
    }

    private function makePipeline(bool $withApplicationGenerator = true): GenerationPipeline
    {
        $container = new Container();
        $engine = new TemplateEngine([], ['debug' => false, 'cache' => false]);

        $definitions = [
            'hexagonal' => [
                'driver' => HexagonalDriver::class,
            ],
        ];

        $manager = new DriverManager(
            $engine,
            $container,
            $definitions,
            [
                'default_architecture' => 'hexagonal',
                'package_templates_path' => dirname(__DIR__, 2) . '/resources/templates',
                'override_templates_path' => dirname(__DIR__, 2) . '/resources/templates',
            ]
        );

        $output = new OutputWriter(sys_get_temp_dir());

        $pipeline = new GenerationPipeline($manager, $output);
        $pipeline->registerGenerator(new DomainLayerGenerator($engine));
        if ($withApplicationGenerator) {
            $pipeline->registerGenerator(new ApplicationLayerGenerator($engine));
        }
        $pipeline->registerGenerator(new InfrastructureLayerGenerator($engine));
        $pipeline->registerGenerator(new ApiLayerGenerator($engine));
        $pipeline->registerGenerator(new TestsLayerGenerator($engine, ['enabled' => true], [
            'enabled' => true,
            'header' => 'If-Match',
            'response_header' => 'ETag',
            'timestamp_column' => 'updated_at',
            'version_field' => 'version',
            'require_header' => true,
            'allow_wildcard' => true,
        ]));
        $pipeline->registerGenerator(new DocsLayerGenerator(new OpenApiDocumentBuilder(), false, false));

        return $pipeline;
    }

    private function makeBlueprint(): Blueprint
    {
        return Blueprint::fromArray([
            'path' => 'blueprints/user.yaml',
            'module' => null,
            'entity' => 'User',
            'table' => 'users',
            'architecture' => 'hexagonal',
            'fields' => [
                ['name' => 'name', 'type' => 'string'],
            ],
            'relations' => [],
            'options' => [
                'timestamps' => true,
            ],
            'api' => [
                'base_path' => '/users',
                'middleware' => [],
                'endpoints' => [],
            ],
            'docs' => [],
            'errors' => [],
            'metadata' => [],
        ]);
    }
}
