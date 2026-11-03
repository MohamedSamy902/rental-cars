<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Accounting\AccountingService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContractDetailShowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        app(AccountingService::class)->ensureChartOfAccounts();
        TenantSetting::query()->create([
            'center_name' => 'اختبار تفاصيل العقد',
            'print_footer' => 'تذييل',
            'feature_flags' => [],
            'operational' => [],
        ]);
    }

    public function test_contract_show_and_print_return_200_with_dossier_payload(): void
    {
        $adminRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        $admin = User::factory()->create([
            'role_id' => $adminRole->id,
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        Sanctum::actingAs($admin);

        $customer = Customer::query()->create([
            'full_name' => 'عميل التفاصيل',
            'phone' => '01012345678',
            'birth_date' => '1992-05-01',
            'license_number' => 'LIC-DET',
            'license_expires_on' => now()->addYear()->toDateString(),
            'status' => 'active',
        ]);
        $vehicle = Vehicle::query()->create([
            'code' => 'DET-1',
            'plate' => 'د ت 11',
            'brand' => 'نيسان',
            'model' => 'صني',
            'year' => 2022,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 20000,
            'daily_rate' => 500,
            'default_deposit' => 2000,
            'is_active' => true,
        ]);
        $contract = Contract::query()->create([
            'number' => 'C-DET-4',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'status' => 'active',
            'starts_at' => now()->subDays(2),
            'planned_ends_at' => now()->addDays(3),
            'frozen_daily_rate' => 500,
            'frozen_agreed_deposit' => 2000,
            'frozen_daily_km_allowance' => 200,
            'frozen_excess_km_rate' => 5,
            'frozen_min_days' => 1,
            'final_total' => 2500,
        ]);

        $show = $this->getJson("/api/contracts/{$contract->id}")->assertOk();
        $show->assertJsonPath('data.id', $contract->id)
            ->assertJsonPath('data.number', 'C-DET-4')
            ->assertJsonPath('data.customer.full_name', 'عميل التفاصيل')
            ->assertJsonPath('data.vehicle.plate', 'د ت 11')
            ->assertJsonStructure([
                'data' => [
                    'payments',
                    'documents',
                    'related',
                    'timeline',
                    'ledger' => ['contract_total', 'paid', 'balance', 'deposit_held'],
                    'progress',
                ],
            ]);

        $print = $this->getJson("/api/contracts/{$contract->id}/print")->assertOk();
        $print->assertJsonPath('data.contract.number', 'C-DET-4')
            ->assertJsonStructure([
                'data' => [
                    'tenant',
                    'contract',
                    'templates' => ['contract', 'handover_receipt'],
                ],
            ]);
    }
}
