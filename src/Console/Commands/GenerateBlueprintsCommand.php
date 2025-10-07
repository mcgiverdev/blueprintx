<?php

namespace BlueprintX\Console\Commands;

use BlueprintX\Blueprint\Blueprint;
use BlueprintX\Contracts\BlueprintParser;
use BlueprintX\Contracts\BlueprintValidator;
use BlueprintX\Exceptions\BlueprintParseException;
use BlueprintX\Exceptions\BlueprintValidationException;
use BlueprintX\Kernel\BlueprintLocator;
use BlueprintX\Kernel\Generation\PipelineResult;
use BlueprintX\Kernel\GenerationPipeline;
use BlueprintX\Validation\ValidationMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

class GenerateBlueprintsCommand extends Command
{
    protected $signature = <<<SIGNATURE
    blueprintx:generate
        {module? : Módulo del blueprint (ej. hr)}
        {entity? : Entidad del blueprint (ej. employee)}
        {--module= : Filtra por módulo (alternativa al argumento)}
        {--entity= : Filtra por entidad (alternativa al argumento)}
        {--architecture= : Sobrescribe la arquitectura declarada en el blueprint}
        {--only= : Lista de capas a generar separadas por coma}
        {--dry-run : Previsualiza cambios sin escribir archivos}
        {--force : Sobrescribe archivos existentes sin preguntar}
        {--with-openapi : Fuerza la generación del documento OpenAPI}
        {--without-openapi : Omite la generación del documento OpenAPI}
        {--validate-openapi : Fuerza la validación del documento OpenAPI}
        {--skip-openapi-validation : Omite la validación del documento OpenAPI}
SIGNATURE;

    protected $description = 'Genera artefactos a partir de blueprints YAML usando el pipeline configurado.';

