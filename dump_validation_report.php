<?php

require __DIR__ . '/../../vendor/autoload.php';

$validator = new BlueprintX\Validation\DefaultBlueprintValidator(
    new BlueprintX\Validation\BlueprintSchemaValidator(__DIR__ . '/resources/schema/blueprint.schema.json', ['hexagonal']),
    new BlueprintX\Validation\SemanticBlueprintValidator()
);

$blueprint = BlueprintX\Tests\Support\BlueprintFactory::employeeWithValidationIssues();

$result = $validator->validate($blueprint);

echo json_encode($result->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
