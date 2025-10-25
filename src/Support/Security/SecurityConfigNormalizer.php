<?php

namespace BlueprintX\Support\Security;

class SecurityConfigNormalizer
{
    public static function normalizeSecurity(mixed $value, ?callable $onInvalid = null, string $defaultDriver = 'inherit'): array
    {
        if ($value === null) {
            return [
                'roles' => self::normalizeRolesConfig(null, $onInvalid, $defaultDriver),
            ];
        }

        if (! is_array($value)) {
            self::reportInvalid($onInvalid, 'La clave "security" debe ser un arreglo.');

            return [
                'roles' => self::normalizeRolesConfig(null, $onInvalid, $defaultDriver),
            ];
        }

        $rolesConfig = $value['roles'] ?? null;

        return [
            'roles' => self::normalizeRolesConfig($rolesConfig, $onInvalid, $defaultDriver),
        ];
    }

    public static function normalizeRolesConfig(mixed $value, ?callable $onInvalid = null, string $defaultDriver = 'inherit'): array
    {
        $result = [
            'driver' => in_array(strtolower($defaultDriver), ['none', 'spatie'], true) ? strtolower($defaultDriver) : 'inherit',
            'infer_permissions_from_crud' => true,
            'roles' => [],
            'permissions' => [],
        ];

        if ($value === null) {
            return $result;
        }

        if (! is_array($value)) {
            self::reportInvalid($onInvalid, 'La clave "security.roles" debe ser un arreglo.');

            return $result;
        }

        if (isset($value['driver'])) {
            $result['driver'] = self::normalizeDriver($value['driver'], $defaultDriver, $onInvalid);
        }

        if (isset($value['infer_permissions_from_crud'])) {
            $result['infer_permissions_from_crud'] = self::boolValue($value['infer_permissions_from_crud']);
        }

        if (isset($value['roles'])) {
            $result['roles'] = self::normalizeRoleDefinitions($value['roles'], $onInvalid);
        }

        if (isset($value['permissions'])) {
            $result['permissions'] = self::normalizePermissionDefinitions($value['permissions'], $onInvalid);
        }

        return $result;
    }

    private static function normalizeRoleDefinitions(mixed $value, ?callable $onInvalid): array
    {
        if ($value === null) {
            return [];
        }

        if (! is_array($value)) {
            self::reportInvalid($onInvalid, 'La clave "security.roles.roles" debe ser un arreglo.');

            return [];
        }

        $roles = [];

        foreach ($value as $key => $definition) {
            $normalized = self::normalizeRoleDefinition($definition, is_string($key) ? $key : null, $onInvalid);

            if ($normalized === null) {
                continue;
            }

            $roles[] = $normalized;
        }

        return $roles;
    }

    private static function normalizePermissionDefinitions(mixed $value, ?callable $onInvalid): array
    {
        if ($value === null) {
            return [];
        }

        if (! is_array($value)) {
            self::reportInvalid($onInvalid, 'La clave "security.roles.permissions" debe ser un arreglo.');

            return [];
        }

        $permissions = [];

        foreach ($value as $key => $definition) {
            $normalized = self::normalizePermissionDefinition($definition, is_string($key) ? $key : null, $onInvalid);

            if ($normalized === null) {
                continue;
            }

            $permissions[] = $normalized;
        }

        return $permissions;
    }

