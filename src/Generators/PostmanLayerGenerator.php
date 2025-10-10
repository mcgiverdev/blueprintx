<?php

namespace BlueprintX\Generators;

use BlueprintX\Blueprint\Blueprint;
use BlueprintX\Blueprint\Field;
use BlueprintX\Contracts\ArchitectureDriver;
use BlueprintX\Contracts\LayerGenerator;
use BlueprintX\Docs\OpenApiDocumentBuilder;
use BlueprintX\Kernel\Generation\GeneratedFile;
use BlueprintX\Kernel\Generation\GenerationResult;
use Illuminate\Support\Str;
use JsonException;

class PostmanLayerGenerator implements LayerGenerator
{
    public function __construct(
        private readonly OpenApiDocumentBuilder $builder,
        private readonly bool $enabledByDefault = false,
        private readonly string $defaultBaseUrl = 'http://localhost/api'
    ) {
    }

    public function layer(): string
    {
        return 'postman';
    }

    /**
     * @param array<string, mixed> $options
     */
    public function generate(Blueprint $blueprint, ArchitectureDriver $driver, array $options = []): GenerationResult
    {
        $result = new GenerationResult();

        $withPostman = $options['with_postman'] ?? $this->enabledByDefault;

        if (is_string($withPostman)) {
            $parsed = filter_var($withPostman, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            $withPostman = $parsed ?? $withPostman;
        }

        if (! (bool) $withPostman) {
            return $result;
        }

        $document = $this->builder->build($blueprint);

        $baseUrl = $options['postman']['base_url'] ?? $this->defaultBaseUrl;
        if (! is_string($baseUrl) || trim($baseUrl) === '') {
            $baseUrl = $this->defaultBaseUrl;
        }

        $collection = $this->buildCollection($blueprint, $document, $baseUrl);

        try {
            $contents = json_encode($collection, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $result->addWarning(sprintf('No se pudo serializar la colección de Postman: %s', $exception->getMessage()));

            return $result;
        }

        $path = $this->buildPath($blueprint, $options);
        $result->addFile(new GeneratedFile($path, $contents . PHP_EOL));

        return $result;
    }

    /**
     * @param array<string, mixed> $document
     */
    private function buildCollection(Blueprint $blueprint, array $document, string $baseUrl): array
    {
        $entityName = Str::studly($blueprint->entity());
        $description = $document['info']['description'] ?? null;

        $items = $this->buildItems($document, $entityName, $blueprint);

        return [
            'info' => array_filter([
                'name' => $entityName . ' API',
                '_postman_id' => (string) Str::uuid(),
                'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
                'description' => is_string($description) && $description !== '' ? $description : null,
            ]),
            'item' => $items,
            'variable' => [
                [
                    'key' => 'base_url',
                    'value' => $baseUrl,
                    'type' => 'string',
                    'description' => 'URL base usada para las peticiones generadas.',
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $document
     * @return array<int, array<string, mixed>>
     */
    private function buildItems(array $document, string $entityName, Blueprint $blueprint): array
    {
        $paths = $document['paths'] ?? [];
        if ($paths instanceof \stdClass) {
            $paths = (array) $paths;
        }

        $groups = [];

        foreach ($paths as $path => $operations) {
            if (! is_string($path) || ! is_array($operations)) {
                continue;
            }

            $pathParameters = $this->normalizeParameters($operations['parameters'] ?? [], $document);

            foreach ($operations as $method => $operation) {
                $httpMethod = strtolower((string) $method);

                if (in_array($httpMethod, ['parameters', 'summary', 'description'], true)) {
                    continue;
                }

                if (! in_array($httpMethod, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                    continue;
                }

                if (! is_array($operation)) {
                    continue;
                }

                $operationParameters = $this->normalizeParameters($operation['parameters'] ?? [], $document);
                $parameters = array_merge($pathParameters, $operationParameters);

                $groupName = $this->resolveGroupName($operation, $path, $entityName);

                if (! isset($groups[$groupName])) {
                    $groups[$groupName] = [
                        'name' => $groupName,
                        'item' => [],
                    ];
                }

                $groups[$groupName]['item'][] = $this->buildItem($httpMethod, $path, $operation, $parameters, $blueprint);
            }
        }

        return array_values($groups);
    }

    /**
     * @param array<string, mixed> $operation
     */
    private function resolveGroupName(array $operation, string $path, string $entityName): string
    {
        $tags = $operation['tags'] ?? [];

        if (is_array($tags) && $tags !== []) {
            foreach ($tags as $tag) {
                if (is_string($tag) && $tag !== '') {
                    return $tag;
                }
            }
        }

        $trimmed = trim($path, '/');
        if ($trimmed === '') {
            return $entityName;
        }

        $firstSegment = explode('/', $trimmed, 2)[0];
        $firstSegment = trim($firstSegment);

        return $firstSegment === '' ? $entityName : Str::title(str_replace(['-', '_'], ' ', $firstSegment));
    }

    /**
     * @param array<int, array<string, mixed>> $parameters
     */
    private function buildItem(string $method, string $path, array $operation, array $parameters, Blueprint $blueprint): array
    {
        $name = $operation['summary'] ?? (strtoupper($method) . ' ' . $path);
        if (! is_string($name) || $name === '') {
            $name = strtoupper($method) . ' ' . $path;
        }

        $description = $operation['description'] ?? null;
        $pathDetails = $this->extractPathSegments($path, $parameters);

        $request = [
            'method' => strtoupper($method),
            'header' => $this->buildHeaders($method, $parameters),
            'url' => array_filter([
                'raw' => '{{base_url}}' . $path,
                'host' => ['{{base_url}}'],
                'path' => $pathDetails['segments'],
                'query' => $this->buildQueryParams($parameters),
                'variable' => $this->buildPathVariables($pathDetails['variables']),
            ], static fn ($value) => $value !== [] && $value !== null),
        ];

        if ($description !== null && is_string($description) && $description !== '') {
            $request['description'] = $description;
        }

        $body = $this->buildBody($method, $operation, $blueprint);
        if ($body !== null) {
            $request['body'] = $body;
        }

        return array_filter([
            'name' => $name,
            'request' => $request,
            'response' => [],
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $parameters
     * @return array{segments: array<int, string>, variables: array<string, array<string, mixed>>}
     */
    private function extractPathSegments(string $path, array $parameters): array
    {
        $segments = [];
        $variables = [];

        foreach (explode('/', trim($path, '/')) as $segment) {
            if ($segment === '') {
                continue;
            }

            if (preg_match('/^\{(.+)}$/', $segment, $matches) === 1) {
                $name = $matches[1];
                $segments[] = ':' . $name;

                foreach ($parameters as $parameter) {
                    if (($parameter['in'] ?? null) === 'path' && ($parameter['name'] ?? null) === $name) {
                        $variables[$name] = $parameter;
                        break;
                    }
                }

                continue;
            }

            $segments[] = $segment;
        }

        return [
            'segments' => $segments,
            'variables' => $variables,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $variables
     * @return array<int, array<string, mixed>>
     */
    private function buildPathVariables(array $variables): array
    {
        $result = [];

        foreach ($variables as $name => $parameter) {
            $result[] = array_filter([
                'key' => $name,
                'value' => $this->exampleFromParameter($parameter),
                'description' => $parameter['description'] ?? null,
            ]);
        }

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $parameters
     * @return array<int, array<string, mixed>>
     */
    private function buildHeaders(string $method, array $parameters): array
    {
        $headers = [];
        $hasAccept = false;
        $hasContentType = false;

        foreach ($parameters as $parameter) {
            if (($parameter['in'] ?? null) !== 'header') {
                continue;
            }

            $name = $parameter['name'] ?? null;
            if (! is_string($name) || $name === '') {
                continue;
            }

            $lower = strtolower($name);
            $hasAccept = $hasAccept || $lower === 'accept';
            $hasContentType = $hasContentType || $lower === 'content-type';

            $headers[] = array_filter([
                'key' => $name,
                'value' => $this->exampleFromParameter($parameter),
                'description' => $parameter['description'] ?? null,
                'disabled' => ! ($parameter['required'] ?? false),
            ], static fn ($value) => $value !== null && $value !== '');
        }

        if (! $hasAccept) {
            $headers[] = [
                'key' => 'Accept',
                'value' => 'application/json',
            ];
        }

        if (in_array(strtolower($method), ['post', 'put', 'patch'], true) && ! $hasContentType) {
            $headers[] = [
                'key' => 'Content-Type',
                'value' => 'application/json',
            ];
        }

        return $headers;
    }

    /**
     * @param array<int, array<string, mixed>> $parameters
     * @return array<int, array<string, mixed>>
     */
    private function buildQueryParams(array $parameters): array
    {
        $query = [];

        foreach ($parameters as $parameter) {
            if (($parameter['in'] ?? null) !== 'query') {
                continue;
            }

            $name = $parameter['name'] ?? null;
            if (! is_string($name) || $name === '') {
                continue;
            }

            $entry = [
                'key' => $name,
                'value' => $this->exampleFromParameter($parameter),
                'description' => $parameter['description'] ?? null,
            ];

            if (! ($parameter['required'] ?? false)) {
                $entry['disabled'] = true;
            }

            $query[] = array_filter($entry, static fn ($value) => $value !== null && $value !== '');
        }

        return $query;
    }

    /**
     * @param array<string, mixed> $operation
     */
    private function buildBody(string $method, array $operation, Blueprint $blueprint): ?array
    {
        if (! in_array(strtolower($method), ['post', 'put', 'patch'], true)) {
            return null;
        }

        $requestBody = $operation['requestBody'] ?? null;
        if (! is_array($requestBody)) {
            return null;
        }

        $content = $requestBody['content'] ?? [];
        if (! is_array($content) || $content === []) {
            return null;
        }

        $jsonPayload = $this->buildExamplePayload($blueprint);

        try {
            $raw = json_encode($jsonPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $raw = json_encode($jsonPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        if (! is_string($raw)) {
            return null;
        }

        return [
            'mode' => 'raw',
            'raw' => $raw . "\n",
            'options' => [
                'raw' => [
                    'language' => 'json',
                ],
            ],
        ];
    }

    private function buildExamplePayload(Blueprint $blueprint): array
    {
        $data = [];

        foreach ($blueprint->fields() as $field) {
            if ($this->isReadOnlyField($field->name)) {
                continue;
            }

            $data[$field->name] = $this->sampleFieldValue($field);
        }

        if ($data === []) {
            return ['data' => new \stdClass()];
        }

        return ['data' => $data];
    }

    private function isReadOnlyField(string $name): bool
    {
        return in_array($name, ['id', 'created_at', 'updated_at', 'deleted_at'], true);
    }

    private function sampleFieldValue(Field $field): mixed
    {
        $type = strtolower($field->type);

        return match (true) {
            str_contains($type, 'uuid') => (string) Str::uuid(),
            str_contains($type, 'bool') => true,
            str_contains($type, 'int') || str_contains($type, 'unsigned') => 1,
            str_contains($type, 'decimal') || str_contains($type, 'float') || str_contains($type, 'double') => $this->formatDecimalSample($field),
            str_contains($type, 'date') && ! str_contains($type, 'time') => date('Y-m-d'),
            str_contains($type, 'time') => date('c'),
            str_contains($type, 'json') => ['sample' => 'value'],
            default => 'Sample ' . Str::title(str_replace(['_', '-'], ' ', $field->name)),
        };
    }

    private function formatDecimalSample(Field $field): string
    {
        $scale = $field->scale ?? 2;
        $value = 1.0;

        if (is_int($field->default) || is_float($field->default)) {
            $value = (float) $field->default;
        }

        return number_format($value, max(0, (int) $scale), '.', '');
    }

    /**
     * @param array<string, mixed> $parameter
     */
    private function exampleFromParameter(array $parameter): string
    {
        $schema = $parameter['schema'] ?? null;

        $candidates = [
            $parameter['example'] ?? null,
            $schema['example'] ?? null,
            $schema['default'] ?? null,
        ];

        if (isset($schema['enum']) && is_array($schema['enum']) && $schema['enum'] !== []) {
            $candidates[] = $schema['enum'][0];
        }

        foreach ($candidates as $candidate) {
            if (is_scalar($candidate)) {
                return (string) $candidate;
            }
        }

        $name = $parameter['name'] ?? 'value';
        if (! is_string($name) || $name === '') {
            $name = 'value';
        }

        $type = is_array($schema) ? ($schema['type'] ?? 'string') : 'string';
        $type = is_string($type) ? strtolower($type) : 'string';

        return match ($type) {
            'integer', 'number' => '1',
            'boolean' => 'true',
            default => match (strtolower($name)) {
                'if-match' => 'W/"etag-value"',
                default => '{{' . Str::slug($name, '_') . '}}',
            },
        };
    }

    /**
     * @param array<int, array|string> $parameters
     * @return array<int, array<string, mixed>>
     */
    private function normalizeParameters(array $parameters, array $document): array
    {
        $normalized = [];

        foreach ($parameters as $parameter) {
            if (is_array($parameter)) {
                if (isset($parameter['$ref']) && is_string($parameter['$ref'])) {
                    $resolved = $this->resolveReference($document, $parameter['$ref']);
                    if ($resolved !== null) {
                        $parameter = $resolved;
                    }
                }

                $name = $parameter['name'] ?? null;
                $in = $parameter['in'] ?? null;

                if (! is_string($name) || $name === '' || ! is_string($in) || $in === '') {
                    continue;
                }

                $parameter['in'] = strtolower($in);
                $normalized[] = $parameter;
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $document
     */
    private function resolveReference(array $document, string $reference): ?array
    {
        if (! str_starts_with($reference, '#/')) {
            return null;
        }

        $segments = explode('/', ltrim($reference, '#/'));
        $current = $document;

        foreach ($segments as $segment) {
            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return is_array($current) ? $current : null;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function buildPath(Blueprint $blueprint, array $options): string
    {
        $basePath = $options['paths']['postman'] ?? 'docs/postman';
        $module = $blueprint->module();

        if ($module !== null && $module !== '') {
            $basePath .= '/' . Str::studly($module);
        }

        $entityName = Str::studly($blueprint->entity());

        return sprintf('%s/%s.postman.json', trim($basePath, '/'), $entityName);
    }
}
