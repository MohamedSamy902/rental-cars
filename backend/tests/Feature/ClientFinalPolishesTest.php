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
                'showroom' => true, 'petty_cash' => true, 'waitlist' => true, 'inventory' => true,
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
            'المحاسب (أحمد محمد)',
            $narrator->actorPhrase('أحمد محمد', 'المحاسب'),
        );
        $this->assertSame(
            'مسؤول الموارد البشرية (محمود إبراهيم)',
            $narrator->actorPhrase('محمود إبراهيم', 'مسؤول الموارد البشرية'),
        );
        $this->assertSame(
            'مدير التشغيل (محمد سامي)',
            $narrator->actorPhrase('محمد سامي', 'مدير التشغيل'),
        );

        $log = new AuditLog([
            'action' => 'petty_cash_topup',
            'record_type' => PettyCashAccount::class,
            'record_id' => 1,
            'after' => ['employee_name' => 'عمرو سائق'],
        ]);
        $sentence = $narrator->summarize($log, 'أحمد محمد', 'المحاسب');
        $this->assertSame('قام المحاسب (أحمد محمد) بتسجيل عهدة للموظف عمرو سائق', $sentence);
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
            ->assertJsonStructure(['summary' => ['upcoming', 'handover_today']]);
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
