<?php

namespace BlueprintX\Console\Commands;

use BlueprintX\Contracts\BlueprintParser;
use BlueprintX\Contracts\BlueprintValidator;
use BlueprintX\Exceptions\BlueprintParseException;
use BlueprintX\Exceptions\BlueprintValidationException;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

class ValidateBlueprintsCommand extends Command
{
    protected $signature = 'blueprintx:validate {module? : Filtra por módulo (ej. hr, sales)} {--json : Devuelve el resultado en formato JSON}';

    protected $description = 'Valida los blueprints YAML registrados en la aplicación.';

    public function __construct(
        private readonly BlueprintParser $parser,
        private readonly BlueprintValidator $validator
    )
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $module = $this->argument('module');
        $asJson = (bool) $this->option('json');

        $config = $this->laravel['config']->get('blueprintx');
        $basePath = $config['paths']['blueprints'] ?? base_path('blueprints');

        if (! is_dir($basePath)) {
            $this->error(sprintf('El directorio de blueprints no existe: %s', $basePath));

            return self::FAILURE;
        }

        $files = $this->discoverBlueprints($basePath, $module);

        if ($files === []) {
            $message = $module ? "No se encontraron blueprints para el módulo '{$module}'." : 'No se encontraron blueprints.';
            $this->warn($message);

            return self::SUCCESS;
        }

        $results = [];
        $hasErrors = false;

        foreach ($files as $file) {
            $result = $this->validateFile($file, $basePath);
            $results[] = $result;
            $hasErrors = $hasErrors || $result['status'] === 'error';
        }

        if ($asJson) {
            $this->line(json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->renderTable($results);
        }

        $summary = sprintf(
            '%d blueprint(s) analizados • %d error(es) • %d warning(s)',
            count($results),
            $this->countIssues($results, 'errors'),
            $this->countIssues($results, 'warnings'),
        );

        if ($hasErrors) {
            $this->error($summary);

            return self::FAILURE;
        }

        $this->info($summary);

        return self::SUCCESS;
    }

    private function discoverBlueprints(string $basePath, ?string $module): array
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($basePath, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $files = [];
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }

            if (! Str::endsWith($file->getFilename(), ['.yaml', '.yml'])) {
                continue;
            }

            $relative = ltrim(str_replace('\\', '/', Str::after($file->getPathname(), $basePath)), '/');
            if ($module !== null && ! Str::startsWith($relative, $module . '/')) {
                continue;
            }

            $files[] = $file->getPathname();
        }

        sort($files);

        return $files;
    }

    /**
     * @return array{file:string,module:?string,entity:?string,status:string,errors:array<int,string>,warnings:array<int,string>}
     */
    private function validateFile(string $path, string $basePath): array
    {
        $relative = ltrim(str_replace('\\', '/', Str::after($path, $basePath)), '/');

        try {
            $blueprint = $this->parser->parse($path);
            $validation = $this->validator->validate($blueprint);
            $errors = array_map(static fn ($message): array => $message->toArray(), $validation->errors());
            $warnings = array_map(static fn ($message): array => $message->toArray(), $validation->warnings());

            return [
                'file' => $relative,
                'module' => $blueprint->module(),
                'entity' => $blueprint->entity(),
                'status' => $errors ? 'error' : 'ok',
                'errors' => $errors,
                'warnings' => $warnings,
            ];
        } catch (BlueprintParseException $exception) {
            return [
                'file' => $relative,
                'module' => null,
                'entity' => null,
                'status' => 'error',
                'errors' => [[
                    'code' => 'parser.error',
                    'message' => $exception->getMessage(),
                    'path' => null,
                ]],
                'warnings' => [],
            ];
        } catch (BlueprintValidationException $exception) {
            return [
                'file' => $relative,
                'module' => null,
                'entity' => null,
                'status' => 'error',
                'errors' => [[
                    'code' => 'validator.failure',
                    'message' => $exception->getMessage(),
                    'path' => null,
                ]],
                'warnings' => [],
            ];
        } catch (Throwable $exception) {
            return [
                'file' => $relative,
                'module' => null,
                'entity' => null,
                'status' => 'error',
                'errors' => [[
                    'code' => 'validator.unexpected',
                    'message' => sprintf('Error inesperado al validar "%s": %s', $relative, $exception->getMessage()),
                    'path' => null,
                ]],
                'warnings' => [],
            ];
        }
    }

    private function renderTable(array $results): void
    {
        $this->table(
            ['Archivo', 'Módulo', 'Entidad', 'Estado', 'Errores', 'Warnings'],
            array_map(static function (array $result): array {
                return [
                    $result['file'],
                    $result['module'] ?? '-',
                    $result['entity'] ?? '-',
                    strtoupper($result['status']),
                    self::messagesToText($result['errors']),
                    self::messagesToText($result['warnings']),
                ];
            }, $results)
        );
    }

    private function countIssues(array $results, string $key): int
    {
        return array_sum(array_map(static fn (array $result): int => count($result[$key]), $results));
    }

    private static function messagesToText(array $messages): string
    {
        if ($messages === []) {
            return '';
        }

        return implode(PHP_EOL, array_map(static function (array $message): string {
            $pathSuffix = $message['path'] ? sprintf(' (%s)', $message['path']) : '';

            return sprintf('[%s] %s%s', $message['code'], $message['message'], $pathSuffix);
        }, $messages));
    }
}
