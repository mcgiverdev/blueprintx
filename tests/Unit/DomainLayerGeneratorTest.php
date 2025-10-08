<?php

namespace BlueprintX\Tests\Unit;

use BlueprintX\Blueprint\Blueprint;
use BlueprintX\Contracts\ArchitectureDriver;
use BlueprintX\Generators\DomainLayerGenerator;
use BlueprintX\Kernel\Drivers\HexagonalDriver;
use BlueprintX\Kernel\TemplateEngine;
use BlueprintX\Tests\Concerns\InteractsWithSnapshots;
use BlueprintX\Tests\Support\BlueprintFactory;
use PHPUnit\Framework\TestCase;

class DomainLayerGeneratorTest extends TestCase
{
    use InteractsWithSnapshots;

    public function test_it_generates_domain_entity_file(): void
    {
        $engine = $this->makeTemplateEngine();
        $driver = $this->makeHexagonalDriver();
        $this->registerDriverNamespaces($engine, $driver);

        $generator = new DomainLayerGenerator($engine);
        $blueprint = $this->makeBlueprint('User');

        $result = $generator->generate($blueprint, $driver);

        $this->assertCount(6, $result->files());

        $files = [];
        foreach ($result->files() as $file) {
            $files[$file->path] = $file->contents;
        }

        $this->assertArrayHasKey('app/Domain/Models/User.php', $files);
        $this->assertArrayHasKey('app/Domain/Repositories/UserRepositoryInterface.php', $files);
        $this->assertArrayHasKey('app/Domain/Shared/Exceptions/DomainException.php', $files);
        $this->assertArrayHasKey('app/Domain/Shared/Exceptions/DomainNotFoundException.php', $files);

        $this->assertStringContainsString('namespace App\\Domain\\Models;', $files['app/Domain/Models/User.php']);
        $this->assertStringContainsString('class User', $files['app/Domain/Models/User.php']);
        $this->assertCount(0, $result->warnings());
    }

    public function test_it_marks_uuid_identifiers_as_non_incrementing(): void
    {
        $engine = $this->makeTemplateEngine();
        $driver = $this->makeHexagonalDriver();
        $this->registerDriverNamespaces($engine, $driver);

        $generator = new DomainLayerGenerator($engine);
        $blueprint = Blueprint::fromArray([
            'path' => 'blueprints/crm/contact.yaml',
            'module' => 'crm',
            'entity' => 'Contact',
            'table' => 'contacts',
            'architecture' => 'hexagonal',
            'fields' => [
                ['name' => 'id', 'type' => 'uuid'],
                ['name' => 'tenant_id', 'type' => 'uuid', 'rules' => 'required|uuid|exists:tenants,id'],
                ['name' => 'email', 'type' => 'string', 'rules' => 'required|email|max:120'],
            ],
            'relations' => [
                ['type' => 'belongsTo', 'target' => 'Tenant', 'field' => 'tenant_id'],
            ],
            'options' => [
                'timestamps' => true,
            ],
            'api' => [
                'base_path' => '/crm/contacts',
                'middleware' => [],
                'endpoints' => [],
            ],
            'docs' => [],
            'errors' => [],
            'metadata' => [],
        ]);

        $result = $generator->generate($blueprint, $driver);

        $files = [];
        foreach ($result->files() as $file) {
            $files[$file->path] = $file->contents;
        }

        $this->assertArrayHasKey('app/Domain/Crm/Models/Contact.php', $files);

        $contents = $files['app/Domain/Crm/Models/Contact.php'];

    $this->assertStringContainsString('use Illuminate\\Database\\Eloquent\\Concerns\\HasUuids;', $contents);
        $this->assertStringContainsString('public $incrementing = false;', $contents);
        $this->assertStringContainsString("protected \$keyType = 'string';", $contents);
    $this->assertStringContainsString('use HasUuids;', $contents);
    }

    public function test_it_uses_module_to_customize_paths_and_namespaces(): void
    {
        $engine = $this->makeTemplateEngine();
        $driver = $this->makeHexagonalDriver();
        $this->registerDriverNamespaces($engine, $driver);

        $generator = new DomainLayerGenerator($engine);
        $blueprint = $this->makeBlueprint('Order', 'sales');

        $options = [
            'paths' => ['domain' => 'app/Modules'],
            'namespaces' => ['domain' => 'App\\Modules'],
        ];

        $result = $generator->generate($blueprint, $driver, $options);

        $files = [];
        foreach ($result->files() as $file) {
            $files[$file->path] = $file->contents;
        }

        $this->assertArrayHasKey('app/Modules/Sales/Models/Order.php', $files);
        $this->assertStringContainsString('namespace App\\Modules\\Sales\\Models;', $files['app/Modules/Sales/Models/Order.php']);
    }

