<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Accounting\AccountingService;
use App\Support\PermissionCatalog;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NeedsReceiveExpiringTest extends TestCase
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
            'operational' => [
                'min_customer_age' => 21,
                'require_handover_signature' => false,
                'block_expired_license' => false,
            ],
        ]);
        Sanctum::actingAs(User::factory()->create([
            'role_id' => Role::query()->where('name', 'super_admin')->firstOrFail()->id,
            'is_active' => true,
        ]));
        Carbon::setTestNow(Carbon::parse('2026-11-28 12:00:00', 'Africa/Cairo'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_dashboard_kpi_counts_overdue_and_due_today(): void
    {
        $customer = $this->customer('A');
        $overdueVehicle = $this->vehicle('OV-1');
        $todayVehicle = $this->vehicle('TD-1');
        $futureVehicle = $this->vehicle('FU-1');

        $this->activateContract($customer, $overdueVehicle, '2026-11-20 08:00:00', '2026-11-27 18:00:00');
        $this->activateContract($customer, $todayVehicle, '2026-11-27 08:00:00', '2026-11-28 18:00:00');
        $this->activateContract($customer, $futureVehicle, '2026-11-28 08:00:00', '2026-11-30 18:00:00');

        $this->assertSame(2, $this->getJson('/api/dashboard')->json('kpis.returning_today'));
    }

    public function test_expiring_tab_includes_overdue_first(): void
    {
        $customer = $this->customer('B');
        $overdueVehicle = $this->vehicle('OV-2');
        $soonVehicle = $this->vehicle('SN-2');

        $this->activateContract($customer, $overdueVehicle, '2026-11-20 08:00:00', '2026-11-26 10:00:00');
        $this->activateContract($customer, $soonVehicle, '2026-11-27 08:00:00', '2026-11-29 18:00:00');

        $ids = collect($this->getJson('/api/vehicles?status=expiring&within_days=2&per_page=50')
            ->assertOk()
            ->json('data'))
            ->pluck('id')
            ->all();

        $this->assertContains($overdueVehicle->id, $ids);
        $this->assertContains($soonVehicle->id, $ids);
        $this->assertSame($overdueVehicle->id, $ids[0], 'المتأخر يظهر أولاً');
    }

    private function customer(string $tag): Customer
    {
        return Customer::query()->create([
            'full_name' => "عميل {$tag}",
            'phone' => '0108'.random_int(1000000, 9999999),
            'birth_date' => '1990-01-01',
            'license_number' => 'LIC-NR-'.$tag.random_int(10, 99),
            'license_expires_on' => now()->addYears(2)->toDateString(),
            'status' => 'active',
        ]);
    }

    private function vehicle(string $code): Vehicle
    {
        return Vehicle::query()->create([
            'code' => $code,
            'plate' => $code,
            'brand' => 'كيا',
            'model' => 'سبورتاج',
            'year' => 2024,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 1000,
            'daily_rate' => 500,
            'default_deposit' => 0,
            'is_active' => true,
        ]);
    }

    private function activateContract(Customer $customer, Vehicle $vehicle, string $start, string $end): int
    {
        $id = $this->postJson('/api/contracts', [
            'payment_amount' => 1,
            'payment_method' => 'cash',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'starts_at' => $start,
            'planned_ends_at' => $end,
            'daily_rate' => 500,
            'deposit' => 0,
            'odometer' => 1000,
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/contracts/{$id}/approve")->assertOk();
        $this->postJson("/api/contracts/{$id}/handover", [
            'odometer_out' => 1000,
            'signature_data' => 'sig-'.str_repeat('x', 40),
        ])->assertOk();

        return (int) $id;
    }
}
