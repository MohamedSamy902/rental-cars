<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Role;
use App\Models\SalaryAdvance;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Accounting\AccountingService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeShowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        app(AccountingService::class)->ensureChartOfAccounts();
        TenantSetting::query()->create([
            'center_name' => 'اختبار',
            'feature_flags' => ['hr' => true, 'evaluations' => true],
            'operational' => [],
        ]);
        $adminRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        Sanctum::actingAs(User::factory()->create(['role_id' => $adminRole->id, 'is_active' => true]));
    }

    public function test_employee_show_returns_dossier(): void
    {
        $employee = Employee::query()->create([
            'name' => 'سارة أحمد',
            'job_title' => 'محاسبة',
            'hired_on' => now()->toDateString(),
            'base_salary' => 7000,
            'status' => 'active',
        ]);

        SalaryAdvance::query()->create([
            'employee_id' => $employee->id,
            'amount' => 500,
            'advanced_on' => now()->toDateString(),
            'method' => 'cash',
            'year' => (int) now()->format('Y'),
            'month' => (int) now()->format('n'),
        ]);

        $res = $this->getJson("/api/employees/{$employee->id}")->assertOk();
        $res->assertJsonPath('data.employee.name', 'سارة أحمد');
        $res->assertJsonPath('data.advances.0.amount', 500);
        $this->assertArrayHasKey('petty_cash', $res->json('data'));
        $this->assertArrayHasKey('payroll_history', $res->json('data'));
        $this->assertArrayHasKey('evaluations', $res->json('data'));
        $this->assertArrayHasKey('assignments', $res->json('data'));
    }
}
