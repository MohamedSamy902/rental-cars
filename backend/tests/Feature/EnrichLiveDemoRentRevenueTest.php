<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Role;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EnrichLiveDemoRentRevenueTest extends TestCase
{
    use RefreshDatabase;

    public function test_enrich_backfills_rent_revenue_for_completed_contracts_without_journals(): void
    {
        PermissionCatalog::ensureSeeded();
        $admin = User::factory()->create([
            'role_id' => Role::query()->where('name', 'super_admin')->firstOrFail()->id,
            'is_active' => true,
        ]);

        $customer = Customer::query()->create([
            'full_name' => 'عميل إيراد',
            'phone' => '01022223333',
            'birth_date' => '1990-01-01',
            'license_number' => 'LIC-REV',
            'license_expires_on' => now()->addYears(2)->toDateString(),
            'status' => 'active',
            'address' => 'المعادي',
        ]);
        $vehicle = Vehicle::query()->create([
            'code' => 'REV-1',
            'plate' => 'ر ف د 1',
            'brand' => 'هيونداي',
            'model' => 'إلنترا',
            'year' => 2023,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 10000,
            'daily_rate' => 1000,
            'default_deposit' => 3000,
            'is_active' => true,
        ]);

        // عقد مكتمل بلا قيود إيجار (مثل الديمو القديم)
        $starts = now()->subMonths(2)->startOfMonth()->addDays(2);
        $ends = $starts->copy()->addDays(4);
        Contract::query()->create([
            'number' => 'CNT-REV-ZERO',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'status' => 'completed',
            'starts_at' => $starts,
            'planned_ends_at' => $ends,
            'actual_ends_at' => $ends,
            'approved_days' => 4,
            'frozen_daily_rate' => 1000,
            'frozen_agreed_deposit' => 3000,
            'period_rent' => null,
            'final_total' => 4000,
            'notes' => 'مكتمل بلا ترحيل',
        ]);

        $this->assertSame(0, JournalEntry::query()->where('source', 'like', 'rent_recog:%')->count());

        $this->artisan('rpm:enrich-live-demo')->assertSuccessful();

        $this->assertGreaterThan(0, JournalEntry::query()->where('source', 'like', 'rent_recog:%')->count());

        Sanctum::actingAs($admin);
        $overview = $this->getJson('/api/accounting/overview')->assertOk()->json('data');
        $months = collect($overview['months'] ?? $overview);
        // overview shape: { months: [...] } or list — support both
        if (isset($overview['months'])) {
            $months = collect($overview['months']);
        } elseif (isset($overview[0]['rent_revenue'])) {
            $months = collect($overview);
        } else {
            $months = collect($overview['months'] ?? []);
        }

        $rentAccount = Account::query()->where('code', '4000')->firstOrFail();
        $credited = (int) JournalLine::query()
            ->where('account_id', $rentAccount->id)
            ->sum('credit');
        $this->assertGreaterThan(0, $credited, 'يجب ترحيل رصيد على حساب إيراد الإيجار 4000');

        $anyPositive = $months->contains(fn ($m) => (int) ($m['rent_revenue'] ?? 0) > 0);
        $this->assertTrue($anyPositive, 'بطاقة شهر واحد على الأقل يجب أن تظهر إيراد إيجار > 0');
    }
}
