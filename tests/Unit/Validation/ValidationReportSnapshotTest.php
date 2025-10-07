<?php

namespace BlueprintX\Tests\Unit\Validation;

use BlueprintX\Tests\Concerns\InteractsWithSnapshots;
use BlueprintX\Tests\Support\BlueprintFactory;
use BlueprintX\Validation\BlueprintSchemaValidator;
use BlueprintX\Validation\DefaultBlueprintValidator;
use BlueprintX\Validation\SemanticBlueprintValidator;
use PHPUnit\Framework\TestCase;

class ValidationReportSnapshotTest extends TestCase
{
    use InteractsWithSnapshots;

    public function test_validation_report_matches_snapshot(): void
    {
        $validator = new DefaultBlueprintValidator(
            new BlueprintSchemaValidator($this->schemaPath(), ['hexagonal']),
            new SemanticBlueprintValidator()
        );

        $blueprint = BlueprintFactory::employeeWithValidationIssues();

        $result = $validator->validate($blueprint);

        $this->assertFalse($result->isValid());

        $report = json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $this->assertNotFalse($report);

        $expected = rtrim($this->snapshot('validation/employee_validation_report.snap'));
        $actual = rtrim($this->normalizeNewLines($report));

        $this->assertSame($expected, $actual);
    }

    private function schemaPath(): string
    {
        return dirname(__DIR__, 3) . '/resources/schema/blueprint.schema.json';
    }
}
