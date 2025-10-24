<?php

namespace BlueprintX\Kernel\History;

use BlueprintX\Blueprint\Blueprint;
use BlueprintX\Kernel\Generation\PipelineResult;
use DateTimeImmutable;
use Illuminate\Support\Str;

final class GenerationHistoryManager
{
    public function __construct(
        private readonly ?string $storagePath,
        private readonly bool $enabled = true,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled && is_string($this->storagePath) && $this->storagePath !== '';
    }

    /**
     * @param array<string, mixed> $context
     */
    public function record(Blueprint $blueprint, string $relativeBlueprintPath, PipelineResult $result, array $context = []): ?string
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $entries = $this->extractEntries($result);

        if ($entries === []) {
            return null;
        }

        $root = $this->historyRoot();

        if ($root === null) {
            return null;
        }

        if (! $this->ensureDirectory($root)) {
            return null;
        }

        $runId = $this->generateRunId($blueprint);
        $runPath = $root . DIRECTORY_SEPARATOR . $runId;

        if (! $this->ensureDirectory($runPath)) {
            return null;
        }

        foreach ($entries as $index => &$entry) {
            if (! isset($entry['previous_contents']) || ! is_string($entry['previous_contents'])) {
                continue;
            }

            $backupFilename = $this->makeBackupFilename($entry['path'] ?? null, $index);
            $backupFullPath = $runPath . DIRECTORY_SEPARATOR . $backupFilename;

            if (file_put_contents($backupFullPath, $entry['previous_contents']) === false) {
                return null;
            }

            $entry['backup'] = $backupFilename;
            unset($entry['previous_contents']);
        }
        unset($entry);

        $manifest = [
            'id' => $runId,
            'timestamp' => (new DateTimeImmutable())->format(DATE_ATOM),
            'sequence' => microtime(true),
            'execution_id' => $context['execution_id'] ?? null,
            'blueprint' => [
                'module' => $blueprint->module(),
                'entity' => $blueprint->entity(),
                'architecture' => $blueprint->architecture(),
                'path' => $relativeBlueprintPath,
            ],
            'options' => $context['options'] ?? [],
            'filters' => $context['filters'] ?? [],
            'warnings' => $context['warnings'] ?? [],
            'entries' => $entries,
        ];

        $encoded = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($encoded === false) {
            return null;
        }

        $manifestPath = $runPath . DIRECTORY_SEPARATOR . 'manifest.json';

        if (file_put_contents($manifestPath, $encoded) === false) {
            return null;
        }

