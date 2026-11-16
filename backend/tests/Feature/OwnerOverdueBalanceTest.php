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
}
