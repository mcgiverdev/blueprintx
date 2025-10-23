<?php

namespace BlueprintX\Support\Tenancy;

use BlueprintX\Blueprint\Blueprint;

final class BlueprintTenancyContext
{
    public readonly string $mode;

    public readonly string $storage;

    public readonly string $routingScope;

    public readonly bool $appliesToCentral;

    public readonly bool $appliesToTenant;

    public readonly string $tenantHeader;

    /**
     * @param array<string, mixed> $options
     */
    public static function fromBlueprint(Blueprint $blueprint, array $options = []): self
    {
        $tenancy = $blueprint->tenancy();

        $mode = self::detectMode($blueprint, $tenancy);
        $storage = self::normalizeScope($tenancy['storage'] ?? null, $mode, default: $mode === 'tenant' ? 'tenant' : ($mode === 'shared' ? 'both' : 'central'));
        $routingScope = self::normalizeScope($tenancy['routing_scope'] ?? null, $mode, default: $mode === 'shared' ? 'both' : $mode);
        $tenantHeader = self::resolveTenantHeader($tenancy, $options);

        $appliesToCentral = in_array($routingScope, ['central', 'both'], true);
        $appliesToTenant = in_array($routingScope, ['tenant', 'both'], true);

        $instance = new self();
        $instance->mode = $mode;
        $instance->storage = $storage;
        $instance->routingScope = $routingScope;
        $instance->appliesToCentral = $appliesToCentral;
        $instance->appliesToTenant = $appliesToTenant;
        $instance->tenantHeader = $tenantHeader;

        return $instance;
    }

    private static function detectMode(Blueprint $blueprint, array $tenancy): string
    {
        $mode = self::normalizeMode($tenancy['mode'] ?? null);
        if ($mode !== null) {
            return $mode;
        }

        $module = $blueprint->module();
        if ($module) {
            $detected = self::detectModeFromSegments(preg_split('#[\\/]+#', $module) ?: []);
            if ($detected !== null) {
                return $detected;
            }
        }

        $path = $blueprint->path();
        if ($path !== '') {
            $segments = array_filter(
                preg_split('#[\\/]+#', $path) ?: [],
                static fn ($segment) => is_string($segment) && $segment !== ''
            );

            $detected = self::detectModeFromSegments($segments);
            if ($detected !== null) {
                return $detected;
            }
        }

        return 'central';
    }

    private static function normalizeMode(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = strtolower(trim($value));

        if ($normalized === '') {
            return null;
        }

        if (in_array($normalized, ['central', 'tenant', 'shared'], true)) {
            return $normalized;
        }

        return null;
    }

    /**
     * @param iterable<int, string> $segments
     */
    private static function detectModeFromSegments(iterable $segments): ?string
    {
        foreach ($segments as $segment) {
            if (! is_string($segment)) {
                continue;
            }

            $candidate = strtolower(trim($segment));

            if (in_array($candidate, ['central', 'tenant', 'shared'], true)) {
                return $candidate;
            }
        }

        return null;
    }

    private static function normalizeScope(mixed $value, string $mode, string $default): string
    {
        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            if (in_array($normalized, ['central', 'tenant', 'both'], true)) {
                return $normalized === 'both' ? 'both' : $normalized;
            }

            if ($normalized === 'shared') {
                return 'both';
            }
        }

        return match ($mode) {
            'tenant' => 'tenant',
            'shared' => 'both',
            default => $default,
        };
    }

    /**
     * @param array<string, mixed> $tenancy
     * @param array<string, mixed> $options
     */
    private static function resolveTenantHeader(array $tenancy, array $options): string
    {
        $candidates = [
            $tenancy['tenant_header'] ?? null,
            $tenancy['header'] ?? null,
            $options['tenancy']['tenant_header'] ?? null,
            $options['tenancy']['header'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate)) {
                $value = trim($candidate);

                if ($value !== '') {
                    return $value;
                }
            }
        }

        return 'X-Tenant';
    }

    private function __construct()
    {
        // use static constructor
    }
}
