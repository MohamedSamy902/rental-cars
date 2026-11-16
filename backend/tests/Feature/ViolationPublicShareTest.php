<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\MediaFile;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Violation;
use App\Services\Accounting\AccountingService;
use App\Services\Media\PublicMediaLinkService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ViolationPublicShareTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Violation $violation;

    private MediaFile $media;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        app(AccountingService::class)->ensureChartOfAccounts();
        config(['app.url' => 'http://localhost', 'app.public_url' => 'http://localhost']);
        TenantSetting::query()->create([
            'center_name' => 'شركة اختبار',
            'feature_flags' => [],
            'operational' => [],
        ]);

        $adminRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        $this->admin = User::factory()->create([
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);

        $customer = Customer::query()->create([
            'full_name' => 'عميل مشاركة',
            'phone' => '01005556677',
            'birth_date' => '1991-01-01',
            'license_number' => 'LIC-SHARE',
            'license_expires_on' => now()->addYears(2)->toDateString(),
            'status' => 'active',
        ]);

        $vehicle = Vehicle::query()->create([
            'code' => 'VEH-SHARE',
            'plate' => 'أ ب 1234',
            'brand' => 'هيونداي',
            'model' => 'إلنترا',
            'year' => 2023,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 5000,
            'odometer_source' => 'manual',
            'daily_rate' => 700,
            'default_deposit' => 1000,
            'is_active' => true,
        ]);

        $contract = Contract::query()->create([
            'number' => 'CNT-SHARE-1',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'status' => 'active',
            'starts_at' => now()->subDays(3),
            'planned_ends_at' => now()->addDays(4),
            'approved_days' => 7,
            'frozen_daily_rate' => 700,
            'frozen_agreed_deposit' => 1000,
            'frozen_daily_km_allowance' => 200,
            'frozen_excess_km_rate' => 5,
            'odometer_out' => 5000,
        ]);

        Storage::fake('local');
        Storage::disk('local')->put('violations/share-test.jpg', 'fake-jpeg-bytes');
        $this->media = MediaFile::query()->create([
            'disk' => 'local',
            'path' => 'violations/share-test.jpg',
            'original_name' => 'share-test.jpg',
            'mime_type' => 'image/jpeg',
            'kind' => 'image',
            'collection' => 'violations',
            'purpose' => 'damage',
            'size_bytes' => 15,
            'uploaded_by' => $this->admin->id,
        ]);

        $this->violation = Violation::query()->create([
            'vehicle_id' => $vehicle->id,
            'customer_id' => $customer->id,
            'contract_id' => $contract->id,
            'occurred_at' => now()->subDay(),
            'amount' => 350,
            'status' => 'assigned',
            'photo_path' => '/media/'.$this->media->id.'/file',
            'photo_paths' => ['/media/'.$this->media->id.'/file'],
            'created_by' => $this->admin->id,
        ]);
    }

    public function test_prepare_share_returns_signed_url_and_whatsapp_text(): void
    {
        Sanctum::actingAs($this->admin);

        $res = $this->postJson('/api/violations/share', [
            'violation_ids' => [$this->violation->id],
        ])->assertOk()
            ->assertJsonPath('data.expires_in_days', 14);

        $shareUrl = (string) $res->json('data.share_url');
        $message = (string) $res->json('data.message_text');

        $this->assertStringContainsString('/api/public/violations/share', $shareUrl);
        $this->assertStringContainsString('signature=', $shareUrl);
        $this->assertStringContainsString('افتح البيان والصور من الرابط:', $message);
        $this->assertStringContainsString($shareUrl, $message);
        $this->assertStringContainsString('350', $message);
    }

    public function test_public_share_page_opens_without_login(): void
    {
        $url = app(PublicMediaLinkService::class)->temporaryViolationsShareUrl([$this->violation->id]);
        $path = $this->pathAndQuery($url);

        $this->get($path)
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertSee('بيان مخالفات', false)
            ->assertSee('عميل مشاركة', false)
            ->assertSee('350', false)
            ->assertSee('/api/public/media/'.$this->media->id.'/file', false);
    }

    public function test_public_media_file_opens_with_valid_signature_without_login(): void
    {
        $url = app(PublicMediaLinkService::class)->temporaryFileUrl($this->media->id);
        $path = $this->pathAndQuery($url);

        $this->get($path)
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
    }

    public function test_public_media_file_rejects_missing_signature(): void
    {
        $this->getJson('/api/public/media/'.$this->media->id.'/file')
            ->assertForbidden();
    }

    public function test_authenticated_media_still_requires_login(): void
    {
        $this->getJson('/api/media/'.$this->media->id.'/file')
            ->assertUnauthorized();
    }

    public function test_expired_signature_is_rejected(): void
    {
        URL::forceRootUrl('http://localhost');
        $url = URL::temporarySignedRoute(
            'public.media.file',
            now()->subMinute(),
            ['medium' => $this->media->id],
        );

        $this->getJson($this->pathAndQuery($url))
            ->assertForbidden();
    }

    private function pathAndQuery(string $absoluteUrl): string
    {
        $parts = parse_url($absoluteUrl);
        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return $path.$query;
    }
}
