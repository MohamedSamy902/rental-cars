<?php

namespace Tests\Feature;

use App\Models\MediaFile;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VehicleShareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        TenantSetting::query()->create([
            'center_name' => 'شركة اختبار',
            'feature_flags' => ['showroom' => true],
            'operational' => [
                'print_phone' => '01125253888',
                'default_min_days' => 5,
            ],
        ]);
        $admin = Role::query()->where('name', 'super_admin')->firstOrFail();
        Sanctum::actingAs(User::factory()->create(['role_id' => $admin->id, 'is_active' => true]));
    }

    public function test_prepare_vehicle_share_includes_signed_url_and_photos_line(): void
    {
        $vehicle = Vehicle::query()->create([
            'code' => 'VEH-SHARE-1',
            'plate' => 'س ص 1111',
            'brand' => 'كيا',
            'model' => 'سبورتاج',
            'year' => 2020,
            'category' => 'SUV',
            'fuel_type' => 'بنزين',
            'transmission' => 'أوتوماتيك',
            'odometer' => 1000,
            'odometer_source' => 'manual',
            'daily_rate' => 2000,
            'half_monthly_rate' => 30000,
            'monthly_rate' => 40000,
            'default_deposit' => 1500,
            'is_active' => true,
        ]);

        MediaFile::query()->create([
            'disk' => 'local',
            'path' => 'media/vehicle_photos/share-a.png',
            'original_name' => 'share-a.png',
            'mime_type' => 'image/png',
            'kind' => 'image',
            'collection' => 'vehicle_photos',
            'purpose' => 'primary',
            'size_bytes' => 12,
            'attachable_type' => Vehicle::class,
            'attachable_id' => $vehicle->id,
        ]);

        $data = $this->postJson('/api/vehicles/'.$vehicle->id.'/share')
            ->assertOk()
            ->json('data');

        $this->assertNotEmpty($data['share_url']);
        $this->assertStringContainsString('/api/public/vehicles/share', $data['share_url']);
        $this->assertSame(1, (int) $data['photos_count']);
        $this->assertStringContainsString('كيا سبورتاج 2020', $data['message_text']);
        $this->assertStringContainsString('افتح التفاصيل والصور من الرابط:', $data['message_text']);
        $this->assertStringContainsString($data['share_url'], $data['message_text']);
    }

    public function test_public_vehicle_share_page_renders_photos(): void
    {
        $vehicle = Vehicle::query()->create([
            'code' => 'VEH-SHARE-2',
            'plate' => 'س ص 2222',
            'brand' => 'تويوتا',
            'model' => 'كورولا',
            'year' => 2022,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 500,
            'odometer_source' => 'manual',
            'daily_rate' => 1000,
            'default_deposit' => 1000,
            'is_active' => true,
        ]);
        $media = MediaFile::query()->create([
            'disk' => 'local',
            'path' => 'media/vehicle_photos/share-b.png',
            'original_name' => 'share-b.png',
            'mime_type' => 'image/png',
            'kind' => 'image',
            'collection' => 'vehicle_photos',
            'purpose' => 'primary',
            'size_bytes' => 12,
            'attachable_type' => Vehicle::class,
            'attachable_id' => $vehicle->id,
        ]);

        $shareUrl = $this->postJson('/api/vehicles/'.$vehicle->id.'/share')
            ->assertOk()
            ->json('data.share_url');

        $path = parse_url($shareUrl, PHP_URL_PATH).'?'.parse_url($shareUrl, PHP_URL_QUERY);
        $html = $this->get($path)->assertOk()->getContent();
        $this->assertStringContainsString('تويوتا كورولا 2022', $html);
        $this->assertStringContainsString('/api/public/media/'.$media->id.'/file', $html);
        $this->assertStringContainsString('يومي', $html);
    }
}
