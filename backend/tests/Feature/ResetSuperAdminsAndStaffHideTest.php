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
            ->assertNotFound();
    }
}
