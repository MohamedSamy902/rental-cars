<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\MediaFile;
use App\Models\Notification;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use App\Services\Accounting\AccountingService;
use App\Services\Contracts\ContractLifecycleService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuditFollowupFixesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ContractLifecycleService $life;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        app(AccountingService::class)->ensureChartOfAccounts();
        TenantSetting::query()->create([
            'center_name' => 'اختبار',
            'feature_flags' => [],
            'operational' => [
                'require_handover_signature' => false,
                'block_expired_license' => false,
                'block_expired_vehicle_docs' => false,
            ],
        ]);
        $adminRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        $this->admin = User::factory()->create(['role_id' => $adminRole->id, 'is_active' => true]);
        Sanctum::actingAs($this->admin);
        $this->life = app(ContractLifecycleService::class);
    }

    public function test_approve_freezes_current_vehicle_rates(): void
    {
        $opsRole = Role::query()->where('name', 'ops_manager')->firstOrFail();
        $opsRole->permissions()->syncWithoutDetaching(
            \App\Models\Permission::query()->whereIn('key', ['contracts.view', 'notifications.view'])->pluck('id')
        );
        User::factory()->create(['role_id' => $opsRole->id, 'is_active' => true]);

        [$customer, $vehicle] = $this->seedParty(900, 2000);
        $c = $this->life->create([
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'starts_at' => now()->addDay()->toDateTimeString(),
            'planned_ends_at' => now()->addDays(4)->toDateTimeString(),
        ], $this->admin->id);

        $this->assertSame(900, (int) $c->frozen_daily_rate);

        $vehicle->update(['daily_rate' => 1100, 'default_deposit' => 3000]);
        $approved = $this->life->approve($c, $this->admin->id);

        $this->assertSame(1100, (int) $approved->frozen_daily_rate);
        $this->assertSame(3000, (int) $approved->frozen_agreed_deposit);
        $this->assertTrue(Notification::query()->where('link_type', 'contract')->where('link_id', $approved->id)->exists());
    }

    public function test_contract_numbers_use_sequence_table(): void
    {
        [$customer, $vehicle] = $this->seedParty();
        $a = $this->life->create([
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'starts_at' => now()->addDays(10)->toDateTimeString(),
            'planned_ends_at' => now()->addDays(12)->toDateTimeString(),
        ], $this->admin->id);
        $b = $this->life->create([
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'starts_at' => now()->addDays(20)->toDateTimeString(),
            'planned_ends_at' => now()->addDays(22)->toDateTimeString(),
        ], $this->admin->id);

        $this->assertNotSame($a->number, $b->number);
        $this->assertDatabaseHas('contract_number_sequences', ['id' => 1]);
    }

    public function test_customer_rejects_free_image_path(): void
    {
        $res = $this->postJson('/api/customers', [
            'full_name' => 'عميل اختبار',
            'phone' => '01012345678',
            'birth_date' => '1990-01-01',
            'license_number' => 'L-1',
            'license_expires_on' => now()->addYear()->toDateString(),
            'national_id_image_path' => '../../etc/passwd',
        ]);

        $res->assertStatus(422)->assertJsonValidationErrors(['national_id_image_path']);
    }

    public function test_customer_accepts_media_reference_path(): void
    {
        $media = MediaFile::query()->create([
            'disk' => 'local',
            'path' => 'uploads/x.jpg',
            'original_name' => 'x.jpg',
            'mime_type' => 'image/jpeg',
            'kind' => 'image',
            'collection' => 'customers_id',
            'purpose' => 'national_id',
            'size_bytes' => 100,
            'uploaded_by' => $this->admin->id,
        ]);

        $res = $this->postJson('/api/customers', [
            'full_name' => 'عميل وسائط',
            'phone' => '01012345678',
            'birth_date' => '1990-01-01',
            'license_number' => 'L-2',
            'license_expires_on' => now()->addYear()->toDateString(),
            'national_id_image_path' => '/media/'.$media->id.'/file',
        ]);

        $res->assertCreated();
        $this->assertSame('/media/'.$media->id.'/file', $res->json('data.national_id_image_path'));
    }

    public function test_open_work_order_blocks_without_manual_status(): void
    {
        [, $vehicle] = $this->seedParty();
        WorkOrder::query()->create([
            'vehicle_id' => $vehicle->id,
            'opened_on' => now()->toDateString(),
            'odometer' => (int) $vehicle->odometer,
            'status' => 'open',
            'puts_in_maintenance' => true,
            'parts_cost' => 0,
            'labor_cost' => 0,
        ]);

        $res = $this->getJson('/api/vehicles/'.$vehicle->id)->assertOk();
        $this->assertSame('maintenance', $res->json('data.status'));
    }

    public function test_archived_vehicle_frees_plate_for_reuse(): void
    {
        [, $vehicle] = $this->seedParty();
        $plate = $vehicle->plate;
        $code = $vehicle->code;

        $this->deleteJson('/api/vehicles/'.$vehicle->id)->assertOk();

        $res = $this->postJson('/api/vehicles', [
            'code' => $code,
            'plate' => $plate,
            'brand' => 'تويوتا',
            'model' => 'كورولا',
            'year' => 2022,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 1000,
            'daily_rate' => 800,
            'default_deposit' => 2000,
        ]);

        $res->assertCreated();
    }

    public function test_sales_cannot_view_others_contract(): void
    {
        $salesRole = Role::query()->where('name', 'sales')->firstOrFail();
        $salesRole->permissions()->syncWithoutDetaching(
            \App\Models\Permission::query()->where('key', 'contracts.view')->pluck('id')
        );
        $salesA = User::factory()->create(['role_id' => $salesRole->id, 'is_active' => true]);
        $salesB = User::factory()->create(['role_id' => $salesRole->id, 'is_active' => true]);

        [$customer, $vehicle] = $this->seedParty();
        $contract = $this->life->create([
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'starts_at' => now()->addDays(30)->toDateTimeString(),
            'planned_ends_at' => now()->addDays(33)->toDateTimeString(),
        ], $salesA->id);

        Sanctum::actingAs($salesA);
        $this->getJson('/api/contracts/'.$contract->id)->assertOk();

        Sanctum::actingAs($salesB);
        $this->getJson('/api/contracts/'.$contract->id)->assertForbidden();
    }

    /**
     * @return array{0: Customer, 1: Vehicle}
     */
    private function seedParty(int $daily = 800, int $deposit = 2000): array
    {
        $customer = Customer::query()->create([
            'full_name' => 'عميل',
            'phone' => '01000000001',
            'birth_date' => '1990-01-01',
            'license_number' => 'LIC-'.uniqid(),
            'license_expires_on' => now()->addYears(2)->toDateString(),
            'status' => 'active',
        ]);
        $vehicle = Vehicle::query()->create([
            'code' => 'V-'.uniqid(),
            'plate' => 'P-'.uniqid(),
            'brand' => 'هيونداي',
            'model' => 'النترا',
            'year' => 2021,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 10000,
            'daily_rate' => $daily,
            'default_deposit' => $deposit,
            'is_active' => true,
            'rental_priority' => 50,
        ]);

        return [$customer, $vehicle];
    }
}
