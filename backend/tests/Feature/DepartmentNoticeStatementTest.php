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

class DepartmentNoticeStatementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        TenantSetting::query()->create([
            'center_name' => 'مركز آر بي إم للتأجير',
            'operational' => [
                'print_phone' => '01125253888',
                'print_signer_name' => 'أ/ محمد عبدالقادر',
                'company_tagline' => 'لتجارة وإيجار أحدث أنواع السيارات',
            ],
            'feature_flags' => ['department_notice' => true],
        ]);
        $admin = User::factory()->create([
            'role_id' => Role::query()->where('name', 'super_admin')->firstOrFail()->id,
            'is_active' => true,
        ]);
        Sanctum::actingAs($admin);
    }

    public function test_department_notice_returns_rental_statements(): void
    {
        $customer = Customer::query()->create([
            'full_name' => 'عميل البيان',
            'phone' => '01009998877',
            'national_id' => '29001011234567',
            'address' => 'حدائق حلوان',
            'birth_date' => '1990-01-01',
            'license_number' => 'LIC-DN',
            'license_expires_on' => now()->addYears(2)->toDateString(),
            'status' => 'active',
        ]);
        $vehicle = Vehicle::query()->create([
            'code' => 'DN-1',
            'plate' => 'ع ص ب 1055',
            'brand' => 'MG',
            'model' => 'لانوس',
            'year' => 2018,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 90000,
            'daily_rate' => 800,
            'default_deposit' => 2000,
            'is_active' => true,
        ]);
        Contract::query()->create([
            'number' => 'CNT-DN-1',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'planned_ends_at' => now()->addDays(4),
            'approved_days' => 5,
            'notes' => 'ملاحظة بيان',
        ]);

        $res = $this->getJson('/api/department-notice?filter=all')->assertOk();
        $res->assertJsonPath('data.signer_name', 'أ/ محمد عبدالقادر')
            ->assertJsonPath('data.phones.0', '01125253888')
            ->assertJsonPath('data.statements.0.renter_name', 'عميل البيان')
            ->assertJsonPath('data.statements.0.national_id', '29001011234567')
            ->assertJsonPath('data.statements.0.plate', 'ع ص ب 1055')
            ->assertJsonPath('data.statements.0.address', 'حدائق حلوان')
            ->assertJsonPath('data.statements.0.notes', 'ملاحظة بيان');
        $this->assertStringContainsString('لانوس', (string) $res->json('data.statements.0.vehicle_type'));
    }
}
