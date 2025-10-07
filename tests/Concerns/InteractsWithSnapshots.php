<?php

namespace BlueprintX\Tests\Concerns;

trait InteractsWithSnapshots
{
    protected function snapshot(string $filename): string
    {
        $path = dirname(__DIR__) . '/__snapshots__/' . ltrim($filename, '/');

        $this->assertFileExists($path, sprintf('No se encontró el snapshot "%s".', $path));

        $contents = file_get_contents($path);
        $this->assertNotFalse($contents, sprintf('No se pudo leer el snapshot "%s".', $path));

        return $this->normalizeNewLines($contents);
    }

    protected function normalizeNewLines(string $contents): string
    {
        return str_replace(["\r\n", "\r"], "\n", $contents);
    }
}
