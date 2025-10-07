<?php

namespace BlueprintX\Console\Commands;

use BlueprintX\Contracts\BlueprintParser;
use BlueprintX\Exceptions\BlueprintParseException;
use BlueprintX\Kernel\BlueprintLocator;
use Illuminate\Console\Command;
use Throwable;

class ListBlueprintsCommand extends Command
{
    protected $signature = <<<SIGNATURE
    blueprintx:list
        {--module= : Filtra por módulo (ej. hr)}
        {--entity= : Filtra por entidad (ej. employee)}
        {--json : Devuelve la salida en formato JSON}
SIGNATURE;

    protected $description = 'Lista los blueprints disponibles y muestra información básica de cada uno.';

    public function __construct(
        private readonly BlueprintParser $parser,
        private readonly BlueprintLocator $locator,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $module = $this->normalizeNullableString($this->option('module'));
        $entity = $this->normalizeNullableString($this->option('entity'));
        $outputJson = (bool) $this->option('json');

        $config = $this->laravel['config']->get('blueprintx', []);
        $blueprintsPath = $config['paths']['blueprints'] ?? null;

        if (! is_string($blueprintsPath) || $blueprintsPath === '') {
            $this->error('No se encontró la configuración "blueprintx.paths.blueprints".');

            return self::FAILURE;
        }

        if (! is_dir($blueprintsPath)) {
            $this->error(sprintf('El directorio de blueprints no existe: %s', $blueprintsPath));

            return self::FAILURE;
        }

        $paths = $this->locator->discover($blueprintsPath, $module, $entity);

        if ($paths === []) {
            $message = $module || $entity
                ? 'No se encontraron blueprints con los filtros proporcionados.'
                : 'No se encontraron blueprints registrados.';

            $this->warn($message);

            return self::SUCCESS;
        }

        $entries = [];
        $errors = [];

        foreach ($paths as $path) {
            $relative = $this->locator->relativePath($blueprintsPath, $path);

            try {
                $blueprint = $this->parser->parse($path);
            } catch (BlueprintParseException $exception) {
                $errors[] = sprintf('%s: %s', $relative, $exception->getMessage());
                continue;
            } catch (Throwable $exception) {
                $errors[] = sprintf('%s: %s', $relative, $exception->getMessage());
                continue;
            }

            $entries[] = [
                'module' => $blueprint->module() ?: '-',
                'entity' => $blueprint->entity(),
                'architecture' => $blueprint->architecture(),
                'path' => $relative,
            ];
        }

        if ($outputJson) {
            $this->line(json_encode([
                'blueprints' => $entries,
                'errors' => $errors,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->table(
                ['Módulo', 'Entidad', 'Arquitectura', 'Ruta'],
                array_map(static function (array $entry): array {
                    return [
                        $entry['module'],
                        $entry['entity'],
                        $entry['architecture'],
                        $entry['path'],
                    ];
                }, $entries)
            );

            if ($errors !== []) {
                $this->warn('Se encontraron errores durante el parseo de algunos blueprints:');
                foreach ($errors as $error) {
                    $this->warn('  - ' . $error);
                }
            }
        }

        return $errors === [] ? self::SUCCESS : self::FAILURE;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
