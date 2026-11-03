<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Expense;
use App\Models\PettyCashAccount;
use App\Models\PettyCashMovement;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use App\Models\WorkOrderLine;
use App\Services\Accounting\AccountingService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PettyCashLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        app(AccountingService::class)->ensureChartOfAccounts();
        TenantSetting::query()->create([
            'center_name' => 'اختبار',
            'feature_flags' => [],
            'operational' => [],
        ]);
        $adminRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        Sanctum::actingAs(User::factory()->create(['role_id' => $adminRole->id, 'is_active' => true]));
    }

    public function test_petty_cash_show_returns_summary_and_enriched_ledger(): void
    {
        $employee = Employee::query()->create([
            'name' => 'أحمد العهدة',
            'job_title' => 'أمين مخزن',
            'hired_on' => now()->toDateString(),
            'base_salary' => 5000,
            'status' => 'active',
        ]);

        $account = PettyCashAccount::query()->create([
            'employee_id' => $employee->id,
            'limit_amount' => 10000,
            'balance' => 7000,
            'is_active' => true,
        ]);

        PettyCashMovement::query()->create([
            'petty_cash_account_id' => $account->id,
            'type' => 'topup',
            'amount' => 10000,
            'moved_on' => now()->subDays(3)->toDateString(),
            'notes' => 'افتتاح عهدة',
        ]);

        $vehicle = Vehicle::query()->create([
            'code' => 'VEH-7701',
            'plate' => 'س ص 3322',
            'brand' => 'تويوتا',
            'model' => 'يارس',
            'year' => 2022,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 12000,
            'odometer_source' => 'manual',
            'daily_rate' => 700,
            'default_deposit' => 1500,
            'is_active' => true,
        ]);

        $expense = Expense::query()->create([
            'title' => 'وقود طوارئ',
            'category' => 'تشغيل',
            'amount' => 500,
            'spent_on' => now()->subDays(2)->toDateString(),
            'payment_method' => 'petty_cash',
            'vehicle_id' => $vehicle->id,
            'employee_id' => $employee->id,
            'from_petty_cash' => true,
        ]);

        PettyCashMovement::query()->create([
            'petty_cash_account_id' => $account->id,
            'type' => 'spend',
            'amount' => 500,
            'moved_on' => now()->subDays(2)->toDateString(),
            'notes' => 'مصروف: وقود طوارئ',
        ]);

        $wo = WorkOrder::query()->create([
            'vehicle_id' => $vehicle->id,
            'workshop' => 'ورشة النور',
            'opened_on' => now()->subDay()->toDateString(),
            'opened_at' => now()->subDay(),
            'odometer' => 12100,
            'status' => 'open',
            'puts_in_maintenance' => true,
            'parts_cost' => 800,
            'labor_cost' => 0,
            'total_cost' => 800,
        ]);

        WorkOrderLine::query()->create([
            'work_order_id' => $wo->id,
            'description' => 'فلتر زيت',
            'part_source' => 'purchase',
            'quantity' => 1,
            'unit_price' => 800,
            'from_petty_cash' => true,
            'petty_cash_employee_id' => $employee->id,
            'petty_cash_amount' => 800,
        ]);

        PettyCashMovement::query()->create([
            'petty_cash_account_id' => $account->id,
            'type' => 'spend',
            'amount' => 800,
            'moved_on' => now()->subDay()->toDateString(),
            'work_order_id' => $wo->id,
            'notes' => 'صيانة من العهدة',
        ]);

        PettyCashMovement::query()->create([
            'petty_cash_account_id' => $account->id,
            'type' => 'return',
            'amount' => 1700,
            'moved_on' => now()->toDateString(),
            'notes' => 'رد نقدية',
        ]);

        $res = $this->getJson("/api/petty-cash/{$account->id}")->assertOk();

        $res->assertJsonPath('data.summary.employee_name', 'أحمد العهدة');
        $res->assertJsonPath('data.summary.employee_role', 'أمين مخزن');
        $res->assertJsonPath('data.summary.limit_amount', 10000);
        $res->assertJsonPath('data.summary.total_topup', 10000);
        $res->assertJsonPath('data.summary.total_spent', 1300);
        $res->assertJsonPath('data.summary.total_returned', 1700);

        $ledger = collect($res->json('data.ledger'));
        $this->assertGreaterThanOrEqual(4, $ledger->count());
        $this->assertTrue($ledger->contains(fn ($row) => ($row['category'] ?? '') === 'expense'));
        $this->assertTrue($ledger->contains(fn ($row) => ($row['category'] ?? '') === 'maintenance'));
        $this->assertTrue($ledger->contains(fn ($row) => ($row['category'] ?? '') === 'settlement'));
        $this->assertNotNull($ledger->firstWhere('category', 'expense')['vehicle']['plate'] ?? null);
        $this->assertSame($expense->id, $ledger->firstWhere('category', 'expense')['expense']['id'] ?? null);
        $this->assertSame($wo->id, $ledger->firstWhere('category', 'maintenance')['work_order']['id'] ?? null);
    }

    public function test_petty_cash_return_movement_decrements_balance(): void
    {
        $employee = Employee::query()->create([
            'name' => 'موظف رد',
            'job_title' => 'سائق',
            'hired_on' => now()->toDateString(),
            'base_salary' => 4000,
            'status' => 'active',
        ]);
        $account = PettyCashAccount::query()->create([
            'employee_id' => $employee->id,
            'limit_amount' => 5000,
            'balance' => 3000,
            'is_active' => true,
        ]);

        $this->postJson("/api/petty-cash/{$account->id}/move", [
            'type' => 'return',
            'amount' => 1000,
            'notes' => 'رد جزئي',
        ])->assertOk();

        $this->assertSame(2000, (int) $account->fresh()->balance);
        $this->assertDatabaseHas('petty_cash_movements', [
            'petty_cash_account_id' => $account->id,
            'type' => 'return',
            'amount' => 1000,
        ]);
    }
}
