<?php

namespace BlueprintX\Generators;

use BlueprintX\Blueprint\Blueprint;
use BlueprintX\Contracts\ArchitectureDriver;
use BlueprintX\Contracts\LayerGenerator;
use BlueprintX\Docs\OpenApiDocumentBuilder;
use BlueprintX\Docs\OpenApi31;
use BlueprintX\Kernel\Generation\GeneratedFile;
use BlueprintX\Kernel\Generation\GenerationResult;
use BlueprintX\Support\Concerns\InteractsWithModules;
use Illuminate\Support\Str;
use JsonException;
use JsonSchema\Constraints\Constraint;
use JsonSchema\Validator;
use Symfony\Component\Yaml\Yaml;
use Throwable;
use cebe\openapi\Reader;

class DocsLayerGenerator implements LayerGenerator
{
    use InteractsWithModules;

    private ?string $schemaPath;
    private string $validationMode;

    public function __construct(
        private readonly OpenApiDocumentBuilder $builder,
        private readonly bool $enabledByDefault = true,
        private readonly bool $validateByDefault = true,
        ?string $schemaPath = null,
        string $validationMode = 'official',
    ) {
        $validationMode = strtolower($validationMode);

        if (! in_array($validationMode, ['official', 'schema'], true)) {
            $validationMode = 'official';
        }

        $this->validationMode = $validationMode;

        if ($validationMode === 'schema') {
            $this->schemaPath = $schemaPath ?? dirname(__DIR__, 2) . '/resources/schema/openapi-minimal.schema.json';
        } else {
            $this->schemaPath = $schemaPath;
        }
    }

    public function layer(): string
    {
        return 'docs';
    }

    /**
     * @param array<string, mixed> $options
     */
    public function generate(Blueprint $blueprint, ArchitectureDriver $driver, array $options = []): GenerationResult
    {
        $result = new GenerationResult();

        $withOpenApi = $options['with_openapi'] ?? $this->enabledByDefault;

        if (is_string($withOpenApi)) {
            $parsed = filter_var($withOpenApi, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            $withOpenApi = $parsed ?? $withOpenApi;
        }

        if (! (bool) $withOpenApi) {
            return $result;
        }

        $document = $this->builder->build($blueprint);
        $contents = Yaml::dump($document, 8, 2, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE | Yaml::DUMP_OBJECT_AS_MAP);
        $path = $this->buildPath($blueprint, $options);

        $result->addFile(new GeneratedFile($path, $contents));

        $validateOpenApi = $options['validate_openapi'] ?? $this->validateByDefault;

        if (is_string($validateOpenApi)) {
            $parsed = filter_var($validateOpenApi, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            $validateOpenApi = $parsed ?? $validateOpenApi;
        }

        if ((bool) $validateOpenApi) {
            foreach ($this->validateDocument($document) as $warning) {
                $result->addWarning($warning);
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function buildPath(Blueprint $blueprint, array $options): string
    {
        $basePath = $options['paths']['docs'] ?? 'docs';
        $module = $this->modulePath($blueprint);

        if ($module !== null) {
            $basePath .= '/' . $module;
        }

        $entityName = Str::studly($blueprint->entity());

        return sprintf('%s/%s.openapi.yaml', trim($basePath, '/'), $entityName);
    }

    /**
     * @return string[]
     */
    private function validateDocument(array $document): array
    {
        return match ($this->validationMode) {
            'official' => $this->validateWithOfficialSpec($document),
            'schema' => $this->validateWithJsonSchema($document),
            default => [],
        };
    }

    private function validateWithOfficialSpec(array $document): array
    {
        try {
            $json = json_encode($document, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return [sprintf('Error al serializar el documento OpenAPI: %s', $exception->getMessage())];
        }

        try {
            $openapi = Reader::readFromJson($json, OpenApi31::class);
        } catch (Throwable $exception) {
            return [sprintf('Error al cargar el documento OpenAPI: %s', $exception->getMessage())];
        }

        if ($openapi->validate()) {
            return [];
        }

        return array_values($openapi->getErrors());
    }

    private function validateWithJsonSchema(array $document): array
    {
        if ($this->schemaPath === null) {
            return [];
        }

        if (! is_file($this->schemaPath)) {
            return [sprintf('No se encontró el schema de OpenAPI en "%s".', $this->schemaPath)];
        }

        $schemaContents = file_get_contents($this->schemaPath);

        if ($schemaContents === false) {
            return [sprintf('No se pudo leer el schema de OpenAPI en "%s".', $this->schemaPath)];
        }

        try {
            $documentObject = json_decode(json_encode($document, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
            $schemaObject = json_decode($schemaContents, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return [sprintf('Error al preparar el documento OpenAPI para validación: %s', $exception->getMessage())];
        }

        $validator = new Validator();
        $validator->validate($documentObject, $schemaObject, Constraint::CHECK_MODE_APPLY_DEFAULTS);

        if ($validator->isValid()) {
            return [];
        }

        return array_map(
            static fn (array $error): string => sprintf('%s: %s', $error['property'], $error['message']),
            $validator->getErrors()
        );
    }
}
