<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Role;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ActorDisplayNameTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
    }

    public function test_display_name_avoids_role_title_and_prefers_phone(): void
    {
        $role = Role::query()->where('name', 'super_admin')->firstOrFail();
        $user = User::factory()->create([
            'name' => 'مدير أعلى',
            'phone' => '01150100104',
            'email' => null,
            'role_id' => $role->id,
            'employee_id' => null,
        ]);

        $this->assertSame('01150100104', $user->displayName());
    }

    public function test_expenses_recorded_by_name_uses_person_not_role_label(): void
    {
        $role = Role::query()->where('name', 'super_admin')->firstOrFail();
        $actor = User::factory()->create([
            'name' => 'مدير أعلى',
            'phone' => '01125253888',
            'email' => null,
            'role_id' => $role->id,
            'employee_id' => null,
            'is_active' => true,
        ]);

        Expense::query()->create([
            'title' => 'وقود',
            'category' => 'تشغيل',
            'amount' => 50,
            'spent_on' => now()->toDateString(),
            'payment_method' => 'cash',
            'from_petty_cash' => false,
            'recorded_by' => $actor->id,
        ]);

        Sanctum::actingAs($actor);

        $this->getJson('/api/expenses')
            ->assertOk()
            ->assertJsonPath('data.0.recorded_by_name', '01125253888');
    }

    public function test_display_name_keeps_real_person_name(): void
    {
        $role = Role::query()->where('name', 'accounts')->firstOrFail();
        $user = User::factory()->create([
            'name' => 'أحمد محمد',
            'phone' => '01000000005',
            'role_id' => $role->id,
        ]);

        $this->assertSame('أحمد محمد', $user->displayName());
    }
}
