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
        $relativePath = ltrim($file->path, '\\/');
        $absolutePath = $this->basePath . DIRECTORY_SEPARATOR . $relativePath;
        $directory = dirname($absolutePath);
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
}
