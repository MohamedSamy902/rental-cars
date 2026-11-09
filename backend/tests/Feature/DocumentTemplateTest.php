<?php

namespace Tests\Feature;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\DocumentTemplate;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\Accounting\AccountingService;
use App\Services\Documents\DocumentTemplateService;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use ZipArchive;

class DocumentTemplateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionCatalog::ensureSeeded();
        app(AccountingService::class)->ensureChartOfAccounts();
        TenantSetting::query()->create([
            'center_name' => 'مركز قوالب الاختبار',
            'print_footer' => 'تذييل اختبار',
            'feature_flags' => [],
            'operational' => [
                'print_phone' => '01011112222',
                'print_address' => 'القاهرة',
                'contract_terms' => 'بند تجريبي',
            ],
        ]);
    }

    public function test_admin_can_list_and_save_document_templates(): void
    {
        $admin = $this->actingAdmin();

        $list = $this->getJson('/api/document-templates')->assertOk();
        $list->assertJsonPath('data.0.type', DocumentTemplate::TYPE_CONTRACT);
        $this->assertNotEmpty($list->json('placeholders'));

        $save = $this->postJson('/api/document-templates', [
            'type' => DocumentTemplate::TYPE_CONTRACT,
            'title' => 'عقد مخصّص',
            'format' => 'html',
            'content' => '<h1>{{company_name}}</h1><p>عميل: {{اسم_العميل}} · لوحة {{رقم_اللوحة}}</p>',
        ])->assertCreated();

        $save->assertJsonPath('data.title', 'عقد مخصّص')
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.is_active', true);

        $this->assertSame(1, DocumentTemplate::query()
            ->where('type', DocumentTemplate::TYPE_CONTRACT)
            ->where('is_active', true)
            ->count());
        $this->assertSame($admin->id, DocumentTemplate::query()->where('is_active', true)->where('type', 'contract')->value('updated_by'));
    }

    public function test_preview_replaces_placeholders_with_sample_and_contract_data(): void
    {
        $this->actingAdmin();
        app(DocumentTemplateService::class)->ensureDefaults();

        $this->postJson('/api/document-templates', [
            'type' => DocumentTemplate::TYPE_HANDOVER_RECEIPT,
            'title' => 'محضر اختبار',
            'content' => 'استلام {{customer_name}} للعربية {{vehicle_plate}} يوم {{start_date}}',
            'format' => 'html',
        ])->assertCreated();

        $preview = $this->postJson('/api/document-templates/preview', [
            'type' => DocumentTemplate::TYPE_HANDOVER_RECEIPT,
            'variables' => [
                'customer_name' => 'سارة علي',
                'vehicle_plate' => 'س ص ع 999',
                'start_date' => '2026-11-03',
            ],
        ])->assertOk();

        $html = (string) $preview->json('data.html');
        $this->assertStringContainsString('سارة علي', $html);
        $this->assertStringContainsString('س ص ع 999', $html);
        $this->assertStringContainsString('2026-11-03', $html);
    }

    public function test_print_payload_includes_rendered_active_templates(): void
    {
        $this->actingAdmin();

        $customer = Customer::query()->create([
            'full_name' => 'عميل القوالب',
            'phone' => '01099998888',
            'birth_date' => '1990-01-01',
            'license_number' => 'LIC-TPL',
            'license_expires_on' => now()->addYears(2)->toDateString(),
            'status' => 'active',
        ]);
        $vehicle = Vehicle::query()->create([
            'code' => 'TPL-1',
            'plate' => 'ط ظ ع 55',
            'brand' => 'تويوتا',
            'model' => 'كورولا',
            'year' => 2024,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 10000,
            'daily_rate' => 700,
            'default_deposit' => 3000,
            'is_active' => true,
        ]);
        $contract = Contract::query()->create([
            'number' => 'C-TPL-1',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'planned_ends_at' => now()->addDays(3),
            'frozen_daily_rate' => 700,
            'frozen_agreed_deposit' => 3000,
            'frozen_daily_km_allowance' => 200,
            'frozen_excess_km_rate' => 5,
            'frozen_min_days' => 1,
        ]);

        $this->postJson('/api/document-templates', [
            'type' => DocumentTemplate::TYPE_CONTRACT,
            'content' => 'عقد {{رقم_العقد}} لـ {{اسم_العميل}} على {{رقم_اللوحة}} بسعر {{السعر_اليومي}}',
            'format' => 'html',
        ])->assertCreated();

        $print = $this->getJson("/api/contracts/{$contract->id}/print")->assertOk();
        $rendered = (string) $print->json('data.templates.contract.rendered_html');
        $this->assertStringContainsString('C-TPL-1', $rendered);
        $this->assertStringContainsString('عميل القوالب', $rendered);
        $this->assertStringContainsString('ط ظ ع 55', $rendered);
        $this->assertStringContainsString('700', $rendered);
        $this->assertNotEmpty($print->json('data.templates.handover_receipt.rendered_html'));
    }

    public function test_default_rental_contract_template_autofills_party_and_terms(): void
    {
        $this->actingAdmin();
        TenantSetting::query()->first()?->update([
            'center_name' => 'آر - بي - إم للنقل والسياحة',
            'operational' => [
                'print_phone' => '01125253888',
                'print_address' => '17 شارع كورنيش النيل - حدائق حلوان - محافظة القاهرة',
                'print_commercial_register' => '18766',
                'print_signer_name' => 'محمد عبد القادر محمد عطية',
                'company_tagline' => 'لخدمات رجال الأعمال',
                'default_contract_vehicle_value' => '450000',
                'default_daily_km' => 200,
                'excess_km_rate' => 5,
            ],
        ]);

        $service = app(DocumentTemplateService::class);
        $service->ensureDefaults();

        $customer = Customer::query()->create([
            'full_name' => 'سارة علي حسن',
            'phone' => '01012345678',
            'national_id' => '29001011234567',
            'address' => 'المعادي — القاهرة',
            'birth_date' => '1990-01-01',
            'license_number' => 'DRV-9090',
            'license_expires_on' => now()->addYears(2)->toDateString(),
            'status' => 'active',
        ]);
        $vehicle = Vehicle::query()->create([
            'code' => 'LEG-1',
            'plate' => 'س ص ع 100',
            'brand' => 'هيونداي',
            'model' => 'إلنترا',
            'year' => 2023,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'chassis_number' => 'CHS-999',
            'engine_number' => 'ENG-888',
            'odometer' => 22000,
            'daily_rate' => 900,
            'default_deposit' => 5000,
            'is_active' => true,
        ]);
        $contract = Contract::query()->create([
            'number' => 'C-LEG-1',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'status' => 'active',
            'starts_at' => now()->startOfDay(),
            'planned_ends_at' => now()->startOfDay()->addDays(3),
            'frozen_daily_rate' => 900,
            'frozen_agreed_deposit' => 5000,
            'frozen_daily_km_allowance' => 250,
            'frozen_excess_km_rate' => 6,
            'frozen_min_days' => 1,
            'odometer_out' => 22000,
        ]);

        $print = $this->getJson("/api/contracts/{$contract->id}/print")->assertOk();
        $rendered = (string) $print->json('data.templates.contract.rendered_html');

        $this->assertStringContainsString('شروط التعاقد', $rendered);
        $this->assertStringContainsString('البند الأول', $rendered);
        $this->assertStringContainsString('البند الحادي عشر', $rendered);
        $this->assertStringContainsString('سارة علي حسن', $rendered);
        $this->assertStringContainsString('29001011234567', $rendered);
        $this->assertStringContainsString('مصري', $rendered);
        $this->assertStringContainsString('س ص ع 100', $rendered);
        $this->assertStringContainsString('هيونداي', $rendered);
        $this->assertStringContainsString('CHS-999', $rendered);
        $this->assertStringContainsString('5000', $rendered);
        $this->assertStringContainsString('250', $rendered);
        $this->assertStringContainsString('450000', $rendered);
        $this->assertStringContainsString('محمد عبد القادر محمد عطية', $rendered);
        $this->assertStringContainsString('التوقيع :', $rendered);
        $this->assertStringNotContainsString('{{customer_name}}', $rendered);
        $this->assertStringNotContainsString('{{deposit}}', $rendered);
    }

    public function test_stock_contract_stub_upgrades_to_paper_form(): void
    {
        $this->actingAdmin();
        DocumentTemplate::query()->where('type', DocumentTemplate::TYPE_CONTRACT)->delete();
        DocumentTemplate::query()->create([
            'type' => DocumentTemplate::TYPE_CONTRACT,
            'title' => 'قالب العقد الأساسي',
            'content' => <<<'HTML'
<header class="print-head"><h1>{{company_name}}</h1></header>
<h2>عقد تأجير · رقم {{contract_number}}</h2>
<table><tr><th>العميل</th><td>{{customer_name}}</td></tr></table>
<h2>بنود وشروط العقد</h2>
<div class="terms">{{contract_terms}}</div>
HTML,
            'format' => 'html',
            'version' => 1,
            'is_active' => true,
        ]);

        app(DocumentTemplateService::class)->ensureDefaults();

        $active = DocumentTemplate::query()
            ->where('type', DocumentTemplate::TYPE_CONTRACT)
            ->where('is_active', true)
            ->orderByDesc('version')
            ->first();

        $this->assertNotNull($active);
        $this->assertGreaterThan(1, (int) $active->version);
        $this->assertStringContainsString('شروط التعاقد', (string) $active->content);
        $this->assertStringContainsString('البند السادس', (string) $active->content);
    }

    public function test_viewer_cannot_mutate_templates_but_service_still_renders(): void
    {
        $viewerRole = Role::query()->where('name', 'viewer')->firstOrFail();
        $viewer = User::factory()->create([
            'role_id' => $viewerRole->id,
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        Sanctum::actingAs($viewer);

        $this->postJson('/api/document-templates', [
            'type' => DocumentTemplate::TYPE_CONTRACT,
            'content' => '<p>محظور</p>',
        ])->assertForbidden();

        $service = app(DocumentTemplateService::class);
        $service->ensureDefaults();
        $html = $service->render(
            'مرحباً {{customer_name}}',
            'html',
            $service->withAliases(['customer_name' => 'مشاهد']),
        );
        $this->assertSame('مرحباً مشاهد', $html);
    }

    public function test_docx_upload_extracts_content_with_placeholders(): void
    {
        Storage::fake((string) config('uploads.disk', 'local'));
        $this->actingAdmin();

        $tmp = tempnam(sys_get_temp_dir(), 'docx');
        $zip = new ZipArchive;
        $zip->open($tmp, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"></Types>');
        $zip->addFromString(
            'word/document.xml',
            '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>عقد {{customer_name}} — {{vehicle_plate}}</w:t></w:r></w:p></w:body></w:document>',
        );
        $zip->close();

        $file = new UploadedFile($tmp, 'contract.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true);

        $res = $this->post('/api/document-templates', [
            'type' => DocumentTemplate::TYPE_CONTRACT,
            'title' => 'من وورد',
            'file' => $file,
        ], [
            'Accept' => 'application/json',
        ])->assertCreated();

        $content = (string) $res->json('data.content');
        $this->assertStringContainsString('{{customer_name}}', $content);
        $this->assertStringContainsString('{{vehicle_plate}}', $content);
        $this->assertSame('docx', $res->json('data.format'));

        @unlink($tmp);
    }

    private function actingAdmin(): User
    {
        $adminRole = Role::query()->where('name', 'super_admin')->firstOrFail();
        $admin = User::factory()->create([
            'email' => 'admin-templates@rpm.local',
            'password' => Hash::make('password'),
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);
        Sanctum::actingAs($admin);

        return $admin;
    }
}
