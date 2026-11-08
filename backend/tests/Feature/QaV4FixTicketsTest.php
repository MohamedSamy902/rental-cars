<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Models\OwnerMonthDue;
use App\Models\PettyCashAccount;
use App\Models\PettyCashMovement;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleOwner;
use App\Models\WorkOrder;
use App\Models\WorkOrderLine;
use App\Services\Accounting\AccountingService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * QA V4: pagination validation, owner GET must not accrue, seeds stay coherent.
 */
class QaV4FixTicketsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

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
        $admin = Role::query()->where('name', 'super_admin')->firstOrFail();
        $this->admin = User::factory()->create(['role_id' => $admin->id, 'is_active' => true]);
        Sanctum::actingAs($this->admin);
    }

    public function test_v4_06_negative_per_page_returns_422(): void
    {
        $this->getJson('/api/contracts?per_page=-1')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['per_page']);

        $this->getJson('/api/customers?per_page=0')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['per_page']);

        $this->getJson('/api/vehicles?page=-2')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['page']);

        $this->getJson('/api/contracts?per_page=100')
            ->assertOk();

        $this->getJson('/api/contracts?per_page=200')->assertOk();
        $this->getJson('/api/vehicles?per_page=abc')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['per_page']);
        $this->getJson('/api/customers?per_page=10000')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['per_page']);
        $this->getJson('/api/work-orders?page=0')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['page']);
    }

    public function test_v4_01_contracts_meta_exposes_all_pages(): void
    {
        for ($i = 0; $i < 55; $i++) {
            Vehicle::query()->create([
                'code' => 'PX-'.$i,
                'plate' => 'P-'.$i,
                'brand' => 'تويوتا',
                'model' => 'QA',
                'year' => 2024,
                'category' => 'اقتصادي',
                'fuel_type' => 'بنزين',
                'odometer' => 1000,
                'daily_rate' => 100,
                'default_deposit' => 0,
                'is_active' => true,
            ]);
        }

        $page1 = $this->getJson('/api/vehicles?per_page=50&page=1')->assertOk()->json();
        $this->assertCount(50, $page1['data']);
        $this->assertSame(55, $page1['meta']['total']);
        $this->assertSame(2, $page1['meta']['last_page']);

        $page2 = $this->getJson('/api/vehicles?per_page=50&page=2')->assertOk()->json();
        $this->assertCount(5, $page2['data']);
        $ids = collect($page1['data'])->pluck('id')
            ->merge(collect($page2['data'])->pluck('id'))
            ->unique();
        $this->assertCount(55, $ids);
    }

    public function test_v4_03_owner_show_does_not_create_dues_or_journals(): void
    {
        $owner = VehicleOwner::query()->create([
            'full_name' => 'مالك اختبار',
            'phone' => '01011112222',
            'is_active' => true,
        ]);
        Vehicle::query()->create([
            'code' => 'OWN-1',
            'plate' => 'OWN-1',
            'brand' => 'هيونداي',
            'model' => 'إلنترا',
            'year' => 2023,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 1000,
            'daily_rate' => 200,
            'default_deposit' => 0,
            'is_active' => true,
            'owner_id' => $owner->id,
            'owner_monthly_amount' => 5000,
            'owner_since' => now()->subMonths(3)->toDateString(),
        ]);

        $duesBefore = OwnerMonthDue::query()->count();
        $journalsBefore = JournalEntry::query()->count();
        $liabilityBefore = (int) Account::query()->where('code', '2100')->value('id');

        $this->getJson('/api/owners/'.$owner->id)->assertOk()
            ->assertJsonPath('data.balance.accrued_total', 0);

        $this->assertSame($duesBefore, OwnerMonthDue::query()->count());
        $this->assertSame($journalsBefore, JournalEntry::query()->count());

        // Explicit accrue still works (payout path / artisan).
        $this->getJson('/api/owners?per_page=50')->assertOk();
        $this->getJson('/api/vehicles/'.$owner->vehicles()->value('id'))->assertOk();
        $this->getJson('/api/reports/trial-balance')->assertOk();
        $this->assertSame($duesBefore, OwnerMonthDue::query()->count());
        $this->assertSame($journalsBefore, JournalEntry::query()->count());

        $this->postJson('/api/owners/'.$owner->id.'/accruals')->assertOk();
        $created = OwnerMonthDue::query()->where('owner_id', $owner->id)->count();
        $journals = JournalEntry::query()->count();
        $this->assertGreaterThan($duesBefore, $created);
        $this->assertGreaterThan(0, AuditLog::query()->where('action', 'owner_month_accrued')->count());

        $this->postJson('/api/owners/'.$owner->id.'/accruals')->assertOk();
        $this->assertSame($created, OwnerMonthDue::query()->where('owner_id', $owner->id)->count());
        $this->assertSame($journals, JournalEntry::query()->count());
        $this->assertNotNull($liabilityBefore);
    }

    public function test_v4_03_locked_month_rejects_accrual_until_reopened(): void
    {
        $owner = VehicleOwner::query()->create([
            'full_name' => 'مالك مقفل',
            'phone' => '01033334444',
            'is_active' => true,
        ]);
        $month = now()->startOfMonth()->subMonth();
        Vehicle::query()->create([
            'code' => 'OWN-L',
            'plate' => 'OWN-L',
            'brand' => 'كيا',
            'model' => 'سيراتو',
            'year' => 2022,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 1000,
            'daily_rate' => 200,
            'default_deposit' => 0,
            'is_active' => true,
            'owner_id' => $owner->id,
            'owner_monthly_amount' => 4000,
            'owner_since' => $month->toDateString(),
        ]);

        app(AccountingService::class)->lockMonth((int) $month->year, (int) $month->month, $this->admin->id);
        $dues = OwnerMonthDue::query()->count();
        $journals = JournalEntry::query()->count();

        $this->postJson('/api/owners/'.$owner->id.'/accruals')->assertStatus(422);
        $this->assertSame($dues, OwnerMonthDue::query()->count());
        $this->assertSame($journals, JournalEntry::query()->count());

        app(AccountingService::class)->reopenMonth((int) $month->year, (int) $month->month, 'اختبار إعادة الفتح', $this->admin->id);
        $this->postJson('/api/owners/'.$owner->id.'/accruals')->assertOk();
        $this->assertSame(1, OwnerMonthDue::query()->where('owner_id', $owner->id)->count());
        $this->postJson('/api/owners/'.$owner->id.'/accruals')->assertOk();
        $this->assertSame(1, OwnerMonthDue::query()->where('owner_id', $owner->id)->count());
    }

    public function test_v4_02_expense_month_lock_uses_cairo_civil_date(): void
    {
        app(AccountingService::class)->lockMonth(2026, 10, $this->admin->id);

        $created = $this->postJson('/api/expenses', [
            'title' => 'مصروف حد الشهر',
            'category' => 'تشغيل',
            'amount' => 50,
            'spent_on' => '2026-10-31T22:00:00.000000Z',
            'payment_method' => 'cash',
        ])->assertCreated();

        $this->assertSame('2026-11-01', substr((string) $created->json('data.spent_on'), 0, 10));

        app(AccountingService::class)->lockMonth(2026, 11, $this->admin->id);
        $this->postJson('/api/expenses', [
            'title' => 'مصروف نوفمبر مقفل',
            'amount' => 40,
            'spent_on' => '2026-10-31T22:00:00.000000Z',
            'payment_method' => 'cash',
        ])->assertStatus(422);
    }

    public function test_v4_04_opening_line_discloses_gap_without_rewriting_balance(): void
    {
        $employee = Employee::query()->create([
            'name' => 'عهدة غير متطابقة',
            'job_title' => 'فني',
            'hired_on' => '2024-01-01',
            'base_salary' => 4000,
            'status' => 'active',
        ]);
        $gap = PettyCashAccount::query()->create([
            'employee_id' => $employee->id,
            'limit_amount' => 20000,
            'balance' => 10500,
            'is_active' => true,
        ]);
        PettyCashMovement::query()->create([
            'petty_cash_account_id' => $gap->id,
            'type' => 'topup',
            'amount' => 16500,
            'moved_on' => '2026-10-01',
            'notes' => 'افتتاح',
        ]);
        PettyCashMovement::query()->create([
            'petty_cash_account_id' => $gap->id,
            'type' => 'spend',
            'amount' => 6045,
            'moved_on' => '2026-10-02',
            'notes' => 'صرف',
        ]);

        $shown = $this->getJson('/api/petty-cash/'.$gap->id)->assertOk();
        $shown->assertJsonPath('data.summary.opening_balance', 45);
        $shown->assertJsonPath('data.summary.balance', 10500);
        $shown->assertJsonPath('data.account.balance', 10500);
        $ledger = $shown->json('data.ledger');
        $this->assertSame(10500, $ledger[0]['running_balance']);
        $this->assertTrue(collect($ledger)->contains(fn ($row) => ($row['category'] ?? '') === 'opening' && ($row['is_computed'] ?? false) === true));
        $this->assertSame(10500, (int) $gap->fresh()->balance);
        $this->assertSame(2, PettyCashMovement::query()->where('petty_cash_account_id', $gap->id)->count());

        $driver = Employee::query()->create([
            'name' => 'عهدة متطابقة',
            'job_title' => 'سائق',
            'hired_on' => '2024-02-01',
            'base_salary' => 4000,
            'status' => 'active',
        ]);
        $ok = PettyCashAccount::query()->create([
            'employee_id' => $driver->id,
            'limit_amount' => 5000,
            'balance' => 3200,
            'is_active' => true,
        ]);
        PettyCashMovement::query()->create([
            'petty_cash_account_id' => $ok->id,
            'type' => 'topup',
            'amount' => 5000,
            'moved_on' => '2026-09-01',
        ]);
        PettyCashMovement::query()->create([
            'petty_cash_account_id' => $ok->id,
            'type' => 'spend',
            'amount' => 1800,
            'moved_on' => '2026-09-02',
        ]);

        $reconciled = $this->getJson('/api/petty-cash/'.$ok->id)->assertOk();
        $reconciled->assertJsonPath('data.summary.opening_balance', 0);
        $reconciled->assertJsonPath('data.summary.balance', 3200);
        $this->assertFalse(collect($reconciled->json('data.ledger'))->contains(fn ($row) => ($row['category'] ?? '') === 'opening'));
        $this->assertSame(3200, (int) $ok->fresh()->balance);
    }

    public function test_v4_05_parts_variance_is_disclosed_and_reclose_does_not_reprice(): void
    {
        $vehicle = Vehicle::query()->create([
            'code' => 'WO-34',
            'plate' => 'WO 34',
            'brand' => 'هيونداي',
            'model' => 'أكسنت',
            'year' => 2021,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 1000,
            'daily_rate' => 100,
            'default_deposit' => 0,
            'is_active' => true,
        ]);
        $order = WorkOrder::query()->create([
            'vehicle_id' => $vehicle->id,
            'workshop' => 'ورشة',
            'opened_on' => '2026-09-01',
            'opened_at' => '2026-09-01 10:00:00',
            'closed_on' => '2026-09-02',
            'odometer' => 1100,
            'status' => 'closed',
            'puts_in_maintenance' => false,
            'parts_cost' => 520,
            'labor_cost' => 300,
        ]);
        WorkOrderLine::query()->create([
            'work_order_id' => $order->id,
            'description' => 'قطع',
            'part_source' => 'stock',
            'quantity' => 2,
            'unit_price' => 180,
        ]);

        $row = collect($this->getJson('/api/work-orders?per_page=50')->assertOk()->json('data'))
            ->firstWhere('id', $order->id);
        $this->assertSame(520, (int) $row['parts_cost']);
        $this->assertSame(360, (int) $row['lines_parts_total']);
        $this->assertSame(160, (int) $row['parts_cost_other']);

        $this->postJson('/api/work-orders/'.$order->id.'/close')->assertStatus(422);
        $this->assertSame(520, (int) $order->fresh()->parts_cost);
        $this->assertSame('closed', $order->fresh()->status);
    }
}
