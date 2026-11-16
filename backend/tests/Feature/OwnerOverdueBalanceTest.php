<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleOwner;
use App\Services\Accounting\AccountingService;
use App\Support\PermissionCatalog;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OwnerOverdueBalanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-16 12:00:00', 'Africa/Cairo'));
        PermissionCatalog::ensureSeeded();
        app(AccountingService::class)->ensureChartOfAccounts();
        TenantSetting::query()->create([
            'center_name' => 'اختبار',
            'feature_flags' => [],
            // بلا أرضية تشغيل حتى تبقى اختبارات الشهور المكتملة التاريخية صريحة
            'operational' => [],
        ]);
        Sanctum::actingAs(User::factory()->create([
            'role_id' => Role::query()->where('name', 'super_admin')->firstOrFail()->id,
            'is_active' => true,
            'password' => Hash::make('password'),
        ]));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_completed_months_without_payout_show_overdue(): void
    {
        $owner = VehicleOwner::query()->create([
            'full_name' => 'خالد المتأخر',
            'phone' => '01111112222',
            'is_active' => true,
        ]);
        Vehicle::query()->create([
            'code' => 'OWN-OD',
            'plate' => 'ك خ 20',
            'brand' => 'هيونداي',
            'model' => 'توسان',
            'year' => 2024,
            'category' => 'SUV',
            'fuel_type' => 'بنزين',
            'odometer' => 1000,
            'daily_rate' => 1500,
            'default_deposit' => 0,
            'is_active' => true,
            'owner_id' => $owner->id,
            'owner_monthly_amount' => 20000,
            'owner_agreement_starts_on' => '2026-09-01',
            'owner_agreement_ends_on' => '2027-09-01',
        ]);

        $show = $this->getJson('/api/owners/'.$owner->id)->assertOk()->json('data');
        // سبتمبر + أكتوبر مكتملان (نوفمبر جاري لا يُحسب) = 40000
        $this->assertSame(40000, $show['balance']['earned_total']);
        $this->assertSame(40000, $show['balance']['overdue']);
        $this->assertSame(20000, $show['balance']['vehicles_monthly_total']);

        $this->postJson('/api/owners/'.$owner->id.'/payouts', [
            'amount' => 20000,
            'paid_on' => '2026-10-05',
            'payment_method' => 'cash',
        ])->assertCreated();

        $after = $this->getJson('/api/owners/'.$owner->id)->assertOk()->json('data');
        $this->assertSame(20000, $after['balance']['paid_total']);
        $this->assertSame(20000, $after['balance']['overdue']);

        $this->postJson('/api/owners/'.$owner->id.'/payouts', [
            'amount' => 20000,
            'paid_on' => '2026-11-05',
            'payment_method' => 'cash',
        ])->assertCreated();

        $cleared = $this->getJson('/api/owners/'.$owner->id)->assertOk()->json('data');
        $this->assertSame(0, $cleared['balance']['overdue']);
    }

    public function test_agreement_starting_mid_current_month_has_zero_overdue(): void
    {
        $owner = VehicleOwner::query()->create([
            'full_name' => 'مناديلي اختبار',
            'phone' => '01114449023',
            'is_active' => true,
        ]);
        Vehicle::query()->create([
            'code' => 'OWN-NOV15',
            'plate' => 'م ن 15',
            'brand' => 'نيسان',
            'model' => 'صني',
            'year' => 2024,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 1000,
            'daily_rate' => 900,
            'default_deposit' => 0,
            'is_active' => true,
            'owner_id' => $owner->id,
            'owner_monthly_amount' => 29000,
            'owner_agreement_starts_on' => '2026-11-15',
            'owner_agreement_ends_on' => '2027-11-15',
        ]);
        Vehicle::query()->create([
            'code' => 'OWN-NOV15B',
            'plate' => 'م ن 16',
            'brand' => 'هيونداي',
            'model' => 'إلينترا',
            'year' => 2024,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 1000,
            'daily_rate' => 900,
            'default_deposit' => 0,
            'is_active' => true,
            'owner_id' => $owner->id,
            'owner_monthly_amount' => 24000,
            'owner_agreement_starts_on' => '2026-11-15',
            'owner_agreement_ends_on' => '2027-11-15',
        ]);

        $show = $this->getJson('/api/owners/'.$owner->id)->assertOk()->json('data');
        $this->assertSame(53000, $show['balance']['vehicles_monthly_total']);
        $this->assertSame(0, $show['balance']['earned_total']);
        $this->assertSame(0, $show['balance']['overdue']);
        $this->assertSame('2026-11-15', $show['vehicles'][0]['owner_agreement_starts_on']);
        $this->assertSame(0, $show['vehicles'][0]['earned_completed']);
    }

    public function test_october_start_shows_completed_october_share_as_overdue(): void
    {
        $owner = VehicleOwner::query()->create([
            'full_name' => 'مالك أكتوبر',
            'phone' => '01114449999',
            'is_active' => true,
        ]);
        Vehicle::query()->create([
            'code' => 'OWN-OCT',
            'plate' => 'ك ت 1',
            'brand' => 'كيان',
            'model' => 'سبورتاج',
            'year' => 2023,
            'category' => 'SUV',
            'fuel_type' => 'بنزين',
            'odometer' => 1000,
            'daily_rate' => 1200,
            'default_deposit' => 0,
            'is_active' => true,
            'owner_id' => $owner->id,
            'owner_monthly_amount' => 29000,
            'owner_agreement_starts_on' => '2026-10-01',
            'owner_agreement_ends_on' => '2027-10-01',
        ]);

        $show = $this->getJson('/api/owners/'.$owner->id)->assertOk()->json('data');
        $this->assertSame(29000, $show['balance']['earned_total']);
        $this->assertSame(29000, $show['balance']['overdue']);
        $this->assertSame(['2026-10' => 29000], $show['balance']['vehicles_breakdown'][0]['shares']);
    }

    public function test_go_live_counts_from_november_ignores_older_agreement_months(): void
    {
        TenantSetting::query()->first()?->update([
            'operational' => ['owner_dues_counts_from' => '2026-11-01'],
        ]);

        $owner = VehicleOwner::query()->create([
            'full_name' => 'محمد قديم العقد',
            'phone' => '01125253888',
            'is_active' => true,
        ]);
        Vehicle::query()->create([
            'code' => 'OWN-JET',
            'plate' => 'ك ر ب 1',
            'brand' => 'جيتور',
            'model' => 'T2',
            'year' => 2025,
            'category' => 'SUV',
            'fuel_type' => 'بنزين',
            'odometer' => 1000,
            'daily_rate' => 2000,
            'default_deposit' => 0,
            'is_active' => true,
            'owner_id' => $owner->id,
            'owner_monthly_amount' => 41000,
            'owner_agreement_starts_on' => '2026-01-11',
            'owner_agreement_ends_on' => '2027-01-11',
        ]);

        $show = $this->getJson('/api/owners/'.$owner->id)->assertOk()->json('data');
        $this->assertSame('2026-11-01', $show['balance']['dues_counts_from']);
        $this->assertSame(0, $show['balance']['earned_total']);
        $this->assertSame(0, $show['balance']['overdue']);
        $this->assertSame([], $show['balance']['vehicles_breakdown'][0]['shares']);
    }
}
