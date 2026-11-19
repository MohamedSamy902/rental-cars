<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DepositCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private Vehicle $vehicle;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        TenantSetting::query()->create([
            'center_name' => 'اختبار عملة التأمين',
            'feature_flags' => [],
            'operational' => [],
        ]);
        $admin = User::factory()->create([
            'role_id' => Role::query()->where('name', 'super_admin')->firstOrFail()->id,
            'is_active' => true,
        ]);
        Sanctum::actingAs($admin);

        $this->customer = Customer::query()->create([
            'full_name' => 'عميل تأمين دولاري',
            'phone' => '01044445555',
            'birth_date' => '1990-01-01',
            'license_number' => 'LIC-DEP-CUR',
            'license_expires_on' => now()->addYears(2)->toDateString(),
            'status' => 'active',
        ]);
        $this->vehicle = Vehicle::query()->create([
            'code' => 'DEP-CUR',
            'plate' => 'د و ل 1',
            'brand' => 'تويوتا',
            'model' => 'كامري',
            'year' => 2023,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 1000,
            'daily_rate' => 900,
            'default_deposit' => 500,
            'chassis_number' => 'CHSDEPCUR1',
            'is_active' => true,
        ]);
    }

    public function test_contract_stores_written_deposit_currency(): void
    {
        $res = $this->postJson('/api/contracts', [
            'customer_id' => $this->customer->id,
            'vehicle_id' => $this->vehicle->id,
            'starts_at' => now()->addDay()->toDateTimeString(),
            'planned_ends_at' => now()->addDays(4)->toDateTimeString(),
            'daily_rate' => 900,
            'deposit' => 500,
            'deposit_currency' => 'دولار',
            'payment_amount' => 900,
            'payment_method' => 'cash',
        ])->assertCreated();

        $this->assertSame('دولار', $res->json('data.frozen_deposit_currency'));
        $this->assertSame(500, (int) $res->json('data.frozen_agreed_deposit'));

        $this->assertDatabaseHas('contracts', [
            'id' => $res->json('data.id'),
            'frozen_deposit_currency' => 'دولار',
            'frozen_agreed_deposit' => 500,
        ]);
    }

    public function test_deposit_currency_defaults_to_egyptian_pound_when_omitted(): void
    {
        $res = $this->postJson('/api/contracts', [
            'customer_id' => $this->customer->id,
            'vehicle_id' => $this->vehicle->id,
            'starts_at' => now()->addDay()->toDateTimeString(),
            'planned_ends_at' => now()->addDays(3)->toDateTimeString(),
            'daily_rate' => 900,
            'deposit' => 300,
            'payment_amount' => 900,
            'payment_method' => 'cash',
        ])->assertCreated();

        $this->assertSame('جنيه مصري', $res->json('data.frozen_deposit_currency'));
    }
}
