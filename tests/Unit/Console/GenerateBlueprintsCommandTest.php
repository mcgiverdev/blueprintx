<?php

namespace BlueprintX\Tests\Unit\Console;

use BlueprintX\Blueprint\Blueprint;
use BlueprintX\Console\Commands\GenerateBlueprintsCommand;
use BlueprintX\Contracts\BlueprintParser;
use BlueprintX\Contracts\BlueprintValidator;
use BlueprintX\Kernel\BlueprintLocator;
use BlueprintX\Kernel\Generation\PipelineResult;
use BlueprintX\Kernel\GenerationPipeline;
use BlueprintX\Validation\ValidationMessage;
use BlueprintX\Validation\ValidationResult;
use Illuminate\Console\Command;
use Illuminate\Container\Container;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class GenerateBlueprintsCommandTest extends TestCase
{
    /** @var array<int, string> */
    private array $tempDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->tempDirectories as $directory) {
            $this->deleteDirectory($directory);
        }

        parent::tearDown();
    }

    public function test_it_generates_blueprint_with_options(): void
    {
        $blueprintsPath = $this->makeBlueprintDirectory([
            'hr/employee.yaml' => ''
        ]);

        $parser = $this->createMock(BlueprintParser::class);
        $validator = $this->createMock(BlueprintValidator::class);
        $pipeline = $this->createMock(GenerationPipeline::class);

        $blueprint = $this->makeBlueprint('Employee', 'hr');

        $parser->expects($this->once())
            ->method('parse')
            ->with($this->equalTo($blueprintsPath . DIRECTORY_SEPARATOR . 'hr' . DIRECTORY_SEPARATOR . 'employee.yaml'))
            ->willReturn($blueprint);

        $validation = new ValidationResult();

        $validator->expects($this->once())
            ->method('validate')
            ->with($this->callback(function (Blueprint $candidate): bool {
                return $candidate->architecture() === 'custom';
            }))
            ->willReturn($validation);

        $pipeline->expects($this->once())
            ->method('generate')
            ->with(
                $this->callback(function (Blueprint $candidate): bool {
                    return $candidate->architecture() === 'custom' && $candidate->entity() === 'Employee';
                }),
                $this->callback(function (array $options): bool {
                    return ($options['dry_run'] ?? false) === true
                        && ($options['force'] ?? null) === false
                        && ($options['only'] ?? null) === 'domain'
                        && ($options['with_openapi'] ?? null) === false
                        && ($options['validate_openapi'] ?? null) === true;
                })
            )
            ->willReturn($this->makePipelineResult([
                [
                    'layer' => 'domain',
                    'path' => 'app/Domain/Entities/Employee.php',
                    'status' => 'preview',
                    'preview' => 'class Employee {}',
                ],
            ]));

        $tester = $this->makeTester($parser, $validator, $pipeline, $blueprintsPath);

        $exitCode = $tester->execute([
            '--module' => 'hr',
            '--entity' => 'employee',
            '--architecture' => 'custom',
            '--only' => 'domain',
            '--dry-run' => true,
        ]);

        $display = $tester->getDisplay();

        $this->assertSame(Command::SUCCESS, $exitCode);

        $this->assertStringContainsString('Se encontraron 1 blueprint(s)', $display);
        $this->assertStringContainsString('Arquitectura forzada a "custom"', $display);
        $this->assertStringContainsString('previews: 1', $display);
        $this->assertStringContainsString('Resumen:', $display);
    }

    public function test_it_fails_when_validation_has_errors(): void
    {
        $blueprintsPath = $this->makeBlueprintDirectory([
            'finance/invoice.yaml' => ''
        ]);

        $parser = $this->createMock(BlueprintParser::class);
        $validator = $this->createMock(BlueprintValidator::class);
        $pipeline = $this->createMock(GenerationPipeline::class);

        $blueprint = $this->makeBlueprint('Invoice', 'finance');

        $parser->expects($this->once())
            ->method('parse')
            ->willReturn($blueprint);

        $result = new ValidationResult();
        $result->addError(new ValidationMessage('validator.failed', 'Campo requerido ausente', 'fields.0.name'));

        $validator->expects($this->once())
            ->method('validate')
            ->willReturn($result);

        $pipeline->expects($this->never())
            ->method('generate');

        $tester = $this->makeTester($parser, $validator, $pipeline, $blueprintsPath);

        $exitCode = $tester->execute([
            '--module' => 'finance',
            '--entity' => 'invoice',
        ]);

        $this->assertSame(Command::FAILURE, $exitCode);

        $display = $tester->getDisplay();

        $this->assertStringContainsString('[validator.failed]', $display);
        $this->assertStringContainsString('La validación produjo errores', $display);
    }

    public function test_it_warns_when_no_blueprints_found(): void
    {
        $blueprintsPath = $this->makeBlueprintDirectory([]);

        $parser = $this->createMock(BlueprintParser::class);
        $validator = $this->createMock(BlueprintValidator::class);
        $pipeline = $this->createMock(GenerationPipeline::class);

        $parser->expects($this->never())->method('parse');
        $validator->expects($this->never())->method('validate');
        $pipeline->expects($this->never())->method('generate');

        $tester = $this->makeTester($parser, $validator, $pipeline, $blueprintsPath);

        $exitCode = $tester->execute([
            '--module' => 'hr',
        ]);

        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertStringContainsString('No se encontraron blueprints', $tester->getDisplay());
    }

    /**
     * @param BlueprintParser|MockObject $parser
     * @param BlueprintValidator|MockObject $validator
     * @param GenerationPipeline|MockObject $pipeline
     */
    private function makeTester(
        mixed $parser,
        mixed $validator,
        mixed $pipeline,
        string $blueprintsPath
    ): CommandTester {
    $command = new GenerateBlueprintsCommand($parser, $validator, $pipeline, new BlueprintLocator());
        $app = $this->makeContainer($blueprintsPath);

        $application = new Application();
        $command->setLaravel($app);
        $command->setApplication($application);
        $application->add($command);

        return new CommandTester($application->find('blueprintx:generate'));
    }

    private function makeContainer(string $blueprintsPath): Container
    {
        $app = new Container();

        $config = [
            'blueprintx' => [
                'paths' => [
                    'blueprints' => $blueprintsPath,
                    'output' => $blueprintsPath,
                ],
                'features' => [
                    'openapi' => [
                        'enabled' => false,
                        'validate' => true,
                    ],
                ],
                'default_architecture' => 'hexagonal',
                'architectures' => [],
                'generators' => [],
            ],
        ];

        $app->instance('config', new class($config) {
            public function __construct(private array $items)
            {
            }

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->items[$key] ?? $default;
            }
        });

        return $app;
    }

    private function makePipelineResult(array $files, array $warnings = []): PipelineResult
    {
        $result = new PipelineResult();

        foreach ($files as $file) {
            $result->addFile($file);
        }

        foreach ($warnings as $warning) {
            $result->addWarning($warning);
        }

        return $result;
    }

    private function makeBlueprint(string $entity, ?string $module = null, string $architecture = 'hexagonal'): Blueprint
    {
        $studly = Str::studly($entity);

        return Blueprint::fromArray([
            'path' => 'blueprints/' . ($module ? $module . '/' : '') . Str::kebab($entity) . '.yaml',
            'module' => $module,
            'entity' => $studly,
            'table' => Str::snake(Str::pluralStudly($studly)),
            'architecture' => $architecture,
            'fields' => [
                ['name' => 'id', 'type' => 'uuid'],
            ],
            'relations' => [],
            'options' => [],
            'api' => [
                'base_path' => '/api/' . Str::kebab(Str::pluralStudly($studly)),
                'middleware' => [],
                'endpoints' => [],
            ],
            'docs' => [],
                'errors' => [],
            'metadata' => [],
        ]);
    }

    /**
     * @param array<string, string> $files
     */
    private function makeBlueprintDirectory(array $files): string
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'blueprintx_' . bin2hex(random_bytes(6));
        mkdir($base, 0777, true);
        $this->tempDirectories[] = $base;

        foreach ($files as $relative => $contents) {
            $fullPath = $base . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
            $directory = dirname($fullPath);

            if (! is_dir($directory)) {
                mkdir($directory, 0777, true);
            }

            file_put_contents($fullPath, $contents);
        }

        return $base;
    }

    private function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }

        @rmdir($directory);
    }
}
