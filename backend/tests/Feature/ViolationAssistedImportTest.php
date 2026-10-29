<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Violation;
use App\Services\Accounting\AccountingService;
use App\Services\Contracts\ContractLifecycleService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ViolationAssistedImportTest extends TestCase
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
            'operational' => [
                'require_handover_signature' => false,
                'block_expired_license' => false,
                'block_expired_vehicle_docs' => false,
            ],
        ]);
        $adminRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        $this->admin = User::factory()->create(['role_id' => $adminRole->id, 'is_active' => true]);
        Sanctum::actingAs($this->admin);
        $this->life = app(ContractLifecycleService::class);
    }

    public function test_portal_info_is_stub_not_live_fetch(): void
    {
        $res = $this->getJson('/api/violations/portal')->assertOk();
        $res->assertJsonPath('data.status', 'source_unavailable');
        $this->assertStringContainsString('ppo.gov.eg', (string) $res->json('data.portal_url'));
        $this->assertSame([], $res->json('data.results'));
    }

    public function test_assisted_save_matches_active_possession(): void
    {
        [$customer, $vehicle] = $this->seedParty();
        $c = $this->life->create([
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'starts_at' => now()->subDay()->toDateTimeString(),
            'planned_ends_at' => now()->addDays(3)->toDateTimeString(),
        ], $this->admin->id);
        $c = $this->life->approve($c, $this->admin->id);
        $this->life->activateOnHandover($c, (int) $vehicle->odometer + 5, $this->admin->id, [
            'signature_data' => str_repeat('sig', 20),
        ]);

        $res = $this->postJson('/api/violations/assisted', [
            'vehicle_id' => $vehicle->id,
            'occurred_at' => now()->subHours(2)->toDateTimeString(),
            'amount' => 250,
            'external_number' => 'VIO-55',
            'location' => 'القاهرة',
        ])->assertCreated();

        $res->assertJsonPath('data.status', 'assigned');
        $res->assertJsonPath('data.contract_id', $c->id);
        $res->assertJsonPath('data.needs_review', false);
        $res->assertJsonPath('data.source', 'assisted');
    }

    public function test_csv_import_by_plate(): void
    {
        [, $vehicle] = $this->seedParty();
        $csv = "plate,occurred_at,amount,location,external_number\n"
            .$vehicle->plate.',2026-10-15 10:00,400,الجيزة,CSV-1'."\n";

        $file = UploadedFile::fake()->createWithContent('violations.csv', $csv);

        $res = $this->post('/api/violations/import-csv', [
            'file' => $file,
        ], [
            'Accept' => 'application/json',
        ])->assertCreated();

        $this->assertSame(1, (int) $res->json('data.created_count'));
        $this->assertDatabaseHas('violations', [
            'external_number' => 'CSV-1',
            'source' => 'csv_import',
            'vehicle_id' => $vehicle->id,
            'amount' => 400,
        ]);
        $this->assertDatabaseHas('violation_sync_runs', ['channel' => 'csv_import']);
    }

    public function test_csv_skips_duplicate_external_number(): void
    {
        [, $vehicle] = $this->seedParty();
        Violation::query()->create([
            'vehicle_id' => $vehicle->id,
            'occurred_at' => now()->subDay(),
            'amount' => 100,
            'status' => 'new',
            'source' => 'csv_import',
            'external_number' => 'DUP-1',
            'needs_review' => true,
        ]);

        $csv = "plate,occurred_at,amount,external_number\n"
            .$vehicle->plate.',2026-10-16 10:00,200,DUP-1'."\n";
        $file = UploadedFile::fake()->createWithContent('violations.csv', $csv);

        $res = $this->post('/api/violations/import-csv', [
            'file' => $file,
        ], [
            'Accept' => 'application/json',
        ])->assertCreated();

        $this->assertSame(0, (int) $res->json('data.created_count'));
        $this->assertSame(1, Violation::query()->where('external_number', 'DUP-1')->count());
    }

    /**
     * @return array{0: Customer, 1: Vehicle}
     */
    private function seedParty(): array
    {
        $customer = Customer::query()->create([
            'full_name' => 'عميل مخالفات',
            'phone' => '01099998888',
            'birth_date' => '1990-01-01',
            'license_number' => 'LIC-V-'.uniqid(),
            'license_expires_on' => now()->addYears(2)->toDateString(),
            'status' => 'active',
        ]);
        $vehicle = Vehicle::query()->create([
            'code' => 'VV-'.uniqid(),
            'plate' => 'أ ب ج 1234',
            'brand' => 'هيونداي',
            'model' => 'النترا',
            'year' => 2021,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 20000,
            'daily_rate' => 800,
            'default_deposit' => 2000,
            'is_active' => true,
            'rental_priority' => 50,
        ]);

        return [$customer, $vehicle];
    }
}
