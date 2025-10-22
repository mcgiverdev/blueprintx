<?php

namespace BlueprintX\Validation;

use BlueprintX\Blueprint\Blueprint;
use BlueprintX\Blueprint\Endpoint;
use BlueprintX\Blueprint\Relation;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class SemanticBlueprintValidator
{
    /**
     * @return array{errors:ValidationMessage[],warnings:ValidationMessage[]}
     */
    public function validate(Blueprint $blueprint): array
    {
        $errors = [];
        $warnings = [];

        $this->validateTenancy($blueprint, $errors, $warnings);

        $fieldNames = [];
        foreach ($blueprint->fields() as $index => $field) {
            if (isset($fieldNames[$field->name])) {
                $errors[] = new ValidationMessage(
                    'fields.duplicate',
                    sprintf('Campo duplicado: %s', $field->name),
                    sprintf('fields[%d].name', $index)
                );
            }
            $fieldNames[$field->name] = true;
        }

        $relationFields = [];
        foreach ($blueprint->relations() as $index => $relation) {
            $relationFields[] = $relation->field;

            if ($relation->type === 'belongsTo' && ! isset($fieldNames[$relation->field])) {
                $errors[] = new ValidationMessage(
                    'relations.missing_field',
                    sprintf('La relación %s requiere el campo "%s" en fields.', $relation->target, $relation->field),
                    sprintf('relations[%d].field', $index)
                );
            }
        }

        $tableSuggestion = Str::snake(Str::pluralStudly($blueprint->entity()));
        if ($tableSuggestion !== $blueprint->table()) {
            $warnings[] = new ValidationMessage(
                'naming.mismatch',
                sprintf('El nombre de la tabla "%s" no coincide con la convención sugerida "%s".', $blueprint->table(), $tableSuggestion)
            );
        }

        $endpointsSeen = [];
        foreach ($blueprint->endpoints() as $index => $endpoint) {
            $endpointsSeenKey = $this->fingerprintEndpoint($endpoint);
            if (isset($endpointsSeen[$endpointsSeenKey])) {
                $errors[] = new ValidationMessage(
                    'endpoints.duplicate',
                    sprintf('Endpoint duplicado para "%s".', $endpointsSeenKey),
                    sprintf('api.endpoints[%d]', $index)
                );
            }
            $endpointsSeen[$endpointsSeenKey] = true;

            $this->validateEndpoint($endpoint, $index, $fieldNames, $relationFields, $errors, $warnings);
        }

        if (Arr::get($blueprint->options(), 'versioned') === true && ! isset($fieldNames['version'])) {
            $errors[] = new ValidationMessage(
                'options.versioned.missing_field',
                'La opción versioned requiere un campo "version" en fields.'
            );
        }

        $docs = $blueprint->docs();
        if (isset($docs['examples']) && is_array($docs['examples'])) {
            foreach ($docs['examples'] as $exampleKey => $payload) {
                if (! is_array($payload)) {
                    continue;
                }

                foreach ($payload as $field => $value) {
                    if (! isset($fieldNames[$field])) {
                        $warnings[] = new ValidationMessage(
                            'docs.invalid_example',
                            sprintf('El ejemplo "%s" referencia el campo desconocido "%s".', $exampleKey, $field),
                            sprintf('docs.examples.%s.%s', $exampleKey, $field)
                        );
                    }
                }
            }
        }

        $errors = $this->uniqueMessages($errors);
        $warnings = $this->uniqueMessages($warnings);

        return [
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param ValidationMessage[] $errors
     * @param ValidationMessage[] $warnings
     */
    private function validateTenancy(Blueprint $blueprint, array &$errors, array &$warnings): void
    {
        $tenancy = $blueprint->tenancy();

        $declaredMode = null;
        if (isset($tenancy['mode']) && is_string($tenancy['mode'])) {
            $declaredMode = strtolower($tenancy['mode']);
        }

        $hasAdditionalConfig = false;
        foreach (['storage', 'connection', 'routing_scope', 'seed_scope'] as $key) {
            if (! array_key_exists($key, $tenancy)) {
                continue;
            }

            $value = $tenancy[$key];
            if ($value === null) {
                continue;
            }

            if (is_string($value) && trim($value) === '') {
                continue;
            }

            $hasAdditionalConfig = true;
            break;
        }

        if ($declaredMode === null && $hasAdditionalConfig) {
            $errors[] = new ValidationMessage(
                'tenancy.mode.missing',
                'La sección tenancy requiere "mode" cuando se configuran otras opciones.',
                'tenancy.mode'
            );
        }

        $inferredMode = $this->inferTenancyMode($blueprint);

        if ($declaredMode !== null && $declaredMode !== $inferredMode) {
            $warnings[] = new ValidationMessage(
                'tenancy.mode.mismatch',
                sprintf('El tenancy.mode "%s" no coincide con la convención de carpeta "%s".', $declaredMode, $inferredMode),
                'tenancy.mode'
            );
        }

        $resolvedMode = $declaredMode ?? $inferredMode;

        $storage = null;
        if (isset($tenancy['storage']) && is_string($tenancy['storage'])) {
            $storage = strtolower(trim($tenancy['storage']));
        }

        if ($storage !== null) {
            if ($resolvedMode === 'central' && $storage === 'tenant') {
                $errors[] = new ValidationMessage(
                    'tenancy.storage.invalid',
                    'El valor tenancy.storage "tenant" no es compatible con tenancy.mode "central".',
                    'tenancy.storage'
                );
            }

            if ($resolvedMode === 'tenant' && $storage === 'central') {
                $errors[] = new ValidationMessage(
                    'tenancy.storage.invalid',
                    'El valor tenancy.storage "central" no es compatible con tenancy.mode "tenant".',
                    'tenancy.storage'
                );
            }
        }

        $routingScope = null;
        if (isset($tenancy['routing_scope']) && is_string($tenancy['routing_scope'])) {
            $routingScope = strtolower(trim($tenancy['routing_scope']));
        }

        if ($routingScope !== null && $routingScope !== 'both') {
            if ($resolvedMode === 'central' && $routingScope === 'tenant') {
                $errors[] = new ValidationMessage(
                    'tenancy.routing_scope.invalid',
                    'El routing_scope "tenant" no puede usarse cuando tenancy.mode es "central".',
                    'tenancy.routing_scope'
                );
            }

            if ($resolvedMode === 'tenant' && $routingScope === 'central') {
                $errors[] = new ValidationMessage(
                    'tenancy.routing_scope.invalid',
                    'El routing_scope "central" no puede usarse cuando tenancy.mode es "tenant".',
                    'tenancy.routing_scope'
                );
            }
        }

        $seedScope = null;
        if (isset($tenancy['seed_scope']) && is_string($tenancy['seed_scope'])) {
            $seedScope = strtolower(trim($tenancy['seed_scope']));
        }

        if ($seedScope !== null && $seedScope !== 'both') {
            if ($resolvedMode === 'central' && $seedScope === 'tenant') {
                $errors[] = new ValidationMessage(
                    'tenancy.seed_scope.invalid',
                    'El seed_scope "tenant" no puede usarse cuando tenancy.mode es "central".',
                    'tenancy.seed_scope'
                );
            }

            if ($resolvedMode === 'tenant' && $seedScope === 'central') {
                $errors[] = new ValidationMessage(
                    'tenancy.seed_scope.invalid',
                    'El seed_scope "central" no puede usarse cuando tenancy.mode es "tenant".',
                    'tenancy.seed_scope'
                );
            }
        }
    }

    private function inferTenancyMode(Blueprint $blueprint): string
    {
        $module = $blueprint->module();
        if ($module !== null) {
            $mode = $this->findModeInSegments(explode('/', $module));
            if ($mode !== null) {
                return $mode;
            }
        }

        $path = $blueprint->path();
        if ($path !== '') {
            $normalizedPath = str_replace('\\', '/', $path);
            $segments = array_filter(explode('/', $normalizedPath), static fn ($segment) => $segment !== '');
            $mode = $this->findModeInSegments($segments);
            if ($mode !== null) {
                return $mode;
            }
        }

        return 'central';
    }

    /**
     * @param iterable<int, string> $segments
     */
    private function findModeInSegments(iterable $segments): ?string
    {
        foreach ($segments as $segment) {
            $mode = $this->modeFromSegment($segment);
            if ($mode !== null) {
                return $mode;
            }
        }

        return null;
    }

    private function modeFromSegment(string $segment): ?string
    {
        return match (strtolower($segment)) {
            'central' => 'central',
            'tenant' => 'tenant',
            'shared' => 'shared',
            default => null,
        };
    }

    /**
     * @param ValidationMessage[] $errors
     * @param ValidationMessage[] $warnings
     * @param array<string, bool> $fieldNames
     * @param string[] $relationFields
     */
    private function validateEndpoint(
        Endpoint $endpoint,
        int $index,
        array $fieldNames,
        array $relationFields,
        array &$errors,
        array &$warnings
    ): void {
        if ($endpoint->type === 'patch') {
            if (! $endpoint->field) {
                $errors[] = new ValidationMessage(
                    'endpoints.patch.missing_field',
                    'Los endpoints patch requieren especificar "field".',
                    sprintf('api.endpoints[%d].field', $index)
                );
            } elseif (! isset($fieldNames[$endpoint->field])) {
                $errors[] = new ValidationMessage(
                    'endpoints.patch.unknown_field',
                    sprintf('El endpoint patch hace referencia al campo desconocido "%s".', $endpoint->field),
                    sprintf('api.endpoints[%d].field', $index)
                );
            }
        }

        if ($endpoint->type === 'search') {
            if ($endpoint->fields === []) {
                $errors[] = new ValidationMessage(
                    'endpoints.search.missing_fields',
                    'Los endpoints search deben definir al menos un campo.',
                    sprintf('api.endpoints[%d].fields', $index)
                );
            } else {
                foreach ($endpoint->fields as $field) {
                    if (! isset($fieldNames[$field])) {
                        $warnings[] = new ValidationMessage(
                            'endpoints.search.unknown_field',
                            sprintf('El endpoint search referencia el campo desconocido "%s".', $field),
                            sprintf('api.endpoints[%d].fields', $index)
                        );
                    }
                }
            }
        }

        if ($endpoint->type === 'stats') {
            if (! $endpoint->by) {
                $errors[] = new ValidationMessage(
                    'endpoints.stats.missing_by',
                    'Los endpoints stats deben definir "by".',
                    sprintf('api.endpoints[%d].by', $index)
                );
            } elseif (! isset($fieldNames[$endpoint->by]) && ! in_array($endpoint->by, $relationFields, true)) {
                $warnings[] = new ValidationMessage(
                    'endpoints.stats.unknown_by',
                    sprintf('El endpoint stats "by" referencia "%s" que no coincide con campos ni relaciones.', $endpoint->by),
                    sprintf('api.endpoints[%d].by', $index)
                );
            }
        }
    }

    /**
     * @param ValidationMessage[] $messages
     * @return ValidationMessage[]
     */
    private function uniqueMessages(array $messages): array
    {
        $seen = [];
        $result = [];

        foreach ($messages as $message) {
            $key = $message->code . '|' . ($message->path ?? '') . '|' . $message->message;
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $result[] = $message;
        }

        return $result;
    }

    private function fingerprintEndpoint(Endpoint $endpoint): string
    {
        if ($endpoint->name) {
            return $endpoint->type . ':' . $endpoint->name;
        }

        if ($endpoint->type === 'stats' && $endpoint->by) {
            return $endpoint->type . ':by=' . $endpoint->by;
        }

        if ($endpoint->field) {
            return $endpoint->type . ':field=' . $endpoint->field;
        }

        return $endpoint->type;
    }
}
