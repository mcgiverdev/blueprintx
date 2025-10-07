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

    /**
     * @param ValidationMessage[] $messages
     * @return array<int, string>
     */
    private function codes(array $messages): array
    {
        return array_map(static fn (ValidationMessage $message): string => $message->code, $messages);
    }
}
