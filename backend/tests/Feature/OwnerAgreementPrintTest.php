<?php

namespace Tests\Feature;

use App\Models\DocumentTemplate;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Accounting\AccountingService;
use App\Services\Documents\DocumentTemplateService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OwnerAgreementPrintTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        app(AccountingService::class)->ensureChartOfAccounts();
        TenantSetting::query()->create([
            'center_name' => 'مركز اختبار المالك',
            'feature_flags' => [],
            'operational' => ['print_phone' => '01000000000'],
        ]);
        app(DocumentTemplateService::class)->ensureDefaults();
    }

    public function test_saving_vehicle_with_owner_registers_printable_agreement(): void
    {
        $adminRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        Sanctum::actingAs(User::factory()->create([
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]));

        $res = $this->postJson('/api/vehicles', [
            'plate_letters' => 'أ ب ج',
            'plate_numbers' => '1234',
            'brand' => 'هيونداي',
            'model' => 'إلنترا',
            'year' => 2024,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 1000,
            'daily_rate' => 700,
            'default_deposit' => 3000,
            'owner_inline' => [
                'full_name' => 'مالك تجريبي',
                'phone' => '01012345678',
                'national_id' => '29001011234567',
            ],
            'owner_monthly_amount' => 9000,
            'owner_agreement_starts_on' => now()->toDateString(),
            'owner_agreement_ends_on' => now()->addYear()->toDateString(),
        ])->assertCreated();

        $res->assertJsonPath('data.owner.full_name', 'مالك تجريبي');
        $this->assertArrayHasKey('owner_agreement', $res->json());
        $html = (string) $res->json('owner_agreement.html');
        $this->assertNotEmpty($html);
        $this->assertStringContainsString('مالك تجريبي', $html);
        $this->assertStringContainsString('OWN-', $res->json('owner_agreement.number'));
        $this->assertStringContainsString('المادة الأولى', $html);
        $this->assertStringContainsString('مركز اختبار المالك', $html);
        $this->assertStringContainsString('هيونداي', $html);

        $vehicleId = (int) $res->json('data.id');
        $print = $this->getJson("/api/vehicles/{$vehicleId}/owner-agreement/print")->assertOk();
        $print->assertJsonPath('data.template.type', DocumentTemplate::TYPE_OWNER_AGREEMENT);
        $printHtml = (string) $print->json('data.html');
        $this->assertStringContainsString('مالك تجريبي', $printHtml);
        $this->assertStringContainsString('المادة الرابعة', $printHtml);
        $this->assertStringContainsString('9000', $printHtml);

        $vehicle = Vehicle::query()->findOrFail($vehicleId);
        $this->assertFalse(isset($vehicle->target_monthly_income));
        $this->assertArrayNotHasKey('owner_agreement_number', $vehicle->getAttributes());
    }

    public function test_owner_agreement_template_exists_in_settings_list(): void
    {
        $adminRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        Sanctum::actingAs(User::factory()->create([
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]));

        $list = $this->getJson('/api/document-templates?active=1')->assertOk();
        $types = collect($list->json('data'))->pluck('type');
        $this->assertTrue($types->contains(DocumentTemplate::TYPE_OWNER_AGREEMENT));
    }
}
