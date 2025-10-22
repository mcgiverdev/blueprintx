<?php

namespace BlueprintX\Support\Tenancy;

use Illuminate\Filesystem\Filesystem;

class TenancyScaffoldingCreator
{
    public function __construct(private readonly Filesystem $files)
    {
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>|null
     */
    public function ensure(array $options = []): ?array
    {
        $enabled = array_key_exists('enabled', $options) ? (bool) $options['enabled'] : false;
        if (! $enabled) {
            return null;
        }

        $dryRun = (bool) ($options['dry_run'] ?? false);
        if ($dryRun) {
            return null;
        }

        $basePath = $options['blueprints_path'] ?? null;
        if (! is_string($basePath) || $basePath === '') {
            return null;
        }

        $relative = $this->normalizeRelativePath($options['relative_path'] ?? 'central/tenancy/tenants.yaml');
        if ($relative === '') {
            return null;
        }

        $absolute = $this->buildAbsolutePath($basePath, $relative);
        $force = (bool) ($options['force'] ?? false);
        $exists = $this->files->exists($absolute);

        if ($exists && ! $force) {
            return [
                'status' => 'skipped',
                'path' => $relative,
                'full_path' => $absolute,
            ];
        }

        $this->files->ensureDirectoryExists((string) dirname($absolute));

        $contents = $this->buildBlueprintContents($options);

        $written = $this->files->put($absolute, $contents);

        if ($written === false) {
            return [
                'status' => 'error',
                'path' => $relative,
                'full_path' => $absolute,
                'message' => 'No se pudo escribir el blueprint de tenancy.',
            ];
        }

        return [
            'status' => $exists ? 'overwritten' : 'written',
            'path' => $relative,
            'full_path' => $absolute,
            'bytes' => strlen($contents),
        ];
    }

    private function normalizeRelativePath(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        $trimmed = trim($value);

        if ($trimmed === '' || $trimmed === '/' || $trimmed === '\\') {
            return '';
        }

        $normalized = str_replace('\\', '/', $trimmed);
        $normalized = preg_replace('#/+#', '/', $normalized) ?? $normalized;

        return ltrim($normalized, '/');
    }

    private function buildAbsolutePath(string $basePath, string $relative): string
    {
        $cleanRelative = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);

        return rtrim($basePath, '\\/') . DIRECTORY_SEPARATOR . $cleanRelative;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function buildBlueprintContents(array $options): string
    {
        $middlewareAlias = $options['middleware_alias'] ?? 'tenant';
        if (! is_string($middlewareAlias) || $middlewareAlias === '') {
            $middlewareAlias = 'tenant';
        }

        $driverLabel = $options['driver_label'] ?? null;
        if (! is_string($driverLabel) || $driverLabel === '') {
            $driverLabel = null;
        }

        $lines = [
            '# Blueprint base generado por BlueprintX (tenancy)',
        ];

        if ($driverLabel !== null) {
            $lines[] = sprintf('# Driver detectado: %s', $driverLabel);
        }

        $lines = array_merge($lines, [
            'module: tenancy',
            'entity: Tenant',
            'table: tenants',
            'architecture: hexagonal',
            'tenancy:',
            '  mode: central',
            '  storage: central',
            '  routing_scope: central',
            '  seed_scope: central',
            'fields:',
            '  - name: id',
            '    type: uuid',
            '  - name: name',
            '    type: string',
            '    rules: required|string|max:120',
            '  - name: slug',
            '    type: string',
            '    rules: nullable|string|max:120',
            '  - name: domain',
            '    type: string',
            '    rules: nullable|string|max:191',
            '  - name: active',
            '    type: boolean',
            '    default: true',
            'options:',
            '  timestamps: true',
            '  softDeletes: true',
            'relations: []',
            'api:',
            '  base_path: /tenancy/tenants',
            '  middleware:',
            '    - auth:sanctum',
            sprintf('    - %s', $middlewareAlias),
            '  endpoints:',
            '    - type: crud',
            '    - type: search',
            '      fields:',
            '        - name',
            '        - domain',
            '  resources:',
            '    includes: []',
            'docs:',
            "  summary: 'Blueprint base para gestionar tenants'",
            'errors: []',
            'metadata: []',
        ]);

        return implode("\n", $lines) . "\n";
    }
}
