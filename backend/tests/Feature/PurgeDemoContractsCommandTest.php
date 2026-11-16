<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurgeDemoContractsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_purge_removes_demo_contracts_only(): void
    {
        $customer = Customer::query()->create([
            'full_name' => 'عميل',
            'phone' => '01000000001',
            'birth_date' => '1990-01-01',
            'license_number' => 'L1',
            'license_expires_on' => now()->addYear()->toDateString(),
            'status' => 'active',
        ]);
        $vehicle = Vehicle::query()->create([
            'code' => 'D-1',
            'plate' => 'د ي م 1',
            'brand' => 'هيونداي',
            'model' => 'إلنترا',
            'year' => 2023,
            'category' => 'سيدان',
            'fuel_type' => 'بنزين',
            'odometer' => 1,
            'daily_rate' => 500,
            'default_deposit' => 0,
            'is_active' => true,
        ]);

        Contract::query()->create([
            'number' => 'DEMO-pending-261116',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'status' => 'pending',
            'starts_at' => now(),
            'planned_ends_at' => now()->addDays(2),
            'frozen_daily_rate' => 500,
            'frozen_agreed_deposit' => 0,
        ]);
        Contract::query()->create([
            'number' => 'CNT-REAL-1',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'status' => 'approved',
            'starts_at' => now()->addDays(3),
            'planned_ends_at' => now()->addDays(5),
            'frozen_daily_rate' => 500,
            'frozen_agreed_deposit' => 0,
        ]);

        $this->artisan('rpm:purge-demo-contracts', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, Contract::withTrashed()->where('number', 'DEMO-pending-261116')->count());
        $this->assertDatabaseHas('contracts', ['number' => 'CNT-REAL-1']);
    }

    public function test_enrich_without_samples_does_not_create_demo_contracts(): void
    {
        PermissionCatalog::ensureSeeded();
        User::factory()->create([
            'role_id' => Role::query()->where('name', 'super_admin')->firstOrFail()->id,
            'is_active' => true,
        ]);
        $this->artisan('rpm:enrich-live-demo')->assertSuccessful();
        $this->assertSame(0, Contract::query()->where('number', 'like', 'DEMO-%')->count());
    }
}
