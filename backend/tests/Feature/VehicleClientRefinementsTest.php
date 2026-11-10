<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Models\VehicleDocumentHistory;
use App\Models\VehicleOwner;
use App\Models\WorkOrder;
use App\Services\Accounting\AccountingService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VehicleClientRefinementsTest extends TestCase
{
    use RefreshDatabase;

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
        Sanctum::actingAs(User::factory()->create(['role_id' => $adminRole->id, 'is_active' => true]));
    }

    public function test_auto_code_and_split_plate_on_create(): void
    {
        $res = $this->postJson('/api/vehicles', [
            'plate_letters' => 'أ ب ج',
            'plate_numbers' => '1234',
            'brand' => 'تويوتا',
            'model' => 'كورولا',
            'year' => 2022,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 1000,
            'daily_rate' => 800,
            'default_deposit' => 2000,
            'chassis_number' => 'CHS12345',
            'engine_number' => 'ENG12345',
        ])->assertCreated();

        $res->assertJsonPath('data.code', 'VEH-0001');
        $this->assertStringContainsString('1234', (string) $res->json('data.plate'));

        $catalog = $this->getJson('/api/vehicles/catalogs')->assertOk();
        $this->assertNotEmpty($catalog->json('data.brands'));
        $this->assertNotEmpty($catalog->json('data.addons'));
        $this->assertSame('VEH-0002', $catalog->json('data.next_code'));
    }

    public function test_can_add_brand_from_vehicle_form_flow(): void
    {
        $created = $this->postJson('/api/vehicles/brands', [
            'name' => 'لاند روفر',
        ])->assertCreated();

        $created->assertJsonPath('data.name', 'لاند روفر');
        $this->assertDatabaseHas('vehicle_brands', ['name' => 'لاند روفر', 'is_active' => 1]);

        $again = $this->postJson('/api/vehicles/brands', [
            'name' => 'لاند روفر',
        ])->assertCreated();
        $this->assertSame((int) $created->json('data.id'), (int) $again->json('data.id'));

        $names = collect($this->getJson('/api/vehicles/catalogs')->json('data.brands'))->pluck('name');
        $this->assertTrue($names->contains('لاند روفر'));
    }

    public function test_owner_agreement_expiring_filter_and_fields(): void
    {
        $owner = VehicleOwner::query()->create([
            'full_name' => 'مالك العقد',
            'phone' => '01001112223',
            'is_active' => true,
        ]);
        $due = Vehicle::query()->create([
            'code' => 'VEH-OWN-1',
            'plate' => 'م ل 1001',
            'brand' => 'كيا',
            'model' => 'سبورتاج',
            'year' => 2023,
            'category' => 'SUV',
            'fuel_type' => 'بنزين',
            'odometer' => 5000,
            'odometer_source' => 'manual',
            'daily_rate' => 1200,
            'default_deposit' => 3000,
            'is_active' => true,
            'owner_id' => $owner->id,
            'owner_monthly_amount' => 8000,
            'owner_agreement_starts_on' => now()->subYear()->toDateString(),
            'owner_agreement_ends_on' => now()->addDays(20)->toDateString(),
        ]);
        $later = Vehicle::query()->create([
            'code' => 'VEH-OWN-2',
            'plate' => 'م ل 1002',
            'brand' => 'تويوتا',
            'model' => 'كورولا',
            'year' => 2022,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 3000,
            'odometer_source' => 'manual',
            'daily_rate' => 800,
            'default_deposit' => 2000,
            'is_active' => true,
            'owner_id' => $owner->id,
            'owner_agreement_ends_on' => now()->addDays(90)->toDateString(),
        ]);

        $res = $this->getJson('/api/vehicles?status=owner_agreement_expiring&within_days=30')->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($due->id, $ids);
        $this->assertNotContains($later->id, $ids);
        $row = collect($res->json('data'))->firstWhere('id', $due->id);
        $this->assertSame(now()->addDays(20)->toDateString(), $row['owner_agreement_ends_on'] ?? null);
        $this->assertGreaterThanOrEqual(1, (int) $res->json('summary.owner_agreement_expiring'));
    }

    public function test_sim_recharge_filter_and_fields(): void
    {
        $due = Vehicle::query()->create([
            'code' => 'VEH-SIM-1',
            'plate' => 'س ج 9001',
            'brand' => 'تويوتا',
            'model' => 'كورولا',
            'year' => 2022,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 1000,
            'odometer_source' => 'manual',
            'daily_rate' => 700,
            'default_deposit' => 1500,
            'is_active' => true,
            'tracker_sim_number' => '01001234567',
            'tracker_sim_last_recharged_on' => now()->subDays(25)->toDateString(),
            'tracker_sim_next_recharge_on' => now()->addDays(5)->toDateString(),
            'tracker_sim_recharge_cycle_days' => 30,
        ]);
        $later = Vehicle::query()->create([
            'code' => 'VEH-SIM-2',
            'plate' => 'س ج 9002',
            'brand' => 'هيونداي',
            'model' => 'إلنترا',
            'year' => 2021,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 2000,
            'odometer_source' => 'manual',
            'daily_rate' => 750,
            'default_deposit' => 1500,
            'is_active' => true,
            'tracker_sim_number' => '01007654321',
            'tracker_sim_next_recharge_on' => now()->addDays(40)->toDateString(),
            'tracker_sim_recharge_cycle_days' => 30,
        ]);

        // التبويب الافتراضي: كل الشرائح
        $all = $this->getJson('/api/vehicles?status=sim_recharge')->assertOk();
        $allIds = collect($all->json('data'))->pluck('id')->all();
        $this->assertContains($due->id, $allIds);
        $this->assertContains($later->id, $allIds);

        // مستحق فقط خلال ١٤ يوماً
        $res = $this->getJson('/api/vehicles?status=sim_recharge&due_only=1&within_days=14')->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($due->id, $ids);
        $this->assertNotContains($later->id, $ids);
        $row = collect($res->json('data'))->firstWhere('id', $due->id);
        $this->assertSame('01001234567', $row['tracker_sim_number'] ?? null);
        $this->assertSame(now()->addDays(5)->toDateString(), $row['tracker_sim_next_recharge_on'] ?? null);
        $this->assertGreaterThanOrEqual(1, (int) $res->json('summary.sim_recharge'));

        $this->postJson("/api/vehicles/{$due->id}/sim-recharge", [
            'amount' => 75,
            'recharged_on' => now()->toDateString(),
            'next_recharge_on' => now()->addDays(30)->toDateString(),
            'notes' => 'فودافون',
        ])->assertOk()
            ->assertJsonPath('data.tracker_sim_last_recharged_on', now()->toDateString())
            ->assertJsonPath('data.tracker_sim_last_recharge_amount', 75)
            ->assertJsonPath('data.tracker_sim_next_recharge_on', now()->addDays(30)->toDateString())
            ->assertJsonPath('recharge.amount', 75);

        $log = $this->getJson("/api/vehicles/{$due->id}/sim-recharges")->assertOk()->json('data');
        $this->assertNotEmpty($log);
        $this->assertSame(75, $log[0]['amount']);
        $this->assertSame('فودافون', $log[0]['notes']);
    }

    public function test_license_expiring_filter_lists_vehicles_within_window(): void
    {
        $soon = Vehicle::query()->create([
            'code' => 'VEH-LIC-1',
            'plate' => 'أ ب 1111',
            'brand' => 'تويوتا',
            'model' => 'يارس',
            'year' => 2022,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 1000,
            'odometer_source' => 'manual',
            'daily_rate' => 700,
            'default_deposit' => 1500,
            'is_active' => true,
        ]);
        $later = Vehicle::query()->create([
            'code' => 'VEH-LIC-2',
            'plate' => 'أ ب 2222',
            'brand' => 'هيونداي',
            'model' => 'أكسنت',
            'year' => 2021,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 2000,
            'odometer_source' => 'manual',
            'daily_rate' => 650,
            'default_deposit' => 1500,
            'is_active' => true,
        ]);

        VehicleDocument::query()->create([
            'vehicle_id' => $soon->id,
            'type' => 'رخصة تسيير',
            'file_path' => 'manual/soon',
            'expires_on' => now()->addDays(20)->toDateString(),
        ]);
        VehicleDocument::query()->create([
            'vehicle_id' => $later->id,
            'type' => 'رخصة تسيير',
            'file_path' => 'manual/later',
            'expires_on' => now()->addDays(60)->toDateString(),
        ]);

        $res = $this->getJson('/api/vehicles?status=license_expiring&within_days=30')->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($soon->id, $ids);
        $this->assertNotContains($later->id, $ids);
        $this->assertSame(
            now()->addDays(20)->toDateString(),
            collect($res->json('data'))->firstWhere('id', $soon->id)['license_expires_on'] ?? null,
        );
        $this->assertGreaterThanOrEqual(1, (int) $res->json('summary.license_expiring'));
    }

    public function test_vehicle_update_license_archives_previous_row(): void
    {
        $vehicle = Vehicle::query()->create([
            'code' => 'VEH-0098',
            'plate' => 'س ص 7787',
            'brand' => 'كيا',
            'model' => 'ريو',
            'year' => 2020,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 8000,
            'odometer_source' => 'manual',
            'daily_rate' => 600,
            'default_deposit' => 1200,
            'is_active' => true,
        ]);

        VehicleDocument::query()->create([
            'vehicle_id' => $vehicle->id,
            'type' => 'رخصة تسيير',
            'file_path' => 'manual/license-old-edit',
            'issued_on' => '2024-02-01',
            'expires_on' => '2025-02-01',
            'notes' => 'قديمة',
        ]);

        $this->putJson("/api/vehicles/{$vehicle->id}", [
            'daily_rate' => 620,
            'license' => [
                'has_license' => true,
                'issued_on' => '2026-02-01',
                'expires_on' => '2027-02-01',
                'file_path' => '/media/99/file',
                'notes' => 'مجدّدة من التعديل',
            ],
        ])->assertOk();

        $this->assertTrue(
            VehicleDocumentHistory::query()
                ->where('vehicle_id', $vehicle->id)
                ->whereDate('expires_on', '2025-02-01')
                ->where('file_path', 'manual/license-old-edit')
                ->exists()
        );
        $active = VehicleDocument::query()->where('vehicle_id', $vehicle->id)->first();
        $this->assertSame('2027-02-01', $active?->expires_on?->toDateString());
        $this->assertSame('/media/99/file', $active?->file_path);
        $this->assertSame('مجدّدة من التعديل', $active?->notes);
    }

    public function test_license_update_archives_previous(): void
    {
        $vehicle = Vehicle::query()->create([
            'code' => 'VEH-0099',
            'plate' => 'س ص 7788',
            'brand' => 'كيا',
            'model' => 'سبورتاج',
            'year' => 2021,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 5000,
            'odometer_source' => 'manual',
            'daily_rate' => 1000,
            'default_deposit' => 2000,
            'is_active' => true,
        ]);

        $doc = VehicleDocument::query()->create([
            'vehicle_id' => $vehicle->id,
            'type' => 'رخصة تسيير',
            'file_path' => 'manual/license-old',
            'issued_on' => '2024-01-01',
            'expires_on' => '2025-01-01',
            'notes' => 'قديمة',
        ]);

        $this->putJson("/api/vehicles/{$vehicle->id}/documents/{$doc->id}", [
            'type' => 'رخصة تسيير',
            'issued_on' => '2026-01-01',
            'expires_on' => '2027-01-01',
            'notes' => 'جديدة',
            'archive_previous' => true,
        ])->assertOk();

        $this->assertTrue(
            VehicleDocumentHistory::query()
                ->where('vehicle_id', $vehicle->id)
                ->where('vehicle_document_id', $doc->id)
                ->whereDate('expires_on', '2025-01-01')
                ->exists()
        );

        $this->assertDatabaseHas('vehicle_documents', [
            'id' => $doc->id,
            'notes' => 'جديدة',
        ]);
        $this->assertSame('2027-01-01', $doc->fresh()->expires_on?->toDateString());

        $this->getJson("/api/vehicles/{$vehicle->id}/document-history")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_vehicle_license_stores_document_number_and_owner_has_no_license_fields(): void
    {
        $this->assertFalse(Schema::hasColumn('vehicle_owners', 'license_front_path'));
        $this->assertFalse(Schema::hasColumn('vehicle_owners', 'license_back_path'));
        $this->assertTrue(Schema::hasColumn('vehicle_documents', 'document_number'));

        $owner = $this->postJson('/api/owners', [
            'full_name' => 'مالك بلا رخصة',
            'phone' => '01055556666',
            'license_front_path' => 'should/be/ignored.jpg',
            'license_back_path' => 'should/be/ignored-back.jpg',
        ])->assertCreated()->json('data');

        $this->assertArrayNotHasKey('license_front_path', $owner);
        $this->assertArrayNotHasKey('license_back_path', $owner);

        $vehicle = $this->postJson('/api/vehicles', [
            'plate_letters' => 'ر خ ص',
            'plate_numbers' => '9988',
            'brand' => 'شيفروليه',
            'model' => 'أوبترا',
            'year' => 2020,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 15000,
            'daily_rate' => 550,
            'default_deposit' => 2000,
            'license' => [
                'has_license' => true,
                'document_number' => 'LIC-VEH-9988',
                'issued_on' => '2025-01-01',
                'expires_on' => '2027-01-01',
                'file_path' => '/media/license-front.jpg',
                'file_path_back' => '/media/license-back.jpg',
            ],
        ])->assertCreated()->json('data');

        $this->assertDatabaseHas('vehicle_documents', [
            'vehicle_id' => $vehicle['id'],
            'type' => 'رخصة تسيير',
            'document_number' => 'LIC-VEH-9988',
            'file_path' => '/media/license-front.jpg',
            'file_path_back' => '/media/license-back.jpg',
        ]);
    }

    public function test_maintenance_expense_links_work_order(): void
    {
        $vehicle = Vehicle::query()->create([
            'code' => 'VEH-0200',
            'plate' => 'ط ظ 1122',
            'brand' => 'هيونداي',
            'model' => 'إلنترا',
            'year' => 2020,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 20000,
            'odometer_source' => 'manual',
            'daily_rate' => 700,
            'default_deposit' => 1500,
            'is_active' => true,
        ]);

        $wo = WorkOrder::query()->create([
            'vehicle_id' => $vehicle->id,
            'workshop' => 'ورشة اختبار',
            'opened_on' => now()->toDateString(),
            'opened_at' => now(),
            'odometer' => 20000,
            'status' => 'open',
            'puts_in_maintenance' => true,
            'parts_cost' => 0,
            'labor_cost' => 0,
            'total_cost' => 0,
        ]);

        $res = $this->postJson('/api/expenses', [
            'title' => 'قطع صيانة',
            'category' => 'صيانة',
            'amount' => 500,
            'spent_on' => now()->toDateString(),
            'payment_method' => 'cash',
            'vehicle_id' => $vehicle->id,
            'work_order_id' => $wo->id,
        ])->assertCreated();

        $res->assertJsonPath('data.work_order_id', $wo->id);
        $res->assertJsonPath('data.category', 'صيانة');
        $this->assertDatabaseHas('expenses', [
            'id' => $res->json('data.id'),
            'work_order_id' => $wo->id,
            'vehicle_id' => $vehicle->id,
        ]);
    }

    public function test_maintenance_expense_requires_vehicle(): void
    {
        $this->postJson('/api/expenses', [
            'title' => 'صيانة بلا عربية',
            'category' => 'صيانة',
            'amount' => 100,
            'spent_on' => now()->toDateString(),
        ])->assertStatus(422);
    }
}
