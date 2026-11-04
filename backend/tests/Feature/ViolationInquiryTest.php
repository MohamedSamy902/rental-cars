<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleOwner;
use App\Models\VehicleViolationInquiry;
use App\Services\Accounting\AccountingService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ViolationInquiryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Vehicle $vehicle;

    private Vehicle $rented;

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
            'name' => 'مستعلم يومي',
        ]);
        Sanctum::actingAs($this->admin);

        $owner = VehicleOwner::query()->create([
            'full_name' => 'مالك تجريبي',
            'phone' => '01012345678',
            'national_id' => '29001011234567',
            'is_active' => true,
        ]);

        $this->vehicle = Vehicle::query()->create([
            'code' => 'VEH-INQ-1',
            'plate' => 'أ ب 1111',
            'brand' => 'هيونداي',
            'model' => 'إلنترا',
            'year' => 2022,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 10000,
            'odometer_source' => 'manual',
            'daily_rate' => 700,
            'default_deposit' => 1500,
            'is_active' => true,
            'owner_id' => $owner->id,
        ]);

        $this->rented = Vehicle::query()->create([
            'code' => 'VEH-INQ-2',
            'plate' => 'س ص 2222',
            'brand' => 'تويوتا',
            'model' => 'كورولا',
            'year' => 2023,
            'category' => 'سيدان',
            'fuel_type' => 'بنزين',
            'odometer' => 5000,
            'odometer_source' => 'manual',
            'daily_rate' => 800,
            'default_deposit' => 1500,
            'is_active' => true,
            'owner_id' => $owner->id,
        ]);

        $customer = Customer::query()->create([
            'full_name' => 'عميل إيجار',
            'phone' => '01099887766',
            'birth_date' => '1990-01-01',
            'license_number' => 'LIC-INQ',
            'license_expires_on' => now()->addYears(2)->toDateString(),
            'status' => 'active',
        ]);

        Contract::query()->create([
            'number' => 'CNT-INQ-1',
            'customer_id' => $customer->id,
            'vehicle_id' => $this->rented->id,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'planned_ends_at' => now()->addDays(3),
            'approved_days' => 4,
            'frozen_daily_rate' => 800,
            'frozen_agreed_deposit' => 1500,
            'frozen_daily_km_allowance' => 200,
            'frozen_excess_km_rate' => 5,
            'odometer_out' => 5000,
        ]);
    }

    public function test_pending_list_and_mark_resets_daily(): void
    {
        $pending = $this->getJson('/api/violation-inquiries?filter=pending')
            ->assertOk()
            ->json('data');
        $ids = collect($pending)->pluck('vehicle_id')->all();
        $this->assertContains($this->vehicle->id, $ids);
        $this->assertContains($this->rented->id, $ids);

        $this->postJson("/api/violation-inquiries/{$this->vehicle->id}/mark")
            ->assertOk()
            ->assertJsonPath('data.inquired_today', true)
            ->assertJsonPath('data.inquired_by_name', 'مستعلم يومي')
            ->assertJsonPath('data.owner_name', 'مالك تجريبي');

        $this->assertTrue(
            VehicleViolationInquiry::query()
                ->where('vehicle_id', $this->vehicle->id)
                ->whereDate('inquired_on', now()->toDateString())
                ->where('inquired_by', $this->admin->id)
                ->exists()
        );

        $pendingAfter = collect($this->getJson('/api/violation-inquiries?filter=pending')->json('data'))
            ->pluck('vehicle_id')
            ->all();
        $this->assertNotContains($this->vehicle->id, $pendingAfter);

        $done = collect($this->getJson('/api/violation-inquiries?filter=done_today')->json('data'))
            ->pluck('vehicle_id')
            ->all();
        $this->assertContains($this->vehicle->id, $done);

        // يوم سابق لا يمنع ظهور العربية كبانتظار اليوم
        VehicleViolationInquiry::query()->where('vehicle_id', $this->rented->id)->delete();
        VehicleViolationInquiry::query()->create([
            'vehicle_id' => $this->rented->id,
            'inquired_on' => now()->subDay()->toDateString(),
            'inquired_by' => $this->admin->id,
        ]);
        $stillPending = collect($this->getJson('/api/violation-inquiries?filter=pending')->json('data'))
            ->pluck('vehicle_id')
            ->all();
        $this->assertContains($this->rented->id, $stillPending);
    }

    public function test_rented_today_filter(): void
    {
        $rented = collect($this->getJson('/api/violation-inquiries?filter=rented_today')->assertOk()->json('data'))
            ->pluck('vehicle_id')
            ->all();
        $this->assertContains($this->rented->id, $rented);
        $this->assertNotContains($this->vehicle->id, $rented);
    }

    public function test_national_id_masked_without_sensitive_permission(): void
    {
        $role = Role::query()->where('name', 'sales')->firstOrFail();
        $sales = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
        // امنح عرض المخالفات فقط
        $permIds = \App\Models\Permission::query()
            ->whereIn('key', ['violations.view', 'violations.update'])
            ->pluck('id');
        $role->permissions()->syncWithoutDetaching($permIds);
        Sanctum::actingAs($sales->fresh());

        $row = collect($this->getJson('/api/violation-inquiries?filter=all')->assertOk()->json('data'))
            ->firstWhere('vehicle_id', $this->vehicle->id);
        $this->assertNotNull($row);
        $this->assertNotSame('29001011234567', $row['owner_national_id']);
        $this->assertStringEndsWith('4567', (string) $row['owner_national_id']);
    }
}
