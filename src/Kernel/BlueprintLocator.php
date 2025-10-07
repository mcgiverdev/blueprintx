<?php

namespace BlueprintX\Kernel;

use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class BlueprintLocator
{
    /**
     * @return array<int, string>
     */
    public function discover(string $basePath, ?string $module = null, ?string $entity = null): array
    {
        $paths = [];
        $normalizedModule = $this->normalizeModule($module);
        $normalizedEntity = $this->normalizeEntity($entity);

        if ($normalizedEntity !== null) {
            $prefix = $normalizedModule ? $normalizedModule . '/' : '';
            foreach (['.yaml', '.yml'] as $extension) {
                $candidate = $basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $prefix . $normalizedEntity . $extension);
                if (is_file($candidate)) {
                    $paths[] = $candidate;
                }
            }

            if ($paths !== []) {
                return $this->sort($paths);
            }
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($basePath, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            if (! Str::endsWith($file->getFilename(), ['.yaml', '.yml'])) {
                continue;
            }

            $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($basePath))), '/');
            $relativeLower = Str::lower($relative);

            if ($normalizedModule !== null) {
                $moduleLower = Str::lower($normalizedModule) . '/';
                if (! str_starts_with($relativeLower, $moduleLower)) {
                    continue;
                }
            }

            if ($normalizedEntity !== null) {
                $filename = pathinfo($relativeLower, PATHINFO_FILENAME);
                if ($filename !== Str::lower($normalizedEntity)) {
                    continue;
                }
            }

            $paths[] = $file->getPathname();
        }

        return $this->sort($paths);
    }

    public function relativePath(string $basePath, string $path): string
    {
        $normalizedBase = rtrim(str_replace('\\', '/', realpath($basePath) ?: $basePath), '/');
        $normalizedPath = str_replace('\\', '/', realpath($path) ?: $path);

        if (str_starts_with($normalizedPath, $normalizedBase)) {
            return ltrim(substr($normalizedPath, strlen($normalizedBase)), '/');
        }

        return $path;
    }

    private function normalizeModule(?string $module): ?string
    {
        if (! is_string($module)) {
            return null;
        }

        $trimmed = trim($module);

        if ($trimmed === '') {
            return null;
        }

        return trim(str_replace(['\\', '/'], '/', $trimmed), '/');
    }

    private function normalizeEntity(?string $entity): ?string
    {
        if (! is_string($entity)) {
            return null;
        }

        $trimmed = trim($entity);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * @param array<int, string> $paths
     * @return array<int, string>
     */
    private function sort(array $paths): array
    {
        sort($paths);

        return array_values($paths);
    }
}
