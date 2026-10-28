<?php

namespace Tests\Feature;

use App\Models\MediaFile;
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

class MediaUploadTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
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

    public function test_upload_image_and_stream_file(): void
    {
        $file = UploadedFile::fake()->create('id.jpg', 120, 'image/jpeg');

        $res = $this->post('/api/media', [
            'file' => $file,
            'collection' => 'customers_id',
            'purpose' => 'national_id',
            'attachable_type' => 'customer',
        ], ['Accept' => 'application/json']);

        $res->assertCreated()
            ->assertJsonPath('data.kind', 'image')
            ->assertJsonPath('data.collection', 'customers_id')
            ->assertJsonPath('data.purpose', 'national_id');

        $id = $res->json('data.id');
        $this->assertDatabaseHas('media_files', ['id' => $id, 'kind' => 'image']);

        $path = MediaFile::query()->findOrFail($id)->path;
        Storage::disk('local')->assertExists($path);

        $this->get("/api/media/{$id}/file")
            ->assertOk();
    }

    public function test_reject_unsupported_mime(): void
    {
        $file = UploadedFile::fake()->create('notes.pdf', 100, 'application/pdf');

        $this->post('/api/media', [
            'file' => $file,
            'collection' => 'general',
        ], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    public function test_reject_oversized_image(): void
    {
        // ٦ ميجا > حد الصور (٥ ميجا)
        $file = UploadedFile::fake()->create('huge.jpg', 6 * 1024, 'image/jpeg');

        $this->post('/api/media', [
            'file' => $file,
            'collection' => 'general',
        ], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    public function test_delete_media(): void
    {
        $file = UploadedFile::fake()->create('car.png', 80, 'image/png');
        $id = $this->post('/api/media', [
            'file' => $file,
            'collection' => 'vehicle_docs',
            'purpose' => 'document',
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        $path = MediaFile::query()->findOrFail($id)->path;
        $this->deleteJson("/api/media/{$id}")->assertOk();
        $this->assertSoftDeleted('media_files', ['id' => $id]);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_upload_video_mp4(): void
    {
        $file = UploadedFile::fake()->create('damage.mp4', 500, 'video/mp4');

        $this->post('/api/media', [
            'file' => $file,
            'collection' => 'inspection_damage',
            'purpose' => 'damage',
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.kind', 'video');
    }
}
