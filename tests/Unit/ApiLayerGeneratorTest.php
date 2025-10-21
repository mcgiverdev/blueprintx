<?php

namespace BlueprintX\Tests\Unit;

use BlueprintX\Blueprint\Blueprint;
use BlueprintX\Contracts\ArchitectureDriver;
use BlueprintX\Generators\ApiLayerGenerator;
use BlueprintX\Kernel\Drivers\HexagonalDriver;
use BlueprintX\Kernel\TemplateEngine;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

class ApiLayerGeneratorTest extends TestCase
{
    private ?Container $previousContainer = null;

    private ?string $tempBasePath = null;

    private ?string $routesPath = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->previousContainer = Container::getInstance();

        $this->tempBasePath = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . 'blueprintx_api_' . bin2hex(random_bytes(6));
        $routesDirectory = $this->tempBasePath . DIRECTORY_SEPARATOR . 'routes';
        $this->routesPath = $routesDirectory . DIRECTORY_SEPARATOR . 'api.php';

        if (! is_dir($routesDirectory)) {
            mkdir($routesDirectory, 0777, true);
        }

        file_put_contents(
            $this->routesPath,
            <<<'PHP'
<?php

use Illuminate\Support\Facades\Route;

Route::prefix('api')->group(function (): void {
});

PHP
        );

        $app = new class($this->tempBasePath) extends Container {
            public function __construct(private readonly string $basePath)
            {
            }

            public function basePath($path = ''): string
            {
                return $this->join($this->basePath, $path);
            }

            public function runningUnitTests(): bool
            {
                return true;
            }

            public function environment(...$environments): mixed
            {
                if ($environments === []) {
                    return 'testing';
                }

                foreach ($environments as $environment) {
                    if ($environment === 'testing') {
                        return true;
                    }
                }

                return false;
            }

            public function storagePath($path = ''): string
            {
                return $this->join($this->basePath, 'storage' . ($path !== '' ? DIRECTORY_SEPARATOR . ltrim((string) $path, '\\/') : ''));
            }

            private function join(string $base, string $path): string
            {
                $base = rtrim($base, '\\/');
                $append = ltrim((string) $path, '\\/');

                return $append === '' ? $base : $base . DIRECTORY_SEPARATOR . $append;
            }
        };

