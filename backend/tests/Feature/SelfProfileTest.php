<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Accounting\AccountingService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SelfProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        app(AccountingService::class)->ensureChartOfAccounts();
        TenantSetting::query()->create([
            'center_name' => 'اختبار الملف الشخصي',
            'feature_flags' => [],
            'operational' => [],
        ]);
    }

    public function test_admin_can_update_own_profile_without_staff_permission_escalation(): void
    {
        $adminRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        $admin = User::factory()->create([
            'name' => 'مدير النظام',
            'email' => 'admin@rpm.local',
            'phone' => '01000000000',
            'password' => Hash::make('password'),
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);
        Sanctum::actingAs($admin);

        $res = $this->putJson('/api/auth/profile', [
            'name' => 'مدير محدّث',
            'email' => 'admin-new@rpm.local',
            'phone' => '01111111111',
        ])->assertOk();

        $res->assertJsonPath('data.name', 'مدير محدّث')
            ->assertJsonPath('data.email', 'admin-new@rpm.local')
            ->assertJsonPath('data.phone', '01111111111')
            ->assertJsonPath('data.role_name', 'super_admin')
            ->assertJsonPath('message', 'تم تحديث بيانات حسابك بنجاح.');

        $this->assertDatabaseHas('users', [
            'id' => $admin->id,
            'name' => 'مدير محدّث',
            'email' => 'admin-new@rpm.local',
            'phone' => '01111111111',
            'role_id' => $adminRole->id,
        ]);
    }

    public function test_viewer_without_user_management_can_update_own_profile_and_password(): void
    {
        $viewerRole = Role::query()->where('name', 'viewer')->firstOrFail();
        $employee = Employee::query()->create([
            'name' => 'مشاهدة فقط',
            'job_title' => 'مراقب',
            'hired_on' => now()->toDateString(),
            'base_salary' => 0,
            'status' => 'active',
        ]);
        $viewer = User::factory()->create([
            'name' => 'مشاهدة فقط',
            'email' => 'viewer@rpm.local',
            'phone' => '01022222222',
            'password' => Hash::make('password'),
            'role_id' => $viewerRole->id,
            'employee_id' => $employee->id,
            'is_active' => true,
        ]);

        // Ensure viewer cannot manage staff (self-profile must still work).
        $this->assertFalse($viewer->hasPermission('staff.manage'));

        Sanctum::actingAs($viewer);

        $this->putJson('/api/auth/profile', [
            'name' => 'مشاهد محدّث',
            'email' => 'viewer-updated@rpm.local',
            'phone' => '01555555555',
            'current_password' => 'password',
            'password' => 'new-secret-99',
            'password_confirmation' => 'new-secret-99',
        ])->assertOk()
            ->assertJsonPath('data.name', 'مشاهد محدّث')
            ->assertJsonPath('data.email', 'viewer-updated@rpm.local')
            ->assertJsonPath('data.phone', '01555555555')
            ->assertJsonPath('data.role_name', 'viewer');

        $viewer->refresh();
        $this->assertTrue(Hash::check('new-secret-99', $viewer->password));
        $this->assertSame($viewerRole->id, $viewer->role_id);
        $this->assertDatabaseHas('employees', [
            'id' => $employee->id,
            'name' => 'مشاهد محدّث',
        ]);
    }

    public function test_maintenance_user_can_patch_own_profile(): void
    {
        $maintRole = Role::query()->where('name', 'maintenance')->firstOrFail();
        $maint = User::factory()->create([
            'name' => 'مسؤول صيانة',
            'email' => 'maint@rpm.local',
            'phone' => '01033333333',
            'password' => Hash::make('password'),
            'role_id' => $maintRole->id,
            'is_active' => true,
        ]);
        Sanctum::actingAs($maint);

        $this->assertFalse($maint->hasPermission('staff.manage'));

        $this->patchJson('/api/auth/profile', [
            'name' => 'فني صيانة محدّث',
            'email' => 'maint@rpm.local',
            'phone' => '01099999999',
        ])->assertOk()
            ->assertJsonPath('data.name', 'فني صيانة محدّث')
            ->assertJsonPath('data.phone', '01099999999')
            ->assertJsonPath('data.role_name', 'maintenance');
    }

    public function test_password_change_requires_current_password(): void
    {
        $viewerRole = Role::query()->where('name', 'viewer')->firstOrFail();
        $viewer = User::factory()->create([
            'email' => 'viewer2@rpm.local',
            'password' => Hash::make('password'),
            'role_id' => $viewerRole->id,
            'is_active' => true,
        ]);
        Sanctum::actingAs($viewer);

        $this->putJson('/api/auth/profile', [
            'name' => $viewer->name,
            'email' => $viewer->email,
            'phone' => null,
            'password' => 'another-pass',
            'password_confirmation' => 'another-pass',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);
    }
}
