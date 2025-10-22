<?php

namespace BlueprintX\Generators;

use BlueprintX\Blueprint\Blueprint;
use BlueprintX\Blueprint\Endpoint;
use BlueprintX\Blueprint\Field;
use BlueprintX\Blueprint\Relation;
use BlueprintX\Contracts\ArchitectureDriver;
use BlueprintX\Contracts\LayerGenerator;
use BlueprintX\Kernel\Generation\GeneratedFile;
use BlueprintX\Kernel\Generation\GenerationResult;
use BlueprintX\Kernel\TemplateEngine;
use Illuminate\Support\Str;

class InfrastructureLayerGenerator implements LayerGenerator
{
    public function __construct(private readonly TemplateEngine $templates)
    {
    }

    public function layer(): string
    {
        return 'infrastructure';
    }

    /**
     * @param array<string, mixed> $options
     */
    public function generate(Blueprint $blueprint, ArchitectureDriver $driver, array $options = []): GenerationResult
    {
        $result = new GenerationResult();

        $context = $this->buildContext($blueprint, $driver, $options);
        $paths = $this->derivePaths($blueprint, $options);

        $template = sprintf('@%s/infrastructure/repository.stub.twig', $driver->name());

        if (! $this->templates->exists($template)) {
            $result->addWarning(sprintf('No se encontró la plantilla "%s" para la capa infrastructure en "%s".', $template, $driver->name()));

            return $result;
        }

        $result->addFile(new GeneratedFile(
            $paths['repository'],
            $this->templates->render($template, $context)
        ));

        if ($serviceProviderFile = $this->makeAppServiceProviderFile($context, $options)) {
            $result->addFile($serviceProviderFile);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function buildContext(Blueprint $blueprint, ArchitectureDriver $driver, array $options): array
    {
        $entity = [
            'name' => $blueprint->entity(),
            'module' => $blueprint->module(),
            'table' => $blueprint->table(),
            'fields' => array_map(static fn (Field $field): array => $field->toArray(), $blueprint->fields()),
            'relations' => array_map(static fn (Relation $relation): array => $relation->toArray(), $blueprint->relations()),
            'endpoints' => array_map(static fn (Endpoint $endpoint): array => $endpoint->toArray(), $blueprint->endpoints()),
            'options' => $blueprint->options(),
        ];

        $namespaces = $this->deriveNamespaces($blueprint, $options);
        $domainNamespaces = $this->deriveDomainNamespaces($blueprint, $options);
        $applicationNamespaces = $this->deriveApplicationNamespaces($blueprint, $options);

        return [
            'blueprint' => $blueprint->toArray(),
            'entity' => $entity,
            'module' => $this->moduleSegment($blueprint),
            'namespaces' => $namespaces,
            'domain' => $domainNamespaces,
            'application' => $applicationNamespaces,
            'naming' => $this->namingContext($blueprint),
            'model' => $this->deriveModelContext($blueprint, $domainNamespaces),
            'driver' => [
                'name' => $driver->name(),
                'layers' => $driver->layers(),
                'metadata' => $driver->metadata(),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, string>
     */
    private function deriveNamespaces(Blueprint $blueprint, array $options): array
    {
        $base = trim($options['namespaces']['infrastructure'] ?? 'App\\Infrastructure\\Persistence\\Eloquent', '\\');
        $module = $this->moduleNamespaceSegment($blueprint);
        $root = $base;

        if ($module !== null) {
            $root .= '\\' . $module;
        }

        return [
            'infrastructure_root' => $root,
            'repositories' => $root . '\\Repositories',
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, string>
     */
    private function derivePaths(Blueprint $blueprint, array $options): array
    {
        $basePath = rtrim($options['paths']['infrastructure'] ?? 'app/Infrastructure/Persistence/Eloquent', '/');
        $module = $this->modulePathSegment($blueprint);
        $entityName = Str::studly($blueprint->entity());

        $root = $basePath;

        if ($module !== null) {
            $root .= '/' . $module;
        }

        return [
            'repository' => sprintf('%s/Repositories/Eloquent%sRepository.php', $root, $entityName),
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, string>
     */
    private function deriveDomainNamespaces(Blueprint $blueprint, array $options): array
    {
        $base = trim($options['namespaces']['domain'] ?? 'App\\Domain', '\\');
        $module = $this->moduleNamespaceSegment($blueprint);
        $root = $base;

        if ($module !== null) {
            $root .= '\\' . $module;
        }

        return [
            'models' => $root . '\\Models',
            'repositories' => $root . '\\Repositories',
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, string>
     */
    private function deriveApplicationNamespaces(Blueprint $blueprint, array $options): array
    {
        $base = trim($options['namespaces']['application'] ?? 'App\\Application', '\\');
        $module = $this->moduleNamespaceSegment($blueprint);
        $root = $base;

        if ($module !== null) {
            $root .= '\\' . $module;
        }

        return [
            'filters' => $root . '\\Queries\\Filters',
        ];
    }

    private function namingContext(Blueprint $blueprint): array
    {
        $entityName = Str::studly($blueprint->entity());

        return [
            'entity_studly' => $entityName,
            'entity_variable' => Str::camel($blueprint->entity()),
        ];
    }

    private function moduleSegment(Blueprint $blueprint): ?string
    {
        $segments = $this->normalizedModuleSegments($blueprint);

        if ($segments === []) {
            return null;
        }

        return implode('', $segments);
    }

    private function moduleNamespaceSegment(Blueprint $blueprint): ?string
    {
        $segments = $this->normalizedModuleSegments($blueprint);

        if ($segments === []) {
            return null;
        }

        return implode('\\', $segments);
    }

    private function modulePathSegment(Blueprint $blueprint): ?string
    {
        $segments = $this->normalizedModuleSegments($blueprint);

        if ($segments === []) {
            return null;
        }

        return implode('/', $segments);
    }

    /**
     * @return array<int, string>
     */
    private function normalizedModuleSegments(Blueprint $blueprint): array
    {
        $module = $blueprint->module();

        if (! is_string($module) || $module === '') {
            return [];
        }

        $normalized = str_replace('\\', '/', $module);
        $parts = array_filter(array_map('trim', explode('/', $normalized)), static fn (string $part): bool => $part !== '');

        if ($parts === []) {
            return [];
        }

        return array_map(static fn (string $part): string => Str::studly($part), $parts);
    }

    private function deriveModelContext(Blueprint $blueprint, array $domainNamespaces): array
    {
        $fillable = [];
        $casts = [];
        $identifierField = 'id';
        $identifierPhpType = 'int';

        foreach ($blueprint->fields() as $field) {
            $fillable[] = $field->name;

            $type = strtolower($field->type);
            $cast = null;

            if ($field->name === 'id') {
                $identifierField = $field->name;
                $identifierPhpType = match ($type) {
                    'uuid', 'guid', 'string', 'ulid' => 'string',
                    'bigint', 'biginteger', 'unsignedbiginteger' => 'int',
                    default => in_array($type, ['integer', 'increments', 'bigincrements', 'id'], true) ? 'int' : 'mixed',
                };
            }

            if ($type === 'boolean') {
                $cast = 'boolean';
            } elseif (in_array($type, ['integer', 'bigint', 'biginteger', 'unsignedinteger', 'unsignedbiginteger', 'tinyint', 'smallint'], true)) {
                $cast = 'integer';
            } elseif (in_array($type, ['decimal', 'float', 'double'], true)) {
                $scale = $field->scale ?? 2;
                $cast = 'decimal:' . $scale;
            } elseif (in_array($type, ['datetime', 'timestamp', 'timestamptz'], true)) {
                $cast = 'datetime';
            } elseif ($type === 'date') {
                $cast = 'date';
            } elseif (in_array($type, ['json', 'array', 'object'], true)) {
                $cast = 'array';
            } elseif (in_array($type, ['uuid', 'guid'], true)) {
                $cast = 'string';
            }

            if ($cast !== null) {
                $casts[$field->name] = $cast;
            }
        }

        $options = $blueprint->options();
        $softDeletes = (bool) ($options['softDeletes'] ?? false);
        $timestampsEnabled = array_key_exists('timestamps', $options) ? (bool) $options['timestamps'] : true;

        if ($timestampsEnabled) {
            $casts['created_at'] = 'datetime';
            $casts['updated_at'] = 'datetime';
        }

        if ($softDeletes) {
            $casts['deleted_at'] = 'datetime';
        }

        $relationReturnTypes = [];
        $relationImports = [];
        $relations = [];
        $selfClass = Str::studly($blueprint->entity());
        $domainModelsNamespace = $domainNamespaces['models'];

        foreach ($blueprint->relations() as $relation) {
            $type = strtolower($relation->type);
            $target = $relation->target;

            if ($target === null || $target === '') {
                continue;
            }

            $relatedClass = Str::studly($target);
            $methodName = match ($type) {
                'belongsto' => Str::camel($target),
                'hasmany', 'belongstomany' => Str::camel(Str::plural($target)),
                'hasone' => Str::camel($target),
                default => null,
            };

            $eloquentMethod = match ($type) {
                'belongsto' => 'belongsTo',
                'hasmany' => 'hasMany',
                'hasone' => 'hasOne',
                'belongstomany' => 'belongsToMany',
                default => null,
            };

            $returnType = match ($type) {
                'belongsto' => 'BelongsTo',
                'hasmany' => 'HasMany',
                'hasone' => 'HasOne',
                'belongstomany' => 'BelongsToMany',
                default => null,
            };

            if ($methodName === null || $eloquentMethod === null || $returnType === null) {
                continue;
            }

            if (! in_array($returnType, $relationReturnTypes, true)) {
                $relationReturnTypes[] = $returnType;
            }

            $relatedFqcn = $domainModelsNamespace . '\\' . $relatedClass;

            if ($relatedClass !== $selfClass && ! in_array($relatedFqcn, $relationImports, true)) {
                $relationImports[] = $relatedFqcn;
            }

            $relations[] = [
                'method' => $methodName,
                'eloquent_method' => $eloquentMethod,
                'return_type' => $returnType,
                'related_class' => $relatedClass,
                'foreign_key' => $relation->field,
            ];
        }

        $fillable = array_values(array_unique($fillable));
        ksort($casts);

        if ($identifierPhpType === 'mixed') {
            $identifierPhpType = 'int|string';
        }

        return [
            'fillable' => $fillable,
            'casts' => $casts,
            'soft_deletes' => $softDeletes,
            'timestamps' => $timestampsEnabled,
            'relation_return_types' => $relationReturnTypes,
            'relation_imports' => $relationImports,
            'relations' => $relations,
            'identifier' => [
                'field' => $identifierField,
                'php_type' => $identifierPhpType,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $options
     */
    private function makeAppServiceProviderFile(array $context, array $options): ?GeneratedFile
    {
        $interfaceFqcn = $this->normalizeClassName(sprintf('%s\\%sRepositoryInterface', $context['domain']['repositories'], $context['naming']['entity_studly']));
        $implementationFqcn = $this->normalizeClassName(sprintf('%s\\Eloquent%sRepository', $context['namespaces']['repositories'], $context['naming']['entity_studly']));

        $providerPath = rtrim($options['paths']['providers'] ?? 'app/Providers', '/') . '/AppServiceProvider.php';
        $absolutePath = $this->resolveAbsolutePath($providerPath);

        $existingContents = null;

        if (is_string($absolutePath) && is_file($absolutePath)) {
            $existingContents = file_get_contents($absolutePath) ?: null;
        }

        $parsed = $this->parseServiceProvider($existingContents ?? '');
        $bindings = $parsed['bindings'];

        $bindings[$interfaceFqcn] = $implementationFqcn;

        $rendered = $this->renderServiceProvider($bindings, $parsed['uses']);

        if ($existingContents !== null && trim($existingContents) === trim($rendered)) {
            return null;
        }

        $overwrite = $existingContents !== null;

        return new GeneratedFile($providerPath, $rendered, $overwrite);
    }

    /**
     * @return array{bindings: array<string, string>, uses: array<int, string>}
     */
    private function parseServiceProvider(string $contents): array
    {
        $bindings = [];
        $uses = [];

        if ($contents === '') {
            return ['bindings' => [], 'uses' => []];
        }

        preg_match_all('/^use\s+([^;]+);/m', $contents, $useMatches);

        $useMap = [];

        foreach ($useMatches[1] as $fqcn) {
            $fqcn = $this->normalizeClassName($fqcn);
            $useMap[$this->classBasename($fqcn)] = $fqcn;
            $uses[] = $fqcn;
        }

        preg_match_all('/\$this->\s*app->\s*bind\(\s*([^,]+)::class\s*,\s*([^)]+)::class\s*\);/m', $contents, $bindMatches, PREG_SET_ORDER);

        foreach ($bindMatches as $match) {
            $interface = $this->resolveImportedClass($match[1], $useMap);
            $implementation = $this->resolveImportedClass($match[2], $useMap);

            if ($interface !== null && $implementation !== null) {
                $bindings[$interface] = $implementation;
            }
        }

        return ['bindings' => $bindings, 'uses' => $uses];
    }

    /**
     * @param array<string, string> $bindings
     * @param array<int, string> $existingUses
     */
    private function renderServiceProvider(array $bindings, array $existingUses = []): string
    {
        $normalizedBindings = [];

        foreach ($bindings as $interface => $implementation) {
            $normalizedBindings[$this->normalizeClassName($interface)] = $this->normalizeClassName($implementation);
        }

        $entries = [];

        foreach ($normalizedBindings as $interface => $implementation) {
            $entries[] = [
                'interface' => $interface,
                'implementation' => $implementation,
                'interface_short' => $this->classBasename($interface),
                'implementation_short' => $this->classBasename($implementation),
            ];
        }

        usort($entries, static fn (array $a, array $b): int => strcmp($a['interface_short'], $b['interface_short']));

        $imports = array_merge(['Illuminate\\Support\\ServiceProvider'], $existingUses);

        foreach ($entries as $entry) {
            $imports[] = $entry['interface'];
            $imports[] = $entry['implementation'];
        }

        $imports = array_values(array_unique(array_map([$this, 'normalizeClassName'], $imports)));
        sort($imports);

        $useLines = array_map(static fn (string $fqcn): string => 'use ' . $fqcn . ';', $imports);
        $useBlock = implode("\n", $useLines);

        $bindingLines = array_map(static function (array $entry): string {
            return sprintf(
                '        $this->app->bind(%s::class, %s::class);',
                $entry['interface_short'],
                $entry['implementation_short']
            );
        }, $entries);

        $registerBody = $bindingLines === []
            ? "        //\n"
            : implode("\n", $bindingLines) . "\n";

        return <<<PHP
<?php

namespace App\Providers;

{$useBlock}

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
{$registerBody}    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}

PHP;
    }

    /**
     * @param array<string, string> $useMap
     */
    private function resolveImportedClass(string $class, array $useMap): ?string
    {
        $class = trim($class);

        if ($class === '') {
            return null;
        }

        if (str_starts_with($class, '\\')) {
            return $this->normalizeClassName($class);
        }

        if (str_contains($class, '\\')) {
            return $this->normalizeClassName($class);
        }

        return $useMap[$class] ?? $this->normalizeClassName($class);
    }

    private function normalizeClassName(string $class): string
    {
        $class = trim($class);

        return ltrim($class, '\\');
    }

    private function classBasename(string $class): string
    {
        $class = $this->normalizeClassName($class);
        $position = strrpos($class, '\\');

        if ($position === false) {
            return $class;
        }

        return substr($class, $position + 1);
    }

    private function resolveAbsolutePath(string $relativePath): ?string
    {
        $base = getcwd();

        if ($base === false) {
            return null;
        }

        $normalized = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $relativePath);

        return rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($normalized, DIRECTORY_SEPARATOR);
    }

}
