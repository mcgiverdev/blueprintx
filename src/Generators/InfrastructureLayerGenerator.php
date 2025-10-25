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
use BlueprintX\Support\Concerns\ResolvesModelNamespaces;
use Illuminate\Support\Str;

class InfrastructureLayerGenerator implements LayerGenerator
{
    use ResolvesModelNamespaces;

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

        $tenancyMode = $this->determineTenancyMode($context['blueprint'] ?? []);

        if ($tenancyMode === 'tenant') {
            if ($tenantProviderFile = $this->makeTenancyServiceProviderFile($context, $options)) {
                $result->addFile($tenantProviderFile);
            }

            if ($providersBootstrapFile = $this->ensureTenancyServiceProviderRegistered($options)) {
                $result->addFile($providersBootstrapFile);
            }
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
        $module = $this->moduleSegment($blueprint);
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
        $module = $this->moduleSegment($blueprint);
        $modulePath = $module !== null ? str_replace('\\', '/', $module) : null;
        $entityName = Str::studly($blueprint->entity());

        $root = $basePath;

        if ($modulePath !== null) {
            $root .= '/' . $modulePath;
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
        $module = $this->moduleSegment($blueprint);
        $root = $base;

        if ($module !== null) {
            $root .= '\\' . $module;
        }

        return [
            'models' => $root . '\\Models',
            'repositories' => $root . '\\Repositories',
            'shared_root' => $base,
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, string>
     */
    private function deriveApplicationNamespaces(Blueprint $blueprint, array $options): array
    {
        $base = trim($options['namespaces']['application'] ?? 'App\\Application', '\\');
        $module = $this->moduleSegment($blueprint);
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
        $module = $blueprint->module();

        if ($module === null || $module === '') {
            return null;
        }

        $normalized = str_replace('\\', '/', $module);
        $segments = array_filter(array_map('trim', explode('/', $normalized)), static fn (string $part): bool => $part !== '');

        if ($segments === []) {
            return null;
        }

        $studlySegments = array_map(static fn (string $part): string => Str::studly($part), $segments);

        return implode('\\', $studlySegments);
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
        $sharedRootNamespace = $domainNamespaces['shared_root'] ?? 'App\\Domain';
        $selfFqcn = $domainModelsNamespace . '\\' . $selfClass;

        foreach ($blueprint->relations() as $relation) {
            $type = strtolower($relation->type);
            $target = $relation->target;

            if ($target === null || $target === '') {
                continue;
            }

            $parsedTarget = $this->parseRelationTarget($target);
            $relatedClass = $parsedTarget['entity'] ?? Str::studly($target);
            $targetModule = $parsedTarget['module'];
            $methodName = match ($type) {
                'belongsto' => Str::camel($relatedClass),
                'hasmany', 'belongstomany' => Str::camel(Str::plural($relatedClass)),
                'hasone' => Str::camel($relatedClass),
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

            $relatedNamespace = $this->resolveModelNamespace(
                $blueprint,
                $relatedClass,
                $targetModule,
                $domainModelsNamespace,
                $sharedRootNamespace
            );
            $relatedFqcn = $relatedNamespace . '\\' . $relatedClass;

            if ($relatedFqcn !== $selfFqcn && ! in_array($relatedFqcn, $relationImports, true)) {
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
        $tenancyMode = $this->determineTenancyMode($context['blueprint'] ?? []);

        if (! in_array($tenancyMode, ['central', 'shared'], true)) {
            return null;
        }

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

        $namespace = $this->detectProviderNamespace($existingContents) ?? 'App\\Providers';
        $driverName = $context['driver']['name'] ?? 'hexagonal';
        $template = sprintf('@%s/infrastructure/app-service-provider.stub.twig', $driverName);

        if (! $this->templates->exists($template)) {
            $template = '@hexagonal/infrastructure/app-service-provider.stub.twig';

            if (! $this->templates->exists($template)) {
                return null;
            }
        }

        $viewData = $this->prepareServiceProviderData($namespace, $bindings, $parsed['uses']);

        if ($existingContents === null) {
            $rendered = $this->templates->render($template, $viewData);

            return new GeneratedFile($providerPath, $rendered, false);
        }

        $updated = $this->applyServiceProviderData($existingContents, $viewData, $parsed['uses']);

        if (trim($existingContents) === trim($updated)) {
            return null;
        }

        return new GeneratedFile($providerPath, $updated, true);
    }

    private function ensureTenancyServiceProviderRegistered(array $options): ?GeneratedFile
    {
        $providersListPath = $options['paths']['providers_list'] ?? 'bootstrap/providers.php';
        $absolutePath = $this->resolveAbsolutePath($providersListPath);

        if (! is_string($absolutePath) || ! is_file($absolutePath)) {
            return null;
        }

        $contents = file_get_contents($absolutePath) ?: '';

        if ($contents === '') {
            return null;
        }

        $tenancyProviderEntry = 'App\\Providers\\TenancyServiceProvider::class';

        if (str_contains($contents, $tenancyProviderEntry)) {
            return null;
        }

        $lineEnding = str_contains($contents, "\r\n") ? "\r\n" : "\n";

        $indentation = '    ';

        if (preg_match('/' . preg_quote($lineEnding, '/') . '([ \t]*)App\\\\Providers\\\\[A-Za-z0-9_\\\\]+::class,/', $contents, $matches) === 1) {
            $indentation = $matches[1];
        }

        $pattern = '/' . preg_quote($lineEnding, '/') . '\\];\\s*$/';

        if (preg_match($pattern, $contents) !== 1) {
            return null;
        }

        $replacement = $lineEnding . $indentation . $tenancyProviderEntry . ',' . $lineEnding . '];' . $lineEnding;
        $updated = preg_replace($pattern, $replacement, $contents, 1);

        if ($updated === null || $updated === $contents) {
            return null;
        }

        $normalizedPath = str_replace('\\', '/', $providersListPath);

        return new GeneratedFile($normalizedPath, $updated, true);
    }

    private function makeTenancyServiceProviderFile(array $context, array $options): ?GeneratedFile
    {
        $interfaceFqcn = $this->normalizeClassName(sprintf('%s\\%sRepositoryInterface', $context['domain']['repositories'], $context['naming']['entity_studly']));
        $implementationFqcn = $this->normalizeClassName(sprintf('%s\\Eloquent%sRepository', $context['namespaces']['repositories'], $context['naming']['entity_studly']));

        $providerPath = rtrim($options['paths']['providers'] ?? 'app/Providers', '/') . '/TenancyServiceProvider.php';
        $absolutePath = $this->resolveAbsolutePath($providerPath);

        $fileExists = is_string($absolutePath) && is_file($absolutePath);
        $existingContents = $fileExists ? (file_get_contents($absolutePath) ?: '') : null;

        $parsedUses = [];
        $bindings = [];

        if ($existingContents !== null && trim($existingContents) !== '') {
            $parsed = $this->parseServiceProvider($existingContents);
            $bindings = $parsed['bindings'];
            $parsedUses = $parsed['uses'];
        }

        $bindings[$interfaceFqcn] = $implementationFqcn;

        $namespace = ($existingContents !== null && trim($existingContents) !== '')
            ? $this->detectProviderNamespace($existingContents) ?? 'App\\Providers'
            : 'App\\Providers';

        $viewData = $this->prepareServiceProviderData($namespace, $bindings, $parsedUses);

        $driverName = $context['driver']['name'] ?? 'hexagonal';
        $template = sprintf('@%s/infrastructure/tenancy-service-provider.stub.twig', $driverName);

        if (! $this->templates->exists($template)) {
            $template = '@hexagonal/infrastructure/tenancy-service-provider.stub.twig';

            if (! $this->templates->exists($template)) {
                return null;
            }
        }

        if (! $fileExists || trim((string) $existingContents) === '') {
            $rendered = $this->templates->render($template, $viewData);

            return new GeneratedFile($providerPath, $rendered, false);
        }

        $updated = $this->applyServiceProviderData($existingContents, $viewData, $parsedUses);

        if (trim($existingContents) === trim($updated)) {
            return null;
        }

        return new GeneratedFile($providerPath, $updated, true);
    }

    /**
     * @return array{bindings: array<string, string>, uses: array<int, array{fqcn: string, alias: ?string}>}
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

        foreach ($useMatches[1] as $statement) {
            $alias = null;
            $fqcn = $statement;

            if (str_contains($statement, ' as ')) {
                [$fqcn, $alias] = array_map('trim', explode(' as ', $statement, 2));
            }

            $fqcn = $this->normalizeClassName($fqcn);

            if ($alias !== null && $alias !== '') {
                $useMap[$alias] = $fqcn;
            }

            $useMap[$this->classBasename($fqcn)] = $fqcn;
            $uses[] = [
                'fqcn' => $fqcn,
                'alias' => $alias !== '' ? $alias : null,
            ];
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
     * @param array<int, array{fqcn: string, alias: ?string}> $existingUses
     */
    private function prepareServiceProviderData(string $namespace, array $bindings, array $existingUses = []): array
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
            ];
        }

        usort($entries, static fn (array $a, array $b): int => strcmp($a['interface'], $b['interface']));

        $imports = $this->collectServiceProviderImports($entries, $existingUses);
        $aliases = $this->buildImportAliases($imports);

        $sortedImports = array_values($imports);
        usort($sortedImports, static fn (array $a, array $b): int => strcmp($a['fqcn'], $b['fqcn']));

        $useStatements = [];
        $seen = [];

        foreach ($sortedImports as $import) {
            $fqcn = $this->normalizeClassName($import['fqcn']);

            if (isset($seen[$fqcn])) {
                continue;
            }

            $seen[$fqcn] = true;

            $alias = $aliases[$fqcn] ?? $this->classBasename($fqcn);
            $base = $this->classBasename($fqcn);

            $useStatements[] = [
                'fqcn' => $fqcn,
                'alias' => $alias !== $base ? $alias : null,
            ];
        }

        $bindingLines = array_map(function (array $entry) use ($aliases): array {
            $interfaceFqcn = $this->normalizeClassName($entry['interface']);
            $implementationFqcn = $this->normalizeClassName($entry['implementation']);

            return [
                'interface_alias' => $aliases[$interfaceFqcn] ?? $this->classBasename($interfaceFqcn),
                'implementation_alias' => $aliases[$implementationFqcn] ?? $this->classBasename($implementationFqcn),
            ];
        }, $entries);

        return [
            'namespace' => $namespace,
            'imports' => $useStatements,
            'bindings' => $bindingLines,
        ];
    }

    /**
     * @param array<int, array{fqcn: string, alias: ?string}> $existingUses
     */
    private function applyServiceProviderData(string $contents, array $viewData, array $existingUses = []): string
    {
        $lineEnding = $this->detectLineEnding($contents);
        $updated = $contents;

        $existingUseMap = [];

        foreach ($existingUses as $use) {
            $existingUseMap[$this->normalizeClassName($use['fqcn'])] = $use['alias'] ?? null;
        }

        foreach ($viewData['imports'] as $import) {
            $statement = sprintf(
                'use %s%s;',
                $import['fqcn'],
                isset($import['alias']) && $import['alias'] !== null
                    ? ' as ' . $import['alias']
                    : ''
            );

            $normalizedFqcn = $this->normalizeClassName($import['fqcn']);

            if (isset($existingUseMap[$normalizedFqcn])) {
                continue;
            }

            $updated = $this->ensureUseStatement($updated, $statement, $lineEnding);
        }

        $bindingLines = array_map(static function (array $binding): string {
            return sprintf(
                '        $this->app->bind(%s::class, %s::class);',
                $binding['interface_alias'],
                $binding['implementation_alias']
            );
        }, $viewData['bindings']);

        return $this->ensureRegisterBindings($updated, $bindingLines, $lineEnding);
    }

    private function detectLineEnding(string $contents): string
    {
        return str_contains($contents, "\r\n") ? "\r\n" : "\n";
    }

    private function ensureUseStatement(string $contents, string $statement, string $lineEnding): string
    {
        if (str_contains($contents, $statement)) {
            return $contents;
        }

        return $this->insertUseStatement($contents, $statement, $lineEnding);
    }

    private function insertUseStatement(string $contents, string $statement, string $lineEnding): string
    {
        if (preg_match_all('/^use\s+[^;]+;/m', $contents, $matches, PREG_OFFSET_CAPTURE) && $matches[0] !== []) {
            $last = $matches[0][array_key_last($matches[0])];
            $insertPos = $last[1] + strlen($last[0]);

            return substr($contents, 0, $insertPos) . $lineEnding . $statement . substr($contents, $insertPos);
        }

        if (preg_match('/^namespace\s+[^;]+;/m', $contents, $match, PREG_OFFSET_CAPTURE) === 1) {
            $namespaceEnd = $match[0][1] + strlen($match[0][0]);
            $before = substr($contents, 0, $namespaceEnd);
            $after = substr($contents, $namespaceEnd);

            $before = rtrim($before, "\r\n") . $lineEnding . $lineEnding;
            $after = ltrim($after, "\r\n");

            return $before . $statement . $lineEnding . $after;
        }

        if (str_starts_with($contents, '<?php')) {
            $openingTagEnd = strpos($contents, $lineEnding);

            if ($openingTagEnd === false) {
                return $contents . $lineEnding . $statement . $lineEnding;
            }

            $insertPos = $openingTagEnd + strlen($lineEnding);

            return substr($contents, 0, $insertPos) . $statement . $lineEnding . substr($contents, $insertPos);
        }

        return $statement . $lineEnding . $contents;
    }

    private function ensureRegisterBindings(string $contents, array $bindingLines, string $lineEnding): string
    {
        if ($bindingLines === []) {
            return $contents;
        }

        $functionPos = strpos($contents, 'function register');

        if ($functionPos === false) {
            return $contents;
        }

        $bracePos = strpos($contents, '{', $functionPos);

        if ($bracePos === false) {
            return $contents;
        }

        $bodyStart = $bracePos + 1;
        $length = strlen($contents);
        $depth = 1;

        for ($index = $bodyStart; $index < $length; $index++) {
            $character = $contents[$index];

            if ($character === '{') {
                ++$depth;
                continue;
            }

            if ($character !== '}') {
                continue;
            }

            --$depth;

            if ($depth === 0) {
                $bodyEnd = $index;
                break;
            }
        }

        if (! isset($bodyEnd)) {
            return $contents;
        }

        $formattedBody = $lineEnding . implode($lineEnding, $bindingLines) . $lineEnding . '    ';

        return substr_replace($contents, $formattedBody, $bodyStart, $bodyEnd - $bodyStart);
    }

    /**
     * @param array<int, array{interface: string, implementation: string}> $entries
     * @param array<int, array{fqcn: string, alias: ?string}> $existingUses
     * @return array<string, array{fqcn: string, alias: ?string}>
     */
    private function collectServiceProviderImports(array $entries, array $existingUses): array
    {
        $imports = [];

        $imports['Illuminate\\Support\\ServiceProvider'] = [
            'fqcn' => 'Illuminate\\Support\\ServiceProvider',
            'alias' => 'ServiceProvider',
        ];

        foreach ($existingUses as $use) {
            $fqcn = $this->normalizeClassName($use['fqcn']);
            $imports[$fqcn] = [
                'fqcn' => $fqcn,
                'alias' => $use['alias'],
            ];
        }

        foreach ($entries as $entry) {
            foreach (['interface', 'implementation'] as $key) {
                $fqcn = $this->normalizeClassName($entry[$key]);

                if (! isset($imports[$fqcn])) {
                    $imports[$fqcn] = [
                        'fqcn' => $fqcn,
                        'alias' => null,
                    ];
                }
            }
        }

        return $imports;
    }

    /**
     * @param array<string, array{fqcn: string, alias: ?string}> $imports
     * @return array<string, string>
     */
    private function buildImportAliases(array $imports): array
    {
        $aliases = [];
        $used = [];
        $groups = [];

        foreach ($imports as $fqcn => $import) {
            $fqcn = $this->normalizeClassName($fqcn);

            if ($import['alias'] !== null && $import['alias'] !== '') {
                $alias = $import['alias'];
                $aliases[$fqcn] = $alias;
                $used[$alias] = true;
                continue;
            }

            $base = $this->classBasename($fqcn);
            $groups[$base][] = $fqcn;
        }

        foreach ($groups as $base => $fqcnList) {
            if (count($fqcnList) === 1) {
                $fqcn = $fqcnList[0];
                $alias = $this->ensureUniqueAlias($base, $used);
                $aliases[$fqcn] = $alias;
                continue;
            }

            foreach ($fqcnList as $fqcn) {
                $prefix = $this->detectContextPrefix($fqcn) ?? $this->deriveNamespaceHint($fqcn) ?? '';
                $alias = $this->ensureUniqueAlias($prefix . $base, $used);
                $aliases[$fqcn] = $alias;
            }
        }

        return $aliases;
    }

    private function ensureUniqueAlias(string $alias, array &$used): string
    {
        $candidate = $alias !== '' ? $alias : 'Alias';
        $index = 2;

        while (isset($used[$candidate])) {
            $candidate = $alias !== '' ? $alias . $index : 'Alias' . $index;
            ++$index;
        }

        $used[$candidate] = true;

        return $candidate;
    }

    private function detectContextPrefix(string $fqcn): ?string
    {
        if (preg_match('/\\\\(Central|Tenant|Shared)\\\\/i', $fqcn, $matches) === 1) {
            return Str::studly($matches[1]);
        }

        return null;
    }

    private function deriveNamespaceHint(string $fqcn): ?string
    {
        $segments = explode('\\', trim($fqcn, '\\'));

        array_pop($segments);

        while ($segments !== []) {
            $segment = array_pop($segments);
            $segment = trim($segment);

            if ($segment === '' || in_array(strtolower($segment), ['repositories', 'models', 'requests', 'resources'], true)) {
                continue;
            }

            return Str::studly($segment);
        }

        return null;
    }

    private function determineTenancyMode(array $blueprint): string
    {
        $mode = strtolower((string) ($blueprint['tenancy']['mode'] ?? ''));

        if ($mode === '') {
            $path = (string) ($blueprint['path'] ?? '');
            $mode = $this->inferTenancyModeFromPath($path);
        }

        return $mode !== '' ? $mode : 'central';
    }

    private function inferTenancyModeFromPath(string $path): string
    {
        $lower = strtolower($path);

        if (str_contains($lower, '/tenant/') || str_contains($lower, '\\tenant\\')) {
            return 'tenant';
        }

        if (str_contains($lower, '/shared/') || str_contains($lower, '\\shared\\')) {
            return 'shared';
        }

        if (str_contains($lower, '/central/') || str_contains($lower, '\\central\\')) {
            return 'central';
        }

        return '';
    }

    private function detectProviderNamespace(?string $contents): ?string
    {
        if ($contents === null) {
            return null;
        }

        if (preg_match('/^namespace\s+([^;]+);/m', $contents, $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
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
