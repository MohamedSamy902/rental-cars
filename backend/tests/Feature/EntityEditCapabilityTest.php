<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\InventoryItem;
use App\Models\JournalEntry;
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

class EntityEditCapabilityTest extends TestCase
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

    public function test_expense_can_be_updated_and_journal_rewritten(): void
    {
        $created = $this->postJson('/api/expenses', [
            'title' => 'مصروف أولي',
            'category' => 'تشغيل',
            'amount' => 250,
            'spent_on' => now()->toDateString(),
            'payment_method' => 'cash',
        ])->assertCreated();

        $id = (int) $created->json('data.id');
        $this->assertDatabaseHas('journal_entries', ['source' => 'expense:'.$id]);

        $updated = $this->putJson("/api/expenses/{$id}", [
            'title' => 'مصروف محدّث',
            'category' => 'تشغيل',
            'amount' => 400,
            'spent_on' => now()->toDateString(),
            'payment_method' => 'transfer',
            'notes' => 'ملاحظة تعديل',
        ])->assertOk();

        $updated->assertJsonPath('data.title', 'مصروف محدّث');
        $updated->assertJsonPath('data.amount', 400);
        $this->assertDatabaseHas('expenses', [
            'id' => $id,
            'title' => 'مصروف محدّث',
            'amount' => 400,
            'payment_method' => 'transfer',
        ]);
        $this->assertSame(1, JournalEntry::query()->where('source', 'expense:'.$id)->count());
        $this->assertSame(400, (int) Expense::query()->findOrFail($id)->amount);
    }

    public function test_inventory_item_can_be_updated(): void
    {
        $created = $this->postJson('/api/inventory/items', [
            'name' => 'زيت محرك',
            'category' => 'زيوت',
            'unit' => 'لتر',
            'reorder_level' => 5,
        ])->assertCreated();

        $id = (int) $created->json('data.id');

        $originalCode = (string) $created->json('data.code');

        $this->putJson("/api/inventory/items/{$id}", [
            'code' => 'OIL-9',
            'name' => 'زيت محرك محدث',
            'category' => 'زيوت',
            'unit' => 'علبة',
            'reorder_level' => 8,
        ])->assertOk()
            ->assertJsonPath('data.name', 'زيت محرك محدث')
            ->assertJsonPath('data.unit', 'علبة')
            ->assertJsonPath('data.reorder_level', 8)
            ->assertJsonPath('data.code', $originalCode);

        // Codes are system-owned — client-supplied code on update is ignored.
        $this->assertDatabaseHas('inventory_items', [
            'id' => $id,
            'code' => $originalCode,
            'name' => 'زيت محرك محدث',
            'reorder_level' => 8,
        ]);
        $this->assertDatabaseMissing('inventory_items', [
            'id' => $id,
            'code' => 'OIL-9',
        ]);
        $this->assertInstanceOf(InventoryItem::class, InventoryItem::query()->find($id));
    }

    public function test_violation_can_be_updated(): void
    {
        $vehicle = Vehicle::query()->create([
            'code' => 'VEH-8801',
            'plate' => 'أ ب 1001',
            'brand' => 'نيسان',
            'model' => 'صني',
            'year' => 2021,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 10000,
            'odometer_source' => 'manual',
            'daily_rate' => 600,
            'default_deposit' => 1000,
            'is_active' => true,
        ]);

        $customer = \App\Models\Customer::query()->create([
            'full_name' => 'عميل تعديل مخالفة',
            'phone' => '01005554433',
            'birth_date' => '1991-01-01',
            'license_number' => 'LIC-8801',
            'license_expires_on' => now()->addYears(2)->toDateString(),
            'status' => 'active',
        ]);

        $contract = \App\Models\Contract::query()->create([
            'number' => 'CNT-8801',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'planned_ends_at' => now()->addDays(3),
            'approved_days' => 4,
            'frozen_daily_rate' => 600,
            'frozen_agreed_deposit' => 1000,
            'frozen_daily_km_allowance' => 200,
            'frozen_excess_km_rate' => 5,
        ]);

        $created = $this->postJson('/api/violations', [
            'contract_id' => $contract->id,
            'occurred_at' => now()->toDateString(),
            'amount' => 150,
            'location' => 'المعادي',
        ])->assertCreated()
            ->assertJsonPath('data.created_by', auth()->id());

        $id = (int) $created->json('data.id');

        $this->putJson("/api/violations/{$id}", [
            'contract_id' => $contract->id,
            'occurred_at' => now()->toDateString(),
            'amount' => 275,
            'location' => 'مدينة نصر',
            'status' => 'assigned',
            'photo_path' => null,
        ])->assertOk()
            ->assertJsonPath('data.amount', 275)
            ->assertJsonPath('data.location', 'مدينة نصر')
            ->assertJsonPath('data.status', 'assigned');

        $this->assertDatabaseHas('violations', [
            'id' => $id,
            'amount' => 275,
            'location' => 'مدينة نصر',
            'status' => 'assigned',
            'contract_id' => $contract->id,
        ]);
        $this->assertInstanceOf(Violation::class, Violation::query()->find($id));
    }
}
