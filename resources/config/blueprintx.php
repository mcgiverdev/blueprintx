<?php

return [
    'paths' => [
        'blueprints' => base_path('blueprints'),
        'templates' => resource_path('vendor/blueprintx/templates'),
        'output' => base_path(),
        'api' => 'app/Http/Controllers/Api',
        'api_requests' => 'app/Http/Requests/Api',
        'api_resources' => 'app/Http/Resources',
    'postman' => 'docs/postman',
    ],

    'default_architecture' => 'hexagonal',

    'architectures' => [
        'hexagonal' => [
            'driver' => BlueprintX\Kernel\Drivers\HexagonalDriver::class,
        ],
    ],

    'twig' => [
        'cache' => storage_path('framework/cache/blueprintx/twig'),
        'debug' => false,
        'auto_reload' => true,
    ],

    'features' => [
        'api' => [
            'form_requests' => [
                'enabled' => true,
                'namespace' => 'App\\Http\\Requests\\Api',
                'path' => 'app/Http/Requests/Api',
                'authorize_by_default' => true,
            ],
            'resources' => [
                'enabled' => true,
                'namespace' => 'App\\Http\\Resources',
                'path' => 'app/Http/Resources',
                'preserve_query' => true,
            ],
            'controller_traits' => [
                'handles_domain_exceptions' => 'BlueprintX\\Support\\Http\\Controllers\\Concerns\\HandlesDomainExceptions',
                'formats_pagination' => 'BlueprintX\\Support\\Http\\Controllers\\Concerns\\FormatsPagination',
            ],
            'optimistic_locking' => [
                'enabled' => env('BLUEPRINTX_API_OPTIMISTIC_LOCKING', true),
                'header' => env('BLUEPRINTX_API_OPTIMISTIC_LOCK_HEADER', 'If-Match'),
                'response_header' => env('BLUEPRINTX_API_OPTIMISTIC_LOCK_RESPONSE_HEADER', 'ETag'),
                'timestamp_column' => env('BLUEPRINTX_API_OPTIMISTIC_LOCK_TIMESTAMP', 'updated_at'),
                'version_field' => env('BLUEPRINTX_API_OPTIMISTIC_LOCK_FIELD', 'version'),
                'require_header' => env('BLUEPRINTX_API_OPTIMISTIC_LOCK_REQUIRE_HEADER', true),
                'allow_wildcard' => env('BLUEPRINTX_API_OPTIMISTIC_LOCK_ALLOW_WILDCARD', true),
            ],
        ],
        'openapi' => [
            'enabled' => false,
            'validate' => true,
            'validation_mode' => env('BLUEPRINTX_OPENAPI_VALIDATION_MODE', 'official'),
            'schema_path' => env('BLUEPRINTX_OPENAPI_SCHEMA'),
        ],
        'postman' => [
            'enabled' => env('BLUEPRINTX_POSTMAN_ENABLED', false),
            'base_url' => env('BLUEPRINTX_POSTMAN_BASE_URL', 'http://localhost/api'),
        ],
    ],

    'generators' => [
        'domain' => BlueprintX\Generators\DomainLayerGenerator::class,
        'application' => BlueprintX\Generators\ApplicationLayerGenerator::class,
        'infrastructure' => BlueprintX\Generators\InfrastructureLayerGenerator::class,
        'api' => BlueprintX\Generators\ApiLayerGenerator::class,
        'database' => BlueprintX\Generators\DatabaseLayerGenerator::class,
        'tests' => BlueprintX\Generators\TestsLayerGenerator::class,
        'docs' => BlueprintX\Generators\DocsLayerGenerator::class,
        'postman' => BlueprintX\Generators\PostmanLayerGenerator::class,
    ],
];
