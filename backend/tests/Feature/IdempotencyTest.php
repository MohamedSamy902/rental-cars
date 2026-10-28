<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Accounting\AccountingService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

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
        $adminRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        $this->admin = User::factory()->create(['role_id' => $adminRole->id, 'is_active' => true]);
        Sanctum::actingAs($this->admin);
    }

    public function test_same_key_replays_without_duplicating_create(): void
    {
        $payload = [
            'code' => 'V-IDEM-1',
            'plate' => 'أ ب ج 1111',
            'brand' => 'تويوتا',
            'model' => 'كورولا',
            'year' => 2022,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 1000,
            'daily_rate' => 50000,
            'default_deposit' => 100000,
        ];

        $headers = [
            'Accept' => 'application/json',
            'Idempotency-Key' => 'test-key-vehicle-1',
        ];

        $first = $this->postJson('/api/vehicles', $payload, $headers);
        $first->assertCreated()->assertHeader('Idempotency-Replayed', 'false');
        $id = $first->json('data.id');

        $second = $this->postJson('/api/vehicles', $payload, $headers);
        $second->assertCreated()
            ->assertHeader('Idempotency-Replayed', 'true')
            ->assertJsonPath('data.id', $id);

        $this->assertSame(1, DB::table('vehicles')->where('code', 'V-IDEM-1')->count());
    }

    public function test_same_key_different_body_is_rejected(): void
    {
        $headers = [
            'Accept' => 'application/json',
            'Idempotency-Key' => 'test-key-conflict',
        ];

        $base = [
            'code' => 'V-IDEM-2',
            'plate' => 'أ ب ج 2222',
            'brand' => 'هيونداي',
            'model' => 'إلنترا',
            'year' => 2021,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 500,
            'daily_rate' => 40000,
            'default_deposit' => 80000,
        ];

        $this->postJson('/api/vehicles', $base, $headers)->assertCreated();

        $this->postJson('/api/vehicles', [...$base, 'code' => 'V-IDEM-2B', 'plate' => 'أ ب ج 2223'], $headers)
            ->assertStatus(422)
            ->assertJsonPath('message', 'مفتاح التكرار مستخدم لطلب مختلف. أرسل مفتاحاً جديداً.');
    }
}
