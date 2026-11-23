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
            'center_name' => 'شركة قوالب الاختبار',
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
        $this->assertStringNotContainsString('شروط التعاقد', $rendered);
        $this->assertNotEmpty($print->json('data.templates.handover_receipt.rendered_html'));
    }

    public function test_default_rental_contract_template_autofills_party_and_terms(): void
    {
        $this->actingAdmin();
        TenantSetting::query()->first()?->update([
            'center_name' => 'شركة آر بي إم للنقل والسياحة',
            'operational' => [
                'print_phone' => '01125253888',
                'print_address' => '17 شارع كورنيش النيل - حدائق حلوان - محافظة القاهرة',
                'print_commercial_register' => '18766',
                'print_signer_name' => 'محمد عبد القادر محمد',
                'company_tagline' => 'لخدمات رجال الأعمال',
                'default_daily_km' => 200,
                'excess_km_rate' => 5,
            ],
        ]);

        $service = app(DocumentTemplateService::class);
        $service->ensureDefaults();

        $customer = Customer::query()->create([
            'full_name' => 'سارة علي حسن',
            'phone' => '01012345678',
            'nationality' => 'مصري',
            'national_id' => '29001011234567',
            'national_id_issued_from' => 'مصر الجديدة',
            'national_id_issued_on' => '2018-05-12',
            'address' => 'المعادي — القاهرة',
            'birth_date' => '1990-01-01',
            'license_number' => 'DRV-9090',
            'license_issued_from' => 'مرور القاهرة',
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
            'purchase_value_estimate' => 450000,
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
            'include_vehicle_value_obligation' => true,
            'frozen_vehicle_value_text' => '450000',
            'odometer_out' => 22000,
        ]);

        $print = $this->getJson("/api/contracts/{$contract->id}/print")->assertOk();
        $rendered = (string) $print->json('data.templates.contract.rendered_html');

        $this->assertStringContainsString('rpm-paper-v6', $rendered);
        $this->assertStringContainsString('شروط التعاقد', $rendered);
        $this->assertStringContainsString('البند الأول', $rendered);
        $this->assertStringContainsString('البند التاسع', $rendered);
        $this->assertStringContainsString('قبل انتهاء الإيجار', $rendered);
        $this->assertStringContainsString('إذا حدث لقدر الله', $rendered);
        $this->assertStringContainsString('400 للسرعة', $rendered);
        $this->assertStringContainsString('خدمة توصيل', $rendered);
        $this->assertStringContainsString('بطاقة الرقم القومي', $rendered);
        $this->assertStringNotContainsString('البند الحادي عشر', $rendered);
        $this->assertStringNotContainsString('rpm-lc-ribbon', $rendered);
        $this->assertStringContainsString('سارة علي حسن', $rendered);
        $this->assertStringContainsString('29001011234567', $rendered);
        $this->assertStringContainsString('مصري', $rendered);
        $this->assertStringContainsString('مصر الجديدة', $rendered);
        $this->assertStringContainsString('05/2018', $rendered);
        $this->assertStringContainsString('مرور القاهرة', $rendered);
        $this->assertStringContainsString('المعادي — القاهرة', $rendered);
        $this->assertStringContainsString('س ص ع 100', $rendered);
        $this->assertStringContainsString('هيونداي', $rendered);
        $this->assertStringContainsString('2023', $rendered);
        $this->assertStringContainsString('CHS-999', $rendered);
        $this->assertStringContainsString('5000', $rendered);
        $this->assertStringContainsString('250', $rendered);
        $this->assertStringContainsString('450000', $rendered);
        $this->assertStringContainsString('18766', $rendered);
        $this->assertStringContainsString('محمد عبد القادر محمد', $rendered);
        $this->assertStringContainsString('أجر السيد /', $rendered);
        $this->assertStringContainsString('شركة آر بي إم للنقل والسياحة', $rendered);
        $this->assertStringNotContainsString('محمد السيد عبد القادر', $rendered);
        $this->assertStringContainsString('التوقيع :', $rendered);
        $this->assertStringNotContainsString('{{customer_name}}', $rendered);
        $this->assertStringNotContainsString('{{deposit}}', $rendered);
        $this->assertStringNotContainsString('صادرة من : <span class="fill"></span>', $rendered);

        $ack = (string) $print->json('data.templates.handover_receipt.rendered_html');
        $this->assertStringContainsString('rpm-ack-v8', $ack);
        $this->assertStringContainsString('إقرار استلام سيارة', $ack);
        $this->assertStringNotContainsString('محضر إثبات', $ack);
        $this->assertStringContainsString('خائنا للأمانة', $ack);
        $this->assertStringContainsString('وهذا إقرار مني بذلك', $ack);
        $this->assertStringContainsString('البصمة', $ack);
        $this->assertStringContainsString('الإسم :', $ack);
        $this->assertStringContainsString('التوقيع :', $ack);
        $this->assertStringContainsString('rpm-ack2-finger-oval', $ack);
        $this->assertStringNotContainsString('الشهود', $ack);
        $this->assertStringNotContainsString('rpm-ack-witnesses', $ack);
        $this->assertStringNotContainsString('العشرون', $ack);
        $this->assertStringNotContainsString('أتعه ', $ack);
        $this->assertStringContainsString('أتعهد بأنه', $ack);
        $this->assertStringContainsString('سارة علي حسن', $ack);
        $this->assertStringContainsString('س ص ع 100', $ack);
        $this->assertStringContainsString('2023', $ack);
        $this->assertStringContainsString('450000', $ack);
        $this->assertStringNotContainsString('{{customer_name}}', $ack);
        $this->assertDoesNotMatchRegularExpression('/الساعة\s*:\s*<span[^>]*>00:00<\/span>/u', $rendered.$ack);
    }

    public function test_contract_print_uses_stored_non_egyptian_nationality(): void
    {
        $this->actingAdmin();
        app(DocumentTemplateService::class)->ensureDefaults();

        $customer = Customer::query()->create([
            'full_name' => 'فهد العتيبي',
            'phone' => '01099990001',
            'nationality' => 'سعودي',
            'national_id' => 'SA-998877',
            'birth_date' => '1985-06-15',
            'license_number' => 'DRV-SA-1',
            'license_expires_on' => now()->addYears(2)->toDateString(),
            'status' => 'active',
        ]);
        $vehicle = Vehicle::query()->create([
            'code' => 'SA-1',
            'plate' => 'س ع د 200',
            'brand' => 'تويوتا',
            'model' => 'كامري',
            'year' => 2022,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'chassis_number' => 'CHS-SA-1',
            'odometer' => 10000,
            'daily_rate' => 800,
            'default_deposit' => 4000,
            'is_active' => true,
        ]);
        $contract = Contract::query()->create([
            'number' => 'C-SA-1',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'status' => 'active',
            'starts_at' => now()->startOfDay(),
            'planned_ends_at' => now()->startOfDay()->addDays(2),
            'frozen_daily_rate' => 800,
            'frozen_agreed_deposit' => 4000,
            'frozen_daily_km_allowance' => 200,
            'frozen_excess_km_rate' => 5,
            'frozen_min_days' => 1,
            'odometer_out' => 10000,
        ]);

        $print = $this->getJson("/api/contracts/{$contract->id}/print")->assertOk();
        $rendered = (string) $print->json('data.templates.contract.rendered_html');
        $this->assertStringContainsString('سعودي', $rendered);
        $this->assertStringContainsString('فهد العتيبي', $rendered);
        $this->assertStringContainsString('SA-998877', $rendered);
    }

    public function test_stock_handover_stub_upgrades_to_ack_form(): void
    {
        $this->actingAdmin();
        DocumentTemplate::query()->where('type', DocumentTemplate::TYPE_HANDOVER_RECEIPT)->delete();
        DocumentTemplate::query()->create([
            'type' => DocumentTemplate::TYPE_HANDOVER_RECEIPT,
            'title' => 'قالب إقرار الاستلام والتسليم',
            'content' => <<<'HTML'
<header class="print-head"><h1>{{company_name}}</h1></header>
<h2>إقرار الاستلام والتسليم</h2>
<table><tr><th>عداد الخروج</th><td>{{odometer_out}}</td></tr></table>
<h2>بيانات التأمين</h2>
<table><tr><th>التأمين</th><td>{{deposit}}</td></tr></table>
HTML,
            'format' => 'html',
            'version' => 1,
            'is_active' => true,
        ]);

        app(DocumentTemplateService::class)->upgradeStockTemplatesIfNeeded();

        $active = DocumentTemplate::query()
            ->where('type', DocumentTemplate::TYPE_HANDOVER_RECEIPT)
            ->where('is_active', true)
            ->orderByDesc('version')
            ->first();

        $this->assertNotNull($active);
        $this->assertGreaterThan(1, (int) $active->version);
        $this->assertStringContainsString('rpm-ack-v8', (string) $active->content);
        $this->assertStringContainsString('إقرار استلام سيارة', (string) $active->content);
        $this->assertStringNotContainsString('محضر إثبات', (string) $active->content);
        $this->assertStringNotContainsString('rpm-ack-witnesses', (string) $active->content);
    }

    public function test_ack_with_witnesses_upgrades_to_word_form_v6(): void
    {
        $this->actingAdmin();
        DocumentTemplate::query()->where('type', DocumentTemplate::TYPE_HANDOVER_RECEIPT)->delete();
        DocumentTemplate::query()->create([
            'type' => DocumentTemplate::TYPE_HANDOVER_RECEIPT,
            'title' => 'إقرار استلام سيارة (ظهر العقد)',
            'content' => <<<'HTML'
<article class="rpm-legal-contract rpm-paper-v4 rpm-ack-v1">
  <h1>إقرار استلام سيارة</h1>
  <ol class="rpm-lc-terms"><li><span class="rpm-lc-band">العشرون</span> بند</li></ol>
  <section class="rpm-ack-witnesses"><div class="rpm-ack-witness-title">الشهود</div></section>
</article>
HTML,
            'format' => 'html',
            'version' => 2,
            'is_active' => true,
        ]);

        app(DocumentTemplateService::class)->upgradeStockTemplatesIfNeeded();

        $active = DocumentTemplate::query()
            ->where('type', DocumentTemplate::TYPE_HANDOVER_RECEIPT)
            ->where('is_active', true)
            ->orderByDesc('version')
            ->first();

        $this->assertNotNull($active);
        $this->assertGreaterThan(2, (int) $active->version);
        $this->assertStringContainsString('rpm-ack-v8', (string) $active->content);
        $this->assertStringNotContainsString('rpm-ack-witnesses', (string) $active->content);
        $this->assertStringNotContainsString('الشهود', (string) $active->content);
        $this->assertStringNotContainsString('محضر إثبات', (string) $active->content);
        $this->assertStringContainsString('خائنا للأمانة', (string) $active->content);
        $this->assertStringContainsString('وهذا إقرار مني بذلك', (string) $active->content);
        $this->assertStringContainsString('rpm-ack2-finger-oval', (string) $active->content);
    }

    public function test_ack_with_subtitle_line_force_upgrades_even_on_v5(): void
    {
        $this->actingAdmin();
        DocumentTemplate::query()->where('type', DocumentTemplate::TYPE_HANDOVER_RECEIPT)->delete();
        DocumentTemplate::query()->create([
            'type' => DocumentTemplate::TYPE_HANDOVER_RECEIPT,
            'title' => 'إقرار استلام سيارة (ظهر العقد)',
            'content' => <<<'HTML'
<article class="rpm-ack-form rpm-ack-v5" dir="rtl">
  <h1 class="rpm-ack2-title">إقرار استلام سيارة</h1>
  <h2 class="rpm-ack2-sub">محضر إثبات تسلّم المركبة وحالتها الظاهرة</h2>
  <p>خائنا للأمانة</p>
</article>
HTML,
            'format' => 'html',
            'version' => 5,
            'is_active' => true,
        ]);

        app(DocumentTemplateService::class)->upgradeStockTemplatesIfNeeded();

        $active = DocumentTemplate::query()
            ->where('type', DocumentTemplate::TYPE_HANDOVER_RECEIPT)
            ->where('is_active', true)
            ->orderByDesc('version')
            ->first();

        $this->assertNotNull($active);
        $this->assertGreaterThan(5, (int) $active->version);
        $this->assertStringContainsString('rpm-ack-v8', (string) $active->content);
        $this->assertStringNotContainsString('محضر إثبات', (string) $active->content);
    }

    public function test_user_published_ack_edits_survive_ensure_defaults_and_reload(): void
    {
        $admin = $this->actingAdmin();
        $marker = 'تعديل_عميل_محفوظ_'.uniqid();

        $save = $this->postJson('/api/document-templates', [
            'type' => DocumentTemplate::TYPE_HANDOVER_RECEIPT,
            'title' => 'إقرار معدّل من الإعدادات',
            'format' => 'html',
            'content' => <<<HTML
<article class="rpm-ack-form rpm-ack-v7" dir="rtl">
  <h1>إقرار استلام سيارة</h1>
  <p>خائنا للأمانة — {$marker}</p>
  <p>بند بدون rpm-vehicle-value-clause عمداً</p>
</article>
HTML,
        ])->assertCreated();

        $this->assertSame($admin->id, (int) $save->json('data.updated_by'));
        $this->assertStringContainsString($marker, (string) $save->json('data.content'));

        // كان ensureDefaults يعيد نشر الافتراضي ويمحو التعديل عند كل قراءة/طباعة.
        app(DocumentTemplateService::class)->ensureDefaults();
        app(DocumentTemplateService::class)->upgradeStockTemplatesIfNeeded();
        $list = $this->getJson('/api/document-templates?active=1')->assertOk();
        $ack = collect($list->json('data'))->firstWhere('type', DocumentTemplate::TYPE_HANDOVER_RECEIPT);
        $this->assertNotNull($ack);
        $this->assertStringContainsString($marker, (string) $ack['content']);
        $this->assertStringNotContainsString('rpm-ack-v8', (string) $ack['content']);
    }

    public function test_ensure_defaults_on_read_does_not_upgrade_stock_ack(): void
    {
        $this->actingAdmin();
        DocumentTemplate::query()->where('type', DocumentTemplate::TYPE_HANDOVER_RECEIPT)->delete();
        DocumentTemplate::query()->create([
            'type' => DocumentTemplate::TYPE_HANDOVER_RECEIPT,
            'title' => 'إقرار استلام سيارة (ظهر العقد)',
            'content' => <<<'HTML'
<article class="rpm-ack-form rpm-ack-v5" dir="rtl">
  <h1 class="rpm-ack2-title">إقرار استلام سيارة</h1>
  <h2 class="rpm-ack2-sub">محضر إثبات تسلّم المركبة وحالتها الظاهرة</h2>
  <p>نسخة قديمة ثابتة على مسار القراءة</p>
</article>
HTML,
            'format' => 'html',
            'version' => 5,
            'is_active' => true,
            'updated_by' => null,
        ]);

        // مسار القراءة (ensureDefaults فقط) لا يرقّي — حتى لا يُمسح تعديل لاحق بعد الحفظ.
        app(DocumentTemplateService::class)->ensureDefaults();
        $afterRead = DocumentTemplate::query()
            ->where('type', DocumentTemplate::TYPE_HANDOVER_RECEIPT)
            ->where('is_active', true)
            ->orderByDesc('version')
            ->first();
        $this->assertNotNull($afterRead);
        $this->assertSame(5, (int) $afterRead->version);
        $this->assertStringContainsString('rpm-ack-v5', (string) $afterRead->content);
        $this->assertStringContainsString('محضر إثبات', (string) $afterRead->content);
    }

    public function test_editor_content_wins_over_uploaded_file_on_publish(): void
    {
        $this->actingAdmin();
        $marker = 'محتوى_المحرر_'.uniqid();
        $tmp = tempnam(sys_get_temp_dir(), 'tpl');
        file_put_contents($tmp, '<article><p>من الملف فقط — يجب ألا يظهر</p></article>');
        $file = new UploadedFile($tmp, 'old.html', 'text/html', null, true);

        $res = $this->post('/api/document-templates', [
            'type' => DocumentTemplate::TYPE_HANDOVER_RECEIPT,
            'title' => 'إقرار',
            'format' => 'html',
            'content' => '<article class="rpm-ack-form rpm-ack-v8"><p>'.$marker.'</p></article>',
            'file' => $file,
        ], [
            'Accept' => 'application/json',
        ])->assertCreated();

        $content = (string) $res->json('data.content');
        $this->assertStringContainsString($marker, $content);
        $this->assertStringNotContainsString('من الملف فقط', $content);
        @unlink($tmp);
    }

    public function test_legacy_wrong_signer_and_company_defaults_are_normalized_on_print(): void
    {
        $this->actingAdmin();
        TenantSetting::query()->first()?->update([
            'center_name' => 'شركة آر بي إم للتأجير',
            'operational' => [
                'print_phone' => '01125253888',
                'print_address' => 'القاهرة',
                'print_signer_name' => 'محمد السيد عبد القادر',
                'company_tagline' => 'لخدمات رجال الأعمال',
            ],
        ]);

        app(DocumentTemplateService::class)->ensureDefaults();

        $customer = Customer::query()->create([
            'full_name' => 'عميل تصحيح الاسم',
            'phone' => '01055554444',
            'birth_date' => '1990-01-01',
            'license_number' => 'LIC-FIX',
            'license_expires_on' => now()->addYears(2)->toDateString(),
            'status' => 'active',
        ]);
        $vehicle = Vehicle::query()->create([
            'code' => 'FIX-1',
            'plate' => 'أ ب ج 1',
            'brand' => 'نيسان',
            'model' => 'صني',
            'year' => 2022,
            'category' => 'اقتصادي',
            'fuel_type' => 'بنزين',
            'odometer' => 1000,
            'daily_rate' => 700,
            'default_deposit' => 2000,
            'is_active' => true,
        ]);
        $contract = Contract::query()->create([
            'number' => 'C-FIX-1',
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'status' => 'active',
            'starts_at' => now()->startOfDay(),
            'planned_ends_at' => now()->startOfDay()->addDays(2),
            'frozen_daily_rate' => 700,
            'frozen_agreed_deposit' => 2000,
            'frozen_daily_km_allowance' => 200,
            'frozen_excess_km_rate' => 5,
            'frozen_min_days' => 1,
        ]);

        $print = $this->getJson("/api/contracts/{$contract->id}/print")->assertOk();
        $rendered = (string) $print->json('data.templates.contract.rendered_html');
        $this->assertStringContainsString('محمد عبد القادر محمد', $rendered);
        $this->assertStringContainsString('شركة آر بي إم للنقل والسياحة', $rendered);
        $this->assertStringNotContainsString('محمد السيد عبد القادر', $rendered);
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

        app(DocumentTemplateService::class)->upgradeStockTemplatesIfNeeded();

        $active = DocumentTemplate::query()
            ->where('type', DocumentTemplate::TYPE_CONTRACT)
            ->where('is_active', true)
            ->orderByDesc('version')
            ->first();

        $this->assertNotNull($active);
        $this->assertGreaterThan(1, (int) $active->version);
        $this->assertStringContainsString('rpm-paper-v6', (string) $active->content);
        $this->assertStringContainsString('شروط التعاقد', (string) $active->content);
        $this->assertStringContainsString('البند السادس', (string) $active->content);
        $this->assertStringContainsString('خدمة توصيل', (string) $active->content);
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
