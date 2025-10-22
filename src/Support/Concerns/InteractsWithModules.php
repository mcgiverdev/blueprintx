<?php

namespace BlueprintX\Support\Concerns;

use BlueprintX\Blueprint\Blueprint;
use Illuminate\Support\Str;

trait InteractsWithModules
{
    /**
     * @return string[]
     */
    private function moduleSegments(Blueprint $blueprint): array
    {
        $module = $blueprint->module();

        if (! is_string($module) || trim($module) === '') {
            return [];
        }

        $normalized = str_replace(['\\', '.'], '/', $module);
        $parts = array_filter(
            array_map('trim', explode('/', $normalized)),
            static fn (string $segment): bool => $segment !== ''
        );

        return array_map(static fn (string $segment): string => Str::studly($segment), $parts);
    }

    private function moduleNamespace(Blueprint $blueprint): ?string
    {
        $segments = $this->moduleSegments($blueprint);

        return $segments === [] ? null : implode('\\', $segments);
    }

    private function modulePath(Blueprint $blueprint): ?string
    {
        $segments = $this->moduleSegments($blueprint);

        return $segments === [] ? null : implode('/', $segments);
    }

    private function moduleClassPrefix(Blueprint $blueprint): string
    {
        $segments = $this->moduleSegments($blueprint);

        return $segments === [] ? '' : implode('', $segments);
    }
}
