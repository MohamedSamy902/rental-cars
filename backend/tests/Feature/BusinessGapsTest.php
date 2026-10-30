<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Employee;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Accounting\AccountingService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BusinessGapsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        app(AccountingService::class)->ensureChartOfAccounts();
        TenantSetting::query()->create([
            'center_name' => 'اختبار',
            'feature_flags' => [
                'showroom' => true,
                'petty_cash' => true,
                'waitlist' => true,
                'inventory' => true,
                'department_notice' => true,
                'chat' => true,
                'evaluations' => true,
                'hr' => true,
            ],
            'operational' => [
                'require_handover_signature' => true,
                'block_expired_license' => true,
                'block_expired_vehicle_docs' => true,
            ],
        ]);
        $adminRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        $this->admin = User::factory()->create(['role_id' => $adminRole->id, 'is_active' => true]);
        Sanctum::actingAs($this->admin);
    }

    public function test_handover_without_signature_fails(): void
    {
        $customer = Customer::query()->create([
            'full_name' => 'عميل',
            'phone' => '01000000001',
            'birth_date' => '1990-01-01',
            'license_number' => 'L1',
            'license_expires_on' => now()->addYear()->toDateString(),
            'status' => 'active',
        ]);
        $vehicle = Vehicle::query()->create([
            'code' => 'G-1', 'plate' => 'GAP 1', 'brand' => 'كيا', 'model' => 'ريو',
            'year' => 2022, 'category' => 'اقتصادي', 'fuel_type' => 'بنزين',
            'odometer' => 1000, 'daily_rate' => 800, 'default_deposit' => 2000, 'is_active' => true,
        ]);
        $id = $this->postJson('/api/contracts', [
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'starts_at' => now()->toIso8601String(),
            'planned_ends_at' => now()->addDays(2)->toIso8601String(),
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/contracts/{$id}/approve")->assertOk();
        $this->postJson("/api/contracts/{$id}/handover", ['odometer_out' => 1000])
            ->assertStatus(422);
    }

    public function test_blacklist_blocks_contract_create(): void
    {
        $customer = Customer::query()->create([
            'full_name' => 'محظور',
            'phone' => '01000000002',
            'birth_date' => '1990-01-01',
            'license_number' => 'L2',
            'license_expires_on' => now()->addYear()->toDateString(),
            'status' => 'blacklist',
        ]);
        $vehicle = Vehicle::query()->create([
            'code' => 'G-2', 'plate' => 'GAP 2', 'brand' => 'كيا', 'model' => 'ريو',
            'year' => 2022, 'category' => 'اقتصادي', 'fuel_type' => 'بنزين',
            'odometer' => 1000, 'daily_rate' => 800, 'default_deposit' => 2000, 'is_active' => true,
        ]);
        $this->postJson('/api/contracts', [
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'starts_at' => now()->toIso8601String(),
            'planned_ends_at' => now()->addDays(2)->toIso8601String(),
        ])->assertStatus(422);
    }

    public function test_expense_and_payroll_and_chat(): void
    {
        $employee = Employee::query()->create([
            'name' => 'فني',
            'job_title' => 'صيانة',
            'hired_on' => '2024-01-01',
            'base_salary' => 5000,
            'status' => 'active',
        ]);

        $vehicle = Vehicle::query()->create([
            'code' => 'VEH-BG01',
            'plate' => 'ب ج 3344',
            'brand' => 'تويوتا',
            'model' => 'يارس',
            'year' => 2019,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 40000,
            'odometer_source' => 'manual',
            'daily_rate' => 600,
            'default_deposit' => 1500,
            'rental_priority' => 50,
            'is_active' => true,
        ]);

        $this->postJson('/api/expenses', [
            'title' => 'زيوت',
            'amount' => 250,
            'spent_on' => now()->toDateString(),
            'category' => 'صيانة',
            'vehicle_id' => $vehicle->id,
        ])->assertCreated();

        $runId = $this->postJson('/api/payroll/draft', [
            'year' => (int) now()->format('Y'),
            'month' => (int) now()->format('n'),
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/payroll/{$runId}/approve")->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->postJson('/api/chat', ['body' => 'مرحبا بالفريق'])->assertCreated();
        $this->postJson('/api/evaluations', [
            'employee_id' => $employee->id,
            'score' => 4,
            'period' => now()->format('Y-m'),
        ])->assertCreated();

        $this->postJson('/api/ocr/review', [
            'source' => 'national_id',
            'raw_text' => 'أحمد محمود 29005121234567',
        ])->assertOk()->assertJsonPath('data.requires_staff_review', true);

        $this->postJson('/api/print-snapshots', [
            'type' => 'contract',
            'record_type' => 'contract',
            'record_id' => 1,
            'payload' => ['number' => 'CNT-x'],
        ])->assertCreated();
    }
}
