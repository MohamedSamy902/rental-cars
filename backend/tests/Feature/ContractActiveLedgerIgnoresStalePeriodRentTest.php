<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Contracts\ContractLedgerMath;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * عقود نشطة وُضع عليها period_rent = أيام×يومي من enrich القديم
 * يجب أن تُحسب بالإيجار الشهري/نص الشهري الحيّ (ن٫٥).
 */
class ContractActiveLedgerIgnoresStalePeriodRentTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_monthly_contract_ignores_stale_daily_times_days_period_rent(): void
    {
        $customer = Customer::query()->create([
            'full_name' => 'عميل شريحة',
            'phone' => '01011112222',
            'birth_date' => '1990-01-01',
            'license_number' => 'LIC-TIER',
            'license_expires_on' => now()->addYears(2)->toDateString(),
            'status' => 'active',
        ]);
        $vehicle = Vehicle::query()->create([
            'code' => 'TIER-1',
            'plate' => 'ا ف ر 7434',
            'brand' => 'كيا',
            'model' => 'اكسيد',
            'year' => 2024,
            'category' => 'SUV',
            'fuel_type' => 'بنزين',
            'odometer' => 1000,
            'daily_rate' => 1200,
            'half_monthly_rate' => 18000,
            'monthly_rate' => 37000,
            'default_deposit' => 5000,
            'is_active' => true,
        ]);

        $contract = Contract::query()->create([
            'number' => 'CNT-TIER-ACTIVE',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'status' => 'active',
            'starts_at' => '2026-11-10 10:00:00',
            'planned_ends_at' => '2026-12-10 10:00:00',
            'approved_days' => 30,
            'rent_basis' => 'monthly',
            'frozen_daily_rate' => 1200,
            'frozen_half_monthly_rate' => 18000,
            'frozen_monthly_rate' => 37000,
            'frozen_agreed_deposit' => 5000,
            // ختم خاطئ من enrich القديم: 30 × 1200
            'period_rent' => 36000,
            'final_total' => null,
            'discount' => 0,
            'other_fees' => 0,
            'delivery_service_fee' => 0,
        ]);

        $total = app(ContractLedgerMath::class)->chargeTotal($contract);

        $this->assertSame(37000, $total, 'الإجمالي يجب أن يطابق السعر الشهري لا أيام×يومي');
    }

    public function test_completed_contract_still_trusts_stored_period_rent(): void
    {
        $customer = Customer::query()->create([
            'full_name' => 'عميل مقفل',
            'phone' => '01011113333',
            'birth_date' => '1990-01-01',
            'license_number' => 'LIC-DONE',
            'license_expires_on' => now()->addYears(2)->toDateString(),
            'status' => 'active',
        ]);
        $vehicle = Vehicle::query()->create([
            'code' => 'DONE-1',
            'plate' => 'ق ط ى 1',
            'brand' => 'نيسان',
            'model' => 'صني',
            'year' => 2022,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 5000,
            'daily_rate' => 1000,
            'monthly_rate' => 22000,
            'default_deposit' => 3000,
            'is_active' => true,
        ]);

        $contract = Contract::query()->create([
            'number' => 'CNT-DONE',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'status' => 'completed',
            'starts_at' => '2026-10-01 10:00:00',
            'planned_ends_at' => '2026-10-31 10:00:00',
            'actual_ends_at' => '2026-10-31 10:00:00',
            'approved_days' => 30,
            'rent_basis' => 'monthly',
            'frozen_daily_rate' => 1000,
            'frozen_monthly_rate' => 22000,
            'frozen_agreed_deposit' => 3000,
            'period_rent' => 22000,
            'final_total' => 22000,
        ]);

        $this->assertSame(22000, app(ContractLedgerMath::class)->chargeTotal($contract));
    }

    public function test_enrich_does_not_stamp_period_rent_on_active_contracts(): void
    {
        PermissionCatalog::ensureSeeded();
        User::factory()->create([
            'role_id' => Role::query()->where('name', 'super_admin')->firstOrFail()->id,
            'is_active' => true,
        ]);

        $customer = Customer::query()->create([
            'full_name' => 'عميل نشط',
            'phone' => '01011114444',
            'birth_date' => '1990-01-01',
            'license_number' => 'LIC-ACT',
            'license_expires_on' => now()->addYears(2)->toDateString(),
            'status' => 'active',
        ]);
        $vehicle = Vehicle::query()->create([
            'code' => 'ACT-1',
            'plate' => 'س ص ع 1',
            'brand' => 'هيونداي',
            'model' => 'إلنترا',
            'year' => 2023,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 2000,
            'daily_rate' => 1000,
            'monthly_rate' => 22000,
            'default_deposit' => 3000,
            'is_active' => true,
        ]);

        $contract = Contract::query()->create([
            'number' => 'CNT-ACT-OPEN',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'status' => 'active',
            'starts_at' => now()->subDays(5),
            'planned_ends_at' => now()->addDays(25),
            'approved_days' => 30,
            'rent_basis' => 'monthly',
            'frozen_daily_rate' => 1000,
            'frozen_monthly_rate' => 22000,
            'frozen_agreed_deposit' => 3000,
            'period_rent' => null,
        ]);

        $this->artisan('rpm:enrich-live-demo')->assertSuccessful();

        $this->assertNull($contract->fresh()->period_rent);
        $this->assertSame(22000, app(ContractLedgerMath::class)->chargeTotal($contract->fresh()));
    }
}