    private static function normalizeRoleDefinition(mixed $definition, ?string $implicitKey, ?callable $onInvalid): ?array
    {
        if (is_string($definition)) {
            $key = trim($definition);

            if ($key === '') {
                self::reportInvalid($onInvalid, 'Las definiciones de roles no pueden ser cadenas vacías.');

                return null;
            }

            return [
                'key' => $key,
                'label' => null,
                'description' => null,
                'guard' => null,
                'permissions' => [],
            ];
        }

        if (! is_array($definition)) {
            self::reportInvalid($onInvalid, 'Cada rol debe ser una cadena o un arreglo.');

            return null;
        }

        $key = $definition['key'] ?? $definition['name'] ?? $implicitKey;

        if (! is_string($key) || ($key = trim($key)) === '') {
            self::reportInvalid($onInvalid, 'Cada rol debe tener una clave válida.');

            return null;
        }

        $label = isset($definition['label']) && is_string($definition['label'])
            ? trim($definition['label'])
            : null;

        $description = isset($definition['description']) && is_string($definition['description'])
            ? trim($definition['description'])
            : null;

        $guard = isset($definition['guard']) && is_string($definition['guard'])
            ? trim($definition['guard'])
            : null;

        $permissions = [];

        if (isset($definition['permissions'])) {
            $permissions = self::normalizeStringList($definition['permissions']);
        }

        return [
            'key' => $key,
            'label' => $label !== '' ? $label : null,
            'description' => $description !== '' ? $description : null,
            'guard' => $guard !== '' ? strtolower($guard) : null,
            'permissions' => $permissions,
        ];
    }

    private static function normalizePermissionDefinition(mixed $definition, ?string $implicitKey, ?callable $onInvalid): ?array
    {
        if (is_string($definition)) {
            $key = trim($definition);

            if ($key === '') {
                self::reportInvalid($onInvalid, 'Las definiciones de permisos no pueden ser cadenas vacías.');

                return null;
            }

            return [
                'key' => $key,
                'label' => null,
                'description' => null,
                'guard' => null,
            ];
        }

        if (! is_array($definition)) {
            self::reportInvalid($onInvalid, 'Cada permiso debe ser una cadena o un arreglo.');

            return null;
        }

        $key = $definition['key'] ?? $definition['name'] ?? $implicitKey;

        if (! is_string($key) || ($key = trim($key)) === '') {
            self::reportInvalid($onInvalid, 'Cada permiso debe tener una clave válida.');

            return null;
        }

        $label = isset($definition['label']) && is_string($definition['label'])
            ? trim($definition['label'])
            : null;

        $description = isset($definition['description']) && is_string($definition['description'])
            ? trim($definition['description'])
            : null;

        $guard = isset($definition['guard']) && is_string($definition['guard'])
            ? trim($definition['guard'])
            : null;

        return [
            'key' => $key,
            'label' => $label !== '' ? $label : null,
            'description' => $description !== '' ? $description : null,
            'guard' => $guard !== '' ? strtolower($guard) : null,
        ];
    }

    private static function normalizeDriver(mixed $value, string $fallback, ?callable $onInvalid): string
    {
        if (! is_string($value)) {
            self::reportInvalid($onInvalid, 'La clave "security.roles.driver" debe ser una cadena.');

            return in_array(strtolower($fallback), ['none', 'spatie'], true) ? strtolower($fallback) : 'inherit';
        }

        $normalized = strtolower(trim($value));

        if ($normalized === '') {
            return in_array(strtolower($fallback), ['none', 'spatie'], true) ? strtolower($fallback) : 'inherit';
        }

        if (! in_array($normalized, ['none', 'spatie', 'inherit'], true)) {
            self::reportInvalid($onInvalid, sprintf('Driver de roles desconocido: "%s".', $value));

            return in_array(strtolower($fallback), ['none', 'spatie'], true) ? strtolower($fallback) : 'inherit';
        }

        return $normalized;
    }

    private static function normalizeStringList(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        $items = [];

        if (is_string($value)) {
            $items = preg_split('/[,|]/', $value) ?: [];
        } elseif (is_array($value)) {
            $items = $value;
        } else {
            return [];
        }

        $normalized = [];

        foreach ($items as $item) {
            if (! is_string($item)) {
                continue;
            }

            $candidate = trim($item);

            if ($candidate === '') {
                continue;
            }

            if (! in_array($candidate, $normalized, true)) {
                $normalized[] = $candidate;
            }
        }

        return $normalized;
    }

    private static function boolValue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            if (in_array($normalized, ['1', 'true', 'yes', 'si', 'sí'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false', 'no'], true)) {
                return false;
            }
        }

        if (is_numeric($value)) {
            return (bool) $value;
        }

        return (bool) $value;
    }

    private static function reportInvalid(?callable $callback, string $message): void
    {
        if ($callback !== null) {
            $callback($message);
        }
    }
}
