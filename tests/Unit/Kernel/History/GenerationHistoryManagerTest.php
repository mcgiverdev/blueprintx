<?php

namespace BlueprintX\Tests\Unit\Kernel\History;

use BlueprintX\Blueprint\Blueprint;
use BlueprintX\Kernel\Generation\PipelineResult;
use BlueprintX\Kernel\History\GenerationHistoryManager;
use PHPUnit\Framework\TestCase;

class GenerationHistoryManagerTest extends TestCase
{
    public function test_it_skips_when_disabled(): void
    {
        $tempDir = $this->makeTempDirectory();
        $manager = new GenerationHistoryManager($tempDir, false);
        $result = new PipelineResult();
        $result->addFile([
            'status' => 'written',
            'path' => 'app/Models/User.php',
            'full_path' => $tempDir . '/app/Models/User.php',
            'layer' => 'domain',
            'bytes' => 10,
            'checksum' => hash('sha256', 'content'),
        ]);

        $runId = $manager->record($this->makeBlueprint(), 'crm/users.yaml', $result);

    $this->assertNull($runId);
    $this->assertSame(['.', '..'], scandir($tempDir));

        $this->removeDirectory($tempDir);
    }

    public function test_it_persists_manifest_for_written_files(): void
    {
        $tempDir = $this->makeTempDirectory();
        $manager = new GenerationHistoryManager($tempDir, true);
        $result = new PipelineResult();
        $result->addFile([
            'status' => 'written',
            'path' => 'app/Domain/Models/User.php',
            'full_path' => $tempDir . '/app/Domain/Models/User.php',
            'layer' => 'domain',
            'bytes' => 64,
            'checksum' => hash('sha256', 'generated'),
        ]);

        $runId = $manager->record($this->makeBlueprint(), 'crm/users.yaml', $result, [
            'options' => ['force' => false],
            'execution_id' => 'test-batch',
        ]);

        $this->assertIsString($runId);
        $manifestPath = $tempDir . DIRECTORY_SEPARATOR . $runId . DIRECTORY_SEPARATOR . 'manifest.json';
        $this->assertFileExists($manifestPath);

        $payload = json_decode((string) file_get_contents($manifestPath), true);
        $this->assertIsArray($payload);
    $this->assertArrayHasKey('blueprint', $payload);
    $this->assertSame('test-batch', $payload['execution_id']);
        $this->assertSame('crm', $payload['blueprint']['module']);
        $this->assertSame('User', $payload['blueprint']['entity']);
        $this->assertSame('crm/users.yaml', $payload['blueprint']['path']);
        $this->assertCount(1, $payload['entries']);
        $this->assertSame('app/Domain/Models/User.php', $payload['entries'][0]['path']);
        $this->assertSame('written', $payload['entries'][0]['status']);
        $this->assertSame(64, $payload['entries'][0]['bytes']);

        $runs = $manager->listRuns();
        $this->assertCount(1, $runs);
        $this->assertSame($runId, $runs[0]['id']);
        $this->assertSame($manifestPath, $runs[0]['path'] . DIRECTORY_SEPARATOR . 'manifest.json');
        $this->assertSame($payload['blueprint']['path'], $runs[0]['manifest']['blueprint']['path']);
        $this->assertSame($payload['entries'][0]['path'], $runs[0]['manifest']['entries'][0]['path']);

        $latest = $manager->getLatestRun();
        $this->assertNotNull($latest);
        $this->assertSame($runId, $latest['id']);

        $this->removeDirectory($tempDir);
    }

    public function test_it_creates_backup_for_overwritten_files(): void
    {
        $tempDir = $this->makeTempDirectory();
        $manager = new GenerationHistoryManager($tempDir, true);
        $result = new PipelineResult();
        $previous = 'previous contents';
        $result->addFile([
            'status' => 'overwritten',
            'path' => 'app/Http/Controllers/UserController.php',
            'full_path' => $tempDir . '/app/Http/Controllers/UserController.php',
            'layer' => 'api',
            'bytes' => 128,
            'checksum' => hash('sha256', 'current'),
            'previous_checksum' => hash('sha256', $previous),
            'previous_bytes' => strlen($previous),
            'previous_contents' => $previous,
        ]);

    $runId = $manager->record($this->makeBlueprint(), 'crm/users.yaml', $result, ['execution_id' => 'test-batch']);

        $this->assertIsString($runId);
        $runDirectory = $tempDir . DIRECTORY_SEPARATOR . $runId;
        $this->assertDirectoryExists($runDirectory);

        $manifest = json_decode((string) file_get_contents($runDirectory . DIRECTORY_SEPARATOR . 'manifest.json'), true);
        $this->assertIsArray($manifest);
        $this->assertArrayHasKey(0, $manifest['entries']);
        $entry = $manifest['entries'][0];
        $this->assertSame('overwritten', $entry['status']);
        $this->assertArrayHasKey('backup', $entry);

        $backupPath = $runDirectory . DIRECTORY_SEPARATOR . $entry['backup'];
        $this->assertFileExists($backupPath);
        $this->assertSame($previous, file_get_contents($backupPath));

        $specific = $manager->getRun($runId);
        $this->assertNotNull($specific);
        $this->assertSame($runId, $specific['id']);
        $this->assertSame($runDirectory, $specific['path']);
    $this->assertSame('overwritten', $specific['manifest']['entries'][0]['status']);
    $this->assertSame('test-batch', $specific['manifest']['execution_id']);

        $this->removeDirectory($tempDir);
    }

    private function makeBlueprint(): Blueprint
    {
        return Blueprint::fromArray([
            'path' => 'crm/users.yaml',
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

    private function makeTempDirectory(): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'blueprintx-history-test-' . uniqid('', true);
        mkdir($path, 0777, true);

        return $path;
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $fileInfo) {
            if ($fileInfo->isDir()) {
                rmdir($fileInfo->getRealPath());
            } else {
                unlink($fileInfo->getRealPath());
            }
        }

        rmdir($path);
    }
}
