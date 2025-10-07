<?php

namespace BlueprintX\Tests\Unit;

use BlueprintX\Blueprint\Blueprint;
use BlueprintX\Generators\DatabaseLayerGenerator;
use BlueprintX\Kernel\Drivers\HexagonalDriver;
use BlueprintX\Kernel\TemplateEngine;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

class DatabaseLayerGeneratorTest extends TestCase
{
    private ?Container $previousApp = null;

    private ?string $storagePath = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousApp = Container::getInstance();
        $this->storagePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'blueprintx_storage_' . bin2hex(random_bytes(6));

        if (! is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0777, true);
        }

        $app = new class($this->storagePath) extends Container {
            public function __construct(private readonly string $storagePath)
            {
            }

            public function storagePath($path = ''): string
            {
                $relative = ltrim((string) $path, '\\/');

                return $relative === ''
                    ? $this->storagePath
                    : $this->storagePath . DIRECTORY_SEPARATOR . $relative;
            }

            public function basePath($path = ''): string
            {
                $base = dirname($this->storagePath);
                $relative = ltrim((string) $path, '\\/');

                return $relative === '' ? $base : $base . DIRECTORY_SEPARATOR . $relative;
            }
        };

        Container::setInstance($app);
    }

    protected function tearDown(): void
    {
        if ($this->storagePath !== null && is_dir($this->storagePath)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->storagePath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $file) {
                if ($file->isDir()) {
                    @rmdir($file->getPathname());
                } else {
                    @unlink($file->getPathname());
                }
            }

            @rmdir($this->storagePath);
        }

        if ($this->previousApp instanceof Container) {
            Container::setInstance($this->previousApp);
        } else {
            Container::setInstance(null);
        }

        $this->storagePath = null;
        $this->previousApp = null;

        parent::tearDown();
    }

    public function test_it_generates_migration_and_factory_files(): void
    {
        $engine = $this->makeTemplateEngine();
        $driver = $this->makeHexagonalDriver();
        $this->registerDriverNamespaces($engine, $driver);

        $generator = new DatabaseLayerGenerator($engine);
        $blueprint = $this->makeBlueprint('Product');

    $result = $generator->generate($blueprint, $driver);

    $this->assertGreaterThanOrEqual(2, $result->files());

        $migration = $this->findMigrationFile($result->files(), 'products');
        $factory = $this->findFileBySuffix($result->files(), 'database/factories/Domain/Models/ProductFactory.php');

        $this->assertNotNull($migration, 'Se esperaba una migración generada.');
        $this->assertNotNull($factory, 'Se esperaba una factory generada.');

        $this->assertMatchesRegularExpression(
            '/database\\/migrations\\/\d{4}_\d{2}_\d{2}_\d{6}_create_products_table\\.php$/',
            $migration->path
        );

        $this->assertStringContainsString("Schema::create('products'", $migration->contents);
        $this->assertStringContainsString("\$table->string('name', 120)->unique();", $migration->contents);
        $this->assertStringContainsString("\$table->decimal('price', 10, 2);", $migration->contents);
        $this->assertStringContainsString("\$table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();", $migration->contents);
        $this->assertStringContainsString('$table->softDeletes();', $migration->contents);

        $this->assertStringContainsString('class ProductFactory extends Factory', $factory->contents);
        $this->assertStringContainsString("'name' => fake()->words(3, true)", $factory->contents);
        $this->assertStringContainsString("'category_id' => Category::factory()", $factory->contents);
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

    private function registerDriverNamespaces(TemplateEngine $engine, HexagonalDriver $driver): void
    {
        foreach ($driver->templateNamespaces() as $namespace => $paths) {
            $engine->registerNamespace($namespace, $paths);
        }
    }

    private function makeBlueprint(string $entity): Blueprint
    {
        return Blueprint::fromArray([
            'path' => 'blueprints/' . strtolower($entity) . '.yaml',
            'module' => null,
            'entity' => $entity,
            'table' => strtolower($entity) . 's',
            'architecture' => 'hexagonal',
            'fields' => [
                ['name' => 'name', 'type' => 'string', 'rules' => 'required|max:120|unique:products,name'],
                ['name' => 'price', 'type' => 'decimal', 'precision' => 10, 'scale' => 2, 'rules' => 'required|numeric|min:0'],
                ['name' => 'active', 'type' => 'boolean', 'default' => true],
                ['name' => 'category_id', 'type' => 'integer', 'rules' => 'required|exists:categories,id'],
            ],
            'relations' => [
                ['type' => 'belongsTo', 'target' => 'Category', 'field' => 'category_id', 'rules' => 'required|exists:categories,id'],
            ],
            'options' => [
                'timestamps' => true,
                'softDeletes' => true,
            ],
            'api' => [
                'base_path' => '/api/products',
                'middleware' => [],
                'endpoints' => [],
            ],
                'docs' => [],
                'errors' => [],
            'metadata' => [],
        ]);
    }

    private function findFileBySuffix(array $files, string $suffix): ?object
    {
        foreach ($files as $file) {
            if (str_ends_with($file->path, $suffix)) {
                return $file;
            }
        }

        return null;
    }

    private function findMigrationFile(array $files, string $table): ?object
    {
        $pattern = sprintf(
            '/database\\/migrations\\/(\d{4}_\d{2}_\d{2}_\d{6})_create_%s_table\\.php$/',
            preg_quote($table, '/')
        );

        foreach ($files as $file) {
            if (preg_match($pattern, $file->path)) {
                return $file;
            }
        }

        return null;
    }
}
