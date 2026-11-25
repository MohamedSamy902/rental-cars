<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\AuthSession;
use App\Support\PermissionCatalog;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AuthSessionDurationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        $adminRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        $this->admin = User::factory()->create([
            'role_id' => $adminRole->id,
            'is_active' => true,
            'email' => 'session-admin@rpm.local',
            'password' => Hash::make('password'),
            'session_minutes' => null,
        ]);
    }

    public function test_login_issues_long_lived_token_by_default(): void
    {
        $res = $this->postJson('/api/login', [
            'login' => $this->admin->email,
            'password' => 'password',
        ])->assertOk();

        $this->assertSame(AuthSession::DEFAULT_MINUTES, (int) $res->json('session_minutes'));
        $expiresAt = DB::table('personal_access_tokens')
            ->where('tokenable_id', $this->admin->id)
            ->value('expires_at');
        $this->assertNotNull($expiresAt);
        $this->assertTrue(
            Carbon::parse($expiresAt)->greaterThan(now()->addDays(25)),
            'Expected token lifetime ≈ 30 days'
        );
    }

    public function test_me_slides_token_expiry_when_near_end(): void
    {
        $login = $this->postJson('/api/login', [
            'login' => $this->admin->email,
            'password' => 'password',
        ])->assertOk();
        $token = (string) $login->json('token');

        /** @var PersonalAccessToken $pat */
        $pat = PersonalAccessToken::query()->where('tokenable_id', $this->admin->id)->firstOrFail();
        $pat->forceFill(['expires_at' => now()->addHours(2)])->save();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/me')
            ->assertOk();

        $pat->refresh();
        $this->assertTrue(
            $pat->expires_at?->greaterThan(now()->addDays(25)) ?? false,
            'GET /me should slide expires_at forward for active sessions'
        );
    }

    public function test_custom_session_minutes_on_user_is_respected(): void
    {
        $this->admin->update(['session_minutes' => 120]);

        $res = $this->postJson('/api/login', [
            'login' => $this->admin->email,
            'password' => 'password',
        ])->assertOk();

        $this->assertSame(120, (int) $res->json('session_minutes'));
        $expiresAt = Carbon::parse(
            DB::table('personal_access_tokens')->where('tokenable_id', $this->admin->id)->value('expires_at')
        );
        $this->assertTrue($expiresAt->between(now()->addMinutes(110), now()->addMinutes(130)));
    }
}
