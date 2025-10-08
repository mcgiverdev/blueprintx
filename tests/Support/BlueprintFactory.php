<?php

namespace BlueprintX\Tests\Support;

use BlueprintX\Blueprint\Blueprint;

class BlueprintFactory
{
    public static function employee(): Blueprint
    {
        return Blueprint::fromArray([
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
            'errors' => [
                [
                    'key' => 'inactive',
                    'name' => 'inactive',
                    'class' => 'Inactive',
                    'exception_class' => 'InactiveException',
                    'code' => 'domain.employee.inactive',
                    'message' => 'El empleado se encuentra inactivo.',
                    'status' => 409,
                    'extends' => 'DomainException',
                    'description' => null,
                ],
            ],
            'metadata' => [],
        ]);
    }

    public static function department(): Blueprint
    {
        return Blueprint::fromArray([
            'path' => 'blueprints/hr/department.yaml',
            'module' => 'hr',
            'entity' => 'Department',
            'table' => 'departments',
            'architecture' => 'hexagonal',
            'fields' => [
                ['name' => 'id', 'type' => 'uuid'],
                ['name' => 'name', 'type' => 'string'],
                ['name' => 'active', 'type' => 'boolean'],
            ],
            'relations' => [],
            'options' => [
                'timestamps' => true,
                'softDeletes' => true,
            ],
            'api' => [
                'base_path' => '/hr/departments',
                'middleware' => [],
                'endpoints' => [
                    ['type' => 'crud'],
                ],
                'resources' => [
                    'includes' => [],
                ],
            ],
            'docs' => [],
            'errors' => [],
            'metadata' => [],
        ]);
    }

    public static function employeeWithValidationIssues(): Blueprint
    {
        return Blueprint::fromArray([
            'path' => 'blueprints/hr/employee.yaml',
            'module' => 'hr',
            'entity' => 'Employee',
            'table' => 'employee_records',
            'architecture' => 'hexagonal',
            'fields' => [
                ['name' => 'id', 'type' => 'uuid'],
                ['name' => 'email', 'type' => 'string'],
                ['name' => 'email', 'type' => 'string'],
            ],
            'relations' => [
                ['type' => 'belongsTo', 'target' => 'Department', 'field' => 'department_id'],
            ],
            'options' => [
                'timestamps' => true,
                'versioned' => true,
            ],
            'api' => [
                'base_path' => '/hr/employees',
                'middleware' => ['auth:sanctum'],
                'endpoints' => [
                    ['type' => 'crud'],
                    ['type' => 'crud'],
                    ['type' => 'patch', 'field' => null],
                    ['type' => 'search', 'fields' => ['email', 'ghost_field']],
                    ['type' => 'stats'],
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
                        'email' => 'john@example.com',
                        'ghost_field' => true,
                    ],
                ],
            ],
            'errors' => [],
            'metadata' => [],
        ]);
    }
}
