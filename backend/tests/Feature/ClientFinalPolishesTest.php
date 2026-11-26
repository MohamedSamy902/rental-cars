<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\PettyCashAccount;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Accounting\AccountingService;
use App\Services\Operations\AuditNarrator;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientFinalPolishesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        app(AccountingService::class)->ensureChartOfAccounts();
        TenantSetting::query()->create([
            'center_name' => 'اختبار الصقل',
            'feature_flags' => [
                'showroom' => true, 'petty_cash' => true, 'inventory' => true,
                'department_notice' => true, 'chat' => true, 'evaluations' => true, 'hr' => true,
            ],
            'operational' => [
                'require_handover_signature' => false,
                'block_expired_license' => false,
                'block_expired_vehicle_docs' => false,
            ],
        ]);
        $adminRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        $this->admin = User::factory()->create([
            'name' => 'أحمد',
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->admin);
    }

    public function test_audit_logs_return_arabic_summary(): void
    {
        AuditLog::query()->create([
            'user_id' => $this->admin->id,
            'action' => 'created',
            'record_type' => Contract::class,
            'record_id' => 1,
            'after' => ['number' => 'C-TEST-1'],
        ]);

        $res = $this->getJson('/api/audit-logs?limit=5')
            ->assertOk()
            ->json();

        $this->assertNotEmpty($res['data']);
        $this->assertStringContainsString('قام', $res['data'][0]['summary']);
        $this->assertStringContainsString('حجز', $res['data'][0]['summary']);
        $this->assertSame('أحمد', $res['data'][0]['user_name']);
        $this->assertSame('مدير النظام', $res['data'][0]['user_role_title']);
        $this->assertStringContainsString('مدير النظام', $res['data'][0]['summary']);
        $this->assertStringContainsString('أحمد', $res['data'][0]['summary']);
        $this->assertNotEmpty($res['meta']['users']);
        $this->assertNotEmpty($res['meta']['actions']);
        $this->assertTrue(collect($res['meta']['actions'])->contains(fn ($a) => ($a['value'] ?? '') === 'created'));
    }

    public function test_audit_narrator_pairs_role_title_with_name(): void
    {
        $narrator = app(AuditNarrator::class);
        $this->assertSame(
            'أحمد محمد (المحاسب)',
            $narrator->actorPhrase('أحمد محمد', 'المحاسب'),
        );
        $this->assertSame(
            'محمود إبراهيم (مسؤول الموارد البشرية)',
            $narrator->actorPhrase('محمود إبراهيم', 'مسؤول الموارد البشرية'),
        );
        $this->assertSame(
            'محمد سامي (مدير التشغيل)',
            $narrator->actorPhrase('محمد سامي', 'مدير التشغيل'),
        );

        $log = new AuditLog([
            'action' => 'petty_cash_topup',
            'record_type' => PettyCashAccount::class,
            'record_id' => 1,
            'after' => ['employee_name' => 'عمرو سائق'],
        ]);
        $sentence = $narrator->summarize($log, 'أحمد محمد', 'المحاسب');
        $this->assertSame('قام أحمد محمد (المحاسب) بتسجيل عهدة للموظف عمرو سائق', $sentence);
    }

    public function test_audit_logs_filter_by_user_action_and_date(): void
    {
        $other = User::factory()->create([
            'name' => 'سارة',
            'role_id' => $this->admin->role_id,
            'is_active' => true,
        ]);

        $old = AuditLog::query()->create([
            'user_id' => $this->admin->id,
            'action' => 'handover',
            'record_type' => Contract::class,
            'record_id' => 10,
            'after' => ['number' => 'C-H-1'],
        ]);
        AuditLog::query()->whereKey($old->id)->update([
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);
        AuditLog::query()->create([
            'user_id' => $other->id,
            'action' => 'completed',
            'record_type' => Contract::class,
            'record_id' => 11,
            'after' => ['number' => 'C-H-2'],
        ]);

        $byUser = $this->getJson('/api/audit-logs?user_id='.$other->id.'&limit=20')
            ->assertOk()
            ->json('data');
        $this->assertCount(1, $byUser);
        $this->assertSame($other->id, $byUser[0]['user_id']);
        $this->assertSame('completed', $byUser[0]['action']);

        $byAction = $this->getJson('/api/audit-logs?action=handover&limit=20')
            ->assertOk()
            ->json('data');
        $this->assertNotEmpty($byAction);
        $this->assertTrue(collect($byAction)->every(fn ($row) => $row['action'] === 'handover'));

        $from = now()->toDateString();
        $byDate = $this->getJson('/api/audit-logs?date_from='.$from.'&date_to='.$from.'&limit=20')
            ->assertOk()
            ->json('data');
        $this->assertNotEmpty($byDate);
        $this->assertTrue(collect($byDate)->every(fn ($row) => $row['action'] === 'completed'));
    }

    public function test_audit_narrator_vehicle_example(): void
    {
        $narrator = app(AuditNarrator::class);
        $log = new AuditLog([
            'action' => 'vehicle_updated',
            'record_type' => Vehicle::class,
            'record_id' => 1,
            'after' => ['plate' => 'أ ب ج 1234'],
        ]);
        $sentence = $narrator->summarize($log, 'سارة');
        $this->assertStringContainsString('بتعديل بيانات السيارة', $sentence);
        $this->assertStringContainsString('أ ب ج 1234', $sentence);
    }

    public function test_contract_create_with_odometer_and_partial_handover_payment(): void
    {
        $customer = Customer::query()->create([
            'full_name' => 'عميل صقل',
            'phone' => '01099998877',
            'birth_date' => '1990-01-01',
            'license_number' => 'L-POLISH',
            'license_expires_on' => now()->addYear()->toDateString(),
            'status' => 'active',
        ]);
        $vehicle = Vehicle::query()->create([
            'code' => 'P-1',
            'plate' => 'صقل 1',
            'brand' => 'هيونداي',
            'model' => 'إلنترا',
            'year' => 2023,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 10000,
            'daily_rate' => 900,
            'default_deposit' => 3000,
            'is_active' => true,
            'manual_status' => 'available',
        ]);

        $starts = now()->addDays(3)->startOfHour();
        $ends = $starts->copy()->addDays(2);

        $created = $this->postJson('/api/contracts', [
            'payment_amount' => 1,
            'payment_method' => 'cash',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'starts_at' => $starts->toDateTimeString(),
            'planned_ends_at' => $ends->toDateTimeString(),
            'daily_rate' => 1100,
            'deposit' => 3000,
            'odometer' => 10050,
        ])->assertCreated()->json('data');

        $this->assertSame(10050, (int) $vehicle->fresh()->odometer);
        $this->assertSame($this->admin->id, (int) $created['sales_user_id']);

        $this->postJson("/api/contracts/{$created['id']}/approve")->assertOk();

        $handover = $this->postJson("/api/contracts/{$created['id']}/handover", [
            'odometer_out' => 10060,
            'payment_amount' => 500,
            'payment_method' => 'cash',
            'balance_due_on' => now()->addDays(5)->toDateString(),
        ])->assertOk()->json('data');

        $this->assertSame('active', $handover['status']);
        $inspection = collect($handover['inspections'] ?? [])->first();
        if (is_array($inspection)) {
            $this->assertArrayNotHasKey('fuel_level', $inspection);
        }

        $dossier = $this->getJson("/api/contracts/{$created['id']}")->assertOk()->json('data');
        $this->assertSame('أحمد', $dossier['created_by']);
        $this->assertGreaterThanOrEqual(500, (int) $dossier['ledger']['paid']);
    }

    public function test_upcoming_and_handover_today_tabs(): void
    {
        $this->getJson('/api/contracts?tab=upcoming')->assertOk();
        $this->getJson('/api/contracts?tab=handover_today')->assertOk()
            ->assertJsonStructure(['summary' => ['upcoming', 'handover_today', 'overdue_handover']]);
        $this->getJson('/api/contracts?tab=overdue_handover')->assertOk()
            ->assertJsonStructure(['summary' => ['overdue_handover'], 'data']);
    }

    public function test_overdue_handover_tab_lists_past_starts_not_yet_handed_over(): void
    {
        $customer = Customer::query()->create([
            'full_name' => 'عميل متأخر',
            'phone' => '01009998877',
            'birth_date' => '1990-01-01',
            'license_number' => 'LIC-LATE',
            'license_expires_on' => now()->addYears(2)->toDateString(),
            'status' => 'active',
        ]);
        $vehicle = Vehicle::query()->create([
            'code' => 'LATE-1',
            'plate' => 'ل ت 1001',
            'brand' => 'هيونداي',
            'model' => 'النترا',
            'year' => 2023,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 1000,
            'daily_rate' => 500,
            'default_deposit' => 1000,
            'is_active' => true,
        ]);

        $overdueHandover = Contract::query()->create([
            'number' => 'C-LATE-1',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'status' => 'approved',
            'starts_at' => now()->subHours(3),
            'planned_ends_at' => now()->addDays(3),
            'approved_days' => 3,
            'frozen_daily_rate' => 500,
            'frozen_agreed_deposit' => 1000,
        ]);
        $overdueReturn = Contract::query()->create([
            'number' => 'C-LATE-RET-1',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'status' => 'active',
            'starts_at' => now()->subDays(5),
            'planned_ends_at' => now()->subDay(),
            'approved_days' => 4,
            'frozen_daily_rate' => 500,
            'frozen_agreed_deposit' => 1000,
        ]);
        Contract::query()->create([
            'number' => 'C-FUTURE-1',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'status' => 'approved',
            'starts_at' => now()->addDays(2),
            'planned_ends_at' => now()->addDays(5),
            'approved_days' => 3,
            'frozen_daily_rate' => 500,
            'frozen_agreed_deposit' => 1000,
        ]);
        Contract::query()->create([
            'number' => 'C-ACTIVE-1',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'status' => 'active',
            'starts_at' => now()->subDays(1),
            'planned_ends_at' => now()->addDays(2),
            'approved_days' => 3,
            'frozen_daily_rate' => 500,
            'frozen_agreed_deposit' => 1000,
        ]);

        $res = $this->getJson('/api/contracts?tab=overdue_handover&per_page=50')->assertOk()->json();
        $ids = collect($res['data'])->pluck('id')->all();
        $this->assertContains($overdueHandover->id, $ids);
        $this->assertContains($overdueReturn->id, $ids, 'عقد نشط فات موعد استلامه يظهر في متأخر');
        $this->assertNotContains(
            Contract::query()->where('number', 'C-FUTURE-1')->value('id'),
            $ids
        );
        $this->assertNotContains(
            Contract::query()->where('number', 'C-ACTIVE-1')->value('id'),
            $ids,
            'عقد نشط لسه ما خلصش مدته مش متأخر'
        );
        $this->assertGreaterThanOrEqual(2, (int) ($res['summary']['overdue_handover'] ?? 0));
    }

    public function test_recommend_has_no_weights_or_scores(): void
    {
        Vehicle::query()->create([
            'code' => 'P-2',
            'plate' => 'صقل 2',
            'brand' => 'كيا',
            'model' => 'ريو',
            'year' => 2022,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 5000,
            'daily_rate' => 700,
            'default_deposit' => 2000,
            'is_active' => true,
            'manual_status' => 'available',
        ]);

        $res = $this->getJson('/api/vehicles/recommend')->assertOk()->json();
        $this->assertArrayNotHasKey('weights', $res);
        foreach ($res['data'] as $row) {
            $this->assertArrayNotHasKey('scores', $row);
            $this->assertArrayNotHasKey('rental_priority', $row);
        }
    }
}
