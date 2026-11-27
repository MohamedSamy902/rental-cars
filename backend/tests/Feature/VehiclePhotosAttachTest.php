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

class VehiclePhotosAttachTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        TenantSetting::query()->create([
            'center_name' => 'اختبار',
            'feature_flags' => [],
            'operational' => [],
        ]);
        $admin = Role::query()->where('name', 'super_admin')->firstOrFail();
        Sanctum::actingAs(User::factory()->create(['role_id' => $admin->id, 'is_active' => true]));
    }

    public function test_store_vehicle_attaches_photo_media_ids_as_primary_then_photo(): void
    {
        $first = MediaFile::query()->create([
            'disk' => 'local',
            'path' => 'media/vehicle_photos/a.png',
            'original_name' => 'a.png',
            'mime_type' => 'image/png',
            'kind' => 'image',
            'collection' => 'vehicle_photos',
            'purpose' => 'photo',
            'size_bytes' => 10,
        ]);
        $second = MediaFile::query()->create([
            'disk' => 'local',
            'path' => 'media/vehicle_photos/b.png',
            'original_name' => 'b.png',
            'mime_type' => 'image/png',
            'kind' => 'image',
            'collection' => 'vehicle_photos',
            'purpose' => 'photo',
            'size_bytes' => 10,
        ]);

        $vehicleId = $this->postJson('/api/vehicles', [
            'plate_letters' => 'س ص',
            'plate_numbers' => '9988',
            'brand' => 'كيا',
            'model' => 'سبورتاج',
            'year' => 2020,
            'category' => 'SUV',
            'fuel_type' => 'بنزين',
            'odometer' => 1000,
            'daily_rate' => 2000,
            'default_deposit' => 1500,
            'chassis_number' => 'CHS-PHOTO-1',
            'photo_media_ids' => [$first->id, $second->id],
        ])->assertCreated()->json('data.id');

        $this->assertDatabaseHas('media_files', [
            'id' => $first->id,
            'attachable_type' => Vehicle::class,
            'attachable_id' => $vehicleId,
            'collection' => 'vehicle_photos',
            'purpose' => 'primary',
        ]);
        $this->assertDatabaseHas('media_files', [
            'id' => $second->id,
            'attachable_type' => Vehicle::class,
            'attachable_id' => $vehicleId,
            'collection' => 'vehicle_photos',
            'purpose' => 'photo',
        ]);
    }
}
