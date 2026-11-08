<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Accounting\AccountingService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class QaV10FixTicketsTest extends TestCase
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
            'operational' => [
                'min_customer_age' => 21,
                'require_handover_signature' => false,
                'block_expired_license' => false,
            ],
        ]);
    }

    public function test_v10_daily_cash_hides_contract_numbers_and_links_the_contract(): void
    {
        $customer = Customer::query()->create([
            'full_name' => 'عميل حركة اليوم',
            'phone' => '01077770010',
            'birth_date' => '1990-04-04',
            'license_number' => 'LIC-V10',
            'license_expires_on' => now()->addYears(2)->toDateString(),
            'status' => 'active',
        ]);
        $vehicle = Vehicle::query()->create([
            'code' => 'V10-CASH',
            'plate' => 'ن ق د 10',
            'brand' => 'تويوتا',
            'model' => 'يارس',
            'year' => 2022,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 1000,
            'daily_rate' => 1000,
            'default_deposit' => 1000,
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->admin());
        $this->postJson('/api/contracts', [
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'starts_at' => now()->addDays(10)->format('Y-m-d H:i:s'),
            'planned_ends_at' => now()->addDays(12)->format('Y-m-d H:i:s'),
        ])->assertCreated();
        $contractId = $this->postJson('/api/contracts', [
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'starts_at' => now()->subHours(2)->format('Y-m-d H:i:s'),
            'planned_ends_at' => now()->addDays(2)->format('Y-m-d H:i:s'),
        ])->assertCreated()->json('data.id');
        $number = (string) Contract::query()->findOrFail($contractId)->number;
        $this->postJson("/api/contracts/{$contractId}/approve")->assertOk();
        $this->postJson("/api/contracts/{$contractId}/handover", ['odometer_out' => 1000])->assertOk();
        $this->postJson("/api/contracts/{$contractId}/deposit", [
            'method' => 'cash',
            'receipt_number' => 'V10-DEP',
            'amount' => 1000,
        ])->assertCreated();
        $depositId = (int) Deposit::query()->where('contract_id', $contractId)->value('id');
        $this->assertNotSame($contractId, $depositId);

        $this->postJson('/api/accounting/payments', [
            'amount' => 100,
            'method' => 'cash',
            'direction' => 'in',
            'reference' => 'V10-RENT',
            'link_type' => 'contract',
            'link_id' => $contractId,
            'notes' => 'دفعة إيجار للعقد '.$number,
        ])->assertCreated();
        $this->postJson('/api/accounting/payments', [
            'amount' => 200,
            'method' => 'cash',
            'direction' => 'out',
            'reference' => 'V10-REF',
            'link_type' => 'deposit',
            'link_id' => $depositId,
            'notes' => 'رد متبقي التأمين النقدي',
        ])->assertCreated();

        $storedDepositNote = (string) Payment::query()
            ->where('link_type', 'deposit')
            ->where('direction', 'in')
            ->value('notes');
        $this->assertStringContainsString($number, $storedDepositNote);

        $accountant = $this->userWith(['vehicles.view', 'accounting.view']);
        Sanctum::actingAs($accountant);
        $this->getJson('/api/contracts/'.$contractId)->assertStatus(403);
        $this->getJson('/api/accounting/payments')->assertStatus(403);
        $hidden = $this->getJson('/api/dashboard')->assertOk()->json('daily_cash');
        $this->assertNotNull($hidden);
        $this->assertStringNotContainsString('CNT-', (string) json_encode($hidden, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('عقد #'.$contractId, (string) json_encode($hidden, JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('قبض تأمين نقدي', (string) json_encode($hidden['entries'], JSON_UNESCAPED_UNICODE));
        $this->assertSame(1100, $hidden['totals']['in']);
        $this->assertSame(200, $hidden['totals']['out']);
        foreach ($hidden['entries'] as $entry) {
            if (($entry['source'] ?? '') === 'payment') {
                $this->assertNull($entry['href']);
                $this->assertArrayHasKey('amount', $entry);
                $this->assertArrayHasKey('direction', $entry);
                $this->assertArrayHasKey('method', $entry);
            }
        }
        $restrictedRent = collect($hidden['entries'])->first(
            fn ($row) => ($row['title'] ?? '') === 'قبض إيجار / دفعة عقد'
        );
        $this->assertNotNull($restrictedRent);
        $this->assertStringContainsString('دفعة عقد', (string) $restrictedRent['subtitle']);
        $this->assertStringNotContainsString((string) $contractId, (string) $restrictedRent['subtitle']);
        $this->assertSame(100, $restrictedRent['amount']);
        $this->assertSame('in', $restrictedRent['direction']);
        $this->assertSame('cash', $restrictedRent['method']);
        $this->assertSame($hidden['totals']['count'], count($hidden['entries']));

        $this->assertStringContainsString($number, (string) Payment::query()
            ->where('link_type', 'deposit')
            ->where('direction', 'in')
            ->value('notes'));

        foreach ([$this->userWith(['vehicles.view', 'accounting.view', 'contracts.view']), $this->admin()] as $user) {
            Sanctum::actingAs($user);
            $shown = $this->getJson('/api/dashboard')->assertOk()->json('daily_cash');
            $this->assertStringContainsString($number, (string) json_encode($shown, JSON_UNESCAPED_UNICODE));
            $byNotes = collect($shown['entries'])->keyBy(fn ($row) => $row['subtitle']);
            $deposit = $byNotes->first(fn ($row) => str_contains((string) $row['subtitle'], 'قبض تأمين نقدي للعقد '.$number));
            $rent = $byNotes->first(fn ($row) => str_contains((string) $row['subtitle'], 'دفعة إيجار للعقد '.$number));
            $refund = $byNotes->first(fn ($row) => str_contains((string) $row['subtitle'], 'رد متبقي التأمين النقدي'));
            $this->assertNotNull($deposit);
            $this->assertNotNull($rent);
            $this->assertNotNull($refund);
            $this->assertSame('/contracts/'.$contractId, $deposit['href']);
            $this->assertSame('/contracts/'.$contractId, $rent['href']);
            $this->assertSame('/contracts/'.$contractId, $refund['href']);
            $this->assertNotSame('/contracts/'.$depositId, $deposit['href']);
            $this->assertStringContainsString('عقد #'.$contractId, (string) $rent['subtitle']);
        }
    }

    /**
     * @param  list<string>  $keys
     */
    private function userWith(array $keys): User
    {
        $role = Role::query()->create([
            'name' => 'v10_'.substr(md5(implode('|', $keys).microtime(true)), 0, 12),
            'label' => 'اختبار',
        ]);
        $role->permissions()->sync(Permission::query()->whereIn('key', $keys)->pluck('id'));

        return User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
    }

    private function admin(): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('name', 'super_admin')->firstOrFail()->id,
            'is_active' => true,
        ]);
    }
}
