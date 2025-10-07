<?php

namespace BlueprintX\Support\Http\Controllers\Concerns;

use App\Domain\Shared\Exceptions\DomainConflictException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

trait HandlesOptimisticLocking
{
    protected function ensureCurrentVersion(Request $request, Model $resource): void
    {
        $config = $this->resolveOptimisticLockingConfig();

        if (! $config['enabled']) {
            return;
        }

        $expectedHeader = $request->headers->get($config['header']);

        if ($expectedHeader === null) {
            if (! $config['require_header']) {
                return;
            }

            throw new DomainConflictException(
                'Se requiere el encabezado de control de versión para completar la operación.',
                'domain.conflict.missing_version',
                409,
                ['header' => $config['header']]
            );
        }

        $expectedToken = $this->normalizeVersionToken($expectedHeader);

        if ($expectedToken === null) {
            throw new DomainConflictException(
                'El encabezado de control de versión tiene un formato inválido.',
                'domain.conflict.invalid_version',
                409,
                ['header' => $config['header'], 'value' => $expectedHeader]
            );
        }

        if ($expectedToken === '*' && $config['allow_wildcard']) {
            return;
        }

        $currentToken = $this->extractVersionToken($resource, $config);

        if ($currentToken === null || ! hash_equals($currentToken, $expectedToken)) {
            throw new DomainConflictException(
                'El recurso fue modificado por otro proceso.',
                'domain.conflict.stale_resource',
                409,
                [
                    'expected' => $expectedToken,
                    'current' => $currentToken,
                ]
            );
        }
    }

    protected function respondWithResourceVersion(JsonResponse $response, Model $resource): JsonResponse
    {
        $config = $this->resolveOptimisticLockingConfig();

        if (! $config['enabled']) {
            return $response;
        }

        $token = $this->extractVersionToken($resource, $config);

        if ($token === null) {
            return $response;
        }

        $etag = sprintf('W/"%s"', $token);

        return $response->header($config['response_header'], $etag);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveOptimisticLockingConfig(): array
    {
        $defaults = [
            'enabled' => true,
            'header' => 'If-Match',
            'response_header' => 'ETag',
            'strategy' => 'timestamp',
            'version_field' => 'version',
            'timestamp_column' => 'updated_at',
            'require_header' => true,
            'allow_wildcard' => true,
        ];

        if (property_exists($this, 'optimisticLocking') && is_array($this->optimisticLocking ?? null)) {
            $defaults = array_merge($defaults, $this->optimisticLocking);
        }

        return $defaults;
    }

    private function normalizeVersionToken(?string $headerValue): ?string
    {
        if ($headerValue === null) {
            return null;
        }

        $value = trim($headerValue);

        if ($value === '') {
            return null;
        }

        if ($value === '*') {
            return '*';
        }

        if (str_starts_with($value, 'W/')) {
            $value = substr($value, 2);
        }

        $value = trim($value, '"');

        return $value === '' ? null : $value;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function extractVersionToken(Model $resource, array $config): ?string
    {
        $strategy = $config['strategy'] ?? 'timestamp';

        if ($strategy === 'version') {
            $field = $config['version_field'] ?? 'version';

            return $this->stringifyVersionValue($resource->getAttribute($field));
        }

        $column = $config['timestamp_column'] ?? 'updated_at';
        $value = $resource->getAttribute($column);

        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('U.u');
        }

        if (is_string($value) || is_numeric($value)) {
            try {
                return Carbon::parse($value)->format('U.u');
            } catch (\Throwable) {
                return (string) $value;
            }
        }

        return null;
    }

    private function stringifyVersionValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('U.u');
        }

        if (method_exists($value, '__toString')) {
            return (string) $value;
        }

        return null;
    }
}
