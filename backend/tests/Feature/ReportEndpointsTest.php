<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Payment;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Accounting\AccountingService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        app(AccountingService::class)->ensureChartOfAccounts();
        TenantSetting::query()->create([
            'center_name' => 'اختبار تقارير',
            'feature_flags' => [],
            'operational' => [],
        ]);
        $adminRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        Sanctum::actingAs(User::factory()->create(['role_id' => $adminRole->id, 'is_active' => true]));

        $this->customer = Customer::query()->create([
            'full_name' => 'منى حسين',
            'phone' => '01000000002',
            'license_number' => 'LIC-RPT-1',
            'license_expires_on' => now()->addYear()->toDateString(),
            'birth_date' => '1990-01-01',
            'status' => 'active',
            'credit_balance' => 250,
        ]);
    }

    public function test_all_report_endpoints_return_ok(): void
    {
        $ar = Account::query()->where('code', '1200')->firstOrFail();
        $entry = JournalEntry::query()->create([
            'entry_date' => now()->toDateString(),
            'narration' => 'ذمم منى حسين — عقد تجريبي',
            'source' => 'test-rpt-'.uniqid(),
        ]);
        JournalLine::query()->create([
            'journal_entry_id' => $entry->id,
            'account_id' => $ar->id,
            'debit' => 1000,
            'credit' => 0,
            'customer_id' => $this->customer->id,
        ]);
        Payment::query()->create([
            'amount' => 100,
            'paid_at' => now(),
            'method' => 'cash',
            'direction' => 'in',
            'reference' => 'RPT-1',
            'link_type' => 'contract',
            'link_id' => 1,
        ]);

        $this->getJson('/api/reports/summary')->assertOk()
            ->assertJsonStructure(['data' => ['fleet', 'rent_by_month', 'occupancy_pct']]);

        $this->getJson('/api/reports/profitability-compare')->assertOk()
            ->assertJsonStructure(['data' => ['fleet']]);

        $this->getJson('/api/reports/trial-balance')->assertOk()
            ->assertJsonStructure(['data']);

        $this->getJson('/api/reports/income-statement')->assertOk()
            ->assertJsonStructure(['data' => ['income', 'expenses', 'net']]);

        $this->getJson('/api/reports/payment-methods')->assertOk()
            ->assertJsonStructure(['data']);

        $this->getJson('/api/reports/payment-methods?from='.now()->subMonth()->toDateString().'&to='.now()->toDateString())
            ->assertOk();

        $this->getJson("/api/reports/customers/{$this->customer->id}/statement")
            ->assertOk()
            ->assertJsonPath('data.customer.full_name', 'منى حسين')
            ->assertJsonPath('data.credit_balance', 250)
            ->assertJsonPath('data.journal_ar_hint', 1000)
            ->assertJsonStructure([
                'data' => [
                    'customer' => ['id', 'full_name', 'phone', 'credit_balance'],
                    'contracts_total',
                    'payments_in',
                    'open_receivable',
                    'credit_ledger',
                    'contracts',
                    'journal_ar_hint',
                ],
            ]);

        // Enriched contract row shape (vehicle / period / paid / remaining) even when empty.
        $this->assertIsArray($this->getJson("/api/reports/customers/{$this->customer->id}/statement")->json('data.contracts'));

        $this->getJson('/api/reports/petty-cash-balances')->assertOk()
            ->assertJsonStructure(['data']);
    }
}
