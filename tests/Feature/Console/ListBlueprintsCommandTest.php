<?php

namespace BlueprintX\Tests\Feature\Console;

use BlueprintX\Tests\TestCase;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class ListBlueprintsCommandTest extends TestCase
{
    public function test_it_lists_available_blueprints_in_table(): void
    {
        $this->putBlueprint('hr/employee.yaml', $this->employeeBlueprint());
        $this->putBlueprint('catalog/product.yaml', $this->productBlueprint());

        $this->artisan('blueprintx:list')
            ->expectsTable(
                ['Módulo', 'Entidad', 'Arquitectura', 'Ruta'],
                [
          ['catalog', 'Product', 'hexagonal', 'catalog/product.yaml'],
          ['hr', 'Employee', 'hexagonal', 'hr/employee.yaml'],
                ]
            )
            ->assertExitCode(Command::SUCCESS);
    }

    public function test_it_can_output_json_format(): void
    {
        $this->putBlueprint('billing/invoice.yaml', $this->invoiceBlueprint());

  $exitCode = Artisan::call('blueprintx:list', ['--json' => true]);

    $this->assertSame(Command::SUCCESS, $exitCode);

  $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    $this->assertIsArray($payload['blueprints'] ?? null);
    $this->assertSame([], $payload['errors'] ?? null);

    $this->assertContains(
      [
        'module' => 'billing',
        'entity' => 'Invoice',
        'architecture' => 'hexagonal',
        'path' => 'billing/invoice.yaml',
      ],
      $payload['blueprints']
    );
    }

    private function employeeBlueprint(): string
    {
        return <<<YAML
entity: Employee
module: hr
table: employees
architecture: hexagonal
fields:
  - name: id
    type: uuid
  - name: first_name
    type: string
  - name: last_name
    type: string
  - name: email
    type: string
api:
  base_path: /hr/employees
  middleware: []
  endpoints: []
relations: []
options:
  softDeletes: true
YAML;
    }

    private function productBlueprint(): string
    {
        return <<<YAML
entity: Product
module: catalog
table: products
architecture: hexagonal
fields:
  - name: id
    type: uuid
  - name: name
    type: string
  - name: price
    type: decimal
    precision: 10
    scale: 2
relations: []
api:
  base_path: /catalog/products
  middleware: []
  endpoints: []
options: []
YAML;
    }

    private function invoiceBlueprint(): string
    {
        return <<<YAML
entity: Invoice
module: billing
table: invoices
architecture: hexagonal
fields:
  - name: id
    type: uuid
  - name: total
    type: decimal
    precision: 10
    scale: 2
relations: []
api:
  base_path: /billing/invoices
  middleware: []
  endpoints: []
options: []
YAML;
    }
}
