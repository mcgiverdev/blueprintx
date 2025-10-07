<?php

namespace BlueprintX\Tests\Unit\Validation;

use BlueprintX\Blueprint\Blueprint;
use BlueprintX\Validation\BlueprintSchemaValidator;
use BlueprintX\Validation\DefaultBlueprintValidator;
use BlueprintX\Validation\SemanticBlueprintValidator;
use BlueprintX\Validation\ValidationMessage;
use PHPUnit\Framework\TestCase;

class DefaultBlueprintValidatorTest extends TestCase
{
    public function test_schema_errors_short_circuit_semantic_validation(): void
    {
        $schema = new BlueprintSchemaValidator($this->schemaPath(), ['hexagonal']);
        $semantic = new class extends SemanticBlueprintValidator {
            public bool $called = false;

            public function validate(\BlueprintX\Blueprint\Blueprint $blueprint): array
            {
                $this->called = true;

                return ['errors' => [], 'warnings' => []];
            }
        };

        $validator = new DefaultBlueprintValidator($schema, $semantic);

        $blueprint = Blueprint::fromArray([
            'path' => 'blueprints/catalog/product.yaml',
            'module' => 'catalog',
            'entity' => 'product',
            'table' => 'products',
            'architecture' => 'hexagonal',
            'fields' => [
                ['name' => 'price', 'type' => 'decimal'],
            ],
            'relations' => [],
            'options' => [],
            'api' => [
                'base_path' => '/catalog/products',
                'middleware' => [],
                'endpoints' => [],
            ],
            'docs' => [],
            'errors' => [],
            'metadata' => [],
        ]);

        $result = $validator->validate($blueprint);

        $this->assertFalse($result->isValid());
        $this->assertNotEmpty($result->errors());
        $this->assertSame([], $result->warnings());
        $this->assertSame('schema.invalid', $result->errors()[0]->code);
        $this->assertFalse($semantic->called, 'Semantic validator should not run when schema fails.');
    }

    public function test_successful_schema_runs_semantic_validation(): void
    {
        $schema = new BlueprintSchemaValidator($this->schemaPath(), ['hexagonal']);
        $semantic = new class extends SemanticBlueprintValidator {
            public int $calls = 0;

            public function validate(\BlueprintX\Blueprint\Blueprint $blueprint): array
            {
                $this->calls++;

                return [
                    'errors' => [],
                    'warnings' => [new ValidationMessage('semantic.warning', 'Se recomienda definir endpoints CRUD', 'api')],
                ];
            }
        };

        $validator = new DefaultBlueprintValidator($schema, $semantic);

        $blueprint = Blueprint::fromArray([
            'path' => 'blueprints/catalog/product.yaml',
            'module' => 'catalog',
            'entity' => 'Product',
            'table' => 'products',
            'architecture' => 'hexagonal',
            'fields' => [
                ['name' => 'price', 'type' => 'decimal', 'precision' => 12, 'scale' => 2],
            ],
            'relations' => [],
            'options' => [],
            'api' => [
                'base_path' => '/catalog/products',
                'middleware' => [],
                'endpoints' => [],
            ],
            'docs' => [],
            'errors' => [],
            'metadata' => [],
        ]);

        $result = $validator->validate($blueprint);

        $this->assertTrue($result->isValid());
        $this->assertSame([], $result->errors());
        $this->assertCount(1, $result->warnings());
        $this->assertSame('semantic.warning', $result->warnings()[0]->code);
        $this->assertSame(1, $semantic->calls);
    }

    private function schemaPath(): string
    {
        return dirname(__DIR__, 3) . '/resources/schema/blueprint.schema.json';
    }
}
