<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\Accounting\AccountingService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MediaUploadArabicTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        PermissionCatalog::ensureSeeded();
        app(AccountingService::class)->ensureChartOfAccounts();
        TenantSetting::query()->create(['center_name' => 'اختبار', 'feature_flags' => [], 'operational' => []]);
        $adminRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        $this->admin = User::factory()->create(['role_id' => $adminRole->id, 'is_active' => true]);
        Sanctum::actingAs($this->admin);
        app()->setLocale('ar');
    }

    public function test_uppercase_jpeg_accepted(): void
    {
        $file = UploadedFile::fake()->create('PHOTO.JPG', 200, 'image/jpeg');
        $this->post('/api/media', ['file' => $file, 'collection' => 'general'], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.kind', 'image');
    }

    public function test_heic_accepted_as_image(): void
    {
        $file = UploadedFile::fake()->create('iphone.heic', 300, 'image/heic');
        $this->post('/api/media', ['file' => $file, 'collection' => 'customers_id', 'purpose' => 'national_id'], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.kind', 'image');
    }

    public function test_exe_rejected_in_arabic(): void
    {
        $file = UploadedFile::fake()->create('virus.exe', 100, 'application/x-msdownload');
        $res = $this->post('/api/media', ['file' => $file], ['Accept' => 'application/json'])->assertStatus(422);
        $msg = (string) $res->json('message');
        $this->assertNotSame('', $msg);
        $this->assertDoesNotMatchRegularExpression('/\b(The|failed|upload|must be)\b/i', $msg);
        $this->assertTrue(str_contains($msg, 'غير') || str_contains($msg, 'مسموح') || str_contains($msg, 'ملف'));
    }

    public function test_oversized_image_arabic_message(): void
    {
        config(['uploads.image.max_bytes' => 1024]); // 1KB for test
        $file = UploadedFile::fake()->create('big.jpg', 20, 'image/jpeg'); // 20KB
        $res = $this->post('/api/media', ['file' => $file], ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertStringContainsString('ميجا', (string) $res->json('message'));
    }

    public function test_mov_and_mp4_ok(): void
    {
        $this->post('/api/media', [
            'file' => UploadedFile::fake()->create('clip.mp4', 400, 'video/mp4'),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.kind', 'video');

        $this->post('/api/media', [
            'file' => UploadedFile::fake()->create('clip.mov', 400, 'video/quicktime'),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.kind', 'video');
    }

    public function test_limits_endpoint(): void
    {
        $this->getJson('/api/media/limits')
            ->assertOk()
            ->assertJsonPath('data.image_max_mb', 12)
            ->assertJsonPath('data.video_max_mb', 50);
    }
}