    public function __construct(
        private readonly BlueprintParser $parser,
        private readonly BlueprintValidator $validator,
        private readonly GenerationPipeline $pipeline,
        private readonly BlueprintLocator $locator,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $module = $this->normalizeNullableString($this->option('module') ?: $this->argument('module'));
        $entity = $this->normalizeNullableString($this->option('entity') ?: $this->argument('entity'));
        $architectureOverride = $this->normalizeNullableString($this->option('architecture'));
        $only = $this->normalizeNullableString($this->option('only'));
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        $config = $this->laravel['config']->get('blueprintx', []);
        $featureConfig = $config['features']['openapi'] ?? [];
        $defaultOpenApiEnabled = (bool) ($featureConfig['enabled'] ?? false);
        $defaultOpenApiValidation = array_key_exists('validate', $featureConfig) ? (bool) $featureConfig['validate'] : true;

        $pathsConfig = $config['paths'] ?? [];
        $formRequestFeature = $config['features']['api']['form_requests'] ?? [];

        $apiControllersPath = $this->normalizeRelativePath($pathsConfig['api'] ?? null, 'app/Http/Controllers/Api');
        $defaultRequestsPath = $this->normalizeRelativePath($pathsConfig['api_requests'] ?? null, 'app/Http/Requests/Api');
        $formRequestsPath = $this->normalizeRelativePath($formRequestFeature['path'] ?? null, $defaultRequestsPath);

        $formRequestsEnabled = array_key_exists('enabled', $formRequestFeature)
            ? (bool) $formRequestFeature['enabled']
            : true;

        $requestsNamespace = $formRequestFeature['namespace'] ?? 'App\\Http\\Requests\\Api';
        if (! is_string($requestsNamespace) || $requestsNamespace === '') {
            $requestsNamespace = 'App\\Http\\Requests\\Api';
        }
        $requestsNamespace = trim($requestsNamespace, '\\');

        $authorizeByDefault = array_key_exists('authorize_by_default', $formRequestFeature)
            ? (bool) $formRequestFeature['authorize_by_default']
            : true;

        $blueprintsPath = $config['paths']['blueprints'] ?? null;

        if (! is_string($blueprintsPath) || $blueprintsPath === '') {
            $this->error('No se encontró la configuración "blueprintx.paths.blueprints".');

            return self::FAILURE;
        }

        if (! is_dir($blueprintsPath)) {
            $this->error(sprintf('El directorio de blueprints no existe: %s', $blueprintsPath));

            return self::FAILURE;
        }

        $withOpenApiOption = (bool) $this->option('with-openapi');
        $withoutOpenApiOption = (bool) $this->option('without-openapi');
        $validateOpenApiOption = (bool) $this->option('validate-openapi');
        $skipOpenApiValidation = (bool) $this->option('skip-openapi-validation');

        if ($withOpenApiOption && $withoutOpenApiOption) {
            $this->error('No se puede usar "--with-openapi" junto con "--without-openapi".');

            return self::FAILURE;
        }

        if ($validateOpenApiOption && $skipOpenApiValidation) {
            $this->error('No se puede usar "--validate-openapi" junto con "--skip-openapi-validation".');

            return self::FAILURE;
        }

        $withOpenApi = $withOpenApiOption ? true : ($withoutOpenApiOption ? false : $defaultOpenApiEnabled);
        $validateOpenApi = $skipOpenApiValidation ? false : ($validateOpenApiOption ? true : $defaultOpenApiValidation);

        $blueprintPaths = $this->locator->discover($blueprintsPath, $module, $entity);

        if ($blueprintPaths === []) {
            $message = $module || $entity
                ? 'No se encontraron blueprints con los filtros proporcionados.'
                : 'No se encontraron blueprints para generar.';

            $this->warn($message);

            return self::SUCCESS;
        }

        $this->info(sprintf('Se encontraron %d blueprint(s) para generar.', count($blueprintPaths)));
        $this->newLine();

        $hasErrors = false;
        $summary = [
            'written' => 0,
            'overwritten' => 0,
            'skipped' => 0,
            'preview' => 0,
            'errors' => 0,
            'warnings' => 0,
        ];

        foreach ($blueprintPaths as $path) {
            $relative = $this->locator->relativePath($blueprintsPath, $path);
            $this->line(sprintf('<comment>Blueprint:</comment> %s', $relative));

            try {
                $blueprint = $this->parser->parse($path);
            } catch (BlueprintParseException $exception) {
                $this->error(sprintf('  Error al parsear "%s": %s', $relative, $exception->getMessage()));
                $hasErrors = true;

                continue;
            } catch (Throwable $exception) {
                $this->error(sprintf('  Error inesperado al parsear "%s": %s', $relative, $exception->getMessage()));
                $hasErrors = true;

                continue;
            }

            if ($architectureOverride !== null) {
                $blueprint = $this->overrideArchitecture($blueprint, $architectureOverride);
                $this->line(sprintf('  > Arquitectura forzada a "%s".', $architectureOverride));
            }

            try {
                $validation = $this->validator->validate($blueprint);
            } catch (BlueprintValidationException $exception) {
                $this->error(sprintf('  Falló la validación del blueprint "%s": %s', $relative, $exception->getMessage()));
                $hasErrors = true;

                continue;
            } catch (Throwable $exception) {
                $this->error(sprintf('  Error inesperado al validar "%s": %s', $relative, $exception->getMessage()));
                $hasErrors = true;

                continue;
            }

            if (! $validation->isValid()) {
                $this->error('  La validación produjo errores:');
                $this->renderValidationMessages($validation->errors(), 'error');
                $hasErrors = true;

                continue;
            }

            if ($validation->warnings() !== []) {
                $this->warn('  La validación devolvió warnings:');
                $this->renderValidationMessages($validation->warnings(), 'warning');
            }

            $pipelineOptions = [
                'dry_run' => $dryRun,
                'force' => $force,
                'with_openapi' => $withOpenApi,
                'validate_openapi' => $validateOpenApi,
                'paths' => [
                    'api' => $apiControllersPath,
                    'api_requests' => $defaultRequestsPath,
                ],
                'form_requests' => [
                    'enabled' => $formRequestsEnabled,
                    'namespace' => $requestsNamespace,
                    'path' => $formRequestsPath,
                    'authorize_by_default' => $authorizeByDefault,
                ],
            ];

            if ($only !== null) {
                $pipelineOptions['only'] = $only;
            }

            try {
                $result = $this->pipeline->generate($blueprint, $pipelineOptions);
            } catch (Throwable $exception) {
                $this->error(sprintf('  Error inesperado al generar "%s": %s', $relative, $exception->getMessage()));
                $hasErrors = true;

                continue;
            }

            $hasErrors = $this->renderPipelineResult($result, $summary) || $hasErrors;

            $this->newLine();
        }

        $totalProcessed = $summary['written'] + $summary['overwritten'] + $summary['skipped'] + $summary['preview'];
        $parts = [];

        if ($summary['written'] > 0) {
            $parts[] = sprintf('nuevos: %d', $summary['written']);
        }
        if ($summary['overwritten'] > 0) {
            $parts[] = sprintf('sobrescritos: %d', $summary['overwritten']);
        }
        if ($summary['skipped'] > 0) {
            $parts[] = sprintf('omitidos: %d', $summary['skipped']);
        }
        if ($summary['preview'] > 0) {
            $parts[] = sprintf('previews: %d', $summary['preview']);
        }

        $parts[] = sprintf('warnings: %d', $summary['warnings']);
        $parts[] = sprintf('errores: %d', $summary['errors']);

        $this->info(sprintf(
            'Resumen: %d archivo(s) procesados • %s',
            $totalProcessed,
            implode(' • ', $parts),
        ));

        if ($hasErrors || $summary['errors'] > 0) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function normalizeRelativePath(mixed $value, string $default): string
    {
        if (! is_string($value)) {
            return trim($default, '\\/');
        }

        $value = trim($value);

        if ($value === '') {
            return trim($default, '\\/');
        }

        return trim($value, '\\/');
    }

    /**
     * @param ValidationMessage[] $messages
     */
    private function renderValidationMessages(array $messages, string $type): void
    {
        foreach ($messages as $message) {
            $text = sprintf('[%s] %s%s', $message->code, $message->message, $message->path ? sprintf(' (%s)', $message->path) : '');

            if ($type === 'error') {
                $this->error('    ' . $text);
            } else {
                $this->warn('    ' . $text);
            }
        }
    }

    private function renderPipelineResult(PipelineResult $result, array &$summary): bool
    {
        $hasErrors = false;
        $rows = [];

        foreach ($result->files() as $file) {
            $status = (string) ($file['status'] ?? 'unknown');
            $layer = (string) ($file['layer'] ?? '');
            $path = (string) ($file['path'] ?? '-');
            $details = $this->formatFileDetails($file);

            $rows[] = [
                Str::upper($layer),
                Str::upper($status),
                $path,
                $details,
            ];

            if ($status === 'error') {
                $summary['errors']++;
                $hasErrors = true;
            }

            $key = match ($status) {
                'written' => 'written',
                'overwritten' => 'overwritten',
                'skipped' => 'skipped',
                'preview' => 'preview',
                default => null,
            };

            if ($key !== null) {
                $summary[$key]++;
            }
        }

        if ($rows !== []) {
            $this->table(['Capa', 'Estado', 'Ruta', 'Detalles'], $rows);
        } else {
            $this->line('  (Sin archivos generados)');
        }

        foreach ($result->warnings() as $warning) {
            $this->warn(sprintf('  [warning] %s', $warning));
            $summary['warnings']++;
        }

        return $hasErrors;
    }

    private function formatFileDetails(array $file): string
    {
        if (isset($file['message']) && is_string($file['message']) && $file['message'] !== '') {
            return $file['message'];
        }

        $status = $file['status'] ?? null;

        if ($status === 'preview' && isset($file['preview']) && is_string($file['preview'])) {
            $length = strlen($file['preview']);

            return sprintf('preview (%d bytes)', $length);
        }

        if (isset($file['full_path']) && is_string($file['full_path']) && $file['full_path'] !== '') {
            return $file['full_path'];
        }

        return '';
    }

    private function overrideArchitecture(Blueprint $blueprint, string $architecture): Blueprint
    {
        $data = $blueprint->toArray();
        $data['path'] = $blueprint->path();
        $data['architecture'] = $architecture;

        return Blueprint::fromArray($data);
    }
}
