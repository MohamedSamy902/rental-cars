<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Contracts\ContractLifecycleService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApprovePreservesFrozenRatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_approve_keeps_create_time_price_override_not_vehicle_rate(): void
    {
        PermissionCatalog::ensureSeeded();
        $role = Role::query()->create(['name' => 'admin', 'label' => 'Admin']);
        $admin = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
        $admin->role->permissions()->sync(
            Permission::query()->pluck('id')
        );

        $vehicle = Vehicle::query()->create([
            'code' => 'VEH-9001',
            'plate' => 'أ ب ج 9001',
            'brand' => 'Toyota',
            'model' => 'Corolla',
            'year' => 2024,
            'category' => 'sedan',
            'fuel_type' => 'بنزين',
            'odometer' => 1000,
            'daily_rate' => 1000,
            'default_deposit' => 5000,
            'is_active' => true,
        ]);

        $customer = Customer::query()->create([
            'full_name' => 'عميل اختبار',
            'phone' => '01000000001',
            'license_number' => 'L-9001',
            'license_expires_on' => now()->addYear()->toDateString(),
            'birth_date' => '1990-01-01',
            'status' => 'active',
            'national_id_image_path' => 'customers/nid.jpg',
            'license_image_path' => 'customers/lic.jpg',
        ]);

        $life = app(ContractLifecycleService::class);
        $contract = $life->create([
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'starts_at' => now()->addDay()->startOfDay()->toDateTimeString(),
            'planned_ends_at' => now()->addDays(4)->startOfDay()->toDateTimeString(),
            'daily_rate' => 777,
            'deposit' => 3333,
            'daily_km_allowance' => 150,
            'excess_km_rate' => 8,
        ], $admin->id, true);

        $this->assertSame(777, (int) $contract->frozen_daily_rate);
        $this->assertSame(3333, (int) $contract->frozen_agreed_deposit);

        // Change vehicle rates after create — approve must NOT pick these up.
        $vehicle->update(['daily_rate' => 9999, 'default_deposit' => 8888]);

        $approved = $life->approve($contract, $admin->id);

        $this->assertSame('approved', $approved->status);
        $this->assertSame(777, (int) $approved->frozen_daily_rate);
        $this->assertSame(3333, (int) $approved->frozen_agreed_deposit);
        $this->assertSame(150, (int) $approved->frozen_daily_km_allowance);
        $this->assertSame(8, (int) $approved->frozen_excess_km_rate);
    }
}
