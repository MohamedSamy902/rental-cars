<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VehicleQrVisitorCardTest extends TestCase
{
    use RefreshDatabase;

    private Vehicle $vehicle;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        TenantSetting::query()->create([
            'center_name' => 'مركز آر بي إم',
            'logo_path' => null,
            'operational' => [
                'print_phone' => '01125253888',
                'print_address' => 'حدائق حلوان — القاهرة',
                'facebook' => 'https://facebook.com/rpm',
            ],
        ]);
        $this->customer = Customer::query()->create([
            'full_name' => 'عميل الحيازة',
            'phone' => '01005551234',
            'national_id' => '29501011234567',
            'birth_date' => '1995-01-01',
            'license_number' => 'LIC-QR',
            'license_expires_on' => now()->addYears(2)->toDateString(),
            'status' => 'active',
        ]);
        $this->vehicle = Vehicle::query()->create([
            'code' => 'QR-1',
            'plate' => 'أ ب ج 1234',
            'brand' => 'هيونداي',
            'model' => 'إلنترا',
            'year' => 2024,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 10000,
            'daily_rate' => 1000,
            'default_deposit' => 2000,
            'is_active' => true,
        ]);
    }

    public function test_public_card_shows_center_only_when_not_rented(): void
    {
        $res = $this->getJson('/api/public/vehicles/'.$this->vehicle->id.'/qr-card')->assertOk();

        $res->assertJsonPath('data.center.name', 'مركز آر بي إم')
            ->assertJsonPath('data.center.phone', '01125253888')
            ->assertJsonPath('data.center.address', 'حدائق حلوان — القاهرة')
            ->assertJsonPath('data.center.map_url', 'https://maps.app.goo.gl/7xn2CMaJ3socdB6r9?g_st=ic')
            ->assertJsonPath('data.center.tagline', 'لخدمات رجال الأعمال')
            ->assertJsonPath('data.center.social.facebook', 'https://facebook.com/rpm')
            ->assertJsonPath('data.is_rented', false)
            ->assertJsonPath('data.current_renter', null)
            ->assertJsonPath('data.credit.name', 'محمد سامي')
            ->assertJsonPath('data.credit.whatsapp_url', 'https://wa.me/201150100104');

        $encoded = json_encode($res->json(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('29501011234567', $encoded);
        $this->assertStringNotContainsString('عميل الحيازة', $encoded);
        $this->assertStringNotContainsString('01005551234', $encoded);
    }

    public function test_public_card_includes_current_renter_when_active_contract(): void
    {
        Contract::query()->create([
            'number' => 'CNT-QR-1',
            'customer_id' => $this->customer->id,
            'vehicle_id' => $this->vehicle->id,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'planned_ends_at' => now()->addDays(3),
        ]);

        $res = $this->getJson('/api/public/vehicles/'.$this->vehicle->id.'/qr-card')->assertOk();

        $res->assertJsonPath('data.is_rented', true)
            ->assertJsonPath('data.current_renter.full_name', 'عميل الحيازة')
            ->assertJsonPath('data.current_renter.phone', '01005551234');

        $encoded = json_encode($res->json(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('29501011234567', $encoded);
        $this->assertStringNotContainsString('CNT-QR-1', $encoded);
    }

    public function test_public_card_falls_back_to_config_contact_when_ops_empty(): void
    {
        TenantSetting::query()->first()?->update([
            'operational' => [],
        ]);

        $this->getJson('/api/public/vehicles/'.$this->vehicle->id.'/qr-card')
            ->assertOk()
            ->assertJsonPath('data.center.phone', '01125253888')
            ->assertJsonPath(
                'data.center.address',
                '17 شارع كورنيش النيل - حدائق حلوان - محافظة القاهرة'
            )
            ->assertJsonPath('data.center.tagline', 'لخدمات رجال الأعمال');
    }

    public function test_inactive_vehicle_is_hidden(): void
    {
        $this->vehicle->update(['is_active' => false]);
        $this->getJson('/api/public/vehicles/'.$this->vehicle->id.'/qr-card')->assertNotFound();
    }

    public function test_qr_payload_points_to_visitor_route(): void
    {
        config(['app.public_url' => 'https://fleet.example.test']);
        PermissionCatalog::ensureSeeded();
        $admin = User::factory()->create([
            'role_id' => Role::query()->where('name', 'super_admin')->firstOrFail()->id,
            'is_active' => true,
        ]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/qr?type=vehicle&id='.$this->vehicle->id)
            ->assertOk()
            ->assertJsonPath('data.qr', 'https://fleet.example.test/qr/vehicles/'.$this->vehicle->id);
    }
}
