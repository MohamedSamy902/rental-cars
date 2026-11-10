<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Payment;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Accounting\AccountingService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountingDailyCashTest extends TestCase
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
        $admin = Role::query()->where('name', 'super_admin')->firstOrFail();
        Sanctum::actingAs(User::factory()->create([
            'role_id' => $admin->id,
            'is_active' => true,
        ]));
    }

    public function test_daily_cash_defaults_to_today_and_lists_money_moves(): void
    {
        Payment::query()->create([
            'amount' => 900,
            'paid_at' => now(),
            'method' => 'نقدي',
            'direction' => 'in',
            'reference' => 'DAY-IN',
            'link_type' => 'contract',
            'link_id' => 1,
        ]);
        Expense::query()->create([
            'title' => 'زيوت',
            'category' => 'صيانة',
            'amount' => 150,
            'spent_on' => now()->toDateString(),
            'payment_method' => 'نقدي',
            'from_petty_cash' => false,
        ]);
        Expense::query()->create([
            'title' => 'أمس',
            'category' => 'تشغيل',
            'amount' => 40,
            'spent_on' => now()->subDay()->toDateString(),
            'payment_method' => 'نقدي',
            'from_petty_cash' => false,
        ]);

        $today = $this->getJson('/api/accounting/daily-cash')->assertOk()->json('data');
        $this->assertSame(now()->toDateString(), $today['date']);
        $this->assertSame(900, $today['totals']['in']);
        $this->assertSame(150, $today['totals']['out']);
        $this->assertSame(2, $today['totals']['count']);

        $yesterday = $this->getJson('/api/accounting/daily-cash?date='.now()->subDay()->toDateString())
            ->assertOk()
            ->json('data');
        $this->assertSame(1, $yesterday['totals']['count']);
        $this->assertSame(40, $yesterday['totals']['out']);
    }

    public function test_chart_names_use_arabic_business_labels(): void
    {
        $rows = collect(app(AccountingService::class)->ensureChartOfAccounts())->keyBy('code');
        $this->assertSame('إيرادات الإيجار', $rows['4000']->name);
        $this->assertSame('مبالغ مؤجلة السداد', $rows['1200']->name);
    }
}
