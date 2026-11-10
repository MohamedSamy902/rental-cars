<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Accounting\AccountingService;
use App\Services\Contracts\ContractLedgerMath;
use App\Services\Contracts\ContractLifecycleService;
use App\Services\Contracts\InspectionService;
use App\Support\PermissionCatalog;
use Database\Seeders\DatabaseSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AcceptanceFixesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ContractLifecycleService $life;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        app(AccountingService::class)->ensureChartOfAccounts();
        TenantSetting::query()->create([
            'center_name' => 'اختبار',
            'feature_flags' => ['department_notice' => true, 'hr' => true],
            'operational' => [
                'require_handover_signature' => false,
                'block_expired_license' => false,
                'block_expired_vehicle_docs' => false,
            ],
        ]);
        $adminRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        $this->admin = User::factory()->create(['role_id' => $adminRole->id, 'is_active' => true]);
        Sanctum::actingAs($this->admin);
        $this->life = app(ContractLifecycleService::class);
    }

    public function test_contract_numbers_skip_values_already_used(): void
    {
        [$customer, $vehicle] = $this->seedParty('01090000001');
        $first = $this->life->create($this->window($customer, $vehicle, 1, 3), $this->admin->id);
        $this->assertSame('CNT-00001', $first->number);

        DB::table('contract_number_sequences')->where('id', 1)->update(['next_value' => 1]);
        [, $other] = $this->seedParty('01090000002');
        $second = $this->life->create($this->window($customer, $other, 1, 3), $this->admin->id);

        $this->assertSame('CNT-00002', $second->number);
    }

    public function test_active_overdue_contract_blocks_a_future_booking_and_extension_still_works(): void
    {
        [$customer, $vehicle] = $this->seedParty('01090000003');
        $active = $this->activate($customer, $vehicle, now()->subDays(3), now()->subHour());
        $active->update(['planned_ends_at' => now()->subHour()]);

        try {
            $this->life->create($this->window($customer, $vehicle, 2, 5), $this->admin->id);
            $this->fail('future booking on an overdue active car was accepted');
        } catch (DomainException $e) {
            $this->assertStringContainsString('متعارض', $e->getMessage());
        }

        $extension = $this->life->extend($active->fresh(), [
            'starts_at' => $active->planned_ends_at->format('Y-m-d H:i:s'),
            'planned_ends_at' => now()->addDays(4)->format('Y-m-d H:i:s'),
        ], $this->admin->id);

        $this->assertSame('pending', $extension->status);
        $this->assertSame($active->id, $extension->extended_from_contract_id);
    }

    public function test_cancel_rechecks_status_inside_the_lock(): void
    {
        [$customer, $vehicle] = $this->seedParty('01090000004');
        $contract = $this->life->create($this->window($customer, $vehicle, 1, 3), $this->admin->id);
        $contract = $this->life->approve($contract, $this->admin->id);
        DB::table('contracts')->where('id', $contract->id)->update(['status' => 'active']);

        try {
            $this->life->cancel($contract, 'إلغاء بعد أن أصبح نشطاً', $this->admin->id);
            $this->fail('stale cancel overwrote an active contract');
        } catch (DomainException $e) {
            $this->assertStringContainsString('بعد التسليم', $e->getMessage());
        }

        $this->assertSame('active', $contract->fresh()->status);
    }

    public function test_swap_rejects_sold_replacement(): void
    {
        [$customer, $vehicle] = $this->seedParty('01090000005');
        [, $sold] = $this->seedParty('01090000006');
        $sold->update(['manual_status' => 'sold']);
        $active = $this->activate($customer, $vehicle, now()->subHour(), now()->addDays(2));

        try {
            $this->life->swapDueToFault($active, [
                'replacement_vehicle_id' => $sold->id,
                'odometer_in' => (int) $vehicle->odometer + 20,
                'odometer_out' => (int) $sold->odometer + 5,
                'fault_description' => 'عطل',
            ], $this->admin->id);
            $this->fail('swap onto a sold car was accepted');
        } catch (DomainException $e) {
            $this->assertStringContainsString('غير متاحة', $e->getMessage());
        }
    }

    public function test_transfer_deposit_posts_to_the_bank_account(): void
    {
        [$customer, $vehicle] = $this->seedParty('01090000007');
        $active = $this->activate($customer, $vehicle, now()->subHour(), now()->addDays(2));
        $deposit = $this->life->receiveDeposit($active, [
            'method' => 'transfer',
            'receipt_number' => 'DEP-T-1',
            'amount' => (int) $active->frozen_agreed_deposit,
        ], $this->admin->id);

        $codes = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_lines.account_id')
            ->where('journal_entries.source', 'deposit_hold:'.$deposit->id)
            ->pluck('accounts.code')
            ->all();

        $this->assertContains('1100', $codes);
        $this->assertContains('1300', $codes);
        $this->assertNotContains('1000', $codes);
    }

    public function test_discount_above_the_total_is_rejected_in_arabic(): void
    {
        [$customer, $vehicle] = $this->seedParty('01090000008');
        $active = $this->activate($customer, $vehicle, now()->subDay(), now()->addDay());
        $active->update(['discount' => 500000]);

        try {
            $this->life->completeOnReturn($active, (int) $active->odometer_out + 10, now(), $this->admin->id);
            $this->fail('negative total was stored');
        } catch (DomainException $e) {
            $this->assertStringContainsString('الخصم أكبر', $e->getMessage());
        }

        $this->assertSame('active', $active->fresh()->status);
    }

    public function test_handover_of_pending_contract_requires_approve_permission(): void
    {
        [$customer, $vehicle] = $this->seedParty('01090000009');
        $pending = $this->life->create($this->window($customer, $vehicle, 0, 2), $this->admin->id);
        $sales = Role::query()->where('name', 'sales')->firstOrFail();
        $sales->permissions()->sync(
            Permission::query()->whereIn('key', ['contracts.view', 'contracts.update'])->pluck('id')
        );
        $user = User::factory()->create(['role_id' => $sales->id, 'is_active' => true]);
        $pending->update(['sales_user_id' => $user->id]);
        Sanctum::actingAs($user);

        $this->postJson("/api/contracts/{$pending->id}/handover", [
            'odometer_out' => (int) $vehicle->odometer + 5,
        ])->assertStatus(403);

        $this->assertSame('pending', $pending->fresh()->status);
    }

    public function test_sales_cannot_open_another_users_contract(): void
    {
        [$customer, $vehicle] = $this->seedParty('01090000010');
        $contract = $this->life->create($this->window($customer, $vehicle, 1, 3), $this->admin->id);
        $sales = Role::query()->where('name', 'sales')->firstOrFail();
        $sales->permissions()->sync(
            Permission::query()->whereIn('key', ['contracts.view'])->pluck('id')
        );
        $user = User::factory()->create(['role_id' => $sales->id, 'is_active' => true]);
        Sanctum::actingAs($user);

        $this->getJson("/api/contracts/{$contract->id}")->assertStatus(403);
    }

    public function test_staff_manager_cannot_grant_super_admin_or_permissions_they_lack(): void
    {
        $ops = Role::query()->where('name', 'ops_manager')->firstOrFail();
        $ops->permissions()->sync(
            Permission::query()->whereIn('key', ['staff.manage', 'staff.view'])->pluck('id')
        );
        $actor = User::factory()->create(['role_id' => $ops->id, 'is_active' => true]);
        Sanctum::actingAs($actor);

        $super = Role::query()->where('name', 'super_admin')->firstOrFail();
        $this->postJson('/api/staff', [
            'name' => 'متسلل',
            'phone' => '01090000099',
            'password' => 'password1',
            'role_id' => $super->id,
        ])->assertStatus(403);

        $extra = Permission::query()->where('key', 'accounting.lock_month')->firstOrFail();
        $this->putJson("/api/roles/{$ops->id}/permissions", [
            'permission_ids' => [$extra->id],
        ])->assertStatus(403);
    }

    public function test_viewer_does_not_receive_base_salary(): void
    {
        Employee::query()->create([
            'name' => 'موظف مشاهدة',
            'base_salary' => 6750,
            'status' => 'active',
        ]);
        $viewer = Role::query()->where('name', 'viewer')->firstOrFail();
        $user = User::factory()->create(['role_id' => $viewer->id, 'is_active' => true]);
        Sanctum::actingAs($user);

        $row = $this->getJson('/api/employees')->assertOk()->json('data.0');
        $this->assertArrayNotHasKey('base_salary', $row);
    }

    public function test_department_notice_redacts_national_id_without_sensitive_permission(): void
    {
        Customer::query()->create([
            'full_name' => 'عميل إخطار',
            'phone' => '01090000011',
            'national_id' => '29901011234567',
            'birth_date' => '1990-01-01',
            'license_number' => 'LIC-NOTICE',
            'license_expires_on' => now()->addYear()->toDateString(),
            'status' => 'active',
        ]);
        $role = Role::query()->create(['name' => 'notice_only', 'label' => 'إخطار']);
        $role->permissions()->sync(
            Permission::query()->where('key', 'department_notice.print')->pluck('id')
        );
        $user = User::factory()->create(['role_id' => $role->id, 'is_active' => true]);
        Sanctum::actingAs($user);

        $this->getJson('/api/department-notice')
            ->assertOk()
            ->assertJsonPath('data.customers.0.national_id', null)
            ->assertJsonPath('data.customers.0.license_number', null);
    }

    public function test_attention_hides_counters_the_user_cannot_view(): void
    {
        $sales = Role::query()->where('name', 'sales')->firstOrFail();
        $sales->permissions()->sync(
            Permission::query()->whereIn('key', ['contracts.view', 'violations.view'])->pluck('id')
        );
        $user = User::factory()->create(['role_id' => $sales->id, 'is_active' => true]);
        Sanctum::actingAs($user);

        $this->getJson('/api/attention')
            ->assertOk()
            ->assertJsonPath('data.open_work_orders', null)
            ->assertJsonPath('data.low_stock', null)
            ->assertJsonPath('data.pending_hr_acks', null)
            ->assertJsonPath('data.draft_payroll_runs', null)
            ->assertJsonPath('data.owners_unpaid', null);
    }

    public function test_payroll_payout_posts_a_balanced_salary_journal(): void
    {
        $employee = Employee::query()->create([
            'name' => 'موظف راتب',
            'base_salary' => 4000,
            'status' => 'active',
        ]);
        $run = $this->postJson('/api/payroll/draft', ['year' => 2026, 'month' => 4])
            ->assertCreated()
            ->json('data');
        $this->postJson("/api/payroll/{$run['id']}/approve")->assertOk();
        $this->postJson("/api/payroll/lines/{$run['lines'][0]['id']}/payout", [
            'amount' => 4000,
            'paid_on' => '2026-04-28',
            'method' => 'cash',
        ])->assertOk();

        $payoutId = DB::table('payroll_payouts')->where('payroll_line_id', $run['lines'][0]['id'])->value('id');
        $lines = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.source', 'payroll_payout:'.$payoutId)
            ->get(['debit', 'credit']);

        $this->assertCount(2, $lines);
        $this->assertSame(4000, (int) $lines->sum('debit'));
        $this->assertSame(4000, (int) $lines->sum('credit'));
        $this->assertSame((int) $employee->id, (int) DB::table('payroll_lines')->where('id', $run['lines'][0]['id'])->value('employee_id'));
    }

    public function test_one_minute_late_does_not_tag_a_late_day(): void
    {
        [$customer, $vehicle] = $this->seedParty('01090000012');
        $active = $this->activate($customer, $vehicle, now()->subDays(2), now()->subMinute());
        $closed = $this->life->completeOnReturn($active, (int) $active->odometer_out + 15, now(), $this->admin->id);

        $this->assertSame(0, (int) $closed->tagged_late_days);
    }

    public function test_ledger_uses_the_same_day_count_as_pricing(): void
    {
        TenantSetting::query()->first()->update([
            'operational' => [
                'require_handover_signature' => false,
                'block_expired_license' => false,
                'block_expired_vehicle_docs' => false,
                'rental_fraction_extra_day_hours' => 1,
            ],
        ]);
        [$customer, $vehicle] = $this->seedParty('01090000013');
        $contract = $this->life->create([
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'starts_at' => '2026-11-01 10:00:00',
            'planned_ends_at' => '2026-11-02 12:00:00',
            'daily_rate' => 100,
        ], $this->admin->id, true);
        $contract->update([
            'approved_days' => 0,
            'final_total' => 0,
            'period_rent' => 0,
            'frozen_daily_rate' => 100,
        ]);

        $this->assertSame(200, app(ContractLedgerMath::class)->chargeTotal($contract->fresh()));
    }

    public function test_http_cannot_force_a_work_order_over_an_active_rental(): void
    {
        [$customer, $vehicle] = $this->seedParty('01090000014');
        $this->activate($customer, $vehicle, now()->subHour(), now()->addDays(2));

        $this->postJson('/api/work-orders', [
            'vehicle_id' => $vehicle->id,
            'odometer' => (int) $vehicle->odometer + 30,
            'puts_in_maintenance' => true,
            'force_after_resolution' => true,
        ])->assertStatus(422);
    }

    public function test_second_return_inspection_is_a_domain_error(): void
    {
        [$customer, $vehicle] = $this->seedParty('01090000015');
        $active = $this->activate($customer, $vehicle, now()->subDay(), now()->addDay());
        $inspections = app(InspectionService::class);
        $inspections->record($active->id, ['type' => 'return', 'odometer' => (int) $active->odometer_out + 5]);

        $this->expectException(DomainException::class);
        $inspections->record($active->id, ['type' => 'return', 'odometer' => (int) $active->odometer_out + 6]);
    }

    public function test_production_seed_does_not_create_the_demo_password(): void
    {
        $this->app['env'] = 'production';
        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseMissing('users', ['email' => 'admin@rpm.local']);
        $this->assertDatabaseHas('roles', ['name' => 'super_admin']);
    }

    public function test_api_responses_send_security_headers_and_tokens_expire(): void
    {
        $this->get('/up')->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->postJson('/api/login', [
            'email' => $this->admin->email,
            'password' => 'password',
        ])->assertOk();

        $this->assertNotNull(DB::table('personal_access_tokens')->where('tokenable_id', $this->admin->id)->value('expires_at'));
    }

    /**
     * @return array{0: Customer, 1: Vehicle}
     */
    private function seedParty(string $phone): array
    {
        $customer = Customer::query()->create([
            'full_name' => 'عميل '.$phone,
            'phone' => $phone,
            'birth_date' => '1990-01-01',
            'license_number' => 'L-'.$phone,
            'license_expires_on' => now()->addYears(3)->toDateString(),
            'status' => 'active',
        ]);
        $vehicle = Vehicle::query()->create([
            'code' => 'V-'.$phone,
            'plate' => 'ق '.$phone,
            'brand' => 'هيونداي',
            'model' => 'إلنترا',
            'year' => 2023,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 10000,
            'daily_rate' => 500,
            'default_deposit' => 1000,
            'is_active' => true,
        ]);

        return [$customer, $vehicle];
    }

    /**
     * @return array<string, mixed>
     */
    private function window(Customer $customer, Vehicle $vehicle, int $startDays, int $endDays): array
    {
        return [
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'starts_at' => now()->addDays($startDays)->toDateTimeString(),
            'planned_ends_at' => now()->addDays($endDays)->toDateTimeString(),
        ];
    }

    private function activate(Customer $customer, Vehicle $vehicle, \DateTimeInterface $start, \DateTimeInterface $end): Contract
    {
        $contract = $this->life->create([
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'starts_at' => $start->format('Y-m-d H:i:s'),
            'planned_ends_at' => $end->format('Y-m-d H:i:s'),
        ], $this->admin->id);
        $contract = $this->life->approve($contract, $this->admin->id);

        return $this->life->activateOnHandover($contract, (int) $vehicle->odometer + 10, $this->admin->id, [
            'signature_data' => str_repeat('sig', 20),
        ]);
    }
}
