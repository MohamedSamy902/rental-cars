<?php

namespace Tests\Feature;

use App\Models\OwnerMonthDue;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleOwner;
use App\Services\Accounting\AccountingService;
use App\Services\Fleet\VehicleOwnerService;
use App\Support\PermissionCatalog;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OwnerDuesReconcileTest extends TestCase
{
    use RefreshDatabase;

    public function test_mid_november_agreement_has_zero_remaining_and_reconcile_clears_invalid_due(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-16 12:00:00', 'Africa/Cairo'));
        PermissionCatalog::ensureSeeded();
        app(AccountingService::class)->ensureChartOfAccounts();

        $admin = User::factory()->create([
            'role_id' => Role::query()->where('name', 'super_admin')->firstOrFail()->id,
            'is_active' => true,
        ]);
        Sanctum::actingAs($admin);

        $owner = VehicleOwner::query()->create([
            'full_name' => 'مالك نوفمبر',
            'phone' => '01125253888',
            'national_id' => '29001010101011',
            'is_active' => true,
        ]);
        $vehicle = Vehicle::query()->create([
            'code' => 'OWN-NOV',
            'plate' => 'ن و ف 1',
            'brand' => 'تويوتا',
            'model' => 'كورولا',
            'year' => 2024,
            'category' => 'سيدان',
            'fuel_type' => 'بنزين',
            'odometer' => 1000,
            'daily_rate' => 1000,
            'default_deposit' => 0,
            'is_active' => true,
            'owner_id' => $owner->id,
            'owner_monthly_amount' => 26000,
            'owner_agreement_starts_on' => '2026-11-15',
        ]);

        $svc = app(VehicleOwnerService::class);
        $created = $svc->ensureAccruedThrough($owner, '2026-11', $admin->id);
        $this->assertSame(0, $created);

        // Simulate a bad historical accrual for the unfinished November month.
        OwnerMonthDue::query()->create([
            'owner_id' => $owner->id,
            'vehicle_id' => $vehicle->id,
            'year_month' => '2026-11',
            'amount' => 396774,
            'created_by' => $admin->id,
        ]);

        $this->assertSame(396774, $svc->balance($owner)['remaining']);
        $fixed = $svc->reconcileAccrualsToSchedule($owner, $admin->id);
        $this->assertGreaterThan(0, $fixed);
        $this->assertSame(0, $svc->balance($owner)['remaining']);
        $this->assertDatabaseMissing('owner_month_dues', [
            'owner_id' => $owner->id,
            'year_month' => '2026-11',
        ]);

        $list = $this->getJson('/api/owners?per_page=50')->assertOk()->json('data');
        $row = collect($list)->firstWhere('id', $owner->id);
        $this->assertNotNull($row);
        $this->assertSame(0, (int) ($row['balance']['remaining'] ?? -1));

        Carbon::setTestNow();
    }
}
