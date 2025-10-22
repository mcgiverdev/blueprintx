<?php

namespace BlueprintX\Parsers;

use BlueprintX\Blueprint\Blueprint;
use BlueprintX\Contracts\BlueprintParser;
use BlueprintX\Exceptions\BlueprintParseException;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class YamlBlueprintParser implements BlueprintParser
{
    /**
     * @var array<string, bool>
     */
    private array $defaultOptions = [
        'timestamps' => true,
        'softDeletes' => false,
        'audited' => false,
        'versioned' => false,
    ];

    public function __construct(
        private readonly string $blueprintsPath,
        private readonly string $defaultArchitecture = 'hexagonal',
    ) {
    }

    public function parse(string $path): Blueprint
    {
        $fullPath = $this->resolvePath($path);

        try {
            $data = Yaml::parseFile($fullPath, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
        } catch (ParseException $exception) {
            throw new BlueprintParseException(
                sprintf('No se pudo parsear el blueprint "%s": %s', $path, $exception->getMessage()),
                0,
                $exception,
            );
        }

        if (! is_array($data)) {
            throw new BlueprintParseException(sprintf('El blueprint "%s" no contiene una estructura válida.', $path));
        }

        $normalized = $this->normalize($data, $fullPath);

        return Blueprint::fromArray($normalized);
    }

    public function parseMany(iterable $paths): array
    {
        $blueprints = [];

        foreach ($paths as $path) {
            $blueprints[] = $this->parse($path);
        }

        return $blueprints;
    }

    private function resolvePath(string $path): string
    {
        $candidate = $path;
        if (! $this->isAbsolutePath($candidate)) {
            $candidate = rtrim($this->blueprintsPath, '\\/') . DIRECTORY_SEPARATOR . ltrim($candidate, '\\/');
        }

        if (! is_file($candidate)) {
            throw new BlueprintParseException(sprintf('No se encontró el blueprint en la ruta "%s".', $path));
        }

        return realpath($candidate) ?: $candidate;
    }

    /**
     * @param array<string, mixed> $data
     * @return array{
     *     path:string,
     *     module:?string,
     *     entity:string,
     *     table:string,
     *     architecture:string,
     *     fields:array<int,array>,
     *     relations:array<int,array>,
     *     options:array<string,mixed>,
    *     api:array{base_path:?string,middleware:array<int,string>,endpoints:array<int,array>,resources:array<string,mixed>},
    *     docs:array<string,mixed>,
    *     errors:array<int,array<string,mixed>>,
    *     metadata:array<string,mixed>,
    *     tenancy:array<string,mixed>
     * }
     */
    private function normalize(array $data, string $fullPath): array
    {
    $module = $this->detectModule($fullPath);

        $entity = Arr::get($data, 'entity');
        if (! is_string($entity) || $entity === '') {
            throw new BlueprintParseException(sprintf('El blueprint "%s" no define una entidad válida.', $fullPath));
        }

        $table = Arr::get($data, 'table');
        if (! is_string($table) || $table === '') {
            $table = Str::snake(Str::pluralStudly($entity));
        }

        $architecture = Arr::get($data, 'architecture', $this->defaultArchitecture);
        if (! is_string($architecture) || $architecture === '') {
            $architecture = $this->defaultArchitecture;
        }

        $fields = $this->normalizeArrayOfArrays($data, 'fields');
        if ($fields === []) {
            throw new BlueprintParseException(sprintf('El blueprint "%s" debe declarar al menos un campo.', $fullPath));
        }

        $relations = $this->normalizeArrayOfArrays($data, 'relations');
        $options = array_merge($this->defaultOptions, Arr::get($data, 'options', []));

        $api = Arr::get($data, 'api', []);
        $apiBasePath = $this->normalizeApiBasePath($api);
        $apiMiddleware = isset($api['middleware']) && is_array($api['middleware'])
            ? array_values(array_filter($api['middleware'], static fn ($middleware): bool => is_string($middleware)))
            : [];
        $endpoints = isset($api['endpoints']) && is_array($api['endpoints'])
            ? array_map(static fn ($endpoint): array => (array) $endpoint, $api['endpoints'])
            : [];
        $apiResources = $this->normalizeApiResources($api['resources'] ?? []);

        $docs = Arr::get($data, 'docs', []);
        if (! is_array($docs)) {
            $docs = [];
        }

        $errors = $this->normalizeErrors(Arr::get($data, 'errors', []), $entity);

        $metadata = Arr::get($data, 'metadata', []);
        if (! is_array($metadata)) {
            $metadata = [];
        }

        $tenancy = $this->normalizeTenancy(Arr::get($data, 'tenancy', null));

        return [
            'path' => $fullPath,
            'module' => $module,
            'entity' => $entity,
            'table' => $table,
            'architecture' => $architecture,
            'fields' => $fields,
            'relations' => $relations,
            'options' => $options,
            'api' => [
                'base_path' => $apiBasePath,
                'middleware' => $apiMiddleware,
                'endpoints' => $endpoints,
                'resources' => $apiResources,
            ],
            'docs' => $docs,
            'errors' => $errors,
            'metadata' => $metadata,
            'tenancy' => $tenancy,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, array>
     */
    private function normalizeArrayOfArrays(array $data, string $key): array
    {
        $value = Arr::get($data, $key, []);
        if ($value === null) {
            return [];
        }

        if (! is_array($value)) {
            throw new BlueprintParseException(sprintf('La clave "%s" debe ser un arreglo.', $key));
        }

        return array_map(static fn ($item): array => (array) $item, array_values($value));
    }

    private function normalizeApiBasePath(mixed $api): ?string
    {
        if (! is_array($api)) {
            return null;
        }

        $candidates = [];

        if (array_key_exists('base_path', $api)) {
            $candidates[] = $api['base_path'];
        }

        if (array_key_exists('basePath', $api)) {
            $candidates[] = $api['basePath'];
        }

        foreach ($candidates as $value) {
            if (! is_string($value)) {
                continue;
            }

            $trimmed = trim($value);

            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return null;
    }

    /**
     * @param mixed $config
     * @return array{includes: array<int, array<string, string>>}
     */
    private function normalizeApiResources(mixed $config): array
    {
        if ($config === null) {
            return ['includes' => []];
        }

        if (! is_array($config)) {
            throw new BlueprintParseException('La clave "api.resources" debe ser un arreglo.');
        }

        $includesValue = $config['includes'] ?? $config['include'] ?? null;

        if ($includesValue === null) {
            return ['includes' => []];
        }

        if (is_string($includesValue)) {
            $includesValue = [$includesValue];
        }

        if (! is_array($includesValue)) {
            throw new BlueprintParseException('La clave "api.resources.includes" debe ser un arreglo o una cadena.');
        }

        $includes = [];

        foreach ($includesValue as $key => $definition) {
            $normalized = $this->normalizeApiResourceInclude($definition, is_string($key) ? $key : null);

            if ($normalized !== null) {
                $includes[] = $normalized;
            }
        }

        return ['includes' => $includes];
    }

    /**
     * @param mixed $definition
     */
    private function normalizeApiResourceInclude(mixed $definition, ?string $key = null): ?array
    {
        if (is_string($definition)) {
            $relation = trim($definition);

            if ($relation === '') {
                return null;
            }

            return [
                'relation' => $relation,
                'alias' => $relation,
            ];
        }

        if (! is_array($definition)) {
            throw new BlueprintParseException('Cada elemento de "api.resources.includes" debe ser una cadena o un arreglo.');
        }

        $relation = $definition['relation'] ?? $definition['name'] ?? $key;

        if (! is_string($relation) || ($relation = trim($relation)) === '') {
            throw new BlueprintParseException('Cada include en "api.resources.includes" debe especificar la relación.');
        }

        $alias = $definition['alias'] ?? $definition['as'] ?? $relation;
        if (! is_string($alias) || ($alias = trim($alias)) === '') {
            $alias = $relation;
        }

        $resource = null;

        if (array_key_exists('resource', $definition)) {
            $value = $definition['resource'];

            if (! is_string($value) || ($value = trim($value, '\\')) === '') {
                throw new BlueprintParseException('El valor "resource" en "api.resources.includes" debe ser una cadena de clase válida.');
            }

            $resource = $value;
        }

        $result = [
            'relation' => $relation,
            'alias' => $alias,
        ];

        if ($resource !== null) {
            $result['resource'] = $resource;
        }

        return $result;
    }

    /**
     * @param mixed $value
     * @return array<int, array<string, mixed>>
     */
    private function normalizeErrors(mixed $value, string $entity): array
    {
        if ($value === null) {
            return [];
        }

        if (! is_array($value)) {
            throw new BlueprintParseException('La clave "errors" debe ser un arreglo asociativo.');
        }

        $errors = [];

        foreach ($value as $key => $definition) {
            if (! is_string($key) || $key === '') {
                throw new BlueprintParseException('Cada error debe tener una clave válida.');
            }

            if (! is_array($definition)) {
                if (is_string($definition)) {
                    $definition = ['message' => $definition];
                } else {
                    throw new BlueprintParseException(sprintf('La definición del error "%s" debe ser un arreglo.', $key));
                }
            }

            $name = Str::snake($key);
            $classBase = Str::studly($key);
            $exceptionClass = str_ends_with($classBase, 'Exception') ? $classBase : $classBase . 'Exception';

            $code = $definition['code'] ?? null;
            if (! is_string($code) || $code === '') {
                $code = sprintf('domain.%s.%s', Str::snake($entity), $name);
            }

            $message = $definition['message'] ?? null;
            if (! is_string($message) || $message === '') {
                $message = Str::headline($key) . '.';
            }

            $status = $definition['status'] ?? null;
            if (! is_numeric($status)) {
                $status = 400;
            }

            $status = (int) $status;
            if ($status < 100 || $status > 599) {
                $status = 400;
            }

            $extends = $definition['extends'] ?? null;
            $extends = is_string($extends) ? strtolower($extends) : null;

            $extendsMap = [
                'not_found' => 'DomainNotFoundException',
                'conflict' => 'DomainConflictException',
                'validation' => 'DomainValidationException',
                'validation_exception' => 'DomainValidationException',
                'conflict_exception' => 'DomainConflictException',
                'not_found_exception' => 'DomainNotFoundException',
                'base' => 'DomainException',
                'domain' => 'DomainException',
            ];

            $extendsClass = $extendsMap[$extends] ?? 'DomainException';

            $description = $definition['description'] ?? null;
            if ($description !== null && ! is_string($description)) {
                $description = null;
            }

            $errors[] = [
                'name' => $name,
                'key' => $key,
                'class' => $classBase,
                'exception_class' => $exceptionClass,
                'code' => $code,
                'message' => $message,
                'status' => $status,
                'extends' => $extendsClass,
                'description' => $description,
            ];
        }

        return $errors;
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private function normalizeTenancy(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (! is_array($value)) {
            throw new BlueprintParseException('La clave "tenancy" debe ser un arreglo.');
        }

        $result = [];

        foreach (['mode', 'storage', 'routing_scope', 'seed_scope'] as $key) {
            if (! array_key_exists($key, $value)) {
                continue;
            }

            $raw = $value[$key];

            if ($raw === null) {
                continue;
            }

            if (! is_string($raw)) {
                throw new BlueprintParseException(sprintf('La clave "tenancy.%s" debe ser una cadena.', $key));
            }

            $normalized = strtolower(trim($raw));

            if ($normalized === '') {
                continue;
            }

            $result[$key] = $normalized;
        }

        if (array_key_exists('connection', $value)) {
            $connection = $value['connection'];

            if ($connection === null) {
                // nothing to add when null
            } elseif (! is_string($connection)) {
                throw new BlueprintParseException('La clave "tenancy.connection" debe ser una cadena.');
            } else {
                $normalizedConnection = trim($connection);

                if ($normalizedConnection !== '') {
                    $result['connection'] = $normalizedConnection;
                }
            }
        }

        return $result;
    }

    private function detectModule(string $fullPath): ?string
    {
        $normalizedBase = rtrim(str_replace('\\', '/', realpath($this->blueprintsPath) ?: $this->blueprintsPath), '/');
        $normalizedPath = str_replace('\\', '/', $fullPath);

        if (! str_starts_with($normalizedPath, $normalizedBase)) {
            return null;
        }

        $relative = ltrim(substr($normalizedPath, strlen($normalizedBase)), '/');
        $segments = explode('/', $relative);
        array_pop($segments); // remove filename
        $module = implode('/', array_filter($segments));

        return $this->sanitizeModule($module !== '' ? $module : null, $fullPath);
    }

    private function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            return true;
        }

        return (bool) preg_match('~^[A-Za-z]:[\\\/]~', $path);
    }

    private function sanitizeModule(?string $module, string $context): ?string
    {
        if ($module === null) {
            return null;
        }

        $trimmed = trim(str_replace('\\', '/', $module), '/');

        if ($trimmed === '') {
            return null;
        }

        $segments = array_values(array_filter(explode('/', $trimmed), static fn ($segment): bool => $segment !== ''));

        if ($segments === []) {
            return null;
        }

        $normalized = [];

        foreach ($segments as $segment) {
            $candidate = strtolower(trim($segment));
            $candidate = preg_replace('/[^a-z0-9_]/', '_', $candidate ?? '');
            $candidate = preg_replace('/_{2,}/', '_', $candidate ?? '');
            $candidate = trim((string) $candidate, '_');

            if ($candidate === '') {
                throw new BlueprintParseException(sprintf('El módulo detectado para "%s" contiene segmentos inválidos.', $context));
            }

            $normalized[] = $candidate;
        }

        return implode('/', $normalized);
    }
}
