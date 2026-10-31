<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\OwnerMonthDue;
use App\Models\OwnerPayout;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleOwner;
use App\Models\WaitlistRequest;
use App\Models\WorkOrder;
use App\Services\Accounting\AccountingService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AttentionSignalsTest extends TestCase
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

    public function test_attention_endpoint_reports_overdue_and_maintenance(): void
    {
        $customer = Customer::query()->create([
            'full_name' => 'عميل',
            'phone' => '01011112222',
            'birth_date' => '1990-01-01',
            'license_number' => 'L-ATT',
            'license_expires_on' => now()->addYear()->toDateString(),
            'status' => 'active',
        ]);
        $vehicle = Vehicle::query()->create([
            'code' => 'ATT-1',
            'plate' => 'ATT 1',
            'brand' => 'تويوتا',
            'model' => 'كورولا',
            'year' => 2022,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 1000,
            'daily_rate' => 800,
            'default_deposit' => 2000,
            'is_active' => true,
            'rental_priority' => 50,
        ]);

        Contract::query()->create([
            'number' => 'CNT-ATT1',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'status' => 'active',
            'starts_at' => now()->subDays(5),
            'planned_ends_at' => now()->subDay(),
            'frozen_daily_rate' => 800,
            'frozen_daily_km_allowance' => 200,
            'frozen_excess_km_rate' => 5,
            'frozen_agreed_deposit' => 2000,
            'odometer_out' => 1010,
        ]);

        Contract::query()->create([
            'number' => 'CNT-ATT2',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'status' => 'pending',
            'starts_at' => now()->addDay(),
            'planned_ends_at' => now()->addDays(3),
            'frozen_daily_rate' => 800,
            'frozen_daily_km_allowance' => 200,
            'frozen_excess_km_rate' => 5,
            'frozen_agreed_deposit' => 2000,
        ]);

        WorkOrder::query()->create([
            'vehicle_id' => $vehicle->id,
            'opened_on' => now()->toDateString(),
            'odometer' => 1010,
            'status' => 'open',
            'puts_in_maintenance' => true,
            'parts_cost' => 0,
            'labor_cost' => 0,
        ]);

        $res = $this->getJson('/api/attention')->assertOk();
        $res->assertJsonPath('data.overdue_returns', 1);
        $res->assertJsonPath('data.pending_approvals', 1);
        $this->assertGreaterThanOrEqual(1, (int) $res->json('data.blocking_work_orders'));
        $this->assertGreaterThanOrEqual(1, (int) $res->json('data.open_work_orders'));
        $res->assertJsonStructure([
            'data' => [
                'overdue_returns',
                'pending_approvals',
                'returning_today',
                'pickups_today',
                'blocking_work_orders',
                'open_work_orders',
                'open_violations',
                'low_stock',
                'unread_notifications',
                'pending_hr_acks',
                'draft_payroll_runs',
                'blacklisted_customers',
                'waiting_requests',
                'owners_unpaid',
            ],
        ]);
    }

    public function test_attention_counts_open_waitlist_and_unpaid_owners(): void
    {
        $customer = Customer::query()->create([
            'full_name' => 'منتظر',
            'phone' => '01033334444',
            'birth_date' => '1992-02-02',
            'license_number' => 'L-WAIT',
            'license_expires_on' => now()->addYear()->toDateString(),
            'status' => 'active',
        ]);

        WaitlistRequest::query()->create([
            'customer_id' => $customer->id,
            'category' => 'اقتصادي',
            'status' => 'waiting',
        ]);
        WaitlistRequest::query()->create([
            'customer_id' => $customer->id,
            'category' => 'عائلي',
            'status' => 'fulfilled',
        ]);
        $gone = WaitlistRequest::query()->create([
            'customer_id' => $customer->id,
            'category' => 'فاخر',
            'status' => 'waiting',
        ]);
        $gone->delete();

        $dueOwner = VehicleOwner::query()->create(['full_name' => 'مالك مستحق', 'is_active' => true]);
        $paidOwner = VehicleOwner::query()->create(['full_name' => 'مالك مسدد', 'is_active' => true]);
        $vehicle = $this->vehicle('OWN-DUE', $dueOwner->id);
        $paidVehicle = $this->vehicle('OWN-PAID', $paidOwner->id);

        OwnerMonthDue::query()->create([
            'owner_id' => $dueOwner->id,
            'vehicle_id' => $vehicle->id,
            'year_month' => now()->format('Y-m'),
            'amount' => 5000,
        ]);
        OwnerMonthDue::query()->create([
            'owner_id' => $paidOwner->id,
            'vehicle_id' => $paidVehicle->id,
            'year_month' => now()->format('Y-m'),
            'amount' => 2000,
        ]);
        OwnerPayout::query()->create([
            'owner_id' => $paidOwner->id,
            'vehicle_id' => $paidVehicle->id,
            'amount' => 2000,
            'paid_on' => now()->toDateString(),
            'payment_method' => 'cash',
        ]);

        $res = $this->getJson('/api/attention')->assertOk();
        $res->assertJsonPath('data.waiting_requests', 1);
        $res->assertJsonPath('data.owners_unpaid', 1);
    }

    private function vehicle(string $code, int $ownerId): Vehicle
    {
        return Vehicle::query()->create([
            'code' => $code,
            'plate' => $code,
            'brand' => 'تويوتا',
            'model' => 'كورولا',
            'year' => 2022,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 1000,
            'daily_rate' => 800,
            'default_deposit' => 2000,
            'is_active' => true,
            'rental_priority' => 50,
            'owner_id' => $ownerId,
            'owner_monthly_amount' => 2000,
        ]);
    }
}
