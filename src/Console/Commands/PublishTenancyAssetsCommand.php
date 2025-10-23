<?php

namespace BlueprintX\Console\Commands;

use BlueprintX\Support\Tenancy\TenancyScaffoldingCreator;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Finder\SplFileInfo;

class PublishTenancyAssetsCommand extends Command
{
    protected $signature = 'blueprintx:tenancy:publish
        {--migrations= : Carpeta destino para las migraciones base (por defecto database/migrations)}
        {--blueprint= : Ruta relativa del blueprint base (por defecto central/tenancy/tenants.yaml)}
        {--without-migrations : Omite copiar las migraciones base}
        {--without-blueprint : Omite generar el blueprint base}
        {--force : Sobrescribe archivos existentes}';

    protected $description = 'Publica los artefactos base de tenancy (migraciones tenants/domains y blueprint central) para poder personalizarlos.';

    public function __construct(
        private readonly Filesystem $files,
        private readonly TenancyScaffoldingCreator $tenancyScaffolding,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $publishMigrations = ! (bool) $this->option('without-migrations');
        $publishBlueprint = ! (bool) $this->option('without-blueprint');

        if (! $publishMigrations && ! $publishBlueprint) {
            $this->warn('No hay artefactos por publicar (ambas opciones fueron omitidas).');

            return self::SUCCESS;
        }

        $basePath = $this->resolveBasePath();
        $summary = [
            'migrations' => [
                'written' => 0,
                'overwritten' => 0,
                'skipped' => 0,
            ],
        ];

        if ($publishMigrations) {
            $result = $this->publishMigrations($basePath, $force);

            if ($result === null) {
                return self::FAILURE;
            }

            $summary['migrations'] = $result;
        }

        if ($publishBlueprint) {
            $result = $this->publishBlueprint($basePath, $force);

            if ($result === null) {
                return self::FAILURE;
            }

            $summary['blueprint'] = $result;
        }

        $this->line('');

        if ($publishMigrations) {
            $this->info(sprintf(
                'Migraciones: %d nuevas, %d sobrescritas, %d omitidas',
                $summary['migrations']['written'],
                $summary['migrations']['overwritten'],
                $summary['migrations']['skipped'],
            ));
        }

        if ($publishBlueprint && isset($summary['blueprint'])) {
            $status = $summary['blueprint']['status'];
            $message = match ($status) {
                'written' => 'Blueprint generado correctamente.',
                'overwritten' => 'Blueprint sobrescrito correctamente.',
                'skipped' => 'Blueprint existente (usa --force para sobrescribir).',
                default => $summary['blueprint']['message'] ?? 'Blueprint procesado.',
            };

            ($status === 'error') ? $this->error($message) : $this->info($message);
        }

        return self::SUCCESS;
    }

    private function publishMigrations(string $basePath, bool $force): ?array
    {
        $destinationOption = $this->option('migrations');
        $destination = $this->resolveDestinationPath($basePath, $destinationOption, 'database/migrations');

        if (! $this->files->isDirectory($destination)) {
            $this->files->ensureDirectoryExists($destination);
        }

        $source = $basePath . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'stancl' . DIRECTORY_SEPARATOR . 'tenancy' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'migrations';

        if (! $this->files->isDirectory($source)) {
            $this->error('No se encontró la carpeta de migraciones base de stancl/tenancy. ¿Está instalado el paquete?');

            return null;
        }

        $files = array_filter(
            $this->files->files($source),
            static fn (SplFileInfo $file): bool => Str::endsWith($file->getFilename(), '_create_tenants_table.php')
                || Str::endsWith($file->getFilename(), '_create_domains_table.php'),
        );

        if ($files === []) {
            $this->warn('No se detectaron archivos de migración para copiar.');

            return [
                'written' => 0,
                'overwritten' => 0,
                'skipped' => 0,
            ];
        }

        $written = 0;
        $overwritten = 0;
        $skipped = 0;

        foreach ($files as $file) {
            $target = $destination . DIRECTORY_SEPARATOR . $file->getFilename();

            if ($this->files->exists($target) && ! $force) {
                $this->line(sprintf('  - Omitido: %s (usa --force para sobrescribir)', $this->relativeToBase($basePath, $target)));
                $skipped++;
                continue;
            }

            $this->files->ensureDirectoryExists(dirname($target));
            $copied = $this->files->copy($file->getRealPath(), $target);

            if (! $copied) {
                $this->error(sprintf('No se pudo copiar %s.', $file->getFilename()));

                return null;
            }

            if ($this->files->exists($target) && $force) {
                $overwritten++;
                $this->info(sprintf('  - Sobrescrito: %s', $this->relativeToBase($basePath, $target)));
            } else {
                $written++;
                $this->info(sprintf('  - Copiado: %s', $this->relativeToBase($basePath, $target)));
            }
        }

        return compact('written', 'overwritten', 'skipped');
    }

    private function publishBlueprint(string $basePath, bool $force): ?array
    {
        $blueprintsBase = $this->resolveBlueprintsRoot();
        $relativeBlueprint = $this->option('blueprint');

        if (! is_string($relativeBlueprint) || trim($relativeBlueprint) === '') {
            $relativeBlueprint = 'central/tenancy/tenants.yaml';
        }

        $result = $this->tenancyScaffolding->ensure([
            'enabled' => true,
            'blueprints_path' => $blueprintsBase,
            'relative_path' => $relativeBlueprint,
            'force' => $force,
            'dry_run' => false,
            'middleware_alias' => config('blueprintx.features.tenancy.middleware_alias', 'tenant'),
            'driver_label' => 'stancl/tenancy',
        ]);

        if ($result === null) {
            $this->error('No se pudo generar el blueprint base (verifica la ruta configurada).');

            return null;
        }

        $path = $result['full_path'] ?? $result['path'] ?? $relativeBlueprint;
        $status = $result['status'] ?? 'written';

        $this->line(sprintf('Blueprint: %s (%s)', $this->relativeToBase($basePath, (string) $path), $status));

        return $result;
    }

    private function resolveBasePath(): string
    {
        if (function_exists('base_path')) {
            return base_path();
        }

        $cwd = getcwd();

        return $cwd !== false ? $cwd : __DIR__ . '/../../../..';
    }

    private function resolveDestinationPath(string $basePath, mixed $option, string $default): string
    {
        $path = is_string($option) && trim($option) !== '' ? trim($option) : $default;

        if ($this->isAbsolutePath($path)) {
            return $path;
        }

            return rtrim($basePath, "/\\") . DIRECTORY_SEPARATOR . str_replace(["/", "\\"], DIRECTORY_SEPARATOR, $path);
    }

    private function resolveBlueprintsRoot(): string
    {
        $configPath = config('blueprintx.paths.blueprints');

        if (is_string($configPath) && trim($configPath) !== '') {
            return $configPath;
        }

        if (function_exists('base_path')) {
            return base_path('blueprints');
        }

        $cwd = getcwd();

        return $cwd !== false ? $cwd . DIRECTORY_SEPARATOR . 'blueprints' : __DIR__ . '/../../../../blueprints';
    }

    private function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || (strlen($path) > 1 && $path[1] === ':')
            || Str::startsWith($path, ['\\', '//']);
    }

    private function relativeToBase(string $basePath, string $target): string
    {
        $normalizedBase = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $basePath), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $normalizedTarget = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $target);

        if (Str::startsWith($normalizedTarget, $normalizedBase)) {
            return substr($normalizedTarget, strlen($normalizedBase));
        }

        return $normalizedTarget;
    }
}
