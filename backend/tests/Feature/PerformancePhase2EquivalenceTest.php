<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PerformancePhase2EquivalenceTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        $role = Role::query()->where('name', 'super_admin')->firstOrFail();
        Sanctum::actingAs(User::factory()->create(['role_id' => $role->id, 'is_active' => true]));

        $this->customer = Customer::query()->create([
            'full_name' => 'عميل P2',
            'phone' => '01099887766',
            'birth_date' => '1990-01-01',
            'license_number' => 'L-P2',
            'license_expires_on' => now()->addYear()->toDateString(),
            'status' => 'active',
        ]);
        $this->vehicle = Vehicle::query()->create([
            'code' => 'P2-V',
            'plate' => 'P2 100',
            'brand' => 'هيونداي',
            'model' => 'إلنترا',
            'year' => 2023,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 1000,
            'daily_rate' => 900,
            'default_deposit' => 2000,
            'is_active' => true,
        ]);
    }

    public function test_contracts_summary_matches_separate_counts(): void
    {
        $this->seedStatuses();

        $res = $this->getJson('/api/contracts?per_page=10')->assertOk()->json('summary');

        $this->assertSame(Contract::query()->count(), (int) $res['total']);
        $this->assertSame(Contract::query()->where('status', 'pending')->count(), (int) $res['pending']);
        $this->assertSame(Contract::query()->where('status', 'approved')->count(), (int) $res['approved']);
        $this->assertSame(Contract::query()->where('status', 'active')->count(), (int) $res['active']);
        $this->assertSame(Contract::query()->where('status', 'completed')->count(), (int) $res['completed']);
        $this->assertSame(Contract::query()->where('status', 'cancelled')->count(), (int) $res['cancelled']);
        $this->assertSame(
            Contract::query()->whereIn('status', ['pending', 'approved'])->where('starts_at', '>', now()->endOfDay())->count(),
            (int) $res['upcoming']
        );
        $this->assertSame(
            Contract::query()->whereIn('status', ['pending', 'approved'])->whereDate('starts_at', now()->toDateString())->count(),
            (int) $res['handover_today']
        );
    }

    public function test_calendar_view_is_light_without_progress_or_ledger(): void
    {
        $this->seedStatuses();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $res = $this->getJson('/api/contracts?view=calendar&per_page=200')->assertOk()->json();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertNotEmpty($res['data']);
        foreach ($res['data'] as $row) {
            $this->assertArrayHasKey('number', $row);
            $this->assertArrayHasKey('starts_at', $row);
            $this->assertArrayHasKey('planned_ends_at', $row);
            $this->assertArrayNotHasKey('progress', $row);
            $this->assertArrayNotHasKey('ledger', $row);
            $this->assertContains($row['status'], ['pending', 'approved', 'active']);
        }
        $this->assertLessThanOrEqual(15, $queries, "calendar view ran {$queries} queries");

        $fullBytes = strlen($this->getJson('/api/contracts?per_page=200')->assertOk()->getContent());
        $calBytes = strlen(json_encode($res));
        $this->assertLessThan($fullBytes * 0.55, $calBytes, 'calendar payload should be much smaller than full list');
    }

    public function test_attention_contract_counters_match_legacy_predicates(): void
    {
        Contract::query()->create([
            'number' => 'CNT-P2-OD',
            'customer_id' => $this->customer->id,
            'vehicle_id' => $this->vehicle->id,
            'status' => 'active',
            'starts_at' => now()->subDays(5),
            'planned_ends_at' => now()->subDay(),
            'frozen_daily_rate' => 900,
            'frozen_agreed_deposit' => 0,
        ]);
        Contract::query()->create([
            'number' => 'CNT-P2-PEND',
            'customer_id' => $this->customer->id,
            'vehicle_id' => $this->vehicle->id,
            'status' => 'pending',
            'starts_at' => now()->addDay(),
            'planned_ends_at' => now()->addDays(3),
            'frozen_daily_rate' => 900,
            'frozen_agreed_deposit' => 0,
        ]);
        Contract::query()->create([
            'number' => 'CNT-P2-PICK',
            'customer_id' => $this->customer->id,
            'vehicle_id' => $this->vehicle->id,
            'status' => 'approved',
            'starts_at' => now()->startOfDay()->addHours(10),
            'planned_ends_at' => now()->addDays(2),
            'frozen_daily_rate' => 900,
            'frozen_agreed_deposit' => 0,
        ]);

        $legacyOverdue = Contract::query()->where('status', 'active')->where('planned_ends_at', '<', now())->count();
        $legacyPending = Contract::query()->where('status', 'pending')->count();
        $legacyPickups = Contract::query()->where('status', 'approved')->whereDate('starts_at', today())->count();

        $data = $this->getJson('/api/attention')->assertOk()->json('data');
        $this->assertSame($legacyOverdue, (int) $data['overdue_returns']);
        $this->assertSame($legacyPending, (int) $data['pending_approvals']);
        $this->assertSame($legacyPickups, (int) $data['pickups_today']);
    }

    private function seedStatuses(): void
    {
        $specs = [
            ['n' => 'P', 'status' => 'pending', 'starts_at' => now()->addDays(2), 'ends' => now()->addDays(5)],
            ['n' => 'A', 'status' => 'approved', 'starts_at' => now()->startOfDay()->addHours(11), 'ends' => now()->addDays(4)],
            ['n' => 'T', 'status' => 'active', 'starts_at' => now()->subDays(2), 'ends' => now()->addDays(3)],
            ['n' => 'C', 'status' => 'completed', 'starts_at' => now()->subDays(10), 'ends' => now()->subDays(3)],
            ['n' => 'X', 'status' => 'cancelled', 'starts_at' => now()->subDay(), 'ends' => now()->addDay()],
            ['n' => 'U', 'status' => 'pending', 'starts_at' => now()->addDays(5), 'ends' => now()->addDays(8)],
        ];
        foreach ($specs as $i => $s) {
            Contract::query()->create([
                'number' => 'CNT-P2-'.$s['n'].$i,
                'customer_id' => $this->customer->id,
                'vehicle_id' => $this->vehicle->id,
                'status' => $s['status'],
                'starts_at' => $s['starts_at'],
                'planned_ends_at' => $s['ends'],
                'frozen_daily_rate' => 900,
                'frozen_agreed_deposit' => 0,
            ]);
        }
    }
}
