<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Accounting\AccountingService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IncomeStatementOwnerAccrualTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        TenantSetting::query()->create([
            'center_name' => 'اختبار',
            'feature_flags' => [],
            'operational' => [],
        ]);
        app(AccountingService::class)->ensureChartOfAccounts();
        $admin = Role::query()->where('name', 'super_admin')->firstOrFail();
        Sanctum::actingAs(User::factory()->create(['role_id' => $admin->id, 'is_active' => true]));
    }

    public function test_income_statement_includes_owner_cash_payout_in_operating_expenses(): void
    {
        $expenseOps = Account::query()->where('code', '5100')->firstOrFail();
        $expenseOwner = Account::query()->where('code', '5200')->firstOrFail();
        $cash = Account::query()->where('code', '1000')->firstOrFail();
        $income = Account::query()->where('code', '4000')->firstOrFail();

        $rent = JournalEntry::query()->create([
            'entry_date' => now()->toDateString(),
            'narration' => 'إيجار',
            'source' => 'test_rent',
        ]);
        JournalLine::query()->create([
            'journal_entry_id' => $rent->id, 'account_id' => $cash->id, 'debit' => 44000, 'credit' => 0,
        ]);
        JournalLine::query()->create([
            'journal_entry_id' => $rent->id, 'account_id' => $income->id, 'debit' => 0, 'credit' => 44000,
        ]);

        $ops = JournalEntry::query()->create([
            'entry_date' => now()->toDateString(),
            'narration' => 'مصروف تشغيل',
            'source' => 'test_ops',
        ]);
        JournalLine::query()->create([
            'journal_entry_id' => $ops->id, 'account_id' => $expenseOps->id, 'debit' => 5000, 'credit' => 0,
        ]);
        JournalLine::query()->create([
            'journal_entry_id' => $ops->id, 'account_id' => $cash->id, 'debit' => 0, 'credit' => 5000,
        ]);

        $payout = JournalEntry::query()->create([
            'entry_date' => now()->toDateString(),
            'narration' => 'صرف شهري لمالك',
            'source' => 'owner_payout:test',
        ]);
        JournalLine::query()->create([
            'journal_entry_id' => $payout->id, 'account_id' => $expenseOwner->id, 'debit' => 20000, 'credit' => 0,
        ]);
        JournalLine::query()->create([
            'journal_entry_id' => $payout->id, 'account_id' => $cash->id, 'debit' => 0, 'credit' => 20000,
        ]);

        $res = $this->getJson('/api/reports/income-statement')->assertOk()->json('data');

        $this->assertSame(44000, $res['income_total']);
        $this->assertSame(25000, $res['expense_total']);
        $this->assertSame(0, $res['owner_accrual_total']);
        $this->assertSame(19000, $res['net']);
        $this->assertSame([], $res['owner_accruals']);
        $this->assertEqualsCanonicalizing(['5100', '5200'], collect($res['expenses'])->pluck('code')->all());
    }
}
