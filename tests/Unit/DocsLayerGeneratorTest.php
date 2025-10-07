<?php

namespace BlueprintX\Tests\Unit;

use BlueprintX\Blueprint\Blueprint;
use BlueprintX\Contracts\ArchitectureDriver;
use BlueprintX\Contracts\BlueprintParser;
use BlueprintX\Docs\OpenApiDocumentBuilder;
use BlueprintX\Exceptions\BlueprintParseException;
use BlueprintX\Generators\DocsLayerGenerator;
use BlueprintX\Kernel\Drivers\HexagonalDriver;
use BlueprintX\Kernel\BlueprintLocator;
use BlueprintX\Tests\Concerns\InteractsWithSnapshots;
use BlueprintX\Tests\Support\BlueprintFactory;
use JsonSchema\Constraints\Constraint;
use JsonSchema\Validator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class DocsLayerGeneratorTest extends TestCase
{
    use InteractsWithSnapshots;

    public function test_it_generates_openapi_document_snapshot(): void
    {
    $generator = new DocsLayerGenerator($this->makeDocumentBuilder());
        $blueprint = BlueprintFactory::employee();

        $result = $generator->generate($blueprint, $this->makeHexagonalDriver());

        $this->assertCount(1, $result->files());
        $this->assertSame([], $result->warnings());
        $file = $result->files()[0];

        $this->assertSame('docs/Hr/Employee.openapi.yaml', $file->path);
        $document = Yaml::parse($file->contents);
        $this->assertIsArray($document);

        $employeeData = $document['components']['schemas']['EmployeeData'] ?? [];
        $this->assertIsArray($employeeData);

        $departmentProperty = $employeeData['properties']['department'] ?? null;
        $this->assertIsArray($departmentProperty);
        $this->assertSame(
            [['$ref' => '#/components/schemas/DepartmentData']],
            $departmentProperty['allOf'] ?? null
        );

        $departmentSchema = $document['components']['schemas']['DepartmentData'] ?? null;
        $this->assertIsArray($departmentSchema);
        $this->assertArrayHasKey('properties', $departmentSchema);
        $this->assertArrayHasKey('name', $departmentSchema['properties']);
        $this->assertArrayHasKey('active', $departmentSchema['properties']);

        $this->assertArrayHasKey('x-domain-errors', $document);
        $errorCodes = array_map(static fn (array $item): string => $item['code'] ?? '', $document['x-domain-errors']);
        $this->assertContains('domain.not_found', $errorCodes);
        $this->assertContains('domain.validation_failed', $errorCodes);

        $components = $document['components'] ?? [];
        $this->assertArrayHasKey('parameters', $components);
        $this->assertArrayHasKey('IfMatchHeader', $components['parameters']);
        $ifMatch = $components['parameters']['IfMatchHeader'];
        $this->assertSame('If-Match', $ifMatch['name']);
        $this->assertSame('header', $ifMatch['in']);

        $this->assertArrayHasKey('headers', $components);
        $this->assertArrayHasKey('ETag', $components['headers']);
        $this->assertSame('string', $components['headers']['ETag']['schema']['type'] ?? null);
    }

    public function test_it_supports_module_specific_paths(): void
    {
    $generator = new DocsLayerGenerator($this->makeDocumentBuilder());
        $blueprint = $this->makeBlueprint('Order', 'sales');

        $options = [
            'paths' => ['docs' => 'documentation'],
        ];

        $result = $generator->generate($blueprint, $this->makeHexagonalDriver(), $options);

        $file = $result->files()[0];
        $this->assertSame('documentation/Sales/Order.openapi.yaml', $file->path);
        $this->assertSame([], $result->warnings());
    }

    public function test_it_generates_document_with_empty_paths_when_no_endpoints(): void
    {
    $generator = new DocsLayerGenerator($this->makeDocumentBuilder());
        $driver = new class implements ArchitectureDriver {
            public function name(): string
            {
                return 'custom';
            }

            public function layers(): array
            {
                return [];
            }

            public function templateNamespaces(): array
            {
                return [];
            }

            public function metadata(): array
            {
                return [];
            }
        };

        $blueprint = $this->makeBlueprint('Simple');

        $result = $generator->generate($blueprint, $driver);

        $file = $result->files()[0];

        $this->assertSame('docs/Simple.openapi.yaml', $file->path);

        $document = Yaml::parse($file->contents);
        $this->assertIsArray($document);
        $this->assertArrayHasKey('paths', $document);
        $this->assertSame([], $document['paths']);
    }

    public function test_it_can_be_disabled_via_option(): void
    {
    $generator = new DocsLayerGenerator($this->makeDocumentBuilder());
        $blueprint = $this->makeBlueprint('Simple');

        $result = $generator->generate($blueprint, $this->makeHexagonalDriver(), ['with_openapi' => false]);

        $this->assertCount(0, $result->files());
        $this->assertSame([], $result->warnings());
    }

    public function test_builder_output_validates_against_minimal_schema(): void
    {
    $builder = $this->makeDocumentBuilder();
        $document = $builder->build(BlueprintFactory::employee());

        $documentObject = json_decode(json_encode($document, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);

        $schemaPath = dirname(__DIR__, 2) . '/resources/schema/openapi-minimal.schema.json';
        $schemaObject = json_decode(file_get_contents($schemaPath), false, 512, JSON_THROW_ON_ERROR);

        $validator = new Validator();
        $validator->validate($documentObject, $schemaObject, Constraint::CHECK_MODE_APPLY_DEFAULTS);

        if (! $validator->isValid()) {
            $messages = array_map(
                static fn (array $error): string => sprintf('%s: %s', $error['property'], $error['message']),
                $validator->getErrors()
            );

            $this->fail('OpenAPI document validation errors: ' . implode('; ', $messages));
        }

        $this->assertTrue(true);
    }

    public function test_it_emits_warnings_when_validation_fails(): void
    {
        $invalidBuilder = new class extends OpenApiDocumentBuilder {
            public function build(Blueprint $blueprint): array
            {
                return ['openapi' => '3.1.0'];
            }
        };

        $generator = new DocsLayerGenerator($invalidBuilder, true, true, null, 'official');

        $result = $generator->generate($this->makeBlueprint('Faulty'), $this->makeHexagonalDriver());

        $this->assertCount(1, $result->files());
        $this->assertNotEmpty($result->warnings());
        $this->assertStringContainsString('info', implode(' ', $result->warnings()));
    }

    public function test_it_skips_validation_when_disabled(): void
    {
        $invalidBuilder = new class extends OpenApiDocumentBuilder {
            public function build(Blueprint $blueprint): array
            {
                return ['openapi' => '3.1.0'];
            }
        };

        $generator = new DocsLayerGenerator($invalidBuilder, true, false);

        $result = $generator->generate($this->makeBlueprint('NoValidation'), $this->makeHexagonalDriver());

        $this->assertCount(1, $result->files());
        $this->assertSame([], $result->warnings());
    }

    public function test_it_uses_json_schema_mode_when_configured(): void
    {
        $schemaPath = dirname(__DIR__, 2) . '/resources/schema/openapi-minimal.schema.json';

        $invalidBuilder = new class extends OpenApiDocumentBuilder {
            public function build(Blueprint $blueprint): array
            {
                return ['openapi' => '3.1.0'];
            }
        };

        $generator = new DocsLayerGenerator($invalidBuilder, true, true, $schemaPath, 'schema');

        $result = $generator->generate($this->makeBlueprint('SchemaMode'), $this->makeHexagonalDriver());

        $this->assertCount(1, $result->files());
        $this->assertNotEmpty($result->warnings());
        $this->assertStringContainsString('required', implode(' ', $result->warnings()));
    }

    private function makeDocumentBuilder(): OpenApiDocumentBuilder
    {
        $repository = [
            'blueprints/hr/employee.yaml' => BlueprintFactory::employee(),
            'blueprints/hr/department.yaml' => BlueprintFactory::department(),
        ];

        $parser = new class($repository) implements BlueprintParser {
            public function __construct(private array $repository)
            {
            }

            public function parse(string $path): Blueprint
            {
                if (! isset($this->repository[$path])) {
                    throw new BlueprintParseException(sprintf('Blueprint not found: %s', $path));
                }

                return $this->repository[$path];
            }

            public function parseMany(iterable $paths): array
            {
                $result = [];

                foreach ($paths as $path) {
                    $result[] = $this->parse($path);
                }

                return $result;
            }
        };

        $locator = new class($repository) extends BlueprintLocator {
            public function __construct(private array $repository)
            {
            }

            public function discover(string $basePath, ?string $module = null, ?string $entity = null): array
            {
                if ($entity === null) {
                    return [];
                }

                $normalizedEntity = strtolower(trim($entity));

                foreach (array_keys($this->repository) as $path) {
                    $filename = pathinfo($path, PATHINFO_FILENAME);

                    if (strtolower($filename) === $normalizedEntity) {
                        return [$path];
                    }
                }

                return [];
            }
        };

        return new OpenApiDocumentBuilder($parser, $locator);
    }

    private function makeHexagonalDriver(): HexagonalDriver
    {
        $basePath = dirname(__DIR__, 2) . '/resources/templates/hexagonal';

        return new HexagonalDriver([
            'package_path' => $basePath,
            'override_path' => $basePath,
        ]);
    }

    private function makeBlueprint(string $entity, ?string $module = null): Blueprint
    {
        return Blueprint::fromArray([
            'path' => 'blueprints/' . strtolower($entity) . '.yaml',
            'module' => $module,
            'entity' => $entity,
            'table' => strtolower($entity) . 's',
            'architecture' => 'hexagonal',
            'fields' => [
                ['name' => 'name', 'type' => 'string'],
            ],
            'relations' => [],
            'options' => [],
            'api' => [
                'base_path' => '/api/' . strtolower($entity) . 's',
                'middleware' => [],
                'endpoints' => [],
                'resources' => ['includes' => []],
            ],
            'docs' => [],
            'errors' => [],
            'metadata' => [],
        ]);
    }
}
