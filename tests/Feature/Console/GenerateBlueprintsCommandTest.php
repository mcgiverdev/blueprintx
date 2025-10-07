<?php

namespace BlueprintX\Tests\Feature\Console;

use BlueprintX\Blueprint\Blueprint;
use BlueprintX\Contracts\BlueprintParser;
use BlueprintX\Contracts\BlueprintValidator;
use BlueprintX\Kernel\Generation\PipelineResult;
use BlueprintX\Kernel\GenerationPipeline;
use BlueprintX\Tests\TestCase;
use BlueprintX\Validation\ValidationResult;
use Illuminate\Console\Command;
use Mockery;

class GenerateBlueprintsCommandTest extends TestCase
{
    public function test_it_executes_pipeline_with_mocked_dependencies(): void
    {
        $blueprintPath = $this->putBlueprint('hr/employee.yaml');

        $blueprint = Blueprint::fromArray([
            'path' => 'blueprints/hr/employee.yaml',
            'module' => 'hr',
            'entity' => 'Employee',
            'table' => 'employees',
            'architecture' => 'hexagonal',
            'fields' => [
                ['name' => 'id', 'type' => 'uuid'],
            ],
            'relations' => [],
            'options' => [],
            'api' => [
                'base_path' => '/api/hr/employees',
                'middleware' => [],
                'endpoints' => [],
            ],
            'docs' => [],
            'errors' => [],
            'metadata' => [],
        ]);

        $parser = Mockery::mock(BlueprintParser::class);
        $parser->shouldReceive('parse')->once()->with($blueprintPath)->andReturn($blueprint);
        $this->app->instance(BlueprintParser::class, $parser);

        $validation = new ValidationResult();

        $validator = Mockery::mock(BlueprintValidator::class);
        $validator->shouldReceive('validate')->once()->with($blueprint)->andReturn($validation);
        $this->app->instance(BlueprintValidator::class, $validator);

        $pipelineResult = new PipelineResult();
        $pipelineResult->addFile([
            'layer' => 'domain',
            'status' => 'preview',
            'path' => 'app/Domain/Entities/Employee.php',
            'preview' => 'class Employee {}',
        ]);

        $pipeline = Mockery::mock(GenerationPipeline::class);
        $pipeline->shouldReceive('generate')->once()->with(
            $blueprint,
            Mockery::on(function (array $options): bool {
                return ($options['dry_run'] ?? false) === true
                    && array_key_exists('force', $options)
                    && $options['force'] === false;
            })
        )->andReturn($pipelineResult);
        $this->app->instance(GenerationPipeline::class, $pipeline);

        $this->artisan('blueprintx:generate', [
            '--module' => 'hr',
            '--entity' => 'employee',
            '--dry-run' => true,
        ])
            ->expectsOutput('Se encontraron 1 blueprint(s) para generar.')
            ->expectsOutputToContain('Resumen: 1 archivo(s) procesados')
            ->assertExitCode(Command::SUCCESS);
    }
}
