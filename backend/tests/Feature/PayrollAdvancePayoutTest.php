<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Accounting\AccountingService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PayrollAdvancePayoutTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        app(AccountingService::class)->ensureChartOfAccounts();
        TenantSetting::query()->create(['center_name' => 'اختبار', 'feature_flags' => [], 'operational' => []]);
        $admin = Role::query()->where('name', 'super_admin')->firstOrFail();
        Sanctum::actingAs(User::factory()->create(['role_id' => $admin->id, 'is_active' => true]));

        $this->employee = Employee::query()->create([
            'name' => 'سامي محاسب',
            'base_salary' => 10000,
            'status' => 'active',
        ]);
    }

    public function test_advance_is_deducted_and_partial_payout_rolls_into_next_cycle(): void
    {
        $this->postJson('/api/salary-advances', [
            'employee_id' => $this->employee->id,
            'amount' => 1500,
            'advanced_on' => '2026-08-10',
        ])->assertCreated()->assertJsonPath('data.amount', 1500);

        $run = $this->postJson('/api/payroll/draft', ['year' => 2026, 'month' => 8])
            ->assertCreated()
            ->assertJsonPath('data.lines.0.advances', 1500)
            ->assertJsonPath('data.lines.0.net_amount', 8500)
            ->json('data');
        $lineId = $run['lines'][0]['id'];

        $this->postJson("/api/payroll/lines/{$lineId}/payout", ['amount' => 1000, 'paid_on' => '2026-08-30'])
            ->assertStatus(422);

        $this->postJson("/api/payroll/{$run['id']}/approve")->assertOk();

        $this->postJson("/api/payroll/lines/{$lineId}/payout", ['amount' => 9000, 'paid_on' => '2026-08-30'])
            ->assertStatus(422);

        $this->postJson("/api/payroll/lines/{$lineId}/payout", ['amount' => 6000, 'paid_on' => '2026-08-30'])
            ->assertOk()
            ->assertJsonPath('data.paid_amount', 6000)
            ->assertJsonPath('data.carried_over', 2500);

        $this->postJson('/api/payroll/draft', ['year' => 2026, 'month' => 9])
            ->assertCreated()
            ->assertJsonPath('data.lines.0.carried_in', 2500)
            ->assertJsonPath('data.lines.0.advances', 0)
            ->assertJsonPath('data.lines.0.net_amount', 12500)
            ->assertJsonPath('data.total_amount', 12500);

        $this->assertDatabaseHas('payroll_payouts', ['payroll_line_id' => $lineId, 'amount' => 6000]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payroll_payout', 'record_id' => $lineId]);
    }

    public function test_advance_on_draft_month_recalculates_line_and_guards_limits(): void
    {
        $run = $this->postJson('/api/payroll/draft', ['year' => 2026, 'month' => 7])->assertCreated()->json('data');
        $this->assertSame(10000, $run['lines'][0]['net_amount']);

        $advance = $this->postJson('/api/salary-advances', [
            'employee_id' => $this->employee->id,
            'amount' => 2000,
            'advanced_on' => '2026-07-05',
        ])->assertCreated()->json('data');

        $this->getJson('/api/payroll')
            ->assertOk()
            ->assertJsonPath('data.0.lines.0.advances', 2000)
            ->assertJsonPath('data.0.lines.0.net_amount', 8000)
            ->assertJsonPath('data.0.total_amount', 8000);

        $this->postJson('/api/salary-advances', [
            'employee_id' => $this->employee->id,
            'amount' => 9000,
            'advanced_on' => '2026-07-06',
        ])->assertStatus(422);

        $this->getJson('/api/salary-advances?year=2026&month=7')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.employee.name', 'سامي محاسب');

        $this->deleteJson("/api/salary-advances/{$advance['id']}")->assertOk();
        $this->getJson('/api/payroll')->assertJsonPath('data.0.lines.0.net_amount', 10000);

        $this->postJson("/api/payroll/{$run['id']}/approve")->assertOk();
        $this->postJson('/api/salary-advances', [
            'employee_id' => $this->employee->id,
            'amount' => 500,
            'advanced_on' => '2026-07-20',
        ])->assertStatus(422);
    }

    public function test_full_payout_leaves_nothing_to_carry(): void
    {
        $run = $this->postJson('/api/payroll/draft', ['year' => 2026, 'month' => 6])->assertCreated()->json('data');
        $this->postJson("/api/payroll/{$run['id']}/approve")->assertOk();

        $this->postJson("/api/payroll/lines/{$run['lines'][0]['id']}/payout", ['amount' => 10000, 'paid_on' => '2026-06-30'])
            ->assertOk()
            ->assertJsonPath('data.carried_over', 0)
            ->assertJsonPath('message', 'تم صرف الراتب بالكامل.');
    }
}
