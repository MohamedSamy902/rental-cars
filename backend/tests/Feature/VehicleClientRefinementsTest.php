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
            'rental_priority' => 50,
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
            'rental_priority' => 50,
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
