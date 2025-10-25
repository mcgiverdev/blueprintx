<?php

namespace BlueprintX\Support\Security;

use Illuminate\Filesystem\Filesystem;

class SecurityScaffoldingCreator
{
    private const SEEDER_RELATIVE_PATH = 'database/seeders/BlueprintX/Security/RolesSeeder.php';
    private const SEEDER_NAMESPACE = 'Database\\Seeders\\BlueprintX\\Security';
    private const SEEDER_CLASS = 'RolesSeeder';

    public function __construct(private readonly Filesystem $files)
    {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function ensure(array $options = []): void
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);

        if ($dryRun) {
            return;
        }

        $driver = $this->normalizeDriver($options['driver'] ?? 'none');
        $matrix = $this->normalizeMatrix($options['matrix'] ?? []);

        $this->ensureConfigFile($driver);

        $shouldRegisterSeeder = $driver === 'spatie' && $matrix !== [];

    $this->ensureRolesSeeder($shouldRegisterSeeder ? $matrix : []);
        $this->ensureDatabaseSeederRegistration($shouldRegisterSeeder);
    }

    private function normalizeDriver(mixed $driver): string
    {
        if (! is_string($driver)) {
            return 'none';
        }

        $normalized = strtolower(trim($driver));

        return $normalized === 'spatie' ? 'spatie' : 'none';
    }

    /**
     * @param mixed $matrix
     * @return array<int, array{guard:string,roles:array<int,array{key:string,permissions:array<int,string>}>,permissions:array<int,array{key:string}>}>
     */
    private function normalizeMatrix(mixed $matrix): array
    {
        if (! is_array($matrix)) {
            return [];
        }

        $result = [];

        foreach ($matrix as $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $guard = isset($definition['guard']) && is_string($definition['guard'])
                ? strtolower(trim($definition['guard']))
                : 'web';

            if ($guard === '') {
                $guard = 'web';
            }

            $roles = [];

            foreach ($definition['roles'] ?? [] as $roleDefinition) {
                if (! is_array($roleDefinition) || ! isset($roleDefinition['key'])) {
                    continue;
                }

                $key = is_string($roleDefinition['key']) ? trim($roleDefinition['key']) : '';

                if ($key === '') {
                    continue;
                }

                $permissions = [];

                foreach ($roleDefinition['permissions'] ?? [] as $permission) {
                    if (! is_string($permission)) {
                        continue;
                    }

                    $name = trim($permission);

                    if ($name === '') {
                        continue;
                    }

                    if (! in_array($name, $permissions, true)) {
                        $permissions[] = $name;
                    }
                }

                $roles[] = [
                    'key' => $key,
                    'permissions' => $permissions,
                ];
            }

            $permissions = [];

            foreach ($definition['permissions'] ?? [] as $permissionDefinition) {
                if (! is_array($permissionDefinition) || ! isset($permissionDefinition['key'])) {
                    continue;
                }

                $key = is_string($permissionDefinition['key']) ? trim($permissionDefinition['key']) : '';

                if ($key === '') {
                    continue;
                }

                $permissions[] = [
                    'key' => $key,
                ];
            }

            if ($roles === [] && $permissions === []) {
                continue;
            }

            $result[] = [
                'guard' => $guard,
                'roles' => $roles,
                'permissions' => $permissions,
            ];
        }

        return $result;
    }

    private function ensureConfigFile(string $driver): void
    {
        $path = $this->resolvePath('config/blueprintx-security.php');
        $contents = $this->renderConfigContents($driver);

        $current = $this->files->exists($path) ? $this->files->get($path) : null;

        if ($current === $contents) {
            return;
        }

        $this->files->ensureDirectoryExists((string) dirname($path));
        $this->files->put($path, $contents);
    }

    private function renderConfigContents(string $driver): string
    {
        $autoDriver = $driver === 'spatie' ? 'spatie' : 'none';

        $template = <<<'PHP'
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | BlueprintX Security Settings
    |--------------------------------------------------------------------------
    |
    | BlueprintX gestiona este archivo automaticamente. Si deseas forzar un
    | driver especifico puedes definir BLUEPRINTX_ROLES_DRIVER en tu entorno
    | o sobrescribir manualmente este valor.
    |
    */
    'roles' => [
        'driver' => env('BLUEPRINTX_ROLES_DRIVER', 'auto'),
        'auto_detected' => '%s',
    ],
];

