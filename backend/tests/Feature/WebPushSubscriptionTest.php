<?php

namespace Tests\Feature;

use App\Models\PushSubscription;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Accounting\AccountingService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WebPushSubscriptionTest extends TestCase
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
        config([
            'services.webpush.vapid.public_key' => 'BL1s48dQBEWuWyE7f0NJZMz6wjiPakipFFB3iodp9asUOJIRLMKoN6UMn3y6oTspyCfWA6SqKKrKuGctE2Ypx0A',
            'services.webpush.vapid.private_key' => 'mYrfBhe0KTsFb3PlFvLn2KusCF7DQIEc627S3hVQhzE',
            'services.webpush.vapid.subject' => 'mailto:test@rpm.local',
        ]);
        $adminRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        $this->admin = User::factory()->create(['role_id' => $adminRole->id, 'is_active' => true]);
        Sanctum::actingAs($this->admin);
    }

    public function test_vapid_public_key_endpoint(): void
    {
        $this->getJson('/api/push/vapid-public-key')
            ->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonStructure(['publicKey']);
    }

    public function test_subscribe_and_status(): void
    {
        $this->postJson('/api/push/subscribe', [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-endpoint-abc',
            'keys' => [
                'p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u7wYTATAtfDTRoLFt0XZWpA8kRQ',
                'auth' => 'tBHItJI5svbpez7KI4CCXg',
            ],
        ])->assertCreated();

        $this->assertDatabaseHas('push_subscriptions', [
            'user_id' => $this->admin->id,
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-endpoint-abc',
        ]);

        $this->getJson('/api/push/status')
            ->assertOk()
            ->assertJsonPath('devices', 1)
            ->assertJsonPath('enabled', true);

        $this->postJson('/api/push/unsubscribe', [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-endpoint-abc',
        ])->assertOk();

        $this->assertSame(0, PushSubscription::query()->count());
    }

    public function test_test_push_without_devices_fails(): void
    {
        $this->postJson('/api/push/test')->assertStatus(422);
    }
}
