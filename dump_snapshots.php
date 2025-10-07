<?php

require __DIR__ . '/../../vendor/autoload.php';

$basePath = __DIR__ . '/resources/templates/hexagonal';

    $engine = new BlueprintX\Kernel\TemplateEngine([], ['debug' => false, 'cache' => false]);
    $driver = new BlueprintX\Kernel\Drivers\HexagonalDriver([
        'package_path' => $basePath,
        'override_path' => $basePath,
    ]);

    foreach ($driver->templateNamespaces() as $namespace => $paths) {
        $engine->registerNamespace($namespace, $paths);
    }

    $blueprint = BlueprintX\Blueprint\Blueprint::fromArray([
        'path' => 'blueprints/hr/employee.yaml',
        'module' => 'hr',
        'entity' => 'Employee',
        'table' => 'employees',
        'architecture' => 'hexagonal',
        'fields' => [
            ['name' => 'id', 'type' => 'uuid'],
            ['name' => 'first_name', 'type' => 'string'],
            ['name' => 'last_name', 'type' => 'string'],
            ['name' => 'email', 'type' => 'string', 'rules' => 'required|email'],
            ['name' => 'salary', 'type' => 'decimal', 'precision' => 10, 'scale' => 2],
            ['name' => 'active', 'type' => 'boolean'],
            ['name' => 'department_id', 'type' => 'uuid'],
            ['name' => 'hired_at', 'type' => 'datetime'],
        ],
        'relations' => [
            ['type' => 'belongsTo', 'target' => 'Department', 'field' => 'department_id'],
            ['type' => 'hasMany', 'target' => 'Contract', 'field' => 'employee_id'],
        ],
        'options' => [
            'softDeletes' => true,
            'timestamps' => true,
        ],
        'api' => [
            'base_path' => '/hr/employees',
            'middleware' => ['auth:sanctum'],
            'endpoints' => [
                ['type' => 'crud'],
                ['type' => 'search', 'fields' => ['first_name', 'last_name', 'email']],
                ['type' => 'stats', 'by' => 'active'],
            ],
            'resources' => [
                'includes' => [
                    ['relation' => 'department', 'alias' => 'department'],
                ],
            ],
        ],
        'docs' => [
            'examples' => [
                'create' => [
                    'first_name' => 'Jane',
                    'last_name' => 'Doe',
                    'email' => 'jane.doe@example.com',
                ],
            ],
        ],
        'errors' => [],
        'metadata' => [],
    ]);

    $generators = [
        new BlueprintX\Generators\DomainLayerGenerator($engine),
        new BlueprintX\Generators\ApplicationLayerGenerator($engine),
        new BlueprintX\Generators\InfrastructureLayerGenerator($engine),
        new BlueprintX\Generators\TestsLayerGenerator($engine, ['enabled' => true], [
            'enabled' => true,
            'header' => 'If-Match',
            'response_header' => 'ETag',
            'timestamp_column' => 'updated_at',
            'version_field' => 'version',
            'require_header' => true,
            'allow_wildcard' => true,
        ]),
    ];

foreach ($generators as $generator) {
    $result = $generator->generate($blueprint, $driver);

    foreach ($result->files() as $file) {
        echo '>>> ' . $file->path . PHP_EOL;
        echo $file->contents . PHP_EOL;
        echo "<<<" . PHP_EOL;
    }
}