PHP;

        return sprintf($template, $autoDriver);
    }

    /**
     * @param array<int, array<string, mixed>> $matrix
     */
    private function ensureRolesSeeder(array $matrix): void
    {
        $path = $this->resolvePath(self::SEEDER_RELATIVE_PATH);

        if ($matrix === []) {
            if ($this->files->exists($path)) {
                $this->files->delete($path);
            }

            return;
        }

        $this->files->ensureDirectoryExists((string) dirname($path));

        $contents = $this->renderSeederContents($matrix);
        $current = $this->files->exists($path) ? $this->files->get($path) : null;

        if ($current === $contents) {
            return;
        }

        $this->files->put($path, $contents);
    }

    /**
     * @param array<int, array<string, mixed>> $matrix
     */
    private function renderSeederContents(array $matrix): string
    {
        $definitions = $this->exportArray($matrix, 2);

    $template = <<<'PHP'
<?php

namespace %s;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class %s extends Seeder
{
    public function run(): void
    {
        $definitions = %s;

        foreach ($definitions as $definition) {
            $guard = (string) ($definition['guard'] ?? 'web');
            $permissions = $definition['permissions'] ?? [];
            $roles = $definition['roles'] ?? [];

            $availablePermissions = $this->ensureGuardPermissions($guard, $permissions, $roles);
            $this->syncGuardRoles($guard, $roles, $availablePermissions);
        }
    }

    /**
     * @param array<int, array{key:string}> $permissions
     * @param array<int, array{key:string,permissions:array<int,string>}> $roles
     * @return array<string, int>
     */
    private function ensureGuardPermissions(string $guard, array $permissions, array $roles): array
    {
        $table = $this->tableName('permissions');
        $lookup = [];

        foreach ($permissions as $permission) {
            $name = (string) ($permission['key'] ?? '');

            if ($name === '') {
                continue;
            }

            $lookup[$name] = $this->ensurePermissionRow($table, $guard, $name);
        }

        foreach ($roles as $role) {
            foreach (($role['permissions'] ?? []) as $permissionName) {
                $permissionName = (string) $permissionName;

                if ($permissionName === '' || $permissionName === '*') {
                    continue;
                }

                $lookup[$permissionName] = $this->ensurePermissionRow($table, $guard, $permissionName);
            }
        }

        ksort($lookup);

        return $lookup;
    }

    /**
     * @param array<int, array{key:string,permissions:array<int,string>}> $roles
     * @param array<string, int> $availablePermissions
     */
    private function syncGuardRoles(string $guard, array $roles, array $availablePermissions): void
    {
        $roleTable = $this->tableName('roles');
        $pivotTable = $this->tableName('role_has_permissions');

        foreach ($roles as $roleDefinition) {
            $name = (string) ($roleDefinition['key'] ?? '');

            if ($name === '') {
                continue;
            }

            $roleId = $this->ensureRoleRow($roleTable, $guard, $name);
            $permissions = array_map(static fn ($value) => (string) $value, $roleDefinition['permissions'] ?? []);

            if ($permissions === []) {
                $this->syncRolePermissions($pivotTable, $roleId, []);

                continue;
            }

            if (in_array('*', $permissions, true)) {
                $this->syncRolePermissions($pivotTable, $roleId, array_values($availablePermissions));

                continue;
            }

            $selected = [];

            foreach ($permissions as $permissionName) {
                if (isset($availablePermissions[$permissionName])) {
                    $selected[] = $availablePermissions[$permissionName];
                }
            }

            $this->syncRolePermissions($pivotTable, $roleId, $selected);
        }
    }

    private function ensurePermissionRow(string $table, string $guard, string $name): int
    {
        $existing = DB::table($table)
            ->where('name', $name)
            ->where('guard_name', $guard)
            ->first();

        if ($existing !== null && isset($existing->id)) {
            return (int) $existing->id;
        }

        $timestamp = now();

        return (int) DB::table($table)->insertGetId([
            'name' => $name,
            'guard_name' => $guard,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    private function ensureRoleRow(string $table, string $guard, string $name): int
    {
        $existing = DB::table($table)
            ->where('name', $name)
            ->where('guard_name', $guard)
            ->first();

        if ($existing !== null && isset($existing->id)) {
            return (int) $existing->id;
        }

        $timestamp = now();

        return (int) DB::table($table)->insertGetId([
            'name' => $name,
            'guard_name' => $guard,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    /**
     * @param array<int, int> $permissionIds
     */
    private function syncRolePermissions(string $table, int $roleId, array $permissionIds): void
    {
        DB::table($table)->where('role_id', $roleId)->delete();

        $permissionIds = array_values(array_unique($permissionIds));

        if ($permissionIds === []) {
            return;
        }

        $rows = [];

        foreach ($permissionIds as $permissionId) {
            $rows[] = [
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ];
        }

        DB::table($table)->insert($rows);
    }

    private function tableName(string $key): string
    {
        $tables = config('permission.table_names', []);

        if (is_array($tables) && isset($tables[$key])) {
            return (string) $tables[$key];
        }

        return match ($key) {
            'permissions' => 'permissions',
            'roles' => 'roles',
            'model_has_permissions' => 'model_has_permissions',
            'model_has_roles' => 'model_has_roles',
            'role_has_permissions' => 'role_has_permissions',
            default => $key,
        };
    }
}

PHP;
        return sprintf($template, self::SEEDER_NAMESPACE, self::SEEDER_CLASS, $definitions);
    }

    private function ensureDatabaseSeederRegistration(bool $register): void
    {
        $path = $this->resolvePath('database/seeders/DatabaseSeeder.php');

        if (! $this->files->exists($path)) {
            return;
        }

        $contents = $this->files->get($path);

        if (! is_string($contents) || $contents === '') {
            return;
        }

        $updated = $register
            ? $this->addSeederRegistration($contents)
            : $this->removeSeederRegistration($contents);

        if ($updated !== $contents) {
            $this->files->put($path, $updated);
        }
    }

    private function addSeederRegistration(string $contents): string
    {
        $eol = str_contains($contents, "\r\n") ? "\r\n" : "\n";
        $useStatement = 'use ' . self::SEEDER_NAMESPACE . '\\' . self::SEEDER_CLASS . ';';

        if (! str_contains($contents, $useStatement)) {
            $needle = 'use Database\\Seeders\\';
            $lastPos = strrpos($contents, $needle);

            if ($lastPos !== false) {
                $lineEnd = strpos($contents, $eol, $lastPos);

                if ($lineEnd === false) {
                    $lineEnd = strlen($contents);
                }

                $contents = substr_replace($contents, $useStatement . $eol, $lineEnd + strlen($eol), 0);
            } else {
                $namespacePosition = strpos($contents, 'namespace Database\\Seeders;');

                if ($namespacePosition !== false) {
                    $afterNamespace = strpos($contents, $eol, $namespacePosition);

                    if ($afterNamespace !== false) {
                        $contents = substr_replace($contents, $useStatement . $eol, $afterNamespace + strlen($eol), 0);
                    }
                }
            }
        }

        $startMarker = strpos($contents, '// @blueprintx:seeders:start');
        $endMarker = strpos($contents, '// @blueprintx:seeders:end');

        if ($startMarker === false || $endMarker === false || $endMarker <= $startMarker) {
            return $contents;
        }

        $block = substr($contents, $startMarker, $endMarker - $startMarker);

        if (str_contains($block, self::SEEDER_CLASS . '::class')) {
            return $contents;
        }

        $insertionTarget = strrpos(substr($contents, 0, $endMarker), '        ]);');

        if ($insertionTarget === false) {
            return $contents;
        }

        $line = '            ' . self::SEEDER_CLASS . '::class,' . $eol;

        return substr_replace($contents, $line, $insertionTarget, 0);
    }

    private function removeSeederRegistration(string $contents): string
    {
        $fqn = self::SEEDER_NAMESPACE . '\\' . self::SEEDER_CLASS;
        $pattern = '/^use\s+' . preg_quote($fqn, '/') . ';\r?\n/m';
        $contents = (string) preg_replace($pattern, '', $contents);

        $callPattern = '/^[ \t]*' . preg_quote(self::SEEDER_CLASS . '::class,', '/') . '\r?\n/m';
        $contents = (string) preg_replace($callPattern, '', $contents);

        return $contents;
    }

    private function exportArray(array $value, int $indent): string
    {
        if ($value === []) {
            return '[]';
        }

        $indentation = str_repeat('    ', $indent);
        $nextIndentation = str_repeat('    ', $indent + 1);
        $lines = ['['];

        foreach ($value as $key => $item) {
            $keyLiteral = is_int($key) ? '' : $this->exportKey($key) . ' => ';

            if (is_array($item)) {
                $lines[] = $nextIndentation . $keyLiteral . $this->exportArray($item, $indent + 1) . ',';

                continue;
            }

            $lines[] = $nextIndentation . $keyLiteral . $this->exportScalar($item) . ',';
        }

        $lines[] = $indentation . ']';

        return implode("\n", $lines);
    }

    private function exportKey(int|string $key): string
    {
        return is_int($key) ? (string) $key : "'" . str_replace("'", "\\'", $key) . "'";
    }

    private function exportScalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if ($value === null) {
            return 'null';
        }

        return "'" . str_replace("'", "\\'", (string) $value) . "'";
    }

    private function resolvePath(string $relative): string
    {
        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
        $normalized = ltrim($normalized, DIRECTORY_SEPARATOR);

        if (function_exists('base_path')) {
            try {
                $resolved = base_path($normalized);

                if (is_string($resolved) && $resolved !== '') {
                    return $resolved;
                }
            } catch (\Throwable) {
                // Ignorado: usamos fallback a continuación.
            }
        }

        $root = getcwd() ?: __DIR__ . '/../../../../..';

        return rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $normalized;
    }
}
