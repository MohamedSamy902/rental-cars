<?php

namespace Tests\Feature;

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

class VehicleOwnerPayoutTest extends TestCase
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
        $adminRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        $this->admin = User::factory()->create(['role_id' => $adminRole->id, 'is_active' => true]);
        Sanctum::actingAs($this->admin);
    }

    public function test_owner_with_two_vehicles_accrues_and_partial_payout(): void
    {
        $owner = $this->postJson('/api/owners', [
            'full_name' => 'حسام المالك',
            'phone' => '01099998888',
        ])->assertCreated()->json('data');

        $v1 = Vehicle::query()->create([
            'code' => 'O-1', 'plate' => 'OWN 1', 'brand' => 'هيونداي', 'model' => 'إلينترا',
            'year' => 2021, 'category' => 'اقتصادي', 'fuel_type' => 'بنزين',
            'odometer' => 10000, 'daily_rate' => 900, 'default_deposit' => 2000, 'is_active' => true,
            'owner_id' => $owner['id'], 'owner_monthly_amount' => 5000,
            'owner_since' => now()->startOfMonth()->toDateString(),
        ]);
        $v2 = Vehicle::query()->create([
            'code' => 'O-2', 'plate' => 'OWN 2', 'brand' => 'كيا', 'model' => 'سيراتو',
            'year' => 2022, 'category' => 'اقتصادي', 'fuel_type' => 'بنزين',
            'odometer' => 8000, 'daily_rate' => 850, 'default_deposit' => 2000, 'is_active' => true,
            'owner_id' => $owner['id'], 'owner_monthly_amount' => 4000,
            'owner_since' => now()->startOfMonth()->toDateString(),
        ]);

        $show = $this->getJson('/api/owners/'.$owner['id'])->assertOk()->json('data');
        $this->assertSame(2, count($show['vehicles']));
        $this->assertSame(9000, $show['balance']['month_accrued']);
        $this->assertSame(9000, $show['balance']['remaining']);
        $this->assertSame(9000, $show['balance']['vehicles_monthly_total']);

        $this->assertDatabaseHas('owner_month_dues', [
            'owner_id' => $owner['id'],
            'vehicle_id' => $v1->id,
            'year_month' => now()->format('Y-m'),
            'amount' => 5000,
        ]);
        $this->assertDatabaseHas('owner_month_dues', [
            'owner_id' => $owner['id'],
            'vehicle_id' => $v2->id,
            'amount' => 4000,
        ]);

        $payout = $this->postJson('/api/owners/'.$owner['id'].'/payouts', [
            'amount' => 3000,
            'paid_on' => now()->toDateString(),
            'payment_method' => 'cash',
            'vehicle_id' => $v1->id,
            'notes' => 'دفعة جزئية',
        ])->assertCreated();

        $this->assertSame(3000, $payout->json('data.amount'));
        $this->assertSame(6000, $payout->json('balance.remaining'));
        $this->assertDatabaseCount('owner_payouts', 1);

        $this->postJson('/api/owners/'.$owner['id'].'/payouts', [
            'amount' => 7000,
            'paid_on' => now()->toDateString(),
        ])->assertStatus(422);

        $this->postJson('/api/owners/'.$owner['id'].'/payouts', [
            'amount' => 6000,
            'paid_on' => now()->toDateString(),
            'payment_method' => 'transfer',
        ])->assertCreated()->assertJsonPath('balance.remaining', 0);
    }

    public function test_vehicle_store_links_owner_monthly_amount(): void
    {
        $ownerId = VehicleOwner::query()->create([
            'full_name' => 'مالك تجريبي',
            'phone' => '01011110000',
            'is_active' => true,
        ])->id;

        $res = $this->postJson('/api/vehicles', [
            'code' => 'OWN-99',
            'plate' => 'XYZ 99',
            'brand' => 'نيسان',
            'model' => 'صني',
            'year' => 2020,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 5000,
            'daily_rate' => 700,
            'default_deposit' => 1500,
            'owner_id' => $ownerId,
            'owner_monthly_amount' => 3500,
        ])->assertCreated();

        $this->assertSame($ownerId, $res->json('data.owner_id'));
        $this->assertSame(3500, $res->json('data.owner_monthly_amount'));
        $this->assertNotNull($res->json('data.owner_since'));
        $this->assertSame('مالك تجريبي', $res->json('data.owner.full_name'));
    }
}
