<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Violation;
use App\Services\Accounting\AccountingService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ViolationsOnBookingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $customer;

    private Vehicle $vehicle;

    private Contract $contract;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        app(AccountingService::class)->ensureChartOfAccounts();
        TenantSetting::query()->create([
            'center_name' => 'اختبار',
            'feature_flags' => [],
            'operational' => [],
        ]);
        $adminRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        $this->admin = User::factory()->create([
            'role_id' => $adminRole->id,
            'is_active' => true,
            'name' => 'أدمن المخالفات',
        ]);
        Sanctum::actingAs($this->admin);

        $this->customer = Customer::query()->create([
            'full_name' => 'عميل مخالفات',
            'phone' => '01009998877',
            'birth_date' => '1992-01-01',
            'license_number' => 'LIC-V',
            'license_expires_on' => now()->addYears(2)->toDateString(),
            'status' => 'active',
        ]);

        $this->vehicle = Vehicle::query()->create([
            'code' => 'VEH-VIO',
            'plate' => 'ق ص 9090',
            'brand' => 'تويوتا',
            'model' => 'كورولا',
            'year' => 2022,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 20000,
            'odometer_source' => 'manual',
            'daily_rate' => 800,
            'default_deposit' => 1500,
            'is_active' => true,
        ]);

        $this->contract = Contract::query()->create([
            'number' => 'CNT-VIO-1',
            'customer_id' => $this->customer->id,
            'vehicle_id' => $this->vehicle->id,
            'status' => 'active',
            'starts_at' => now()->subDays(2),
            'planned_ends_at' => now()->addDays(3),
            'approved_days' => 5,
            'frozen_daily_rate' => 800,
            'frozen_agreed_deposit' => 1500,
            'frozen_daily_km_allowance' => 200,
            'frozen_excess_km_rate' => 5,
            'sales_user_id' => $this->admin->id,
            'odometer_out' => 20000,
        ]);
    }

    public function test_store_requires_booking_and_records_creator(): void
    {
        $this->postJson('/api/violations', [
            'occurred_at' => now()->toDateString(),
            'amount' => 350,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['contract_id']);

        $created = $this->postJson('/api/violations', [
            'contract_id' => $this->contract->id,
            'occurred_at' => now()->toDateString(),
            'amount' => 350,
            'photo_paths' => ['/media/1/file', '/media/2/file'],
        ])->assertCreated()
            ->assertJsonPath('data.amount', 350)
            ->assertJsonPath('data.contract_id', $this->contract->id)
            ->assertJsonPath('data.vehicle_id', $this->vehicle->id)
            ->assertJsonPath('data.customer_id', $this->customer->id)
            ->assertJsonPath('data.status', 'assigned')
            ->assertJsonPath('data.created_by', $this->admin->id)
            ->assertJsonPath('data.created_by_name', 'أدمن المخالفات')
            ->assertJsonPath('data.photo_paths.0', '/media/1/file')
            ->assertJsonPath('data.photo_paths.1', '/media/2/file');

        $this->assertDatabaseHas('violations', [
            'id' => $created->json('data.id'),
            'created_by' => $this->admin->id,
            'contract_id' => $this->contract->id,
            'photo_path' => '/media/1/file',
        ]);
    }

    public function test_dossier_and_index_expose_open_violations_due(): void
    {
        Violation::query()->create([
            'vehicle_id' => $this->vehicle->id,
            'contract_id' => $this->contract->id,
            'customer_id' => $this->customer->id,
            'occurred_at' => now()->subDay(),
            'amount' => 200,
            'status' => 'assigned',
            'created_by' => $this->admin->id,
        ]);
        Violation::query()->create([
            'vehicle_id' => $this->vehicle->id,
            'contract_id' => $this->contract->id,
            'customer_id' => $this->customer->id,
            'occurred_at' => now()->subDays(2),
            'amount' => 150,
            'status' => 'new',
            'created_by' => $this->admin->id,
        ]);
        Violation::query()->create([
            'vehicle_id' => $this->vehicle->id,
            'contract_id' => $this->contract->id,
            'customer_id' => $this->customer->id,
            'occurred_at' => now()->subDays(5),
            'amount' => 999,
            'status' => 'confirmed',
            'created_by' => $this->admin->id,
            'confirmed_by' => $this->admin->id,
            'confirmed_at' => now(),
        ]);

        $this->getJson("/api/contracts/{$this->contract->id}")
            ->assertOk()
            ->assertJsonPath('data.open_violations_total', 350)
            ->assertJsonPath('data.ledger.violations_due', 350)
            ->assertJsonCount(2, 'data.open_violations');

        $list = $this->getJson('/api/contracts?per_page=50')->assertOk();
        $row = collect($list->json('data'))->firstWhere('id', $this->contract->id);
        $this->assertNotNull($row);
        $this->assertSame(350, (int) $row['open_violations_total']);
        $this->assertSame(2, (int) $row['open_violations_count']);
    }

    public function test_index_shows_who_uploaded(): void
    {
        $this->postJson('/api/violations', [
            'contract_id' => $this->contract->id,
            'occurred_at' => now()->toDateString(),
            'amount' => 120,
        ])->assertCreated();

        $this->getJson('/api/violations')
            ->assertOk()
            ->assertJsonPath('data.0.created_by_name', 'أدمن المخالفات')
            ->assertJsonPath('data.0.creator.name', 'أدمن المخالفات');
    }
}
