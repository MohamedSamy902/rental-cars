<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\OwnerMonthDue;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleOwner;
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
        $this->artisan('owners:accrue-dues', ['--owner' => $owner->id])->assertSuccessful();
        $this->assertGreaterThan($duesBefore, OwnerMonthDue::query()->where('owner_id', $owner->id)->count());
        $this->assertNotNull($liabilityBefore);
    }
}
