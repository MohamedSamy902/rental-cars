<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Accounting\AccountingService;
use App\Services\Contracts\ContractLifecycleService;
use App\Support\PermissionCatalog;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LifecycleRaceGuardsTest extends TestCase
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
            'feature_flags' => [],
            'operational' => ['require_handover_signature' => false, 'block_expired_license' => false, 'block_expired_vehicle_docs' => false],
        ]);
        $adminRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        $this->admin = User::factory()->create(['role_id' => $adminRole->id, 'is_active' => true]);
        Sanctum::actingAs($this->admin);
        $this->life = app(ContractLifecycleService::class);
    }

    public function test_overlap_rejected(): void
    {
        [$customer, $vehicle] = $this->seedParty();
        $this->life->create([
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'starts_at' => now()->addDay()->toDateTimeString(),
            'planned_ends_at' => now()->addDays(5)->toDateTimeString(),
        ], $this->admin->id);

        $this->expectException(DomainException::class);
        $this->life->create([
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'starts_at' => now()->addDays(2)->toDateTimeString(),
            'planned_ends_at' => now()->addDays(6)->toDateTimeString(),
        ], $this->admin->id);
    }

    public function test_maintenance_blocked_when_active_rental(): void
    {
        [$customer, $vehicle] = $this->seedParty();
        $c = $this->life->create([
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'starts_at' => now()->subHour()->toDateTimeString(),
            'planned_ends_at' => now()->addDays(3)->toDateTimeString(),
        ], $this->admin->id);
        $c = $this->life->approve($c, $this->admin->id);
        $this->life->activateOnHandover($c, (int) $vehicle->odometer + 10, $this->admin->id, [
            'signature_data' => str_repeat('sig', 20),
        ]);

        $res = $this->postJson('/api/work-orders', [
            'vehicle_id' => $vehicle->id,
            'odometer' => (int) $vehicle->odometer + 20,
            'puts_in_maintenance' => true,
        ]);
        $res->assertStatus(422);
        $this->assertStringContainsString('صيانة', (string) $res->json('message'));
    }

    public function test_journal_close_idempotent(): void
    {
        [$customer, $vehicle] = $this->seedParty();
        $c = $this->life->create([
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'starts_at' => now()->subDays(2)->toDateTimeString(),
            'planned_ends_at' => now()->addDay()->toDateTimeString(),
        ], $this->admin->id, true);
        $c = $this->life->approve($c, $this->admin->id);
        $c = $this->life->activateOnHandover($c, (int) $vehicle->odometer + 5, $this->admin->id, [
            'signature_data' => str_repeat('sig', 20),
        ]);
        $c = $this->life->completeOnReturn($c, (int) $c->odometer_out + 50, now(), $this->admin->id);

        $acc = app(AccountingService::class);
        $first = $acc->postContractClose($c->fresh(), $this->admin->id);
        $second = $acc->postContractClose($c->fresh(), $this->admin->id);
        $this->assertSame($first->id, $second->id);
    }

    public function test_sold_vehicle_not_bookable(): void
    {
        [$customer, $vehicle] = $this->seedParty();
        $vehicle->update(['manual_status' => 'sold']);
        $this->expectException(DomainException::class);
        $this->life->create([
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'starts_at' => now()->addDay()->toDateTimeString(),
            'planned_ends_at' => now()->addDays(3)->toDateTimeString(),
        ], $this->admin->id);
    }

    /** @return array{0: Customer, 1: Vehicle} */
    private function seedParty(): array
    {
        $customer = Customer::query()->create([
            'full_name' => 'عميل',
            'phone' => '01011112222',
            'birth_date' => '1990-01-01',
            'license_number' => 'L-99',
            'license_expires_on' => now()->addYears(2)->toDateString(),
            'status' => 'active',
        ]);
        $vehicle = Vehicle::query()->create([
            'code' => 'V-'.uniqid(),
            'plate' => 'أ ب ج '.random_int(1000, 9999),
            'brand' => 'هيونداي',
            'model' => 'إلنترا',
            'year' => 2023,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 20000,
            'daily_rate' => 800,
            'default_deposit' => 2000,
            'is_active' => true,
        ]);

        return [$customer, $vehicle];
    }
}
