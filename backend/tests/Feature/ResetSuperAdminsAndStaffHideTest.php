<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Accounting\AccountingService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ResetSuperAdminsAndStaffHideTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        app(AccountingService::class)->ensureChartOfAccounts();
        TenantSetting::query()->create([
            'center_name' => 'اختبار سوبر',
            'feature_flags' => ['hr' => true],
            'operational' => [],
        ]);
    }

    public function test_reset_super_admins_wipes_users_and_creates_phone_supers_without_employee(): void
    {
        $role = Role::query()->where('name', 'viewer')->firstOrFail();
        User::factory()->create([
            'role_id' => $role->id,
            'phone' => '01011110000',
            'email' => 'old@rpm.local',
            'is_active' => true,
        ]);
        User::factory()->create([
            'role_id' => Role::query()->where('name', 'super_admin')->firstOrFail()->id,
            'phone' => '01022220000',
            'email' => 'old-admin@rpm.local',
            'is_active' => true,
        ]);

        $this->artisan('rpm:reset-super-admins', [
            '--force' => true,
            '--phone1' => '01150100104',
            '--phone2' => '01125253888',
            '--password1' => 'StrongPass!23456',
            '--password2' => 'StrongPass!65432',
            '--name1' => 'سوبر أ',
            '--name2' => 'سوبر ب',
        ])->assertSuccessful();

        $this->assertSame(2, User::query()->count());
        $u1 = User::query()->where('phone', '01150100104')->first();
        $u2 = User::query()->where('phone', '01125253888')->first();
        $this->assertNotNull($u1);
        $this->assertNotNull($u2);
        $this->assertNull($u1->employee_id);
        $this->assertNull($u2->employee_id);
        $this->assertSame('super_admin', $u1->role->name);
        $this->assertSame('super_admin', $u2->role->name);
        $this->assertTrue(Hash::check('StrongPass!23456', $u1->password));
        $this->assertTrue(Hash::check('StrongPass!65432', $u2->password));
    }

    public function test_super_admin_never_appears_in_staff_index_or_update(): void
    {
        $adminRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        $accounts = Role::query()->where('name', 'accounts')->firstOrFail();

        $super = User::factory()->create([
            'role_id' => $adminRole->id,
            'phone' => '01150100104',
            'email' => null,
            'employee_id' => null,
            'is_active' => true,
        ]);
        $staff = User::factory()->create([
            'role_id' => $accounts->id,
            'phone' => '01033334444',
            'email' => 'staff@rpm.local',
            'is_active' => true,
        ]);

        Sanctum::actingAs($super);

        $this->getJson('/api/staff')
            ->assertOk()
            ->assertJsonPath('summary.total', 1)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $staff->id)
            ->assertJsonMissing(['phone' => '01150100104']);

        $this->putJson('/api/staff/'.$super->id, ['name' => 'مخفي'])
            ->assertForbidden()
            ->assertJsonPath('message', 'حسابات النظام العليا تُدار من أمر إعادة التعيين فقط، وليس من شاشة الموظفين.');
    }

    public function test_employee_linked_misassigned_super_can_reset_password_and_demote(): void
    {
        $adminRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        $ops = Role::query()->where('name', 'ops_manager')->firstOrFail();
        $actor = User::factory()->create([
            'role_id' => $adminRole->id,
            'phone' => '01150100104',
            'email' => null,
            'is_active' => true,
        ]);
        $employee = \App\Models\Employee::query()->create([
            'name' => 'هيثم صلاح',
            'job_title' => 'مدير تشغيل',
            'hired_on' => now()->toDateString(),
            'base_salary' => 8500,
            'status' => 'active',
        ]);
        $misassigned = User::factory()->create([
            'name' => 'هيثم صلاح',
            'role_id' => $adminRole->id,
            'phone' => '01099628409',
            'email' => null,
            'employee_id' => $employee->id,
            'is_active' => true,
            'password' => 'password',
        ]);

        Sanctum::actingAs($actor);

        $this->postJson('/api/staff/'.$misassigned->id.'/password', [
            'password' => 'NewOpsPass1',
            'password_confirmation' => 'NewOpsPass1',
        ])->assertOk()
            ->assertJsonPath('message', 'تم تحديث كلمة المرور.');

        $this->assertTrue(Hash::check('NewOpsPass1', $misassigned->fresh()->password));

        $this->putJson('/api/staff/'.$misassigned->id, [
            'role_id' => $ops->id,
        ])->assertOk()
            ->assertJsonPath('data.role.name', 'ops_manager');
    }

    public function test_ops_manager_password_reset_endpoint(): void
    {
        $adminRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        $ops = Role::query()->where('name', 'ops_manager')->firstOrFail();
        $actor = User::factory()->create([
            'role_id' => $adminRole->id,
            'phone' => '01150100104',
            'is_active' => true,
        ]);
        $target = User::factory()->create([
            'role_id' => $ops->id,
            'phone' => '01000000099',
            'is_active' => true,
            'password' => 'password',
        ]);

        Sanctum::actingAs($actor);

        $this->postJson('/api/staff/'.$target->id.'/password', [
            'password' => 'OpsPass1234',
            'password_confirmation' => 'OpsPass1234',
        ])->assertOk();

        $this->assertTrue(Hash::check('OpsPass1234', $target->fresh()->password));
    }

    public function test_staff_store_rejects_super_admin_role(): void
    {
        $adminRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        $actor = User::factory()->create([
            'role_id' => $adminRole->id,
            'phone' => '01150100104',
            'is_active' => true,
        ]);
        Sanctum::actingAs($actor);

        $this->postJson('/api/staff', [
            'name' => 'محاولة سوبر',
            'phone' => '01077778888',
            'password' => 'password',
            'role_id' => $adminRole->id,
            'create_employee' => true,
        ])->assertForbidden()
            ->assertJsonPath('message', 'لا يُنشأ ولا يُمنح دور المدير الأعلى من شاشة الموظفين.');
    }
}
