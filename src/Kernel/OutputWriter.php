<?php

namespace BlueprintX\Kernel;

use BlueprintX\Kernel\Generation\GeneratedFile;

class OutputWriter
{
    public function __construct(private readonly string $basePath)
    {
    }

    /**
     * @param GeneratedFile[] $files
     * @return array<int, array<string, mixed>>
     */
    public function writeFiles(array $files, array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $force = (bool) ($options['force'] ?? false);

        $results = [];

        foreach ($files as $file) {
            $results[] = $this->writeFile($file, $dryRun, $force);
        }

        return $results;
    }

    private function writeFile(GeneratedFile $file, bool $dryRun, bool $force): array
    {
        $relativePath = ltrim(str_replace('\\', '/', $file->path), '/');
        $absolutePath = $this->basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $relativeDirectory = trim(str_replace('\\', '/', dirname($relativePath)), './');
        $directory = $relativeDirectory === ''
            ? $this->basePath
            : $this->basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);
        $bytes = strlen($file->contents);
        $checksum = hash('sha256', $file->contents);

        if ($dryRun) {
            return [
                'path' => $relativePath,
                'full_path' => $absolutePath,
                'status' => 'preview',
                'preview' => $file->contents,
                'bytes' => $bytes,
                'checksum' => $checksum,
            ];
        }

        if (! $this->ensureDirectoryStructure($relativeDirectory)) {
            return [
                'path' => $relativePath,
                'full_path' => $absolutePath,
                'status' => 'error',
                'message' => sprintf('No se pudo preparar el directorio "%s".', $directory),
                'bytes' => $bytes,
                'checksum' => $checksum,
            ];
        }

        if (! is_dir($directory)) {
            if (! mkdir($directory, 0777, true) && ! is_dir($directory)) {
                return [
                    'path' => $relativePath,
                    'full_path' => $absolutePath,
                    'status' => 'error',
                    'message' => sprintf('No se pudo crear el directorio "%s".', $directory),
                    'bytes' => $bytes,
                    'checksum' => $checksum,
                ];
            }
        }

        $exists = is_file($absolutePath);
        $previousContents = null;

        if ($exists) {
            $contents = file_get_contents($absolutePath);

            if ($contents !== false) {
                $previousContents = $contents;
            }
        }

        if ($exists && ! ($force || $file->overwrite)) {
            return [
                'path' => $relativePath,
                'full_path' => $absolutePath,
                'status' => 'skipped',
                'message' => 'El archivo ya existe. Usa --force para sobrescribir.',
                'bytes' => $bytes,
                'checksum' => $checksum,
            ];
        }

        $written = file_put_contents($absolutePath, $file->contents);
        if ($written === false) {
            return [
                'path' => $relativePath,
                'full_path' => $absolutePath,
                'status' => 'error',
                'message' => 'No se pudo escribir el archivo.',
                'bytes' => $bytes,
                'checksum' => $checksum,
            ];
        }

        $result = [
            'path' => $relativePath,
            'full_path' => $absolutePath,
            'status' => $exists ? 'overwritten' : 'written',
            'bytes' => $bytes,
            'checksum' => $checksum,
        ];

        if ($exists && is_string($previousContents)) {
            $result['previous_contents'] = $previousContents;
            $result['previous_checksum'] = hash('sha256', $previousContents);
            $result['previous_bytes'] = strlen($previousContents);
        }

        return $result;
    }

    private function ensureDirectoryStructure(string $relativeDirectory): bool
    {
        $normalized = trim(str_replace('\\', '/', $relativeDirectory), '/');

        if ($normalized === '') {
            return true;
        }

        $segments = array_filter(explode('/', $normalized), static fn (string $segment): bool => $segment !== '' && $segment !== '.');

        $current = $this->basePath;

        foreach ($segments as $segment) {
            $expectedPath = $current . DIRECTORY_SEPARATOR . $segment;

            if (is_dir($expectedPath)) {
                $current = $expectedPath;

                continue;
            }

            $matchedPath = $this->findDirectoryCaseInsensitive($current, $segment);

            if ($matchedPath !== null) {
                if ($matchedPath !== $expectedPath && ! $this->renameDirectoryPreservingCase($matchedPath, $expectedPath)) {
                    return false;
                }

                $current = $expectedPath;

                continue;
            }

            if (! mkdir($expectedPath, 0777, true) && ! is_dir($expectedPath)) {
                return false;
            }

            $current = $expectedPath;
        }

        return true;
    }

    private function findDirectoryCaseInsensitive(string $parent, string $segment): ?string
    {
        if (! is_dir($parent)) {
            return null;
        }

        $entries = scandir($parent);

        if ($entries === false) {
            return null;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $candidate = $parent . DIRECTORY_SEPARATOR . $entry;

            if (! is_dir($candidate)) {
                continue;
            }

            if (strcasecmp($entry, $segment) === 0) {
                return $candidate;
            }
        }

        return null;
    }

    private function renameDirectoryPreservingCase(string $currentPath, string $expectedPath): bool
    {
        if ($currentPath === $expectedPath) {
            return true;
        }

        if (@rename($currentPath, $expectedPath)) {
            return true;
        }

        $tempPath = $expectedPath . '__tmp__' . uniqid('', false);

        if (@rename($currentPath, $tempPath)) {
            if (@rename($tempPath, $expectedPath)) {
                return true;
            }

            @rename($tempPath, $currentPath);
        }

        return false;
    }
}
