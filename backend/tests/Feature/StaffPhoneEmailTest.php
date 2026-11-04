<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Accounting\AccountingService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StaffPhoneEmailTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Role $adminRole;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        app(AccountingService::class)->ensureChartOfAccounts();
        TenantSetting::query()->create([
            'center_name' => 'اختبار موظفين',
            'feature_flags' => ['hr' => true],
            'operational' => [],
        ]);
        $this->adminRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        $this->admin = User::factory()->create([
            'role_id' => $this->adminRole->id,
            'is_active' => true,
            'phone' => '01099990000',
            'email' => 'admin-staff@rpm.local',
        ]);
        Sanctum::actingAs($this->admin);
    }

    public function test_staff_store_requires_phone_and_allows_null_email(): void
    {
        $role = Role::query()->where('name', 'accounts')->firstOrFail();

        $this->postJson('/api/staff', [
            'name' => 'موظف بلا بريد',
            'phone' => '01011112222',
            'password' => 'password',
            'role_id' => $role->id,
            'create_employee' => true,
            'job_title' => 'محاسب صندوق',
        ])->assertCreated()
            ->assertJsonPath('data.phone', '01011112222')
            ->assertJsonPath('data.email', null)
            ->assertJsonPath('data.name', 'موظف بلا بريد');

        $this->assertDatabaseHas('users', [
            'phone' => '01011112222',
            'email' => null,
        ]);
        $this->assertDatabaseHas('employees', [
            'name' => 'موظف بلا بريد',
            'job_title' => 'محاسب صندوق',
        ]);
    }

    public function test_staff_store_rejects_missing_phone(): void
    {
        $role = Role::query()->where('name', 'accounts')->firstOrFail();

        $this->postJson('/api/staff', [
            'name' => 'بدون موبايل',
            'email' => 'no-phone@rpm.local',
            'password' => 'password',
            'role_id' => $role->id,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);
    }

    public function test_staff_update_can_clear_email_and_keep_phone(): void
    {
        $role = Role::query()->where('name', 'hr')->firstOrFail();
        $user = User::factory()->create([
            'name' => 'محمود إبراهيم',
            'email' => 'hr-old@rpm.local',
            'phone' => '01033334444',
            'role_id' => $role->id,
            'is_active' => true,
        ]);

        $this->putJson("/api/staff/{$user->id}", [
            'email' => '',
            'phone' => '01033334444',
            'name' => 'محمود إبراهيم',
        ])->assertOk()
            ->assertJsonPath('data.email', null)
            ->assertJsonPath('data.phone', '01033334444');
    }

    public function test_employee_show_dossier_still_works(): void
    {
        $created = $this->postJson('/api/staff', [
            'name' => 'سارة للتفاصيل',
            'phone' => '01055556666',
            'password' => 'password',
            'role_id' => $this->adminRole->id,
            'create_employee' => true,
            'base_salary' => 8000,
        ])->assertCreated()->json('data');

        $employeeId = $created['employee_id'];
        $this->assertNotNull($employeeId);

        $this->getJson("/api/employees/{$employeeId}")
            ->assertOk()
            ->assertJsonPath('data.employee.name', 'سارة للتفاصيل')
            ->assertJsonPath('data.user.phone', '01055556666');
    }
}