    public function test_it_adds_warning_when_template_missing(): void
    {
        $engine = $this->makeTemplateEngine();
        $driver = new class implements ArchitectureDriver {
            public function name(): string
            {
                return 'custom';
            }

            public function layers(): array
            {
                return ['domain'];
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

        $generator = new DomainLayerGenerator($engine);
        $blueprint = $this->makeBlueprint('User');

        $result = $generator->generate($blueprint, $driver);

        $this->assertCount(0, $result->files());
        $this->assertNotEmpty($result->warnings());
    }

    public function test_generated_domain_files_match_snapshots(): void
    {
        $engine = $this->makeTemplateEngine();
        $driver = $this->makeHexagonalDriver();
        $this->registerDriverNamespaces($engine, $driver);

        $generator = new DomainLayerGenerator($engine);
        $blueprint = BlueprintFactory::employee();

        $result = $generator->generate($blueprint, $driver);

        $this->assertGreaterThanOrEqual(6, count($result->files()));

        $files = [];

        foreach ($result->files() as $file) {
            $files[$file->path] = $this->normalizeNewLines($file->contents);
        }

        $this->assertArrayHasKey('app/Domain/Hr/Models/Employee.php', $files);
        $this->assertArrayHasKey('app/Domain/Hr/Repositories/EmployeeRepositoryInterface.php', $files);
        $this->assertArrayHasKey('app/Domain/Shared/Exceptions/DomainException.php', $files);
    $this->assertArrayHasKey('app/Domain/Hr/Exceptions/InactiveException.php', $files);

        $this->assertSame($this->snapshot('employee_domain_model.snap'), $files['app/Domain/Hr/Models/Employee.php']);
        $this->assertSame($this->snapshot('employee_domain_repository_interface.snap'), $files['app/Domain/Hr/Repositories/EmployeeRepositoryInterface.php']);
    }

    public function test_it_generates_unique_method_names_for_multiple_belongs_to_relations(): void
    {
        $engine = $this->makeTemplateEngine();
        $driver = $this->makeHexagonalDriver();
        $this->registerDriverNamespaces($engine, $driver);

        $generator = new DomainLayerGenerator($engine);

        $blueprint = Blueprint::fromArray([
            'path' => 'blueprints/crm/account.yaml',
            'module' => 'crm',
            'entity' => 'Account',
            'table' => 'accounts',
            'architecture' => 'hexagonal',
            'fields' => [
                ['name' => 'id', 'type' => 'uuid'],
                ['name' => 'owner_id', 'type' => 'uuid'],
                ['name' => 'created_by', 'type' => 'uuid'],
                ['name' => 'updated_by', 'type' => 'uuid'],
            ],
            'relations' => [
                ['type' => 'belongsTo', 'target' => 'User', 'field' => 'owner_id'],
                ['type' => 'belongsTo', 'target' => 'User', 'field' => 'created_by'],
                ['type' => 'belongsTo', 'target' => 'User', 'field' => 'updated_by'],
            ],
            'options' => [],
            'api' => [
                'base_path' => '/crm/accounts',
                'middleware' => [],
                'endpoints' => [],
            ],
            'docs' => [],
            'errors' => [],
            'metadata' => [],
        ]);

        $result = $generator->generate($blueprint, $driver);

        $files = [];
        foreach ($result->files() as $file) {
            $files[$file->path] = $file->contents;
        }

        $model = $files['app/Domain/Crm/Models/Account.php'] ?? '';

        $this->assertStringContainsString('public function owner(): BelongsTo', $model);
        $this->assertStringContainsString("return \$this->belongsTo(User::class, 'owner_id');", $model);

        $this->assertStringContainsString('public function createdBy(): BelongsTo', $model);
        $this->assertStringContainsString("return \$this->belongsTo(User::class, 'created_by');", $model);

        $this->assertStringContainsString('public function updatedBy(): BelongsTo', $model);
        $this->assertStringContainsString("return \$this->belongsTo(User::class, 'updated_by');", $model);
    }

    private function makeTemplateEngine(): TemplateEngine
    {
    return new TemplateEngine([], ['debug' => false, 'cache' => false]);
    }

    private function makeHexagonalDriver(): HexagonalDriver
    {
        $basePath = dirname(__DIR__, 2) . '/resources/templates/hexagonal';

        return new HexagonalDriver([
            'package_path' => $basePath,
            'override_path' => $basePath,
        ]);
    }

    private function registerDriverNamespaces(TemplateEngine $engine, ArchitectureDriver $driver): void
    {
        foreach ($driver->templateNamespaces() as $namespace => $paths) {
            $engine->registerNamespace($namespace, $paths);
        }
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
            ],
            'docs' => [],
            'errors' => [],
            'metadata' => [],
        ]);
    }
}
