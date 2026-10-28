<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PermissionGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        TenantSetting::query()->create([
            'center_name' => 'اختبار',
            'feature_flags' => [
                'showroom' => false,
                'petty_cash' => true,
                'waitlist' => true,
                'inventory' => true,
                'department_notice' => true,
                'chat' => true,
                'evaluations' => true,
                'hr' => true,
            ],
        ]);
    }

    public function test_viewer_cannot_approve_contract(): void
    {
        $viewer = Role::query()->where('name', 'viewer')->firstOrFail();
        $user = User::factory()->create(['role_id' => $viewer->id, 'is_active' => true]);
        Sanctum::actingAs($user);

        $this->postJson('/api/contracts/1/approve')
            ->assertStatus(403)
            ->assertJsonPath('message', 'ليست لديك صلاحية لتنفيذ هذا الإجراء.');
    }

    public function test_showroom_flag_blocks_endpoint(): void
    {
        $admin = Role::query()->where('name', 'super_admin')->firstOrFail();
        $user = User::factory()->create(['role_id' => $admin->id, 'is_active' => true]);
        Sanctum::actingAs($user);

        $this->getJson('/api/showroom')
            ->assertStatus(403)
            ->assertJsonPath('feature', 'showroom');
    }

    public function test_non_integrator_cannot_update_integrator_settings(): void
    {
        $ops = Role::query()->where('name', 'ops_manager')->firstOrFail();
        // give settings.update only
        $ops->permissions()->sync(
            \App\Models\Permission::query()->whereIn('key', ['settings.view', 'settings.update'])->pluck('id')
        );
        $user = User::factory()->create(['role_id' => $ops->id, 'is_active' => true]);
        Sanctum::actingAs($user);

        $this->putJson('/api/integrator/tenant', [
            'feature_flags' => ['showroom' => true],
        ])->assertStatus(403);
    }

    public function test_ops_can_update_operational_settings(): void
    {
        $ops = Role::query()->where('name', 'ops_manager')->firstOrFail();
        $ops->permissions()->sync(
            \App\Models\Permission::query()->whereIn('key', ['settings.view', 'settings.update'])->pluck('id')
        );
        $user = User::factory()->create(['role_id' => $ops->id, 'is_active' => true]);
        Sanctum::actingAs($user);

        $this->putJson('/api/settings/operational', [
            'print_footer' => 'تذييل تشغيل',
            'operational' => ['require_handover_signature' => true],
        ])->assertOk()
            ->assertJsonPath('data.print_footer', 'تذييل تشغيل');
    }
}
