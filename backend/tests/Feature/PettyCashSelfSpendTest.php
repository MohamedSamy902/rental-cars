<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Permission;
use App\Models\PettyCashAccount;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Accounting\AccountingService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PettyCashSelfSpendTest extends TestCase
{
    use RefreshDatabase;

    private Employee $self;

    private Employee $other;

    private PettyCashAccount $selfAccount;

    private PettyCashAccount $otherAccount;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        app(AccountingService::class)->ensureChartOfAccounts();
        TenantSetting::query()->create([
            'center_name' => 'اختبار',
            'feature_flags' => [],
            'operational' => [],
        ]);

        $this->self = Employee::query()->create([
            'name' => 'موظف ذاتي',
            'job_title' => 'فني',
            'hired_on' => now()->toDateString(),
            'base_salary' => 4000,
            'status' => 'active',
        ]);
        $this->other = Employee::query()->create([
            'name' => 'موظف آخر',
            'job_title' => 'فني',
            'hired_on' => now()->toDateString(),
            'base_salary' => 4000,
            'status' => 'active',
        ]);

        $this->selfAccount = PettyCashAccount::query()->create([
            'employee_id' => $this->self->id,
            'limit_amount' => 5000,
            'balance' => 3000,
            'is_active' => true,
        ]);
        $this->otherAccount = PettyCashAccount::query()->create([
            'employee_id' => $this->other->id,
            'limit_amount' => 5000,
            'balance' => 3000,
            'is_active' => true,
        ]);
    }

    /**
     * @param  list<string>  $keys
     */
    private function actingWith(array $keys, ?int $employeeId = null): User
    {
        $role = Role::query()->create([
            'name' => 'pcs_'.md5(implode(',', $keys).microtime(true)),
            'label' => 'اختبار عهدة',
        ]);
        $role->permissions()->sync(
            Permission::query()->whereIn('key', $keys)->pluck('id')
        );
        $user = User::factory()->create([
            'role_id' => $role->id,
            'employee_id' => $employeeId,
            'is_active' => true,
        ]);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_staff_cannot_spend_another_employees_petty_cash(): void
    {
        $this->actingWith(['expenses.create', 'expenses.view'], $this->self->id);

        $this->postJson('/api/expenses', [
            'title' => 'وقود',
            'amount' => 200,
            'spent_on' => now()->toDateString(),
            'from_petty_cash' => true,
            'employee_id' => $this->other->id,
        ])->assertStatus(422)
            ->assertJsonFragment(['message' => 'لا يجوز صرف عهدة موظف آخر — الإدارة فقط تملك هذه الصلاحية.']);

        $this->assertSame(3000, (int) $this->otherAccount->fresh()->balance);
    }

    public function test_staff_can_spend_own_petty_cash(): void
    {
        $this->actingWith(['expenses.create', 'expenses.view'], $this->self->id);

        $this->postJson('/api/expenses', [
            'title' => 'وقود',
            'amount' => 200,
            'spent_on' => now()->toDateString(),
            'from_petty_cash' => true,
            'employee_id' => $this->self->id,
        ])->assertCreated();

        $this->assertSame(2800, (int) $this->selfAccount->fresh()->balance);
    }

    public function test_manager_can_spend_another_employees_petty_cash(): void
    {
        $this->actingWith(['expenses.create', 'expenses.view', 'petty_cash.manage'], null);

        $this->postJson('/api/expenses', [
            'title' => 'وقود إداري',
            'amount' => 150,
            'spent_on' => now()->toDateString(),
            'from_petty_cash' => true,
            'employee_id' => $this->other->id,
        ])->assertCreated();

        $this->assertSame(2850, (int) $this->otherAccount->fresh()->balance);
    }

    public function test_me_includes_employee_id(): void
    {
        $user = $this->actingWith(['expenses.view'], $this->self->id);
        $this->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('employee_id', $user->employee_id);
    }
}
