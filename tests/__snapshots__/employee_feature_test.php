<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Hr\Domain\Models\Department;
use App\Modules\Hr\Domain\Models\Employee;
use App\Modules\Hr\Infrastructure\Seeders\EmployeeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class EmployeeFeatureTest extends TestCase
{
    use RefreshDatabase;
    use WithFaker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(EmployeeSeeder::class);
    }

    public function test_can_list_employees(): void
    {
        $response = $this->getJson('/hr/employees');

        $response->assertOk();
    }

    public function test_can_show_employee(): void
    {
        $employee = Employee::factory()->create();

        $response = $this->getJson("/hr/employees/{$employee->id}");

        $response->assertOk();
        $response->assertJsonPath('data.id', (string) $employee->id);
    }

    public function test_can_create_employee(): void
    {
        $department = Department::factory()->create();

        $payload = [
            'first_name' => $this->faker->firstName(),
            'last_name' => $this->faker->lastName(),
            'email' => $this->faker->unique()->safeEmail(),
            'salary' => $this->faker->randomFloat(2, 30000, 150000),
            'active' => true,
            'department_id' => (string) $department->id,
        ];

        $response = $this->postJson('/hr/employees', $payload);

        $response->assertCreated();
        $response->assertJsonPath('data.first_name', $payload['first_name']);
    }

    public function test_can_update_employee(): void
    {
        $employee = Employee::factory()->create();

        $payload = [
            'first_name' => 'Updated First Name',
            'last_name' => 'Updated Last Name',
            'email' => 'updated.email@example.com',
            'salary' => '42000.00',
            'active' => false,
        ];

        $response = $this->putJson("/hr/employees/{$employee->id}", $payload);

        $response->assertOk();
        $response->assertJsonPath('data.first_name', $payload['first_name']);
    }

    public function test_can_delete_employee(): void
    {
        $employee = Employee::factory()->create();

        $response = $this->deleteJson("/hr/employees/{$employee->id}");

        $response->assertNoContent();
        $this->assertSoftDeleted(Employee::class, ['id' => $employee->id]);
    }

    public function test_can_toggle_active_flag(): void
    {
        $employee = Employee::factory()->active(false)->create();

        $response = $this->patchJson("/hr/employees/{$employee->id}/toggle-active");

        $response->assertOk();
        $response->assertJsonPath('data.active', true);
    }

    public function test_can_update_salary(): void
    {
        $employee = Employee::factory()->create();

        $payload = [
            'salary' => '85000.00',
        ];

        $response = $this->patchJson("/hr/employees/{$employee->id}/update-salary", $payload);

        $response->assertOk();
        $response->assertJsonPath('data.salary', $payload['salary']);
    }

    public function test_can_search_employees(): void
    {
        Employee::factory()->create([
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane.doe@example.com',
        ]);

        $response = $this->getJson('/hr/employees?search=Jane');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertSeeText('Jane', $escaped = false);
    }

    public function test_can_get_active_stats(): void
    {
        Employee::factory()->active()->create();
        Employee::factory()->active(false)->create();

        $response = $this->getJson('/hr/employees/stats/active');

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                ['active', 'count'],
            ],
        ]);
    }
}
