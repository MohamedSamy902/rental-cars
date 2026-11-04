<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Models\VehicleDocumentHistory;
use App\Services\Accounting\AccountingService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        $wo = \App\Models\WorkOrder::query()->create([
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