        return $runId;
    }

    public function historyRoot(): ?string
    {
        if (! $this->isEnabled()) {
            return null;
        }

        return $this->normalizePath((string) $this->storagePath);
    }

    /**
     * @return array<int, array{id:string,path:string,manifest:array<string,mixed>}>
     */
    public function listRuns(): array
    {
        $root = $this->historyRoot();

        if ($root === null || ! is_dir($root)) {
            return [];
        }

        $entries = [];

        $items = @scandir($root);
        if (! is_array($items)) {
            return [];
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $runPath = $root . DIRECTORY_SEPARATOR . $item;

            if (! is_dir($runPath)) {
                continue;
            }

            $manifest = $this->loadManifestFromPath($runPath);

            if ($manifest === null) {
                continue;
            }

            $entries[] = [
                'id' => (string) ($manifest['id'] ?? $item),
                'path' => $runPath,
                'manifest' => $manifest,
            ];
        }

        usort($entries, static function (array $left, array $right): int {
            $leftSequence = isset($left['manifest']['sequence']) ? (float) $left['manifest']['sequence'] : null;
            $rightSequence = isset($right['manifest']['sequence']) ? (float) $right['manifest']['sequence'] : null;

            if ($leftSequence !== null && $rightSequence !== null && $leftSequence !== $rightSequence) {
                return $rightSequence <=> $leftSequence;
            }

            $leftTime = isset($left['manifest']['timestamp']) ? strtotime((string) $left['manifest']['timestamp']) : false;
            $rightTime = isset($right['manifest']['timestamp']) ? strtotime((string) $right['manifest']['timestamp']) : false;

            if ($leftTime !== false && $rightTime !== false && $leftTime !== $rightTime) {
                return $rightTime <=> $leftTime;
            }

            $leftMTime = isset($left['path']) && is_string($left['path']) ? @filemtime($left['path']) : false;
            $rightMTime = isset($right['path']) && is_string($right['path']) ? @filemtime($right['path']) : false;

            if ($leftMTime !== false && $rightMTime !== false && $leftMTime !== $rightMTime) {
                return $rightMTime <=> $leftMTime;
            }

            return strcmp($right['id'], $left['id']);
        });

        return $entries;
    }

    /**
     * @return array{id:string,path:string,manifest:array<string,mixed>}|null
     */
    public function getLatestRun(): ?array
    {
        $runs = $this->listRuns();

        return $runs[0] ?? null;
    }

    /**
     * @return array{id:string,path:string,manifest:array<string,mixed>}|null
     */
    public function getRun(string $runId): ?array
    {
        $runId = trim($runId);

        if ($runId === '') {
            return null;
        }

        $root = $this->historyRoot();

        if ($root === null) {
            return null;
        }

        $runPath = $root . DIRECTORY_SEPARATOR . $runId;

        if (! is_dir($runPath)) {
            return null;
        }

        $manifest = $this->loadManifestFromPath($runPath);

        if ($manifest === null) {
            return null;
        }

        return [
            'id' => (string) ($manifest['id'] ?? $runId),
            'path' => $runPath,
            'manifest' => $manifest,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function extractEntries(PipelineResult $result): array
    {
        $entries = [];

        foreach ($result->files() as $file) {
            $status = $file['status'] ?? null;

            if (! in_array($status, ['written', 'overwritten'], true)) {
                continue;
            }

            $entry = [
                'status' => $status,
                'layer' => $file['layer'] ?? null,
                'path' => $file['path'] ?? null,
                'full_path' => $file['full_path'] ?? null,
            ];

            if (isset($file['bytes'])) {
                $entry['bytes'] = (int) $file['bytes'];
            }

            if (isset($file['checksum'])) {
                $entry['checksum'] = (string) $file['checksum'];
            }

            if ($status === 'overwritten') {
                if (isset($file['previous_checksum'])) {
                    $entry['previous_checksum'] = (string) $file['previous_checksum'];
                }

                if (isset($file['previous_bytes'])) {
                    $entry['previous_bytes'] = (int) $file['previous_bytes'];
                }

                if (isset($file['previous_contents'])) {
                    $entry['previous_contents'] = $file['previous_contents'];
                }
            }

            $entries[] = $entry;
        }

        return $entries;
    }

    private function ensureDirectory(string $path): bool
    {
        if (is_dir($path)) {
            return true;
        }

        return mkdir($path, 0777, true) || is_dir($path);
    }

    private function normalizePath(string $path): string
    {
        return rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
    }

    private function generateRunId(Blueprint $blueprint): string
    {
        $timestamp = (new DateTimeImmutable())->format('YmdHis');
        $module = $blueprint->module() ? Str::slug($blueprint->module()) : 'global';
        $entity = Str::slug($blueprint->entity());

        if ($entity === '') {
            $entity = 'entity';
        }

        return sprintf('%s-%s-%s-%s', $timestamp, $module ?: 'global', $entity, (string) Str::uuid());
    }

    private function makeBackupFilename(?string $path, int $index): string
    {
        $basename = $path ? pathinfo($path, PATHINFO_BASENAME) : 'file';
        $sanitized = Str::slug($basename);

        if ($sanitized === '') {
            $sanitized = 'file';
        }

        return sprintf('%03d-%s.bak', $index + 1, $sanitized);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadManifestFromPath(string $runPath): ?array
    {
        $manifestPath = $runPath . DIRECTORY_SEPARATOR . 'manifest.json';

        if (! is_file($manifestPath)) {
            return null;
        }

        $contents = file_get_contents($manifestPath);

        if ($contents === false) {
            return null;
        }

        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            return null;
        }

        if (! isset($decoded['entries']) || ! is_array($decoded['entries'])) {
            $decoded['entries'] = [];
        }

        return $decoded;
    }
}
