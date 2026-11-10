<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContractNumberSequenceCollisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_survives_stale_sequence_behind_seeded_numbers(): void
    {
        PermissionCatalog::ensureSeeded();
        $admin = User::factory()->create([
            'role_id' => Role::query()->where('name', 'super_admin')->firstOrFail()->id,
            'is_active' => true,
        ]);
        Sanctum::actingAs($admin);

        $customer = Customer::query()->create([
            'full_name' => 'عميل',
            'phone' => '01112223344',
            'birth_date' => '1990-01-01',
            'license_number' => 'L1',
            'license_expires_on' => now()->addYear()->toDateString(),
            'status' => 'active',
        ]);
        $vehicle = Vehicle::query()->create([
            'code' => 'V1', 'plate' => 'أ 1', 'brand' => 'MG', 'model' => 'RX5',
            'year' => 2024, 'category' => 'SUV', 'fuel_type' => 'بنزين',
            'odometer' => 1000, 'daily_rate' => 1500, 'default_deposit' => 2000, 'is_active' => true,
        ]);

        // Seed high numbered contract like production CNT-M-0110
        DB::table('contracts')->insert([
            'number' => 'CNT-M-0110',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'status' => 'cancelled',
            'starts_at' => now()->subMonths(2),
            'planned_ends_at' => now()->subMonths(2)->addDays(2),
            'frozen_daily_rate' => 1000,
            'frozen_agreed_deposit' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // And early CNT-00001 style
        DB::table('contracts')->insert([
            'number' => 'CNT-00001',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'status' => 'cancelled',
            'starts_at' => now()->subMonths(3),
            'planned_ends_at' => now()->subMonths(3)->addDays(2),
            'frozen_daily_rate' => 1000,
            'frozen_agreed_deposit' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Stale sequence pointing at already-used 1
        DB::table('contract_number_sequences')->where('id', 1)->update(['next_value' => 1]);

        $v2 = Vehicle::query()->create([
            'code' => 'V2', 'plate' => 'أ 2', 'brand' => 'MG', 'model' => 'ZS',
            'year' => 2024, 'category' => 'SUV', 'fuel_type' => 'بنزين',
            'odometer' => 1000, 'daily_rate' => 1500, 'default_deposit' => 2000, 'is_active' => true,
        ]);

        $res = $this->postJson('/api/contracts', [
            'customer_id' => $customer->id,
            'vehicle_id' => $v2->id,
            'starts_at' => now()->addDays(3)->toDateTimeString(),
            'planned_ends_at' => now()->addDays(5)->toDateTimeString(),
        ]);

        if ($res->status() >= 400) {
            dump($res->json(), $res->status());
        }
        $res->assertCreated();
        $this->assertNotSame('CNT-00001', $res->json('data.number'));
    }
}
