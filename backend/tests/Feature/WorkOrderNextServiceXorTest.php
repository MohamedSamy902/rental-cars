<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkOrderNextServiceXorTest extends TestCase
{
    use RefreshDatabase;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        $admin = User::factory()->create([
            'role_id' => Role::query()->where('name', 'super_admin')->firstOrFail()->id,
            'is_active' => true,
        ]);
        Sanctum::actingAs($admin);
        $this->vehicle = Vehicle::query()->create([
            'code' => 'WO-XOR',
            'plate' => 'و س ر 1',
            'brand' => 'هيونداي',
            'model' => 'إلنترا',
            'year' => 2024,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 10000,
            'daily_rate' => 900,
            'default_deposit' => 3000,
            'is_active' => true,
        ]);
    }

    public function test_rejects_missing_both_next_service_fields(): void
    {
        $this->postJson('/api/work-orders', [
            'vehicle_id' => $this->vehicle->id,
            'odometer' => 10050,
            'baseline' => true,
            'puts_in_maintenance' => false,
            'labor_cost' => 0,
            'lines' => [[
                'description' => 'فحص',
                'part_source' => 'purchase',
                'quantity' => 1,
                'unit_price' => 0,
            ]],
        ])->assertStatus(422);
    }

    public function test_rejects_both_next_service_fields_together(): void
    {
        $this->postJson('/api/work-orders', [
            'vehicle_id' => $this->vehicle->id,
            'odometer' => 10050,
            'baseline' => true,
            'puts_in_maintenance' => false,
            'labor_cost' => 0,
            'next_service_on' => now()->addMonths(3)->toDateString(),
            'next_service_odometer' => 20000,
            'lines' => [[
                'description' => 'فحص',
                'part_source' => 'purchase',
                'quantity' => 1,
                'unit_price' => 0,
            ]],
        ])->assertStatus(422);
    }

    public function test_accepts_date_only_or_odometer_only(): void
    {
        $this->postJson('/api/work-orders', [
            'vehicle_id' => $this->vehicle->id,
            'odometer' => 10050,
            'baseline' => true,
            'puts_in_maintenance' => false,
            'labor_cost' => 0,
            'next_service_on' => now()->addMonths(3)->toDateString(),
            'lines' => [[
                'description' => 'توقيت',
                'part_source' => 'purchase',
                'quantity' => 1,
                'unit_price' => 0,
            ]],
        ])->assertCreated();

        $this->postJson('/api/work-orders', [
            'vehicle_id' => $this->vehicle->id,
            'odometer' => 10100,
            'baseline' => true,
            'puts_in_maintenance' => false,
            'labor_cost' => 0,
            'next_service_odometer' => 25000,
            'lines' => [[
                'description' => 'كيلومتر',
                'part_source' => 'purchase',
                'quantity' => 1,
                'unit_price' => 0,
            ]],
        ])->assertCreated();
    }

    public function test_accepts_first_upcoming_baseline_without_money_or_petty(): void
    {
        $res = $this->postJson('/api/work-orders', [
            'vehicle_id' => $this->vehicle->id,
            'odometer' => 10200,
            'baseline' => true,
            'puts_in_maintenance' => false,
            'labor_cost' => 0,
            'notes' => 'تسجيل أساس — أول صيانة قادمة',
            'next_service_on' => now()->addMonths(2)->toDateString(),
            'lines' => [
                [
                    'description' => 'زيت موتور',
                    'part_source' => 'purchase',
                    'quantity' => 1,
                    'unit_price' => 0,
                    'from_petty_cash' => false,
                ],
                [
                    'description' => 'فلتر هوا',
                    'part_source' => 'purchase',
                    'quantity' => 1,
                    'unit_price' => 0,
                    'from_petty_cash' => false,
                ],
                [
                    'description' => 'بطانة فرامل',
                    'part_source' => 'purchase',
                    'quantity' => 1,
                    'unit_price' => 0,
                    'from_petty_cash' => false,
                ],
            ],
        ])->assertCreated();

        $this->assertSame(0, (int) $res->json('data.parts_cost'));
        $this->assertFalse((bool) $res->json('data.puts_in_maintenance'));
        $this->assertCount(3, $res->json('data.lines') ?? []);
        $this->vehicle->refresh();
        $this->assertNull($this->vehicle->manual_status);
    }

    public function test_rejects_regular_work_order_without_funding_source_or_zero_amount(): void
    {
        $this->postJson('/api/work-orders', [
            'vehicle_id' => $this->vehicle->id,
            'odometer' => 10300,
            'puts_in_maintenance' => true,
            'next_service_on' => now()->addMonths(1)->toDateString(),
            'lines' => [[
                'description' => 'زيت موتور',
                'part_source' => 'purchase',
                'quantity' => 1,
                'unit_price' => 0,
                'from_petty_cash' => false,
            ]],
        ])->assertStatus(422);

        $this->postJson('/api/work-orders', [
            'vehicle_id' => $this->vehicle->id,
            'odometer' => 10310,
            'puts_in_maintenance' => true,
            'next_service_on' => now()->addMonths(1)->toDateString(),
            'lines' => [[
                'description' => 'زيت موتور',
                'part_source' => 'purchase',
                'quantity' => 1,
                'unit_price' => 0,
                'from_petty_cash' => true,
                'petty_cash_employee_id' => 1,
                'petty_cash_amount' => 0,
            ]],
        ])->assertStatus(422);
    }
}
