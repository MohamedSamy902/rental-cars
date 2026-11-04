<?php

namespace Tests\Feature;

use App\Models\DocumentTemplate;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCustodyAgreement;
use App\Models\VehicleOwner;
use App\Services\Accounting\AccountingService;
use App\Services\Documents\DocumentTemplateService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VehicleCustodyAgreementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        app(AccountingService::class)->ensureChartOfAccounts();
        TenantSetting::query()->create([
            'center_name' => 'مركز اعتماد الاختبار',
            'print_footer' => 'تذييل اعتماد',
            'feature_flags' => [],
            'operational' => [
                'print_phone' => '01150100104',
                'print_address' => 'مدينة نصر',
                'print_commercial_register' => 'CR-99',
                'print_tax_info' => 'TAX-88',
                'contract_terms' => 'بند إضافي للاعتماد',
            ],
        ]);
        app(DocumentTemplateService::class)->ensureDefaults();
    }

    public function test_issue_fills_center_owner_vehicle_and_freezes_html(): void
    {
        $admin = $this->actingAdmin();
        [$vehicle] = $this->seedOwnedVehicle();

        $issue = $this->postJson("/api/vehicles/{$vehicle->id}/custody-agreements", [])->assertCreated();

        $issue->assertJsonPath('data.number', 'OWN-CAD-1')
            ->assertJsonPath('data.starts_on', '2026-01-01')
            ->assertJsonPath('data.ends_on', '2026-12-31')
            ->assertJsonPath('data.monthly_amount', 9000)
            ->assertJsonPath('data.immutable', true)
            ->assertJsonPath('data.owner.full_name', 'مالك الاعتماد');

        $html = (string) $issue->json('data.rendered_html');
        $this->assertStringContainsString('مركز اعتماد الاختبار', $html);
        $this->assertStringContainsString('مالك الاعتماد', $html);
        $this->assertStringContainsString('أ ب ج 7788', $html);
        $this->assertStringContainsString('هيونداي', $html);
        $this->assertStringContainsString('إلنترا', $html);
        $this->assertStringContainsString('CHS-CAD-1', $html);
        $this->assertStringContainsString('9000', $html);
        $this->assertStringContainsString('2026-01-01', $html);
        $this->assertStringContainsString('2026-12-31', $html);
        $this->assertStringContainsString('01150100104', $html);
        $this->assertSame($admin->id, VehicleCustodyAgreement::query()->value('issued_by'));

        // Mutating the live template must not alter the issued snapshot.
        $this->postJson('/api/document-templates', [
            'type' => DocumentTemplate::TYPE_CUSTODY_AGREEMENT,
            'title' => 'قالب معدّل',
            'content' => '<p>نص جديد بالكامل بدون بيانات</p>',
            'format' => 'html',
        ])->assertCreated();

        $print = $this->getJson("/api/vehicles/{$vehicle->id}/custody-agreements/".$issue->json('data.id').'/print')
            ->assertOk();

        $printed = (string) $print->json('data.html');
        $this->assertStringContainsString('مالك الاعتماد', $printed);
        $this->assertStringContainsString('مركز اعتماد الاختبار', $printed);
        $this->assertStringNotContainsString('نص جديد بالكامل بدون بيانات', $printed);
        $this->assertTrue((bool) $print->json('data.immutable'));
    }

    public function test_reissue_gets_new_serial_when_owner_agreement_number_reused(): void
    {
        $this->actingAdmin();
        [$vehicle] = $this->seedOwnedVehicle();

        $first = $this->postJson("/api/vehicles/{$vehicle->id}/custody-agreements", [])->assertCreated();
        $second = $this->postJson("/api/vehicles/{$vehicle->id}/custody-agreements", [])->assertCreated();

        $this->assertSame('OWN-CAD-1', $first->json('data.number'));
        $this->assertNotSame('OWN-CAD-1', $second->json('data.number'));
        $this->assertStringStartsWith('CAD-'.now()->format('Y').'-', (string) $second->json('data.number'));

        $list = $this->getJson("/api/vehicles/{$vehicle->id}/custody-agreements")->assertOk();
        $this->assertCount(2, $list->json('data'));
    }

    public function test_cannot_issue_without_owner_or_dates(): void
    {
        $this->actingAdmin();
        $owned = Vehicle::query()->create([
            'code' => 'VEH-CAD-NO',
            'plate' => 'س ص 1',
            'brand' => 'كيا',
            'model' => 'ريو',
            'year' => 2020,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 1000,
            'daily_rate' => 500,
            'default_deposit' => 1000,
            'is_active' => true,
        ]);

        $this->postJson("/api/vehicles/{$owned->id}/custody-agreements", [])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'عقد الاعتماد للمالك الخارجي فقط — اربط العربية بمالك أولاً.']);

        $owner = VehicleOwner::query()->create([
            'full_name' => 'بلا مدة',
            'is_active' => true,
        ]);
        $owned->update(['owner_id' => $owner->id]);

        $this->postJson("/api/vehicles/{$owned->id}/custody-agreements", [])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'تاريخ بداية عقد الاعتماد مطلوب.']);
    }

    public function test_custody_agreement_template_is_seeded_and_editable(): void
    {
        $this->actingAdmin();

        $list = $this->getJson('/api/document-templates?active=1')->assertOk();
        $types = collect($list->json('data'))->pluck('type')->all();
        $this->assertContains(DocumentTemplate::TYPE_CUSTODY_AGREEMENT, $types);

        $keys = collect($list->json('placeholders'))->pluck('key')->all();
        $this->assertContains('owner_name', $keys);
        $this->assertContains('agreement_number', $keys);
        $this->assertContains('monthly_amount', $keys);

        $this->postJson('/api/document-templates', [
            'type' => DocumentTemplate::TYPE_CUSTODY_AGREEMENT,
            'title' => 'عقد اعتماد مخصّص',
            'content' => '<h1>{{اسم_المركز}}</h1><p>{{اسم_المالك}} · {{رقم_اللوحة}}</p>',
            'format' => 'html',
        ])->assertCreated()->assertJsonPath('data.version', 2);
    }

    /**
     * @return array{0: Vehicle, 1: VehicleOwner}
     */
    private function seedOwnedVehicle(): array
    {
        $owner = VehicleOwner::query()->create([
            'full_name' => 'مالك الاعتماد',
            'phone' => '01022223333',
            'national_id' => '29001019999999',
            'is_active' => true,
        ]);
        $vehicle = Vehicle::query()->create([
            'code' => 'VEH-CAD-1',
            'plate' => 'أ ب ج 7788',
            'brand' => 'هيونداي',
            'model' => 'إلنترا',
            'year' => 2024,
            'color' => 'فضي',
            'chassis_number' => 'CHS-CAD-1',
            'engine_number' => 'ENG-CAD-1',
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 12000,
            'daily_rate' => 800,
            'default_deposit' => 4000,
            'is_active' => true,
            'owner_id' => $owner->id,
            'owner_monthly_amount' => 9000,
            'owner_agreement_starts_on' => '2026-01-01',
            'owner_agreement_ends_on' => '2026-12-31',
            'owner_agreement_number' => 'OWN-CAD-1',
            'owner_agreement_notes' => 'اعتماد سنوي',
        ]);

        return [$vehicle, $owner];
    }

    private function actingAdmin(): User
    {
        $adminRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        $admin = User::factory()->create([
            'email' => 'admin-custody@rpm.local',
            'password' => Hash::make('password'),
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);
        Sanctum::actingAs($admin);

        return $admin;
    }
}
