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

class ValidationArabicTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        TenantSetting::query()->create([
            'center_name' => 'اختبار',
            'feature_flags' => [
                'showroom' => true, 'petty_cash' => true, 'waitlist' => true, 'inventory' => true,
                'department_notice' => true, 'chat' => true, 'evaluations' => true, 'hr' => true,
            ],
            'operational' => [
                'require_handover_signature' => false,
                'block_expired_license' => false,
                'block_expired_vehicle_docs' => false,
            ],
        ]);
        $admin = Role::query()->where('name', 'super_admin')->firstOrFail();
        $this->user = User::factory()->create(['role_id' => $admin->id, 'is_active' => true]);
        Sanctum::actingAs($this->user);
    }

    public function test_eastern_digits_phone_is_normalized_on_customer_store(): void
    {
        $response = $this->postJson('/api/customers', [
            'full_name' => 'عميل أرقام شرقية',
            'phone' => '٠١٠٠١١١٢٢٣٣',
            'birth_date' => '1990-01-01',
            'license_number' => 'LIC-EAST-1',
            'license_expires_on' => now()->addYears(2)->toDateString(),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.phone', '01001112233');

        $this->assertDatabaseHas('customers', [
            'full_name' => 'عميل أرقام شرقية',
            'phone' => '01001112233',
        ]);
    }

    public function test_invalid_national_id_returns_arabic_message(): void
    {
        $response = $this->postJson('/api/customers', [
            'full_name' => 'عميل هوية خاطئة',
            'phone' => '01001112233',
            'national_id' => '123',
            'birth_date' => '1990-01-01',
            'license_number' => 'LIC-NID-1',
            'license_expires_on' => now()->addYears(2)->toDateString(),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['national_id']);

        $messages = $response->json('errors.national_id');
        $this->assertIsArray($messages);
        $this->assertNotEmpty($messages);
        $this->assertStringContainsString('الرقم القومي', $messages[0]);
    }

    public function test_contract_end_before_start_returns_arabic_message(): void
    {
        $customer = Customer::query()->create([
            'full_name' => 'عميل عقد',
            'phone' => '01002223344',
            'birth_date' => '1990-01-01',
            'license_number' => 'LIC-C-1',
            'license_expires_on' => now()->addYears(2)->toDateString(),
            'status' => 'active',
        ]);

        $vehicle = Vehicle::query()->create([
            'code' => 'V-AR-1',
            'plate' => 'أ ب ج 111',
            'brand' => 'هيونداي',
            'model' => 'إلنترا',
            'year' => 2023,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 1000,
            'daily_rate' => 800,
            'default_deposit' => 1500,
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/contracts', [
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'starts_at' => '2026-10-20T10:00:00+02:00',
            'planned_ends_at' => '2026-10-18T10:00:00+02:00',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['planned_ends_at']);

        $messages = $response->json('errors.planned_ends_at');
        $this->assertIsArray($messages);
        $this->assertNotEmpty($messages);
        $joined = implode(' ', $messages);
        $this->assertTrue(
            str_contains($joined, 'بعد') || str_contains($joined, 'النهاية'),
            'Expected Arabic after/end message, got: '.$joined
        );
    }

    public function test_soft_deleted_vehicle_is_rejected_on_contract_store(): void
    {
        $customer = Customer::query()->create([
            'full_name' => 'عميل سيارة محذوفة',
            'phone' => '01003334455',
            'birth_date' => '1990-01-01',
            'license_number' => 'LIC-DEL-1',
            'license_expires_on' => now()->addYears(2)->toDateString(),
            'status' => 'active',
        ]);

        $vehicle = Vehicle::query()->create([
            'code' => 'V-DEL-1',
            'plate' => 'محذوف 999',
            'brand' => 'كيا',
            'model' => 'سيراتو',
            'year' => 2022,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 5000,
            'daily_rate' => 900,
            'default_deposit' => 1600,
            'is_active' => true,
        ]);
        $vehicle->delete();

        $response = $this->postJson('/api/contracts', [
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'starts_at' => now()->toIso8601String(),
            'planned_ends_at' => now()->addDays(2)->toIso8601String(),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['vehicle_id']);

        $messages = $response->json('errors.vehicle_id');
        $this->assertIsArray($messages);
        $this->assertNotEmpty($messages);
        $this->assertStringContainsString('العربية', $messages[0]);
    }
}
