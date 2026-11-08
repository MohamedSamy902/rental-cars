<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleOwner;
use App\Services\Accounting\AccountingService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QaV6FixTicketsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        app(AccountingService::class)->ensureChartOfAccounts();
        TenantSetting::query()->create([
            'center_name' => 'اختبار',
            'feature_flags' => [],
            'operational' => ['min_customer_age' => 21],
        ]);
        $adminRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        $this->admin = User::factory()->create([
            'role_id' => $adminRole->id,
            'is_active' => true,
            'email' => 'v6-admin@rpm.local',
            'password' => Hash::make('password'),
        ]);
        Sanctum::actingAs($this->admin);
    }

    public function test_v6_01_rental_cannot_pass_the_owner_agreement_end(): void
    {
        $owner = VehicleOwner::query()->create([
            'full_name' => 'مالك اتفاق',
            'phone' => '01011110001',
            'is_active' => true,
        ]);
        $ended = $this->vehicle($owner->id, '2026-08-31');
        $open = $this->vehicle($owner->id, '2026-12-14');
        $customer = $this->customer();

        $this->postJson('/api/contracts', [
            'customer_id' => $customer->id,
            'vehicle_id' => $ended->id,
            'starts_at' => '2026-11-20 10:00:00',
            'planned_ends_at' => '2026-11-22 10:00:00',
        ])->assertStatus(422)->assertJsonPath('message', 'عقد المالك لهذه العربية ينتهي في 2026-08-31 — لا يمكن تأجيرها بعد هذا التاريخ');

        $this->postJson('/api/contracts', [
            'customer_id' => $customer->id,
            'vehicle_id' => $open->id,
            'starts_at' => '2026-12-12 10:00:00',
            'planned_ends_at' => '2026-12-18 10:00:00',
        ])->assertStatus(422);
    }

    public function test_v6_02_unpaginated_lists_reject_bad_page_size(): void
    {
        foreach (['/api/expenses', '/api/accounting/payments', '/api/petty-cash', '/api/inventory'] as $path) {
            $this->getJson($path.'?per_page=-1')->assertStatus(422)->assertJsonValidationErrors(['per_page']);
            $this->getJson($path.'?page=0')->assertStatus(422)->assertJsonValidationErrors(['page']);
            $this->getJson($path.'?per_page=50')->assertOk()->assertJsonStructure(['data', 'meta' => ['current_page', 'last_page', 'total']]);
        }
    }

    public function test_v6_03_dashboard_hides_contract_and_customer_blocks_without_permission(): void
    {
        $role = Role::query()->create(['name' => 'v6_vehicles_only', 'label' => 'عربيات فقط']);
        $perm = Permission::query()->where('key', 'vehicles.view')->firstOrFail();
        $role->permissions()->sync([$perm->id]);
        $user = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
        Sanctum::actingAs($user);

        $dash = $this->getJson('/api/dashboard')->assertOk();
        $dash->assertJsonPath('tasks', []);
        $dash->assertJsonPath('recent_contracts', []);
        $dash->assertJsonPath('kpis.customers', null);
        $dash->assertJsonPath('kpis.active_contracts', null);
        $dash->assertJsonPath('profitability_compare', null);
        $dash->assertJsonPath('daily_cash', null);
    }

    public function test_v6_04_copy_age_login_and_duplicate_phone(): void
    {
        $payload = [
            'full_name' => 'صغير',
            'phone' => '01022220001',
            'birth_date' => now()->subYears(11)->toDateString(),
            'license_number' => 'LIC-V6-AGE',
            'license_expires_on' => now()->addYears(2)->toDateString(),
        ];
        $this->postJson('/api/customers', $payload)->assertStatus(422)
            ->assertJsonPath('errors.birth_date.0', 'عمر العميل أقل من 21 سنة.');

        $this->postJson('/api/work-orders', [])->assertStatus(422);
        $message = (string) $this->postJson('/api/work-orders', [])->json('message');
        $this->assertStringNotContainsString('and', $message);
        $this->assertStringNotContainsString('more', $message);

        $staff = User::factory()->create([
            'role_id' => Role::query()->where('name', 'viewer')->firstOrFail()->id,
            'is_active' => false,
            'email' => 'stopped@rpm.local',
            'password' => Hash::make('password'),
        ]);
        $this->postJson('/api/login', [
            'email' => $staff->email,
            'password' => 'password',
        ])->assertStatus(422)->assertJsonPath('errors.login.0', 'الحساب موقوف، تواصل مع المدير');

        $this->postJson('/api/customers', [
            'full_name' => 'أول',
            'phone' => '01022220002',
            'birth_date' => '1990-01-01',
            'license_number' => 'LIC-V6-A',
            'license_expires_on' => now()->addYears(2)->toDateString(),
        ])->assertCreated();
        $second = $this->postJson('/api/customers', [
            'full_name' => 'ثان',
            'phone' => '01022220003',
            'birth_date' => '1991-01-01',
            'license_number' => 'LIC-V6-B',
            'license_expires_on' => now()->addYears(2)->toDateString(),
        ])->assertCreated();
        $this->putJson('/api/customers/'.$second->json('data.id'), ['phone' => '01022220002'])
            ->assertStatus(422)
            ->assertJsonPath('errors.phone.0', 'رقم الهاتف مسجل لعميل آخر.');
    }

    private function customer(): Customer
    {
        $created = $this->postJson('/api/customers', [
            'full_name' => 'عميل اتفاق',
            'phone' => '01033330001',
            'birth_date' => '1990-04-04',
            'license_number' => 'LIC-V6-C',
            'license_expires_on' => now()->addYears(3)->toDateString(),
        ])->assertCreated();

        return Customer::query()->findOrFail($created->json('data.id'));
    }

    private function vehicle(int $ownerId, string $endsOn): Vehicle
    {
        return Vehicle::query()->create([
            'code' => 'V6-'.random_int(1000, 9999),
            'plate' => 'V6 '.random_int(1000, 9999),
            'brand' => 'كيا',
            'model' => 'سيراتو',
            'year' => 2021,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 100,
            'daily_rate' => 400,
            'default_deposit' => 500,
            'is_active' => true,
            'owner_id' => $ownerId,
            'owner_monthly_amount' => 1000,
            'owner_since' => '2026-01-01',
            'owner_agreement_starts_on' => '2026-01-01',
            'owner_agreement_ends_on' => $endsOn,
        ]);
    }
}
