<?php

namespace BlueprintX\Tests\Unit\Validation;

use BlueprintX\Blueprint\Blueprint;
use BlueprintX\Validation\SemanticBlueprintValidator;
use BlueprintX\Validation\ValidationMessage;
use PHPUnit\Framework\TestCase;

class SemanticBlueprintValidatorTest extends TestCase
{
    public function test_detects_semantic_issues_and_warnings(): void
    {
        $blueprint = Blueprint::fromArray([
            'path' => 'blueprints/hr/employee.yaml',
            'module' => 'hr',
            'entity' => 'Employee',
            'table' => 'employees_custom',
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
                'versioned' => true,
            ],
            'api' => [
                'base_path' => '/hr/employees',
                'middleware' => [],
                'endpoints' => [
                    ['type' => 'patch', 'field' => 'active'],
                    ['type' => 'patch', 'field' => 'active'],
                    ['type' => 'search', 'fields' => ['email', 'last_name']],
                    ['type' => 'stats', 'by' => 'status'],
                ],
            ],
            'docs' => [
                'examples' => [
                    'create' => [
                        'email' => 'john@example.com',
                        'unknown_field' => 'value',
                    ],
                ],
            ],
            'errors' => [],
            'metadata' => [],
        ]);

        $validator = new SemanticBlueprintValidator();
        $result = $validator->validate($blueprint);

        $errors = $this->codes($result['errors']);
        $warnings = $this->codes($result['warnings']);

        $this->assertContains('fields.duplicate', $errors);
        $this->assertContains('relations.missing_field', $errors);
        $this->assertContains('endpoints.patch.unknown_field', $errors);
        $this->assertContains('endpoints.duplicate', $errors);
        $this->assertContains('options.versioned.missing_field', $errors);

        $this->assertContains('naming.mismatch', $warnings);
        $this->assertContains('endpoints.search.unknown_field', $warnings);
        $this->assertContains('endpoints.stats.unknown_by', $warnings);
        $this->assertContains('docs.invalid_example', $warnings);
    }

    public function test_tenancy_requires_mode_when_overriding_options(): void
    {
        $blueprint = Blueprint::fromArray([
            'path' => 'blueprints/tenant/hr/employee.yaml',
            'module' => 'tenant/hr',
            'entity' => 'Employee',
            'table' => 'employees',
            'architecture' => 'hexagonal',
            'fields' => [
                ['name' => 'id', 'type' => 'uuid'],
            ],
            'relations' => [],
            'options' => [],
            'api' => [
                'base_path' => '/hr/employees',
                'middleware' => [],
                'endpoints' => [],
            ],
            'docs' => [],
            'errors' => [],
            'metadata' => [],
            'tenancy' => [
                'storage' => 'tenant',
            ],
        ]);

        $validator = new SemanticBlueprintValidator();
        $result = $validator->validate($blueprint);

        $this->assertContains('tenancy.mode.missing', $this->codes($result['errors']));
    }

    public function test_tenancy_detects_mismatched_mode_and_incompatible_scopes(): void
    {
        $blueprint = Blueprint::fromArray([
            'path' => 'blueprints/tenant/hr/employee.yaml',
            'module' => 'tenant/hr',
            'entity' => 'Employee',
            'table' => 'employees',
            'architecture' => 'hexagonal',
            'fields' => [
                ['name' => 'id', 'type' => 'uuid'],
            ],
            'relations' => [],
            'options' => [],
            'api' => [
                'base_path' => '/hr/employees',
                'middleware' => [],
                'endpoints' => [],
            ],
            'docs' => [],
            'errors' => [],
            'metadata' => [],
            'tenancy' => [
                'mode' => 'central',
                'storage' => 'tenant',
                'routing_scope' => 'tenant',
                'seed_scope' => 'tenant',
            ],
        ]);

        $validator = new SemanticBlueprintValidator();
        $result = $validator->validate($blueprint);

        $errorCodes = $this->codes($result['errors']);
        $warningCodes = $this->codes($result['warnings']);

        $this->assertContains('tenancy.storage.invalid', $errorCodes);
        $this->assertContains('tenancy.routing_scope.invalid', $errorCodes);
        $this->assertContains('tenancy.seed_scope.invalid', $errorCodes);
        $this->assertContains('tenancy.mode.mismatch', $warningCodes);
    }

    /**
     * @param ValidationMessage[] $messages
     * @return array<int, string>
     */
    private function codes(array $messages): array
    {
        return array_map(static fn (ValidationMessage $message): string => $message->code, $messages);
    }
}