        Container::setInstance($app);
    }

    protected function tearDown(): void
    {
        if ($this->previousContainer instanceof Container || $this->previousContainer === null) {
            Container::setInstance($this->previousContainer);
        }

        if (is_string($this->tempBasePath) && is_dir($this->tempBasePath)) {
            $this->deleteDirectory($this->tempBasePath);
        }

        $this->routesPath = null;
        $this->tempBasePath = null;
        $this->previousContainer = null;

        parent::tearDown();
    }

    public function test_it_generates_controller_file(): void
    {
        $engine = $this->makeTemplateEngine();
        $driver = $this->makeHexagonalDriver();
        $this->registerDriverNamespaces($engine, $driver);

        $generator = new ApiLayerGenerator($engine);
        $blueprint = $this->makeBlueprint('User');

        $result = $generator->generate($blueprint, $driver);

        $files = $result->files();
        $this->assertCount(4, $files);

        $paths = array_map(static fn ($file): string => $file->path, $files);

        $this->assertContains('app/Http/Controllers/Api/UserController.php', $paths);
        $this->assertContains('app/Http/Resources/UserResource.php', $paths);
        $this->assertContains('app/Http/Resources/UserCollection.php', $paths);
        $this->assertContains('routes/api.php', $paths);

        $controller = $this->findFileByPath($files, 'app/Http/Controllers/Api/UserController.php');
        $this->assertNotNull($controller);
        $this->assertStringContainsString('namespace App\\Http\\Controllers\\Api;', $controller->contents);
        $this->assertStringContainsString('class UserController', $controller->contents);
        $this->assertStringContainsString('UserCollection', $controller->contents);
        $this->assertStringContainsString('UserResource', $controller->contents);
        $this->assertStringContainsString('HandlesOptimisticLocking', $controller->contents);
        $this->assertStringContainsString('protected array $optimisticLocking = [', $controller->contents);
        $this->assertStringContainsString('$this->respondWithResourceVersion($response, $resource);', $controller->contents);
        $this->assertStringContainsString('$this->ensureCurrentVersion($request, $resource);', $controller->contents);
        $this->assertStringContainsString('function destroy(int $id, Request $request,', $controller->contents);

        $resource = $this->findFileByPath($files, 'app/Http/Resources/UserResource.php');
        $this->assertNotNull($resource);
        $this->assertStringContainsString('class UserResource extends JsonResource', $resource->contents);

        $collection = $this->findFileByPath($files, 'app/Http/Resources/UserCollection.php');
        $this->assertNotNull($collection);
            $this->assertStringContainsString('class UserCollection extends ResourceCollection', $collection->contents);
            $this->assertStringContainsString('public $collects = UserResource::class;', $collection->contents);

        $routeFile = $this->findFileByPath($files, 'routes/api.php');
            $this->assertNotNull($routeFile);
            $this->assertStringContainsString("Route::apiResource('api'", $routeFile->contents);

        $this->assertCount(0, $result->warnings());
    }

    public function test_it_skips_optimistic_locking_when_strategy_unavailable(): void
    {
        $engine = $this->makeTemplateEngine();
        $driver = $this->makeHexagonalDriver();
        $this->registerDriverNamespaces($engine, $driver);

        $generator = new ApiLayerGenerator($engine);
        $blueprint = $this->makeBlueprint('Audit', null, '/audit', [
            'timestamps' => false,
            'versioned' => false,
        ]);

        $result = $generator->generate($blueprint, $driver);

        $files = $result->files();
        $controller = $this->findFileByPath($files, 'app/Http/Controllers/Api/AuditController.php');
        $this->assertNotNull($controller);
        $this->assertStringNotContainsString('HandlesOptimisticLocking', $controller->contents);
        $this->assertStringNotContainsString('$this->respondWithResourceVersion', $controller->contents);
        $this->assertStringNotContainsString('$this->ensureCurrentVersion', $controller->contents);
        $this->assertStringNotContainsString('protected array $optimisticLocking', $controller->contents);
    }

    public function test_it_infers_route_from_blueprint_when_missing(): void
    {
        $engine = $this->makeTemplateEngine();
        $driver = $this->makeHexagonalDriver();
        $this->registerDriverNamespaces($engine, $driver);

        $generator = new ApiLayerGenerator($engine);
        $blueprint = $this->makeBlueprint('Invoice', null, null);

        $result = $generator->generate($blueprint, $driver);

    $files = $result->files();
    $this->assertCount(4, $files);

        $controller = $this->findFileByPath($files, 'app/Http/Controllers/Api/InvoiceController.php');
        $this->assertNotNull($controller);
        $this->assertStringContainsString('InvoiceController', $controller->contents);
        $this->assertNotNull($this->findFileByPath($files, 'routes/api.php'));
        $this->assertCount(0, $result->warnings());
    }

    public function test_it_respects_custom_paths_and_namespaces(): void
    {
        $engine = $this->makeTemplateEngine();
        $driver = $this->makeHexagonalDriver();
        $this->registerDriverNamespaces($engine, $driver);

        $generator = new ApiLayerGenerator($engine);
        $blueprint = $this->makeBlueprint('Order', 'sales', '/sales/orders');

        $options = [
            'paths' => ['api' => 'app/Modules'],
            'namespaces' => ['api' => 'App\\Modules'],
        ];

        $result = $generator->generate($blueprint, $driver, $options);

        $files = $result->files();
        $controller = $this->findFileByPath($files, 'app/Modules/Sales/OrderController.php');
        $this->assertNotNull($controller);
        $this->assertStringContainsString('namespace App\\Modules\\Sales;', $controller->contents);

        $resource = $this->findFileByPath($files, 'app/Http/Resources/Sales/OrderResource.php');
        $this->assertNotNull($resource);
        $this->assertStringContainsString('namespace App\\Http\\Resources\\Sales;', $resource->contents);

        $collection = $this->findFileByPath($files, 'app/Http/Resources/Sales/OrderCollection.php');
        $this->assertNotNull($collection);
        $this->assertStringContainsString('OrderCollection extends ResourceCollection', $collection->contents);
        $this->assertStringContainsString('public $collects = OrderResource::class;', $collection->contents);

        $routeFile = $this->findFileByPath($files, 'routes/api.php');
        $this->assertNotNull($routeFile);
        $this->assertStringContainsString('use App\\Modules\\Sales\\OrderController;', $routeFile->contents);
        $this->assertStringContainsString("Route::apiResource('sales/orders'", $routeFile->contents);
    }

    public function test_it_generates_form_requests_when_enabled(): void
    {
        $engine = $this->makeTemplateEngine();
        $driver = $this->makeHexagonalDriver();
        $this->registerDriverNamespaces($engine, $driver);

        $generator = new ApiLayerGenerator($engine, [
            'enabled' => true,
            'namespace' => 'App\\Http\\Requests\\Api',
            'path' => 'app/Http/Requests/Api',
        ]);

        $blueprint = $this->makeBlueprint('Employee', 'hr', '/hr/employees');

        $result = $generator->generate($blueprint, $driver);

    $files = $result->files();
    $this->assertCount(6, $files);

        $paths = array_map(static fn ($file): string => $file->path, $files);

        $this->assertContains('app/Http/Controllers/Api/Hr/EmployeeController.php', $paths);
        $this->assertContains('app/Http/Resources/Hr/EmployeeResource.php', $paths);
        $this->assertContains('app/Http/Resources/Hr/EmployeeCollection.php', $paths);
        $this->assertContains('app/Http/Requests/Api/Hr/StoreEmployeeRequest.php', $paths);
        $this->assertContains('app/Http/Requests/Api/Hr/UpdateEmployeeRequest.php', $paths);
    $this->assertContains('routes/api.php', $paths);

        $controller = $this->findFileByPath($files, 'app/Http/Controllers/Api/Hr/EmployeeController.php');
        $this->assertNotNull($controller);
        $this->assertStringContainsString('use App\\Http\\Requests\\Api\\Hr\\StoreEmployeeRequest;', $controller->contents);
        $this->assertStringContainsString('use App\\Http\\Resources\\Hr\\EmployeeResource;', $controller->contents);
        $this->assertStringContainsString('use App\\Http\\Resources\\Hr\\EmployeeCollection;', $controller->contents);
        $this->assertStringContainsString('EmployeeCollection::make($result);', $controller->contents);
        $this->assertStringContainsString('EmployeeResource::make($resource)', $controller->contents);
        $this->assertStringContainsString('->preserveQuery();', $controller->contents);

        $resource = $this->findFileByPath($files, 'app/Http/Resources/Hr/EmployeeResource.php');
        $this->assertNotNull($resource);
        $this->assertStringContainsString('class EmployeeResource extends JsonResource', $resource->contents);

        $collection = $this->findFileByPath($files, 'app/Http/Resources/Hr/EmployeeCollection.php');
        $this->assertNotNull($collection);
        $this->assertStringContainsString('class EmployeeCollection extends ResourceCollection', $collection->contents);
        $this->assertStringContainsString('public $collects = EmployeeResource::class;', $collection->contents);

        $storeRequest = $this->findFileByPath($files, 'app/Http/Requests/Api/Hr/StoreEmployeeRequest.php');
        $this->assertNotNull($storeRequest);
        $this->assertStringContainsString('class StoreEmployeeRequest extends FormRequest', $storeRequest->contents);
        $this->assertStringContainsString("'name' => ['required', 'string']", $storeRequest->contents);

        $updateRequest = $this->findFileByPath($files, 'app/Http/Requests/Api/Hr/UpdateEmployeeRequest.php');
        $this->assertNotNull($updateRequest);
        $this->assertStringContainsString('class UpdateEmployeeRequest extends FormRequest', $updateRequest->contents);
        $this->assertStringContainsString("'sometimes'", $updateRequest->contents);

        $routeFile = $this->findFileByPath($files, 'routes/api.php');
        $this->assertNotNull($routeFile);
        $this->assertStringContainsString("Route::apiResource('hr/employees'", $routeFile->contents);
    }

    public function test_it_embeds_configured_relations(): void
    {
        $engine = $this->makeTemplateEngine();
        $driver = $this->makeHexagonalDriver();
        $this->registerDriverNamespaces($engine, $driver);

        $generator = new ApiLayerGenerator($engine);
        $blueprint = Blueprint::fromArray([
            'path' => 'blueprints/hr/employee.yaml',
            'module' => 'hr',
            'entity' => 'Employee',
            'table' => 'employees',
            'architecture' => 'hexagonal',
            'fields' => [
                ['name' => 'id', 'type' => 'uuid'],
                ['name' => 'department_id', 'type' => 'uuid'],
                ['name' => 'first_name', 'type' => 'string'],
            ],
            'relations' => [
                ['type' => 'belongsTo', 'target' => 'Department', 'field' => 'department_id'],
                ['type' => 'hasMany', 'target' => 'Project', 'field' => 'employee_id'],
            ],
            'options' => [],
            'api' => [
                'base_path' => '/hr/employees',
                'middleware' => [],
                'endpoints' => [
                    ['type' => 'crud'],
                ],
                'resources' => [
                    'includes' => [
                        ['relation' => 'department', 'alias' => 'department'],
                        'projects',
                    ],
                ],
            ],
            'docs' => [],
            'errors' => [],
            'metadata' => [],
        ]);

        $result = $generator->generate($blueprint, $driver);

        $controller = $this->findFileByPath($result->files(), 'app/Http/Controllers/Api/Hr/EmployeeController.php');
        $this->assertNotNull($controller);
        $this->assertStringContainsString("'with' => ['department', 'projects']", $controller->contents);
    $this->assertStringContainsString('$resource->load([\'department\', \'projects\']);', $controller->contents);
    $this->assertStringContainsString('$updated->load([\'department\', \'projects\']);', $controller->contents);

        $resource = $this->findFileByPath($result->files(), 'app/Http/Resources/Hr/EmployeeResource.php');
        $this->assertNotNull($resource);
        $this->assertStringContainsString('use App\\Http\\Resources\\Hr\\DepartmentResource;', $resource->contents);
        $this->assertStringContainsString("'department' => DepartmentResource::make(", $resource->contents);
        $this->assertStringContainsString("whenLoaded('department')", $resource->contents);
        $this->assertStringContainsString('use App\\Http\\Resources\\Hr\\ProjectCollection;', $resource->contents);
        $this->assertStringContainsString("'projects' => ProjectCollection::make(", $resource->contents);
        $this->assertStringContainsString("whenLoaded('projects')", $resource->contents);

        $this->assertCount(0, $result->warnings());
    }

    public function test_it_warns_when_template_missing(): void
    {
        $engine = $this->makeTemplateEngine();
        $driver = new class implements ArchitectureDriver {
            public function name(): string
            {
                return 'custom';
            }

            public function layers(): array
            {
                return ['api'];
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

        $generator = new ApiLayerGenerator($engine);
        $blueprint = $this->makeBlueprint('User');

        $result = $generator->generate($blueprint, $driver);

        $this->assertCount(0, $result->files());
        $this->assertNotEmpty($result->warnings());
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

    /**
     * @param array<int, object> $files
     */
    private function findFileByPath(array $files, string $path): ?object
    {
        foreach ($files as $file) {
            if ($file->path === $path) {
                return $file;
            }
        }

        return null;
    }

    private function deleteDirectory(string $directory): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($directory);
    }

    private function makeBlueprint(string $entity, ?string $module = null, ?string $apiBasePath = '/api', array $options = []): Blueprint
    {
        return Blueprint::fromArray([
            'path' => 'blueprints/' . strtolower($entity) . '.yaml',
            'module' => $module,
            'entity' => $entity,
            'table' => strtolower($entity) . 's',
            'architecture' => 'hexagonal',
            'fields' => [
                ['name' => 'name', 'type' => 'string', 'rules' => 'required|string'],
            ],
            'relations' => [],
            'options' => $options,
            'api' => [
                'base_path' => $apiBasePath,
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
