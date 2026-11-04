<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\PettyCashAccount;
use App\Models\PettyCashMovement;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Accounting\AccountingService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PettyCashAccumulateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        app(AccountingService::class)->ensureChartOfAccounts();
        TenantSetting::query()->create([
            'center_name' => 'اختبار',
            'feature_flags' => ['petty_cash' => true],
            'operational' => [],
        ]);
        $adminRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        Sanctum::actingAs(User::factory()->create(['role_id' => $adminRole->id, 'is_active' => true]));
    }

    public function test_store_accumulates_balance_and_logs_topup(): void
    {
        $employee = Employee::query()->create([
            'name' => 'موظف العهدة',
            'job_title' => 'أمين',
            'hired_on' => now()->toDateString(),
            'base_salary' => 4000,
            'status' => 'active',
        ]);

        $this->postJson('/api/petty-cash', [
            'employee_id' => $employee->id,
            'limit_amount' => 0,
            'balance' => 1000,
        ])->assertCreated()
            ->assertJsonPath('data.balance', 1000);

        $this->postJson('/api/petty-cash', [
            'employee_id' => $employee->id,
            'limit_amount' => 0,
            'balance' => 500,
        ])->assertCreated()
            ->assertJsonPath('data.balance', 1500);

        $account = PettyCashAccount::query()->where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame(1500, (int) $account->balance);
        $this->assertSame(2, PettyCashMovement::query()->where('petty_cash_account_id', $account->id)->where('type', 'topup')->count());
    }

    public function test_return_deducts_balance(): void
    {
        $employee = Employee::query()->create([
            'name' => 'موظف السحب',
            'job_title' => 'أمين',
            'hired_on' => now()->toDateString(),
            'base_salary' => 4000,
            'status' => 'active',
        ]);

        $account = PettyCashAccount::query()->create([
            'employee_id' => $employee->id,
            'limit_amount' => 0,
            'balance' => 800,
            'is_active' => true,
        ]);

        $this->postJson("/api/petty-cash/{$account->id}/move", [
            'type' => 'return',
            'amount' => 300,
            'notes' => 'سحب / استرداد من العهدة',
        ])->assertOk()
            ->assertJsonPath('data.account.balance', 500);

        $this->postJson("/api/petty-cash/{$account->id}/move", [
            'type' => 'return',
            'amount' => 500,
            'notes' => 'سحب الرصيد بالكامل',
        ])->assertOk()
            ->assertJsonPath('data.account.balance', 0);
    }
}
