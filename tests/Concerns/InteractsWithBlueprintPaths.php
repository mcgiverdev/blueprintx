<?php

namespace BlueprintX\Tests\Concerns;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

trait InteractsWithBlueprintPaths
{
    /**
     * @var array<int, string>
     */
    protected array $temporaryDirectories = [];

    protected string $blueprintsPath;

    protected string $outputPath;

    protected function initializeBlueprintWorkspace(): void
    {
        if (isset($this->blueprintsPath) && isset($this->outputPath)) {
            return;
        }

        $basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'blueprintx_' . bin2hex(random_bytes(6));
        $this->temporaryDirectories[] = $basePath;

    $this->blueprintsPath = $basePath . DIRECTORY_SEPARATOR . 'blueprints';
    $this->outputPath = $basePath . DIRECTORY_SEPARATOR . 'output';

        $this->ensureDirectoryExists($this->blueprintsPath);
        $this->ensureDirectoryExists($this->outputPath);
    }

    protected function blueprintsPath(string $path = ''): string
    {
        $this->initializeBlueprintWorkspace();

        return rtrim($this->blueprintsPath . ($path !== '' ? DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR) : ''), DIRECTORY_SEPARATOR);
    }

    protected function outputPath(string $path = ''): string
    {
        $this->initializeBlueprintWorkspace();

        return rtrim($this->outputPath . ($path !== '' ? DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR) : ''), DIRECTORY_SEPARATOR);
    }

    protected function putBlueprint(string $relativePath, string $contents = ''): string
    {
        $this->initializeBlueprintWorkspace();

        $absolutePath = $this->blueprintsPath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
        $directory = dirname($absolutePath);

        $this->ensureDirectoryExists($directory);
        file_put_contents($absolutePath, $contents);

        return $absolutePath;
    }

    protected function cleanupTemporaryDirectories(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            $this->deleteDirectory($directory);
        }

        $this->temporaryDirectories = [];
        unset($this->blueprintsPath, $this->outputPath);
    }

    private function ensureDirectoryExists(string $path): void
    {
        if (! is_dir($path)) {
            mkdir($path, 0777, true);
        }
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
