<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\OwnerMonthDue;
use App\Models\OwnerPayout;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleOwner;
use App\Services\Accounting\AccountingService;
use App\Services\Fleet\VehicleOwnerService;
use App\Support\PermissionCatalog;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OwnerAccrualPurgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_purge_clears_dues_and_reclasses_old_payable_payouts(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-16 12:00:00', 'Africa/Cairo'));
        PermissionCatalog::ensureSeeded();
        app(AccountingService::class)->ensureChartOfAccounts();

        $admin = User::factory()->create([
            'role_id' => Role::query()->where('name', 'super_admin')->firstOrFail()->id,
            'is_active' => true,
        ]);

        $owner = VehicleOwner::query()->create([
            'full_name' => 'مالك تنظيف',
            'phone' => '01100001111',
            'is_active' => true,
        ]);
        $vehicle = Vehicle::query()->create([
            'code' => 'OWN-PURGE',
            'plate' => 'ت ن ظ 1',
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
            'owner_monthly_amount' => 20000,
            'owner_agreement_starts_on' => '2026-01-01',
        ]);

        $payable = Account::query()->where('code', '2100')->firstOrFail();
        $expense = Account::query()->where('code', '5200')->firstOrFail();
        $cash = Account::query()->where('code', '1000')->firstOrFail();

        $accrual = JournalEntry::query()->create([
            'entry_date' => '2026-10-01',
            'narration' => 'استحقاق قديم',
            'source' => 'owner_accrual:'.$vehicle->id.':2026-10',
            'posted_by' => $admin->id,
        ]);
        JournalLine::query()->create([
            'journal_entry_id' => $accrual->id, 'account_id' => $expense->id, 'debit' => 396774, 'credit' => 0, 'vehicle_id' => $vehicle->id,
        ]);
        JournalLine::query()->create([
            'journal_entry_id' => $accrual->id, 'account_id' => $payable->id, 'debit' => 0, 'credit' => 396774, 'vehicle_id' => $vehicle->id,
        ]);
        $due = OwnerMonthDue::query()->create([
            'owner_id' => $owner->id,
            'vehicle_id' => $vehicle->id,
            'year_month' => '2026-10',
            'amount' => 396774,
            'journal_entry_id' => $accrual->id,
            'created_by' => $admin->id,
        ]);

        $payoutEntry = JournalEntry::query()->create([
            'entry_date' => '2026-10-15',
            'narration' => 'صرف قديم عبر 2100',
            'source' => 'owner_payout:tmp',
            'posted_by' => $admin->id,
        ]);
        JournalLine::query()->create([
            'journal_entry_id' => $payoutEntry->id, 'account_id' => $payable->id, 'debit' => 20000, 'credit' => 0, 'vehicle_id' => $vehicle->id,
        ]);
        JournalLine::query()->create([
            'journal_entry_id' => $payoutEntry->id, 'account_id' => $cash->id, 'debit' => 0, 'credit' => 20000, 'vehicle_id' => $vehicle->id,
        ]);
        $payout = OwnerPayout::query()->create([
            'owner_id' => $owner->id,
            'vehicle_id' => $vehicle->id,
            'amount' => 20000,
            'paid_on' => '2026-10-15',
            'payment_method' => 'cash',
            'journal_entry_id' => $payoutEntry->id,
            'recorded_by' => $admin->id,
        ]);
        $payoutEntry->update(['source' => 'owner_payout:'.$payout->id]);

        $svc = app(VehicleOwnerService::class);
        $this->assertSame(376774, $svc->balance($owner)['remaining']);

        $result = $svc->purgeAccrualLedger($owner, $admin->id);
        $this->assertSame(1, $result['dues_cleared']);
        $this->assertSame(1, $result['payouts_reclassed']);
        $this->assertDatabaseCount('owner_month_dues', 0);
        $this->assertDatabaseCount('owner_payouts', 1);
        $this->assertSame(0, $svc->balance($owner)['remaining']);
        $this->assertSame(20000, $svc->balance($owner)['paid_total']);
        $this->assertDatabaseHas('journal_entries', ['source' => 'owner_accrual_purge:'.$due->id.':2026-10']);
        $this->assertDatabaseHas('journal_entries', ['source' => 'owner_payout_reclass:'.$payout->id]);

        Carbon::setTestNow();
    }
}
