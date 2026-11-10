<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OwnerVehicleContractRequiredFieldsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $customer;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        $this->admin = User::factory()->create([
            'role_id' => Role::query()->where('name', 'super_admin')->firstOrFail()->id,
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->admin);
        $this->customer = Customer::query()->create([
            'full_name' => 'عميل إلزام',
            'phone' => '01099887766',
            'birth_date' => '1992-01-01',
            'license_number' => 'LIC-REQ',
            'license_expires_on' => now()->addYears(2)->toDateString(),
            'status' => 'active',
        ]);
        $this->vehicle = Vehicle::query()->create([
            'code' => 'REQ-1',
            'plate' => 'ر ق ب 1',
            'brand' => 'نيسان',
            'model' => 'صني',
            'year' => 2022,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 1000,
            'daily_rate' => 500,
            'default_deposit' => 1500,
            'chassis_number' => 'REQCHS00001',
            'is_active' => true,
        ]);
    }

    public function test_owner_create_requires_phone_national_id_and_id_images(): void
    {
        $this->injectRequiredCreateFields = false;
        $this->postJson('/api/owners', [
            'full_name' => 'مالك ناقص',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['phone', 'national_id', 'id_front_path', 'id_back_path']);

        $this->injectRequiredCreateFields = true;
        $this->postJson('/api/owners', [
            'full_name' => 'مالك مكتمل',
            'phone' => '01011112222',
            'national_id' => '29001011234567',
            'id_front_path' => 'tests/owners/front.jpg',
            'id_back_path' => 'tests/owners/back.jpg',
        ])->assertCreated();
    }

    public function test_vehicle_create_requires_chassis_number(): void
    {
        $this->injectRequiredCreateFields = false;
        $this->postJson('/api/vehicles', [
            'plate_letters' => 'أ ب ج',
            'plate_numbers' => '7788',
            'brand' => 'هيونداي',
            'model' => 'إكسينت',
            'year' => 2021,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 2000,
            'daily_rate' => 600,
            'default_deposit' => 2000,
            'chassis_number' => '',
        ])->assertStatus(422)->assertJsonValidationErrors(['chassis_number']);
    }

    public function test_contract_create_requires_positive_payment_not_over_rent_total(): void
    {
        $this->injectRequiredCreateFields = false;
        $starts = now()->addDay()->startOfHour();
        $ends = $starts->copy()->addDays(2);

        $this->postJson('/api/contracts', [
            'customer_id' => $this->customer->id,
            'vehicle_id' => $this->vehicle->id,
            'starts_at' => $starts->toDateTimeString(),
            'planned_ends_at' => $ends->toDateTimeString(),
            'daily_rate' => 500,
        ])->assertStatus(422)->assertJsonValidationErrors(['payment_amount']);

        $this->postJson('/api/contracts', [
            'customer_id' => $this->customer->id,
            'vehicle_id' => $this->vehicle->id,
            'starts_at' => $starts->toDateTimeString(),
            'planned_ends_at' => $ends->toDateTimeString(),
            'daily_rate' => 500,
            'payment_amount' => 0,
        ])->assertStatus(422)->assertJsonValidationErrors(['payment_amount']);

        $this->postJson('/api/contracts', [
            'customer_id' => $this->customer->id,
            'vehicle_id' => $this->vehicle->id,
            'starts_at' => $starts->toDateTimeString(),
            'planned_ends_at' => $ends->toDateTimeString(),
            'daily_rate' => 500,
            'payment_amount' => -50,
        ])->assertStatus(422)->assertJsonValidationErrors(['payment_amount']);

        // يومان × 500 = 1000 — دفعة 1500 مرفوضة
        $this->postJson('/api/contracts', [
            'customer_id' => $this->customer->id,
            'vehicle_id' => $this->vehicle->id,
            'starts_at' => $starts->toDateTimeString(),
            'planned_ends_at' => $ends->toDateTimeString(),
            'daily_rate' => 500,
            'payment_amount' => 1500,
        ])->assertStatus(422)->assertJsonValidationErrors(['payment_amount']);

        $this->postJson('/api/contracts', [
            'customer_id' => $this->customer->id,
            'vehicle_id' => $this->vehicle->id,
            'starts_at' => $starts->toDateTimeString(),
            'planned_ends_at' => $ends->toDateTimeString(),
            'daily_rate' => 500,
            'payment_amount' => 500,
            'payment_method' => 'cash',
            'balance_due_on' => now()->addDays(3)->toDateString(),
        ])->assertCreated();
    }
}
