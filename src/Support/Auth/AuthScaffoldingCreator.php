<?php

namespace BlueprintX\Support\Auth;

use BlueprintX\Blueprint\Blueprint;
use BlueprintX\Kernel\Generation\PipelineResult;
use BlueprintX\Kernel\History\GenerationHistoryManager;
use BlueprintX\Kernel\TemplateEngine;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class AuthScaffoldingCreator
{
    /** @var array<int, array<string, mixed>> */
    private array $historyEntries = [];

    private const REGISTER_EXCLUDED_FIELDS = [
        'id',
        'created_at',
        'updated_at',
        'deleted_at',
        'email_verified_at',
        'remember_token',
    ];

    private const DEFAULT_DEVICE_NAME_RULES = ['nullable', 'string', 'max:120'];

    public function __construct(
        private readonly Filesystem $files,
        private readonly TemplateEngine $templates,
        private readonly GenerationHistoryManager $history,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function ensure(array $options = []): void
    {
        $this->historyEntries = [];

        $architecture = $this->normalizeArchitecture($options['architecture'] ?? null);

        $controllersPath = $this->normalizeRelativePath($options['controllers_path'] ?? 'app/Http/Controllers/Api');
        $requestsPath = $this->normalizeRelativePath($options['requests_path'] ?? 'app/Http/Requests/Api');
        $resourcesPath = $this->normalizeRelativePath($options['resources_path'] ?? 'app/Http/Resources');

        $controllersNamespace = $this->deriveNamespace($options['controllers_namespace'] ?? $controllersPath, 'App\\Http\\Controllers\\Api');
        $requestsNamespace = $this->deriveNamespace($options['requests_namespace'] ?? 'App\\Http\\Requests\\Api', 'App\\Http\\Requests\\Api');
        $resourcesNamespace = $this->deriveNamespace($options['resources_namespace'] ?? 'App\\Http\\Resources', 'App\\Http\\Resources');

        $force = (bool) ($options['force'] ?? false);
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $model = $options['model'] ?? null;
        $sanctumInstalled = (bool) ($options['sanctum_installed'] ?? false);

        if (! is_array($model)) {
            $model = null;
        }

        $contexts = $this->prepareContextDefinitions(
            is_array($options['contexts'] ?? null) ? $options['contexts'] : [],
            $controllersNamespace,
            $requestsNamespace,
            $resourcesNamespace,
            $controllersPath,
            $requestsPath,
            $resourcesPath,
            $model
        );

        if ($contexts === []) {
            return;
        }

        foreach ($contexts as $context) {
            $this->ensureAuthController($architecture, $context, $force, $dryRun);
            $this->ensureLoginRequest($architecture, $context, $force, $dryRun);
            $this->ensureRegisterRequest($architecture, $context, $force, $dryRun);
            $this->ensureUserResource($architecture, $context, $force, $dryRun);
        }

        $primaryModel = $contexts['central']['model'] ?? ($contexts[array_key_first($contexts)]['model'] ?? null);

        $this->ensureApplicationUserModel($architecture, $primaryModel, $force, $dryRun);
        $this->ensurePersonalAccessTokensMigrationForContexts($contexts, $force, $dryRun);
        $this->ensureSanctumGuide($architecture, $sanctumInstalled, $force, $dryRun);

        if (! $dryRun) {
            $this->ensureRoutes($controllersNamespace, $contexts);
            $this->ensureAuthConfiguration($contexts, $force);
        }

        if (! $dryRun) {
            $this->persistHistory($architecture, $options);
        } else {
            $this->historyEntries = [];
        }
    }

    /**
     * @param array<string, mixed> $contextOptions
     * @return array<string, array<string, mixed>>
     */
    private function prepareContextDefinitions(
        array $contextOptions,
        string $controllersNamespace,
        string $requestsNamespace,
        string $resourcesNamespace,
        string $controllersPath,
        string $requestsPath,
        string $resourcesPath,
        ?array $fallbackModel,
    ): array {
        $contexts = [];

        if ($contextOptions !== []) {
            foreach ($contextOptions as $key => $definition) {
                $model = $this->extractContextModel($definition);

                if ($model === null) {
                    continue;
                }

                $candidateKey = is_string($key) ? $key : ($definition['key'] ?? null);
                $context = $this->makeContextDefinition(
                    $candidateKey,
                    $model,
                    $controllersNamespace,
                    $requestsNamespace,
                    $resourcesNamespace,
                    $controllersPath,
                    $requestsPath,
                    $resourcesPath,
                );

                if ($context !== null) {
                    $contexts[$context['key']] = $context;
                }
            }
        }

        if ($contexts === [] && $fallbackModel !== null) {
            $fallback = $this->makeContextDefinition(
                'central',
                $fallbackModel,
                $controllersNamespace,
                $requestsNamespace,
                $resourcesNamespace,
                $controllersPath,
                $requestsPath,
                $resourcesPath,
            );

            if ($fallback !== null) {
                $contexts[$fallback['key']] = $fallback;
            }
        }

        ksort($contexts);

        return $contexts;
    }

    private function extractContextModel(mixed $definition): ?array
    {
        if (is_array($definition) && isset($definition['model']) && is_array($definition['model'])) {
            return $definition['model'];
        }

        return null;
    }

    private function makeContextDefinition(
        ?string $key,
        array $model,
        string $controllersNamespace,
        string $requestsNamespace,
        string $resourcesNamespace,
        string $controllersPath,
        string $requestsPath,
        string $resourcesPath,
    ): ?array {
        $tenancyMode = $this->determineTenancyMode($model);
        $contextKey = $this->normalizeContextKey($key, $tenancyMode);
        $studlyKey = Str::studly($contextKey);
        $controllerNamespace = $this->appendNamespace($controllersNamespace, $studlyKey . '\\Auth');
        $controllerClass = $studlyKey . 'AuthController';
        $controllerPath = $this->resolveAbsolutePath($controllersPath, sprintf('%s/Auth/%s.php', $studlyKey, $controllerClass));
        $controllerFqn = $controllerNamespace . '\\' . $controllerClass;

        $requestsNamespaceFull = $this->appendNamespace($requestsNamespace, $studlyKey . '\\Auth');
        $loginClass = $studlyKey . 'LoginRequest';
        $registerClass = $studlyKey . 'RegisterRequest';
        $loginPath = $this->resolveAbsolutePath($requestsPath, sprintf('%s/Auth/%s.php', $studlyKey, $loginClass));
        $registerPath = $this->resolveAbsolutePath($requestsPath, sprintf('%s/Auth/%s.php', $studlyKey, $registerClass));

        $resourceNamespace = $this->appendNamespace($resourcesNamespace, $studlyKey . '\\Auth');
        $resourceClass = $studlyKey . 'UserResource';
        $resourcePath = $this->resolveAbsolutePath($resourcesPath, sprintf('%s/Auth/%s.php', $studlyKey, $resourceClass));

        $userModelFqn = $this->resolveDomainUserFqn($model);

        if ($userModelFqn === null) {
            return null;
        }

        return [
            'key' => $contextKey,
            'studly_key' => $studlyKey,
            'guard' => $contextKey,
            'tenancy_mode' => $tenancyMode,
            'tenant_aware' => $tenancyMode === 'tenant',
            'controller' => [
                'namespace' => $controllerNamespace,
                'class' => $controllerClass,
                'path' => $controllerPath,
                'fqn' => $controllerFqn,
            ],
            'requests' => [
                'namespace' => $requestsNamespaceFull,
                'login_class' => $loginClass,
                'register_class' => $registerClass,
                'login_path' => $loginPath,
                'register_path' => $registerPath,
                'login_fqn' => $requestsNamespaceFull . '\\' . $loginClass,
                'register_fqn' => $requestsNamespaceFull . '\\' . $registerClass,
            ],
            'resource' => [
                'namespace' => $resourceNamespace,
                'class' => $resourceClass,
                'path' => $resourcePath,
                'fqn' => $resourceNamespace . '\\' . $resourceClass,
            ],
            'user_model_fqn' => $userModelFqn,
            'model' => $model,
            'supports_registration' => true,
        ];
    }

    private function determineTenancyMode(array $model): string
    {
        $mode = strtolower((string) ($model['tenancy']['mode'] ?? 'central'));

        return in_array($mode, ['tenant', 'central'], true) ? $mode : 'central';
    }

    private function normalizeContextKey(?string $key, string $tenancyMode): string
    {
        $normalized = is_string($key) ? strtolower(trim($key)) : '';

        if (in_array($normalized, ['central', 'tenant'], true)) {
            return $normalized;
        }

        return $tenancyMode === 'tenant' ? 'tenant' : 'central';
    }

    private function ensureAuthController(string $architecture, array $context, bool $force, bool $dryRun): void
    {
        $controller = $context['controller'];
        $requests = $context['requests'];
        $resource = $context['resource'];
        $path = $controller['path'];

        if ($dryRun) {
            return;
        }

        if ($this->files->exists($path) && ! $force) {
            return;
        }

        $this->files->ensureDirectoryExists((string) dirname($path));

        $contents = $this->renderTemplate($architecture, 'controller.stub.twig', [
            'namespace' => $controller['namespace'],
            'controller_class' => $controller['class'],
            'login_request_fqn' => $requests['login_fqn'],
            'register_request_fqn' => $requests['register_fqn'],
            'login_request_class' => $requests['login_class'],
            'register_request_class' => $requests['register_class'],
            'user_resource_fqn' => $resource['fqn'],
            'user_model_fqn' => $context['user_model_fqn'],
            'tenant_aware' => (bool) ($context['tenant_aware'] ?? false),
            'guard' => $context['guard'],
            'supports_registration' => (bool) ($context['supports_registration'] ?? true),
        ]);

        $previous = $this->getExistingContents($path);

        if ($this->files->put($path, $contents) !== false) {
            $this->recordWrittenFile($path, $contents, $previous);
        }
    }

    private function ensureLoginRequest(string $architecture, array $context, bool $force, bool $dryRun): void
    {
        $requests = $context['requests'];
        $path = $requests['login_path'];

        if ($dryRun) {
            return;
        }

        if ($this->files->exists($path) && ! $force) {
            return;
        }

        $this->files->ensureDirectoryExists((string) dirname($path));

        $contents = $this->renderTemplate($architecture, 'login-request.stub.twig', [
            'namespace' => $requests['namespace'],
            'class' => $requests['login_class'],
            'tenancy_mode' => $context['tenancy_mode'],
        ]);

        $previous = $this->getExistingContents($path);

        if ($this->files->put($path, $contents) !== false) {
            $this->recordWrittenFile($path, $contents, $previous);
        }
    }

    private function ensureRegisterRequest(string $architecture, array $context, bool $force, bool $dryRun): void
    {
        if (! ($context['supports_registration'] ?? true)) {
            return;
        }

        $requests = $context['requests'];
        $path = $requests['register_path'];

        if ($dryRun) {
            return;
        }

        if ($this->files->exists($path) && ! $force) {
            return;
        }

        $this->files->ensureDirectoryExists((string) dirname($path));

        $rules = $this->prepareRegisterValidationRules($context['model'] ?? null, $context);

        $contents = $this->renderTemplate($architecture, 'register-request.stub.twig', [
            'namespace' => $requests['namespace'],
            'class' => $requests['register_class'],
            'has_rules' => $rules !== [],
            'rules' => $rules,
        ]);

        $previous = $this->getExistingContents($path);

        if ($this->files->put($path, $contents) !== false) {
            $this->recordWrittenFile($path, $contents, $previous);
        }
    }

    private function ensureUserResource(string $architecture, array $context, bool $force, bool $dryRun): void
    {
        $resource = $context['resource'];
        $path = $resource['path'];

        if ($dryRun) {
            return;
        }

        if ($this->files->exists($path) && ! $force) {
            return;
        }

        $this->files->ensureDirectoryExists((string) dirname($path));

        $resourceContext = $this->prepareUserResourceContext($context['model'] ?? null, $resource['namespace'], $context);

        $contents = $this->renderTemplate($architecture, 'user-resource.stub.twig', [
            'namespace' => $resource['namespace'],
            'resource_class' => $resource['class'],
            'attributes' => $resourceContext['attributes'],
            'relationships' => $resourceContext['relationships'],
            'imports' => $resourceContext['imports'],
        ]);

        $previous = $this->getExistingContents($path);

        if ($this->files->put($path, $contents) !== false) {
            $this->recordWrittenFile($path, $contents, $previous);
        }
    }

    private function ensureRoutes(string $controllersNamespace, array $contexts): void
    {
        $path = base_path('routes/api.php');

        if (! $this->files->exists($path)) {
            return;
        }

        $original = $this->files->get($path);
        $normalized = str_replace(["\r\n", "\r"], "\n", $original);

        $normalized = $this->removeLegacyAuthRoutes($normalized, $controllersNamespace);

        foreach ($contexts as $context) {
            $controllerFqn = $context['controller']['fqn'];

            if (! str_contains($normalized, $controllerFqn)) {
                $normalized = $this->insertUseStatement($normalized, sprintf('use %s;', $controllerFqn));
            }
        }

        $authBlock = $this->buildAuthRouteBlock($contexts);
        $normalized = $this->synchronizeAuthRouteBlock($normalized, $authBlock);

        $updated = $this->restoreLineEndings($original, $normalized);

        if ($updated !== $original) {
            if ($this->files->put($path, $updated) !== false) {
                $this->recordWrittenFile($path, $updated, $original);
            }
        }
    }

    private function synchronizeAuthRouteBlock(string $contents, string $authBlock): string
    {
        $pattern = "#\nRoute::prefix\\('auth'\\)->group\\(function \(\): void \\{\n(?: {4}.*\n)*?\}\);\n#";
        $normalizedBlock = trim($authBlock, "\n");

        if (preg_match($pattern, $contents) === 1) {
            return preg_replace($pattern, "\n" . $normalizedBlock . "\n", $contents, 1) ?? $contents;
        }

        $needle = "Route::prefix('crm')";
        $position = strpos($contents, $needle);

        if ($position !== false) {
            return substr($contents, 0, $position) . "\n" . $normalizedBlock . "\n" . substr($contents, $position);
        }

        return rtrim($contents) . "\n" . $normalizedBlock . "\n";
    }

    private function ensureAuthConfiguration(array $contexts, bool $force): void
    {
        $path = base_path('config/auth.php');

        if (! $this->files->exists($path)) {
            return;
        }

        $original = $this->files->get($path);
        $normalized = str_replace(["\r\n", "\r"], "\n", $original);

        $guardsBlock = $this->extractArrayBlock($normalized, "'guards' => [");
        $providersBlock = $this->extractArrayBlock($normalized, "'providers' => [");

        if ($guardsBlock === null || $providersBlock === null) {
            return;
        }

        $guardsContent = $guardsBlock['block'];
        $providersContent = $providersBlock['block'];

        $primaryContext = $contexts['central'] ?? reset($contexts);
        $primaryProvider = ($primaryContext !== false) ? $primaryContext['key'] . '_users' : 'users';

        foreach ($contexts as $context) {
            $guardKey = $context['key'];
            $providerKey = $context['key'] . '_users';

            $guardsContent = $this->ensureGuardEntry($guardsContent, $guardsBlock['indent'], $guardKey, $providerKey);
            $providersContent = $this->ensureProviderEntry($providersContent, $providersBlock['indent'], $providerKey, $context['user_model_fqn']);
        }

        if ($primaryContext !== false) {
            $primaryProvider = $primaryContext['key'] . '_users';
            $guardsContent = $this->updateGuardProvider($guardsContent, 'sanctum', $primaryProvider);
            $guardsContent = $this->updateGuardProvider($guardsContent, 'api', $primaryProvider);
            $providersContent = $this->synchronizeDefaultUserProvider($providersContent, $providersBlock['indent'], $primaryContext['user_model_fqn']);
        }

        $normalized = $this->replaceArrayBlock($normalized, $guardsBlock, $guardsContent);
        $normalized = $this->replaceArrayBlock($normalized, $providersBlock, $providersContent);

        $updated = $this->restoreLineEndings($original, $normalized);

        if ($updated !== $original) {
            if ($this->files->put($path, $updated) !== false) {
                $this->recordWrittenFile($path, $updated, $original);
            }
        }
    }

    /**
     * @return array{block:string,start:int,end:int,indent:string}|null
     */
    private function extractArrayBlock(string $contents, string $needle): ?array
    {
        $position = strpos($contents, $needle);

        if ($position === false) {
            return null;
        }

        $lineStart = strrpos(substr($contents, 0, $position), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;
        $line = substr($contents, $lineStart, $position - $lineStart);
        preg_match('/^\s*/', $line, $matches);
        $indent = $matches[0] ?? '';

        $bracketStart = strpos($contents, '[', $position);

        if ($bracketStart === false) {
            return null;
        }

        $depth = 0;
        $length = strlen($contents);
        $end = null;

        for ($i = $bracketStart; $i < $length; $i++) {
            $char = $contents[$i];

            if ($char === '[') {
                $depth++;
            } elseif ($char === ']') {
                $depth--;

                if ($depth === 0) {
                    $end = $i;
                    break;
                }
            }
        }

        if ($end === null) {
            return null;
        }

        return [
            'block' => substr($contents, $bracketStart, $end - $bracketStart + 1),
            'start' => $bracketStart,
            'end' => $end,
            'indent' => $indent,
        ];
    }

    private function replaceArrayBlock(string $contents, array $blockInfo, string $replacement): string
    {
        return substr($contents, 0, $blockInfo['start']) . $replacement . substr($contents, $blockInfo['end'] + 1);
    }

    private function ensureGuardEntry(string $block, string $indent, string $guardKey, string $providerKey): string
    {
        if (str_contains($block, "'{$guardKey}' => [")) {
            return $block;
        }

        $entryIndent = $indent . '    ';
        $innerIndent = $entryIndent . '    ';

        $entry = sprintf(
            "%s'%s' => [\n%s'driver' => 'sanctum',\n%s'provider' => '%s',\n%s],\n",
            $entryIndent,
            $guardKey,
            $innerIndent,
            $innerIndent,
            $providerKey,
            $entryIndent
        );

        return $this->insertArrayEntry($block, $entry, $indent);
    }

    private function ensureProviderEntry(string $block, string $indent, string $providerKey, string $modelFqn): string
    {
        if (str_contains($block, "'{$providerKey}' => [")) {
            return $block;
        }

        $entryIndent = $indent . '    ';
        $innerIndent = $entryIndent . '    ';

        $entry = sprintf(
            "%s'%s' => [\n%s'driver' => 'eloquent',\n%s'model' => %s::class,\n%s],\n",
            $entryIndent,
            $providerKey,
            $innerIndent,
            $innerIndent,
            $modelFqn,
            $entryIndent
        );

        return $this->insertArrayEntry($block, $entry, $indent);
    }

    private function insertArrayEntry(string $block, string $entry, string $indent): string
    {
        $closingPos = strrpos($block, ']');

        if ($closingPos === false) {
            return $block;
        }

        $before = rtrim(substr($block, 0, $closingPos));
        $after = substr($block, $closingPos);

        if (! str_ends_with($before, "\n")) {
            $before .= "\n";
        }

        $before .= $entry;

        if (! str_ends_with($before, "\n")) {
            $before .= "\n";
        }

        return $before . $indent . $after;
    }

    private function updateGuardProvider(string $block, string $guardKey, string $providerKey): string
    {
        $pattern = sprintf(
            "#('%s'\s*=>\s*\[\s*(?:\n|.)*?'provider'\s*=>\s*)'[^']*'#",
            preg_quote($guardKey, '#')
        );

        return preg_replace($pattern, "$1'" . $providerKey . "'", $block, 1) ?? $block;
    }

    private function synchronizeDefaultUserProvider(string $block, string $indent, string $modelFqn): string
    {
    $pattern = "#('users'\\s*=>\\s*\\[[\\s\\S]*?'model'\\s*=>\\s*)(.+?)(\\r?\\n)#";
    $replacement = "$1env('AUTH_MODEL', %s::class),$3";

    $updated = preg_replace($pattern, sprintf($replacement, $modelFqn), $block, 1);

        return $updated !== null ? $updated : $block;
    }

    private function removeLegacyAuthRoutes(string $contents, string $controllersNamespace): string
    {
    $namespaceRoot = trim($controllersNamespace, '\\');
    $legacyUsePattern = sprintf('#^use %s\\\\Auth\\\\[A-Za-z\\\\]+AuthController;\n#m', preg_quote($namespaceRoot, '#'));
    $contents = preg_replace($legacyUsePattern, '', $contents) ?? $contents;

    $directLegacy = sprintf("use %s\\\\Auth\\\\AuthController;\n", $namespaceRoot);
    $contents = str_replace($directLegacy, '', $contents);

        $contents = preg_replace_callback(
            "#\nRoute::prefix\('auth'\)->group\(function \(\): void \{\n(?:(?:    ).+\n)*?\}\);\n#s",
            static function (array $matches): string {
                return str_contains($matches[0], 'AuthController::class') ? "\n" : $matches[0];
            },
            $contents
        ) ?? $contents;

        return $contents;
    }

    /**
     * @param array<string, array<string, mixed>> $contexts
     */
    private function buildAuthRouteBlock(array $contexts): string
    {
        $lines = ["", "Route::prefix('auth')->group(function (): void {"];

        foreach ($contexts as $context) {
            $lines[] = $this->buildAuthRouteGroup($context);
        }

        $lines[] = "});";

        return implode("\n", $lines);
    }

    private function buildAuthRouteGroup(array $context): string
    {
        $indent = '    ';
        $controllerClass = $context['controller']['class'];
        $guard = $context['guard'];
        $supportsRegistration = (bool) ($context['supports_registration'] ?? true);

        if (($context['key'] ?? '') === 'central' && ! ($context['tenant_aware'] ?? false)) {
            $lines = [];
            $lines[] = sprintf("%sRoute::post('login', [%s::class, 'login']);", $indent, $controllerClass);

            if ($supportsRegistration) {
                $lines[] = sprintf("%sRoute::post('register', [%s::class, 'register']);", $indent, $controllerClass);
            }

            $lines[] = sprintf("%sRoute::middleware(['auth:%s'])->group(function (): void {", $indent, $guard);
            $lines[] = sprintf("%s    Route::post('logout', [%s::class, 'logout']);", $indent, $controllerClass);
            $lines[] = sprintf("%s    Route::get('me', [%s::class, 'me']);", $indent, $controllerClass);
            $lines[] = sprintf("%s});", $indent);

            return implode("\n", $lines);
        }

        $prefix = $context['key'];
        $middlewares = [];

        if ($context['tenant_aware']) {
            $middlewares[] = "'tenancy'";
        }

        $middlewareSuffix = $middlewares !== []
            ? '->middleware([' . implode(', ', $middlewares) . '])'
            : '';

        $group = [];
        $group[] = sprintf("%sRoute::prefix('%s')%s->group(function (): void {", $indent, $prefix, $middlewareSuffix);
        $group[] = sprintf("%s    Route::post('login', [%s::class, 'login']);", $indent, $controllerClass);

        if ($supportsRegistration) {
            $group[] = sprintf("%s    Route::post('register', [%s::class, 'register']);", $indent, $controllerClass);
        }

        $group[] = sprintf("%s    Route::middleware(['auth:%s'])->group(function (): void {", $indent, $guard);
        $group[] = sprintf("%s        Route::post('logout', [%s::class, 'logout']);", $indent, $controllerClass);
        $group[] = sprintf("%s        Route::get('me', [%s::class, 'me']);", $indent, $controllerClass);
        $group[] = sprintf("%s    });", $indent);
        $group[] = sprintf("%s});", $indent);

        return implode("\n", $group);
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function prepareRegisterValidationRules(?array $model, array $context): array
    {
        $ruleMap = $this->deriveRegisterRuleMap($model, $context);

        if ($ruleMap === []) {
            return [];
        }

        return $this->formatRulesForTemplate($ruleMap);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function deriveRegisterRuleMap(?array $model, array $context): array
    {
        if ($model === null) {
            return $this->defaultRegisterRuleMap($context);
        }

        $rules = [];
        $order = [];
        $fields = $model['fields'] ?? [];

        if (is_array($fields)) {
            foreach ($fields as $definition) {
                if (! is_array($definition)) {
                    continue;
                }

                $name = $definition['name'] ?? null;

                if (! is_string($name) || $name === '') {
                    continue;
                }

                if (in_array($name, self::REGISTER_EXCLUDED_FIELDS, true)) {
                    continue;
                }

                $parts = $this->splitRules($definition['rules'] ?? null);

                if ($parts === []) {
                    $parts = $this->inferRulesFromFieldDefinition($definition);
                }

                if ($name === 'password' && ! $this->containsRule($parts, 'confirmed')) {
                    $parts[] = 'confirmed';
                }

                if ($parts === []) {
                    continue;
                }

                $rules[$name] = $this->uniqueRules($parts);
                $order[] = $name;
            }
        }

        foreach ($this->defaultRegisterRuleMap($context) as $field => $parts) {
            if (! array_key_exists($field, $rules)) {
                $rules[$field] = $parts;
                $order[] = $field;
            }
        }

        if (! array_key_exists('device_name', $rules)) {
            $rules['device_name'] = self::DEFAULT_DEVICE_NAME_RULES;
            $order[] = 'device_name';
        } else {
            $rules['device_name'] = $this->uniqueRules($rules['device_name']);
        }

        if (isset($rules['password'])) {
            $rules['password'] = $this->uniqueRules($rules['password']);
        }

        if (($context['tenancy_mode'] ?? 'central') === 'tenant' && ! array_key_exists('tenant_id', $rules)) {
            $rules['tenant_id'] = ['nullable', 'uuid'];
            $order[] = 'tenant_id';
        }

        $ordered = [];

        foreach (array_unique($order) as $field) {
            $ordered[$field] = $rules[$field];
        }

        return $ordered;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function defaultRegisterRuleMap(array $context): array
    {
        $defaults = [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'device_name' => self::DEFAULT_DEVICE_NAME_RULES,
        ];

        if (($context['tenancy_mode'] ?? 'central') === 'tenant') {
            $defaults['tenant_id'] = ['nullable', 'uuid'];
        }

        return $defaults;
    }

    private function inferRulesFromFieldDefinition(array $definition): array
    {
        $rules = [];
        $nullable = array_key_exists('nullable', $definition) ? (bool) $definition['nullable'] : false;
        $rules[] = $nullable ? 'nullable' : 'required';

        $type = $definition['type'] ?? null;

        if (! is_string($type)) {
            return $rules;
        }

        $type = strtolower($type);

        switch ($type) {
            case 'uuid':
                $rules[] = 'uuid';
                break;
            case 'string':
            case 'text':
                $rules[] = 'string';
                break;
            case 'boolean':
                $rules[] = 'boolean';
                break;
            case 'integer':
                $rules[] = 'integer';
                break;
            case 'decimal':
            case 'float':
                $rules[] = 'numeric';
                break;
            case 'datetime':
            case 'timestamp':
                $rules[] = 'date';
                break;
        }

        return $rules;
    }

    /**
     * @param array<string, array<int, string>> $rules
     * @return array<int, array<string, string>>
     */
    private function formatRulesForTemplate(array $rules): array
    {
        $formatted = [];

        foreach ($rules as $field => $parts) {
            $formatted[] = [
                'field_code' => var_export($field, true),
                'parts_code' => implode(', ', array_map(static fn ($rule) => var_export($rule, true), $parts)),
            ];
        }

        return $formatted;
    }

    /**
     * @return array{attributes: array<int, string>, relationships: array<int, array<string, string>>, imports: array<int, string>}
     */
    private function prepareUserResourceContext(?array $model, string $resourceNamespace, array $context): array
    {
        if ($model === null) {
            return $this->defaultUserResourceContext($resourceNamespace, $context);
        }

        $attributes = $this->buildResourceAttributesFromModel($model);
        $relationships = $this->buildResourceRelationshipsFromModel($model, $resourceNamespace);

        $fallback = $this->defaultUserResourceContext($resourceNamespace, $context);

        if ($attributes === []) {
            $attributes = $fallback['attributes'];
        }

        if ($relationships['items'] === []) {
            $relationships = [
                'items' => $fallback['relationships'],
                'imports' => $fallback['imports'],
            ];
        }

        return [
            'attributes' => $attributes,
            'relationships' => $relationships['items'],
            'imports' => array_values(array_unique($relationships['imports'])),
        ];
    }

    /**
     * @return array{attributes: array<int, string>, relationships: array<int, array<string, string>>, imports: array<int, string>}
     */
    private function defaultUserResourceContext(string $resourceNamespace, array $context): array
    {
        $tenantResourceFqn = $this->appendNamespace($resourceNamespace, 'TenantResource');

        $attributes = [
            'id',
            'name',
            'email',
            'status',
            'locale',
            'timezone',
            'last_login_at',
            'created_at',
            'updated_at',
        ];

        if (($context['tenancy_mode'] ?? 'central') === 'tenant') {
            array_splice($attributes, 1, 0, ['tenant_id']);
            $attributes[] = 'phone';
        }

        $relationships = [];
        $imports = [];

        if (($context['tenancy_mode'] ?? 'central') === 'tenant') {
            $relationships[] = [
                'property' => 'tenant',
                'expression' => 'TenantResource::make($this->whenLoaded(\'tenant\'))',
            ];
            $imports[] = $tenantResourceFqn;
        }

        return [
            'attributes' => array_values(array_unique($attributes)),
            'relationships' => $relationships,
            'imports' => array_values(array_unique($imports)),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function buildResourceAttributesFromModel(array $model): array
    {
        $attributes = ['id'];
        $fields = $model['fields'] ?? [];

        if (is_array($fields)) {
            foreach ($fields as $definition) {
                if (! is_array($definition)) {
                    continue;
                }

                $name = $definition['name'] ?? null;

                if (is_string($name) && $name !== '') {
                    if (in_array($name, ['password', 'remember_token'], true)) {
                        continue;
                    }

                    $attributes[] = $name;
                }
            }
        }

        $options = $model['options'] ?? [];
        $timestampsEnabled = true;

        if (is_array($options) && array_key_exists('timestamps', $options)) {
            $timestampsEnabled = (bool) $options['timestamps'];
        }

        if ($timestampsEnabled) {
            $attributes[] = 'created_at';
            $attributes[] = 'updated_at';
        }

        if (is_array($options) && (bool) ($options['softDeletes'] ?? false)) {
            $attributes[] = 'deleted_at';
        }

        $attributes = array_values(array_unique(array_filter($attributes, static fn ($value): bool => is_string($value) && $value !== '')));

        return $attributes;
    }

    /**
     * @return array{items: array<int, array<string, string>>, imports: array<int, string>}
     */
    private function buildResourceRelationshipsFromModel(array $model, string $resourceNamespace): array
    {
        $api = $model['api'] ?? [];
        $includes = [];

        if (is_array($api) && isset($api['includes']) && is_array($api['includes'])) {
            $includes = $api['includes'];
        }

        if ($includes === []) {
            return [
                'items' => [],
                'imports' => [],
            ];
        }

        $relationMap = $this->buildRelationMapFromModel($model);

        if ($relationMap === []) {
            return [
                'items' => [],
                'imports' => [],
            ];
        }

        $items = [];
        $imports = [];
        $namespace = trim($resourceNamespace, '\\');

        foreach ($includes as $definition) {
            $relationName = null;
            $alias = null;
            $resourceOverride = null;

            if (is_string($definition)) {
                $relationName = Str::camel($definition);
                $alias = $definition;
            } elseif (is_array($definition)) {
                if (! isset($definition['relation'])) {
                    continue;
                }

                $relationName = Str::camel((string) $definition['relation']);
                $alias = $definition['alias'] ?? $definition['relation'];

                if (isset($definition['resource']) && is_string($definition['resource']) && $definition['resource'] !== '') {
                    $resourceOverride = $definition['resource'];
                }
            } else {
                continue;
            }

            if ($relationName === '') {
                continue;
            }

            $relation = $relationMap[$relationName] ?? null;

            if ($relation === null) {
                continue;
            }

            $alias = is_string($alias) && $alias !== '' ? $alias : $relation['method'];

            $resourceFqcn = $resourceOverride !== null ? $this->normalizeResourceFqcn($resourceOverride, $namespace) : null;

            if (in_array($relation['type'], ['belongsto', 'hasone'], true)) {
                if ($resourceFqcn === null) {
                    $resourceFqcn = $this->guessResourceFqcn($namespace, $relation['target']);
                }
            } elseif (in_array($relation['type'], ['hasmany', 'belongstomany'], true)) {
                if ($resourceFqcn === null) {
                    $resourceFqcn = $this->guessCollectionFqcn($namespace, $relation['target']);
                }
            } else {
                continue;
            }

            if ($resourceFqcn === null) {
                continue;
            }

            $resourceClass = Str::afterLast($resourceFqcn, '\\');

            $items[] = [
                'property' => $alias,
                'expression' => sprintf('%s::make($this->whenLoaded(\'%s\'))', $resourceClass, $relation['method']),
            ];

            if (! in_array($resourceFqcn, $imports, true)) {
                $imports[] = $resourceFqcn;
            }
        }

        return [
            'items' => $items,
            'imports' => array_values(array_unique($imports)),
        ];
    }

    /**
     * @return array<string, array{method: string, type: string, target: string}>
     */
    private function buildRelationMapFromModel(array $model): array
    {
        $relations = $model['relations'] ?? [];

        if (! is_array($relations) || $relations === []) {
            return [];
        }

        $map = [];

        foreach ($relations as $relation) {
            if (! is_array($relation)) {
                continue;
            }

            $type = strtolower((string) ($relation['type'] ?? ''));
            $target = $relation['target'] ?? '';

            if (! is_string($target) || $target === '') {
                continue;
            }

            $method = $this->deriveRelationMethod($relation);

            if ($method === null || $method === '') {
                continue;
            }

            $map[$method] = [
                'method' => $method,
                'type' => $type,
                'target' => $target,
            ];
        }

        return $map;
    }

    private function deriveRelationMethod(array $relation): ?string
    {
        $type = strtolower((string) ($relation['type'] ?? ''));
        $target = (string) ($relation['target'] ?? '');

        return match ($type) {
            'belongsto', 'hasone' => $this->deriveBelongsToMethodName($relation, $target),
            'hasmany', 'belongstomany' => Str::camel(Str::plural($target)),
            default => null,
        };
    }

    private function deriveBelongsToMethodName(array $relation, string $target): string
    {
        $candidate = $this->normalizeRelationFieldName($relation['field'] ?? null);

        if ($candidate !== null) {
            return Str::camel($candidate);
        }

        return Str::camel($target);
    }

    private function normalizeRelationFieldName(mixed $field): ?string
    {
        if (! is_string($field) || $field === '') {
            return null;
        }

        $normalized = preg_replace('/_(id|uuid|ulid|guid)$/', '', $field);
        $normalized = preg_replace('/_fk$/', '', (string) $normalized);
        $normalized = trim((string) $normalized, '_');

        if ($normalized === '') {
            return null;
        }

        return $normalized;
    }

    private function normalizeResourceFqcn(string $value, string $fallbackNamespace): string
    {
        $value = trim($value, '\\');

        if ($value === '') {
            return $this->appendNamespace($fallbackNamespace, 'Resource');
        }

        if (! str_contains($value, '\\')) {
            return $this->appendNamespace($fallbackNamespace, $value);
        }

        return $value;
    }

    private function guessResourceFqcn(string $resourceNamespace, string $target): ?string
    {
        $namespace = trim($resourceNamespace, '\\');

        if ($namespace === '') {
            return null;
        }

        $class = Str::studly($target) . 'Resource';

        return $namespace . '\\' . $class;
    }

    private function guessCollectionFqcn(string $resourceNamespace, string $target): ?string
    {
        $namespace = trim($resourceNamespace, '\\');

        if ($namespace === '') {
            return null;
        }

        $class = Str::studly($target) . 'Collection';

        return $namespace . '\\' . $class;
    }

    private function ensureApplicationUserModel(string $architecture, ?array $model, bool $force, bool $dryRun): void
    {
        if ($dryRun) {
            return;
        }

        $context = $this->prepareApplicationUserModelContext($model);

        if ($context === null) {
            return;
        }

        $path = base_path('app/Models/User.php');
        $this->files->ensureDirectoryExists((string) dirname($path));

        $contents = $this->renderTemplate($architecture, 'user-model.stub.twig', $context);

        $previous = $this->getExistingContents($path);

        if ($previous !== null && ! $force) {
            if ($this->normalizePhpContents($previous) === $this->normalizePhpContents($contents)) {
                return;
            }
        }

        if ($this->files->put($path, $contents) !== false) {
            $this->recordWrittenFile($path, $contents, $previous);
        }
    }

    private function prepareApplicationUserModelContext(?array $model): ?array
    {
        $domainFqn = $this->resolveDomainUserFqn($model);

        if ($domainFqn === null) {
            return null;
        }

        return [
            'namespace' => 'App\\Models',
            'domain_fqn' => $domainFqn,
            'domain_alias' => $this->deriveDomainAlias($domainFqn),
        ];
    }

    private function resolveDomainUserFqn(?array $model): ?string
    {
        if ($model === null) {
            return null;
        }

        $module = $model['module'] ?? null;
        $entity = $model['entity'] ?? null;

        if (! is_string($entity) || $entity === '') {
            return null;
        }

        $entitySegment = Str::studly($entity);

        $moduleSegment = $this->normalizeModuleNamespace($module);

        return sprintf('App\\Domain\\%s\\Models\\%s', $moduleSegment, $entitySegment);
    }

    private function normalizeModuleNamespace(mixed $module): string
    {
        if (! is_string($module) || $module === '') {
            return 'Shared';
        }

        $normalized = str_replace(['\\', '/'], '/', strtolower(trim($module)));
        $normalized = preg_replace('/[^a-z0-9_\/]+/', '', (string) $normalized);

        if ($normalized === '' || $normalized === null) {
            return 'Shared';
        }

        $segments = array_filter(explode('/', (string) str_replace('_', '/', $normalized)), static fn (string $segment): bool => $segment !== '');

        if ($segments === []) {
            return 'Shared';
        }

        $studlySegments = array_map(static fn (string $segment): string => Str::studly($segment), $segments);

        return implode('\\', $studlySegments);
    }

    private function deriveDomainAlias(string $domainFqn): string
    {
        $basename = Str::afterLast($domainFqn, '\\');

        if ($basename === '') {
            return 'DomainUser';
        }

        $alias = 'Domain' . $basename;

        if ($alias === $basename) {
            $alias .= 'Base';
        }

        return $alias;
    }

    private function normalizePhpContents(string $contents): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $contents);

        return trim($normalized);
    }

    private function ensureSanctumGuide(string $architecture, bool $sanctumInstalled, bool $force, bool $dryRun): void
    {
        if ($dryRun || $sanctumInstalled) {
            return;
        }

        $path = base_path('docs/README_SANCTUM.md');

        if ($this->files->exists($path) && ! $force) {
            return;
        }

        $this->files->ensureDirectoryExists((string) dirname($path));

        $contents = $this->renderTemplate($architecture, 'sanctum-guide.stub.twig', []);

        $previous = $this->getExistingContents($path);

        if ($this->files->put($path, $contents) !== false) {
            $this->recordWrittenFile($path, $contents, $previous);
        }
    }

    /**
     * @param array<string, array<string, mixed>> $contexts
     */
    private function ensurePersonalAccessTokensMigrationForContexts(array $contexts, bool $force, bool $dryRun): void
    {
        foreach ($contexts as $context) {
            $model = $context['model'] ?? null;

            if (! is_array($model)) {
                continue;
            }

            if (! $this->authModelUsesUuidPrimaryKey($model)) {
                continue;
            }

            $this->ensurePersonalAccessTokensMigration($model, $force, $dryRun);

            return;
        }
    }

    private function ensurePersonalAccessTokensMigration(?array $model, bool $force, bool $dryRun): void
    {
        if ($dryRun || ! $this->authModelUsesUuidPrimaryKey($model)) {
            return;
        }

        $paths = glob(base_path('database/migrations/*create_personal_access_tokens_table.php')) ?: [];

        foreach ($paths as $path) {
            if (! is_string($path) || $path === '') {
                continue;
            }

            if (! $this->files->exists($path)) {
                continue;
            }

            $original = $this->files->get($path);

            if (str_contains($original, 'uuidMorphs(')) {
                continue;
            }

            if (! $force && ! str_contains($original, 'morphs(')) {
                continue;
            }

            $updated = preg_replace(
                '/(->)\s*(nullableMorphs|morphs)\s*\(\s*([\'\"])tokenable\3\s*\)/',
                '$1 uuidMorphs($3tokenable$3)',
                $original,
            );

            if ($updated === null || $updated === $original) {
                continue;
            }

            if ($this->files->put($path, $updated) !== false) {
                $this->recordWrittenFile($path, $updated, $original);
            }
        }
    }

    private function authModelUsesUuidPrimaryKey(?array $model): bool
    {
        if ($model === null) {
            return false;
        }

        $fields = $model['fields'] ?? [];

        if (! is_array($fields)) {
            return false;
        }

        foreach ($fields as $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $name = $definition['name'] ?? null;

            if ($name !== 'id') {
                continue;
            }

            $type = strtolower((string) ($definition['type'] ?? ''));

            if (in_array($type, ['uuid', 'ulid'], true)) {
                return true;
            }
        }

        return false;
    }

    private function splitRules(?string $rules): array
    {
        if ($rules === null) {
            return [];
        }

        $segments = array_map('trim', explode('|', $rules));
        $segments = array_filter($segments, static fn (string $segment): bool => $segment !== '');

        return array_values($segments);
    }

    private function containsRule(array $rules, string $needle): bool
    {
        $needle = strtolower($needle);

        foreach ($rules as $rule) {
            if (strtolower($rule) === $needle) {
                return true;
            }
        }

        return false;
    }

    private function uniqueRules(array $rules): array
    {
        $unique = [];

        foreach ($rules as $rule) {
            if (! in_array($rule, $unique, true)) {
                $unique[] = $rule;
            }
        }

        return $unique;
    }

    private function insertUseStatement(string $normalized, string $statement): string
    {
        $lastUsePos = strrpos($normalized, "\nuse ");

        if ($lastUsePos === false) {
            $openingTagPos = strpos($normalized, "<?php\n");

            if ($openingTagPos === false) {
                return $statement . "\n\n" . ltrim($normalized);
            }

            $offset = $openingTagPos + 6;

            return substr($normalized, 0, $offset) . $statement . "\n" . substr($normalized, $offset);
        }

        $semicolonPos = strpos($normalized, ';', $lastUsePos);

        if ($semicolonPos === false) {
            return $normalized . "\n" . $statement;
        }

        $semicolonPos++;

        return substr($normalized, 0, $semicolonPos) . "\n" . $statement . substr($normalized, $semicolonPos);
    }

    private function restoreLineEndings(string $original, string $normalized): string
    {
        if (str_contains($original, "\r\n")) {
            return str_replace("\n", "\r\n", $normalized);
        }

        return $normalized;
    }

    private function getExistingContents(string $path): ?string
    {
        if (! $this->files->exists($path)) {
            return null;
        }

        try {
            return $this->files->get($path);
        } catch (Throwable) {
            return null;
        }
    }

    private function recordWrittenFile(string $absolutePath, string $contents, ?string $previous): void
    {
        $entry = [
            'layer' => 'auth_scaffolding',
            'path' => $this->relativeToBasePath($absolutePath),
            'full_path' => $this->normalizeAbsolutePath($absolutePath),
            'status' => $previous === null ? 'written' : 'overwritten',
            'bytes' => strlen($contents),
            'checksum' => hash('sha256', $contents),
        ];

        if ($previous !== null) {
            $entry['previous_contents'] = $previous;
            $entry['previous_checksum'] = hash('sha256', $previous);
            $entry['previous_bytes'] = strlen($previous);
        }

        $this->historyEntries[] = $entry;
    }

    private function relativeToBasePath(string $absolutePath): string
    {
        $base = str_replace('\\', '/', base_path());
        $normalized = str_replace('\\', '/', $absolutePath);

        if (! str_ends_with($base, '/')) {
            $base .= '/';
        }

        if (str_starts_with($normalized, $base)) {
            $relative = substr($normalized, strlen($base));
        } else {
            $relative = ltrim($normalized, '/');
        }

        return trim(str_replace('\\', '/', $relative), '/');
    }

    private function normalizeAbsolutePath(string $path): string
    {
        $resolved = realpath($path);

        if ($resolved !== false) {
            return $resolved;
        }

        return $this->normalizePathSeparators($path);
    }

    private function normalizePathSeparators(string $path): string
    {
        return str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
    }

    private function makeVirtualBlueprint(string $architecture): Blueprint
    {
        $architecture = $architecture !== '' ? $architecture : 'hexagonal';

        return Blueprint::fromArray([
            'path' => 'blueprints/virtual/auth_scaffolding.yaml',
            'module' => 'auth',
            'entity' => 'auth_scaffolding',
            'table' => 'users',
            'architecture' => $architecture,
            'fields' => [],
            'relations' => [],
            'options' => ['virtual' => true],
            'api' => [
                'base_path' => null,
                'middleware' => [],
                'resources' => ['includes' => []],
                'endpoints' => [],
            ],
            'docs' => [],
            'errors' => [],
            'metadata' => [
                'origin' => 'auth_scaffolding',
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function persistHistory(string $architecture, array $options): void
    {
        if ($this->historyEntries === []) {
            return;
        }

        if (! $this->history->isEnabled()) {
            $this->historyEntries = [];

            return;
        }

        $result = new PipelineResult($this->historyEntries);
        $blueprint = $this->makeVirtualBlueprint($architecture);

        $contextOptions = [
            'force' => (bool) ($options['force'] ?? false),
        ];

        if (array_key_exists('sanctum_installed', $options)) {
            $contextOptions['sanctum_installed'] = (bool) $options['sanctum_installed'];
        }

        $context = [
            'execution_id' => $options['execution_id'] ?? null,
            'options' => $contextOptions,
            'filters' => [],
            'warnings' => [],
        ];

        $this->history->record($blueprint, 'virtual/auth_scaffolding.yaml', $result, $context);

        $this->historyEntries = [];
    }

    private function resolveAbsolutePath(string $relative, string $append): string
    {
        $relative = trim(str_replace('\\', '/', $relative), '/');
        $append = trim(str_replace('\\', '/', $append), '/');

        $path = $relative === '' ? $append : $relative . '/' . $append;

        return base_path($path);
    }

    private function normalizeRelativePath(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return '';
        }

        return trim(str_replace('\\', '/', $value), '/');
    }

    private function deriveNamespace(mixed $value, string $fallback): string
    {
        if (! is_string($value) || $value === '') {
            return $fallback;
        }

        $normalized = trim($value);

        $normalized = str_replace('/', '\\', $normalized);
        $normalized = trim($normalized, '\\');

        if ($normalized === '') {
            return $fallback;
        }

        if (strncasecmp($normalized, 'app\\', 4) === 0) {
            $normalized = 'App\\' . substr($normalized, 4);
        }

        if (! str_starts_with($normalized, 'App\\')) {
            $normalized = 'App\\' . ltrim($normalized, '\\');
        }

        return $normalized !== '' ? $normalized : $fallback;
    }

    private function appendNamespace(string $base, string $suffix): string
    {
        $base = trim(str_replace('/', '\\', $base), '\\');

        if ($base === '') {
            return trim($suffix, '\\');
        }

        return $base . '\\' . trim($suffix, '\\');
    }

    private function normalizeArchitecture(mixed $value): string
    {
        if (is_string($value) && $value !== '') {
            return strtolower(trim($value));
        }

        return 'hexagonal';
    }

    private function renderTemplate(string $architecture, string $template, array $context): string
    {
        $candidates = array_unique([$architecture, 'hexagonal']);

        foreach ($candidates as $candidate) {
            $path = sprintf('%s/auth/%s', $candidate, $template);

            if ($this->templates->exists($path)) {
                return $this->templates->render($path, $context);
            }
        }

        throw new RuntimeException(sprintf('No se encontró la plantilla "%s" para el scaffolding de auth.', $template));
    }
}
