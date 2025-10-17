<?php

namespace BlueprintX\Tests\Feature\Console;

use BlueprintX\Blueprint\Blueprint;
use BlueprintX\Kernel\Generation\PipelineResult;
use BlueprintX\Kernel\History\GenerationHistoryManager;
use BlueprintX\Tests\TestCase;
use Illuminate\Console\Command;

class RollbackBlueprintsCommandTest extends TestCase
{
    public function test_it_rolls_back_the_latest_run(): void
    {
        $this->prepareBlueprint();

        $manager = $this->app->make(GenerationHistoryManager::class);
        $blueprint = $this->makeBlueprint();

        $createdPath = $this->outputPath('app/Domain/Models/User.php');
        $this->ensureDirectory(dirname($createdPath));
        file_put_contents($createdPath, 'generated content');

        $controllerPath = $this->outputPath('app/Http/Controllers/UserController.php');
        $this->ensureDirectory(dirname($controllerPath));
        file_put_contents($controllerPath, 'current version');

        $result = new PipelineResult();
        $result->addFile([
            'status' => 'written',
            'layer' => 'domain',
            'path' => 'app/Domain/Models/User.php',
            'full_path' => $createdPath,
            'bytes' => strlen('generated content'),
            'checksum' => hash('sha256', 'generated content'),
        ]);
        $result->addFile([
            'status' => 'overwritten',
            'layer' => 'api',
            'path' => 'app/Http/Controllers/UserController.php',
            'full_path' => $controllerPath,
            'bytes' => strlen('current version'),
            'checksum' => hash('sha256', 'current version'),
            'previous_bytes' => strlen('previous version'),
            'previous_checksum' => hash('sha256', 'previous version'),
            'previous_contents' => 'previous version',
        ]);

        $runId = $manager->record($blueprint, 'crm/users.yaml', $result, ['execution_id' => 'batch-1']);
        $this->assertIsString($runId);

        $this->assertFileExists($createdPath);
        $this->assertSame('current version', file_get_contents($controllerPath));

        $this->artisan('blueprintx:rollback', ['--force' => true])
            ->assertExitCode(Command::SUCCESS);

        $this->assertFileDoesNotExist($createdPath);
        $this->assertSame('previous version', file_get_contents($controllerPath));
    }

    public function test_dry_run_does_not_modify_files(): void
    {
        $this->prepareBlueprint();

        $manager = $this->app->make(GenerationHistoryManager::class);
        $blueprint = $this->makeBlueprint();

        $createdPath = $this->outputPath('app/Application/Commands/CreateUserCommand.php');
        $this->ensureDirectory(dirname($createdPath));
        file_put_contents($createdPath, 'new');

        $result = new PipelineResult();
        $result->addFile([
            'status' => 'written',
            'layer' => 'application',
            'path' => 'app/Application/Commands/CreateUserCommand.php',
            'full_path' => $createdPath,
            'bytes' => strlen('new'),
            'checksum' => hash('sha256', 'new'),
        ]);

        $manager->record($blueprint, 'crm/users.yaml', $result, ['execution_id' => 'batch-2']);

        $this->artisan('blueprintx:rollback', ['--dry-run' => true])
            ->assertExitCode(Command::SUCCESS);

        $this->assertFileExists($createdPath);
        $this->assertSame('new', file_get_contents($createdPath));
    }

    public function test_it_rolls_back_all_runs_of_latest_execution(): void
    {
        $this->prepareBlueprint();

        $manager = $this->app->make(GenerationHistoryManager::class);
        $blueprint = $this->makeBlueprint();

        $oldPath = $this->outputPath('app/Legacy/Keep.txt');
        $this->ensureDirectory(dirname($oldPath));
        file_put_contents($oldPath, 'old file');

        $oldResult = new PipelineResult();
        $oldResult->addFile([
            'status' => 'written',
            'layer' => 'legacy',
            'path' => 'app/Legacy/Keep.txt',
            'full_path' => $oldPath,
            'bytes' => strlen('old file'),
            'checksum' => hash('sha256', 'old file'),
        ]);

        $manager->record($blueprint, 'crm/legacy.yaml', $oldResult, ['execution_id' => 'old-exec']);

        $latestExecution = 'latest-exec';

        $newFilePath = $this->outputPath('app/Services/NewService.php');
        $this->ensureDirectory(dirname($newFilePath));
        file_put_contents($newFilePath, 'generated');

        $latestNewResult = new PipelineResult();
        $latestNewResult->addFile([
            'status' => 'written',
            'layer' => 'service',
            'path' => 'app/Services/NewService.php',
            'full_path' => $newFilePath,
            'bytes' => strlen('generated'),
            'checksum' => hash('sha256', 'generated'),
        ]);

        $manager->record($blueprint, 'crm/service.yaml', $latestNewResult, ['execution_id' => $latestExecution]);

        $overwrittenPath = $this->outputPath('app/Domain/Existing.php');
        $this->ensureDirectory(dirname($overwrittenPath));
        file_put_contents($overwrittenPath, 'current version');

        $latestOverwriteResult = new PipelineResult();
        $latestOverwriteResult->addFile([
            'status' => 'overwritten',
            'layer' => 'domain',
            'path' => 'app/Domain/Existing.php',
            'full_path' => $overwrittenPath,
            'bytes' => strlen('current version'),
            'checksum' => hash('sha256', 'current version'),
            'previous_bytes' => strlen('previous version'),
            'previous_checksum' => hash('sha256', 'previous version'),
            'previous_contents' => 'previous version',
        ]);

        $manager->record($blueprint, 'crm/domain.yaml', $latestOverwriteResult, ['execution_id' => $latestExecution]);

        $this->artisan('blueprintx:rollback', ['--force' => true])
            ->assertExitCode(Command::SUCCESS);

        $this->assertFileExists($oldPath);
        $this->assertSame('old file', file_get_contents($oldPath));
        $this->assertFileDoesNotExist($newFilePath);
        $this->assertSame('previous version', file_get_contents($overwrittenPath));
    }

    public function test_it_fails_when_no_history_is_available(): void
    {
        $this->artisan('blueprintx:rollback')
            ->assertExitCode(Command::FAILURE);
    }

    private function prepareBlueprint(): void
    {
        $this->putBlueprint('crm/users.yaml', 'entity: User');
    }

    private function makeBlueprint(): Blueprint
    {
        return Blueprint::fromArray([
            'path' => 'blueprints/crm/users.yaml',
            'module' => 'crm',
            'entity' => 'User',
            'table' => 'users',
            'architecture' => 'hexagonal',
            'fields' => [],
            'relations' => [],
            'options' => [],
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

    private function ensureDirectory(string $path): void
    {
        if (! is_dir($path)) {
            mkdir($path, 0777, true);
        }
    }
}
