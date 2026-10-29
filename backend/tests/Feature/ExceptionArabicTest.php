<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExceptionArabicTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        app()->setLocale('ar');
    }

    public function test_404_json_arabic(): void
    {
        $role = Role::query()->where('name', 'super_admin')->firstOrFail();
        Sanctum::actingAs(User::factory()->create(['role_id' => $role->id, 'is_active' => true]));
        $res = $this->getJson('/api/contracts/999999');
        $res->assertNotFound();
        $this->assertStringContainsString('غير موجود', (string) $res->json('message'));
    }

    public function test_403_json_arabic(): void
    {
        $viewer = Role::query()->where('name', 'viewer')->firstOrFail();
        Sanctum::actingAs(User::factory()->create(['role_id' => $viewer->id, 'is_active' => true]));
        $res = $this->postJson('/api/contracts', []);
        $res->assertForbidden();
        $msg = (string) $res->json('message');
        $this->assertNotSame('', $msg);
        $this->assertDoesNotMatchRegularExpression('/\b(Forbidden|Unauthorized|This action)\b/i', $msg);
    }

    public function test_login_throttle_arabic(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/login', ['email' => 'nope@rpm.local', 'password' => 'wrong']);
        }
        $res = $this->postJson('/api/login', ['email' => 'nope@rpm.local', 'password' => 'wrong']);
        $res->assertStatus(429);
        $this->assertStringContainsString('محاولات', (string) $res->json('message'));
    }

    public function test_post_too_large_arabic(): void
    {
        Route::middleware('api')->post('/api/__test_too_large', function () {
            throw new PostTooLargeException;
        });
        $role = Role::query()->where('name', 'super_admin')->firstOrFail();
        Sanctum::actingAs(User::factory()->create(['role_id' => $role->id, 'is_active' => true]));
        $res = $this->postJson('/api/__test_too_large');
        $res->assertStatus(413);
        $this->assertStringContainsString('حجم', (string) $res->json('message'));
    }

    public function test_validation_uploaded_key_translated(): void
    {
        $this->assertSame(
            'فشل رفع الملف. تحقق من الحجم والنوع وحاول مرة أخرى.',
            __('validation.uploaded', ['attribute' => 'الملف']),
        );
    }
}
