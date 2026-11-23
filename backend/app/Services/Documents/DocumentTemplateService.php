<?php

namespace App\Services\Documents;

use App\Models\Contract;
use App\Models\DocumentTemplate;
use App\Models\TenantSetting;
use App\Models\Vehicle;
use App\Support\CivilDate;
use App\Support\DepositCurrency;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

class DocumentTemplateService
{
    /**
     * @return list<array{key:string,aliases:list<string>,label:string,sample:string,group:string}>
     */
    public function placeholderCatalog(): array
    {
        return [
            ['key' => 'company_name', 'aliases' => ['اسم_الشركة', 'اسم_المركز'], 'label' => 'اسم الشركة', 'sample' => 'شركة آر بي إم للنقل والسياحة', 'group' => 'الشركة'],
            ['key' => 'company_phone', 'aliases' => ['هاتف_الشركة'], 'label' => 'هاتف الشركة', 'sample' => '01150100104', 'group' => 'الشركة'],
            ['key' => 'company_address', 'aliases' => ['عنوان_الشركة'], 'label' => 'عنوان الشركة', 'sample' => 'القاهرة', 'group' => 'الشركة'],
            ['key' => 'company_cr', 'aliases' => ['السجل_التجاري'], 'label' => 'السجل التجاري', 'sample' => '12345', 'group' => 'الشركة'],
            ['key' => 'company_tax', 'aliases' => ['البطاقة_الضريبية'], 'label' => 'البطاقة الضريبية', 'sample' => '67890', 'group' => 'الشركة'],
            ['key' => 'logo_src', 'aliases' => ['شعار_الشركة'], 'label' => 'مسار شعار الشركة', 'sample' => '/brand/rpm-logo.webp', 'group' => 'الشركة'],
            ['key' => 'print_footer', 'aliases' => ['تذييل_الطباعة'], 'label' => 'تذييل الطباعة', 'sample' => 'نسخة داخلية', 'group' => 'الشركة'],
            ['key' => 'contract_terms', 'aliases' => ['بنود_العقد'], 'label' => 'بنود وشروط العقد', 'sample' => 'يلتزم المستأجر بإعادة العربية في الموعد المحدد.', 'group' => 'الشركة'],
            ['key' => 'contract_number', 'aliases' => ['رقم_العقد'], 'label' => 'رقم العقد', 'sample' => 'C-1001', 'group' => 'العقد'],
            ['key' => 'contract_status', 'aliases' => ['حالة_العقد'], 'label' => 'حالة العقد', 'sample' => 'نشط', 'group' => 'العقد'],
            ['key' => 'customer_name', 'aliases' => ['اسم_العميل'], 'label' => 'اسم العميل', 'sample' => 'محمد أحمد', 'group' => 'العميل'],
            ['key' => 'customer_phone', 'aliases' => ['هاتف_العميل', 'رقم_الهاتف'], 'label' => 'هاتف العميل', 'sample' => '01000000000', 'group' => 'العميل'],
            ['key' => 'customer_national_id', 'aliases' => ['الرقم_القومي', 'قومي_العميل'], 'label' => 'الرقم القومي للعميل', 'sample' => '29001011234567', 'group' => 'العميل'],
            ['key' => 'customer_id_issued_from', 'aliases' => ['جهة_صدور_البطاقة', 'صادرة_من_البطاقة'], 'label' => 'جهة صدور بطاقة العميل', 'sample' => 'مصر الجديدة', 'group' => 'العميل'],
            ['key' => 'customer_id_issued_on', 'aliases' => ['تاريخ_صدور_البطاقة'], 'label' => 'تاريخ صدور بطاقة العميل', 'sample' => '01/2020', 'group' => 'العميل'],
            ['key' => 'customer_nationality', 'aliases' => ['جنسية_العميل'], 'label' => 'جنسية العميل', 'sample' => 'مصري', 'group' => 'العميل'],
            ['key' => 'customer_address', 'aliases' => ['عنوان_العميل', 'محل_الإقامة'], 'label' => 'محل إقامة المستأجر', 'sample' => 'القاهرة — المعادي', 'group' => 'العميل'],
            ['key' => 'customer_license', 'aliases' => ['رخصة_العميل', 'رقم_الرخصة'], 'label' => 'رقم رخصة القيادة', 'sample' => 'LIC-12345', 'group' => 'العميل'],
            ['key' => 'customer_license_issued_from', 'aliases' => ['جهة_صدور_الرخصة', 'صادرة_من_الرخصة'], 'label' => 'جهة صدور رخصة العميل', 'sample' => 'المرور', 'group' => 'العميل'],
            ['key' => 'vehicle_plate', 'aliases' => ['رقم_اللوحة'], 'label' => 'رقم اللوحة', 'sample' => 'أ ب ج 1234', 'group' => 'العربية'],
            ['key' => 'vehicle_name', 'aliases' => ['اسم_العربية', 'العربية'], 'label' => 'الماركة والموديل', 'sample' => 'هيونداي إلنترا', 'group' => 'العربية'],
            ['key' => 'vehicle_brand', 'aliases' => ['ماركة_العربية'], 'label' => 'ماركة العربية', 'sample' => 'هيونداي', 'group' => 'العربية'],
            ['key' => 'vehicle_model', 'aliases' => ['موديل_العربية'], 'label' => 'موديل العربية', 'sample' => 'إلنترا', 'group' => 'العربية'],
            ['key' => 'daily_km', 'aliases' => ['بدل_الكيلومتر', 'الكيلومتر_اليومي'], 'label' => 'بدل الكيلومتر اليومي', 'sample' => '200', 'group' => 'العقد'],
            ['key' => 'excess_km_rate', 'aliases' => ['سعر_الكيلومتر_الزائد'], 'label' => 'سعر الكيلومتر الزائد', 'sample' => '5', 'group' => 'العقد'],
            ['key' => 'rental_duration', 'aliases' => ['مدة_الإيجار'], 'label' => 'مدة الإيجار', 'sample' => '4 أيام', 'group' => 'العقد'],
            ['key' => 'contract_day', 'aliases' => ['يوم_التحرير'], 'label' => 'يوم تحرير العقد', 'sample' => 'السبت', 'group' => 'العقد'],
            ['key' => 'contract_date', 'aliases' => ['تاريخ_التحرير'], 'label' => 'تاريخ تحرير العقد (يوم/شهر/سنة)', 'sample' => '08/11/2026', 'group' => 'العقد'],
            ['key' => 'contract_time', 'aliases' => ['ساعة_التحرير'], 'label' => 'ساعة تحرير العقد', 'sample' => '10:30', 'group' => 'العقد'],
            ['key' => 'start_day', 'aliases' => ['يوم_البداية'], 'label' => 'يوم بداية الإيجار', 'sample' => 'السبت', 'group' => 'العقد'],
            ['key' => 'start_date_only', 'aliases' => ['تاريخ_البداية_فقط'], 'label' => 'تاريخ البداية (يوم/شهر/سنة)', 'sample' => '01/11/2026', 'group' => 'العقد'],
            ['key' => 'start_time', 'aliases' => ['ساعة_البداية'], 'label' => 'ساعة البداية', 'sample' => '10:00', 'group' => 'العقد'],
            ['key' => 'end_day', 'aliases' => ['يوم_النهاية'], 'label' => 'يوم نهاية الإيجار', 'sample' => 'الأربعاء', 'group' => 'العقد'],
            ['key' => 'end_date_only', 'aliases' => ['تاريخ_النهاية_فقط'], 'label' => 'تاريخ النهاية (يوم/شهر/سنة)', 'sample' => '05/11/2026', 'group' => 'العقد'],
            ['key' => 'end_time', 'aliases' => ['ساعة_النهاية'], 'label' => 'ساعة النهاية', 'sample' => '10:00', 'group' => 'العقد'],
            ['key' => 'annual_km', 'aliases' => ['الكيلومتر_السنوي'], 'label' => 'بدل الكيلومتر السنوي', 'sample' => '73000', 'group' => 'العقد'],
            ['key' => 'km_extra_after', 'aliases' => ['حد_الكيلومتر_الإضافي'], 'label' => 'حد احتساب الكيلومتر الإضافي', 'sample' => '1000', 'group' => 'العقد'],
            ['key' => 'rideshare_penalty', 'aliases' => ['غرامة_النقل_التشاركي'], 'label' => 'غرامة التأجير لأوبر ونحوها', 'sample' => '50000', 'group' => 'العقد'],
            ['key' => 'customer_id_doc', 'aliases' => ['وثيقة_هوية_العميل'], 'label' => 'رقم قومي أو جواز سفر', 'sample' => '29001011234567', 'group' => 'العميل'],
            ['key' => 'print_signer_name', 'aliases' => ['المفوض_بالتوقيع', 'اسم_المفوض'], 'label' => 'المفوض بالتوقيع عن الشركة', 'sample' => 'محمد عبد القادر محمد', 'group' => 'الشركة'],
            ['key' => 'company_tagline', 'aliases' => ['شعار_فرعي'], 'label' => 'الشعار الفرعي تحت اللوجو', 'sample' => 'لخدمات رجال الأعمال', 'group' => 'الشركة'],
            ['key' => 'delivery_address', 'aliases' => ['عنوان_التوصيل'], 'label' => 'عنوان خدمة التوصيل', 'sample' => 'المعادي — القاهرة', 'group' => 'العقد'],
            ['key' => 'delivery_pickup_mark', 'aliases' => ['علامة_الاستلام'], 'label' => 'علامة استلام التوصيل', 'sample' => '☐', 'group' => 'العقد'],
            ['key' => 'delivery_dropoff_mark', 'aliases' => ['علامة_التسليم'], 'label' => 'علامة تسليم التوصيل', 'sample' => '☐', 'group' => 'العقد'],
            ['key' => 'vehicle_value', 'aliases' => ['قيمة_السيارة', 'ثمن_السيارة'], 'label' => 'نص ثمن العربية المتجمّد على العقد (اختياري)', 'sample' => '50% من قيمة السيارة وهو مبلغ وقدره 500.000 فقط خمسمائة ألف جنيه', 'group' => 'العربية'],
            ['key' => 'start_date', 'aliases' => ['تاريخ_البداية'], 'label' => 'تاريخ البداية', 'sample' => '01/11/2026 10:00', 'group' => 'العقد'],
            ['key' => 'end_date', 'aliases' => ['تاريخ_النهاية', 'النهاية_المخططة'], 'label' => 'تاريخ النهاية المخططة', 'sample' => '05/11/2026 10:00', 'group' => 'العقد'],
            ['key' => 'actual_end_date', 'aliases' => ['تاريخ_الاستلام_الفعلي'], 'label' => 'تاريخ الاستلام الفعلي', 'sample' => '05/11/2026 18:00', 'group' => 'العقد'],
            ['key' => 'daily_rate', 'aliases' => ['السعر_اليومي'], 'label' => 'السعر اليومي', 'sample' => '800', 'group' => 'العقد'],
            ['key' => 'deposit', 'aliases' => ['التأمين', 'التأمين_النقدي'], 'label' => 'مبلغ التأمين النقدي', 'sample' => '5000', 'group' => 'التأمين'],
            ['key' => 'deposit_currency', 'aliases' => ['عملة_التأمين'], 'label' => 'عملة التأمين النقدي', 'sample' => 'جنيه مصري', 'group' => 'التأمين'],
            ['key' => 'deposit_status', 'aliases' => ['حالة_التأمين'], 'label' => 'حالة التأمين', 'sample' => 'مقبوض', 'group' => 'التأمين'],
            ['key' => 'deposit_remaining', 'aliases' => ['متبقي_التأمين'], 'label' => 'متبقي التأمين', 'sample' => '5000', 'group' => 'التأمين'],
            ['key' => 'deposit_deductions', 'aliases' => ['خصومات_التأمين'], 'label' => 'خصومات التأمين', 'sample' => '0', 'group' => 'التأمين'],
            ['key' => 'final_total', 'aliases' => ['الإجمالي_النهائي'], 'label' => 'الإجمالي النهائي', 'sample' => '3200', 'group' => 'العقد'],
            ['key' => 'odometer_out', 'aliases' => ['عداد_الخروج'], 'label' => 'عداد الخروج', 'sample' => '45000', 'group' => 'العقد'],
            ['key' => 'odometer_in', 'aliases' => ['عداد_العودة'], 'label' => 'عداد العودة', 'sample' => '45210', 'group' => 'العقد'],
            ['key' => 'handover_at', 'aliases' => ['وقت_التسليم'], 'label' => 'وقت التسليم', 'sample' => '01/11/2026 10:00', 'group' => 'العقد'],
            ['key' => 'return_at', 'aliases' => ['وقت_الاستلام'], 'label' => 'وقت الاستلام', 'sample' => '05/11/2026 18:00', 'group' => 'العقد'],
            ['key' => 'template_version', 'aliases' => ['إصدار_القالب'], 'label' => 'رقم إصدار القالب', 'sample' => '1', 'group' => 'العقد'],
            ['key' => 'owner_name', 'aliases' => ['اسم_المالك'], 'label' => 'اسم المالك', 'sample' => 'محمود علي', 'group' => 'المالك'],
            ['key' => 'owner_phone', 'aliases' => ['هاتف_المالك'], 'label' => 'هاتف المالك', 'sample' => '01011112222', 'group' => 'المالك'],
            ['key' => 'owner_phone_alt', 'aliases' => ['هاتف_المالك_البديل'], 'label' => 'هاتف المالك البديل', 'sample' => '01122223333', 'group' => 'المالك'],
            ['key' => 'owner_national_id', 'aliases' => ['قومي_المالك'], 'label' => 'الرقم القومي للمالك', 'sample' => '29001011234567', 'group' => 'المالك'],
            ['key' => 'owner_nationality', 'aliases' => ['جنسية_المالك'], 'label' => 'جنسية المالك', 'sample' => 'مصري', 'group' => 'المالك'],
            ['key' => 'owner_monthly_amount', 'aliases' => ['المبلغ_الشهري_للمالك'], 'label' => 'المبلغ الشهري للمالك', 'sample' => '8000', 'group' => 'المالك'],
            ['key' => 'owner_agreement_number', 'aliases' => ['رقم_عقد_المالك'], 'label' => 'رقم عقد المالك (تلقائي)', 'sample' => 'OWN-VEH-0001', 'group' => 'المالك'],
            ['key' => 'owner_agreement_starts_on', 'aliases' => ['بداية_عقد_المالك'], 'label' => 'بداية عقد المالك', 'sample' => '01/01/2026', 'group' => 'المالك'],
            ['key' => 'owner_agreement_ends_on', 'aliases' => ['نهاية_عقد_المالك'], 'label' => 'نهاية عقد المالك', 'sample' => '31/12/2026', 'group' => 'المالك'],
            ['key' => 'agreement_date', 'aliases' => ['تاريخ_تحرير_العقد'], 'label' => 'تاريخ تحرير العقد', 'sample' => '08/11/2026', 'group' => 'المالك'],
            ['key' => 'agreement_number', 'aliases' => ['رقم_عقد_الاعتماد'], 'label' => 'رقم عقد الاعتماد الصادر', 'sample' => 'CAD-2026-0001', 'group' => 'المالك'],
            ['key' => 'monthly_amount', 'aliases' => ['القيمة_الشهرية_للاعتماد'], 'label' => 'القيمة الشهرية لعقد الاعتماد', 'sample' => '8000', 'group' => 'المالك'],
            ['key' => 'vehicle_code', 'aliases' => ['كود_العربية'], 'label' => 'الكود الداخلي للعربية', 'sample' => 'VEH-0001', 'group' => 'العربية'],
            ['key' => 'vehicle_year', 'aliases' => ['سنة_العربية'], 'label' => 'سنة الصنع', 'sample' => '2024', 'group' => 'العربية'],
            ['key' => 'vehicle_category', 'aliases' => ['فئة_العربية'], 'label' => 'فئة العربية', 'sample' => 'اقتصادي', 'group' => 'العربية'],
            ['key' => 'vehicle_color', 'aliases' => ['لون_العربية'], 'label' => 'لون العربية', 'sample' => 'أبيض', 'group' => 'العربية'],
            ['key' => 'vehicle_fuel', 'aliases' => ['وقود_العربية'], 'label' => 'نوع الوقود', 'sample' => 'بنزين', 'group' => 'العربية'],
            ['key' => 'vehicle_transmission', 'aliases' => ['ناقل_الحركة'], 'label' => 'ناقل الحركة', 'sample' => 'أوتوماتيك', 'group' => 'العربية'],
            ['key' => 'vehicle_chassis', 'aliases' => ['رقم_الشاسيه'], 'label' => 'رقم الشاسيه', 'sample' => 'CHS-123456', 'group' => 'العربية'],
            ['key' => 'vehicle_engine', 'aliases' => ['رقم_المحرك'], 'label' => 'رقم المحرك', 'sample' => 'ENG-654321', 'group' => 'العربية'],
            ['key' => 'vehicle_odometer', 'aliases' => ['عداد_العربية'], 'label' => 'قراءة العداد عند التعاقد', 'sample' => '45000', 'group' => 'العربية'],
            ['key' => 'vehicle_daily_rate', 'aliases' => ['سعر_التأجير_اليومي'], 'label' => 'سعر التأجير اليومي المعتمد', 'sample' => '800', 'group' => 'العربية'],
            ['key' => 'vehicle_deposit', 'aliases' => ['تأمين_التأجير_الافتراضي'], 'label' => 'التأمين النقدي الافتراضي للتأجير', 'sample' => '5000', 'group' => 'العربية'],
        ];
    }

    public function tableReady(): bool
    {
        try {
            return Schema::hasTable('document_templates');
        } catch (\Throwable) {
            return false;
        }
    }

    public function ensureDefaults(?int $userId = null): void
    {
        if (! $this->tableReady()) {
            return;
        }

        foreach ([
            DocumentTemplate::TYPE_CONTRACT => [
                'title' => 'عقد إيجار سيارة (نموذج الشركة)',
                'content' => $this->defaultContractHtml(),
            ],
            DocumentTemplate::TYPE_HANDOVER_RECEIPT => [
                'title' => 'إقرار استلام سيارة (ظهر العقد)',
                'content' => $this->defaultHandoverHtml(),
            ],
            DocumentTemplate::TYPE_OWNER_AGREEMENT => [
                'title' => 'عقد إدارة وتشغيل مركبة (مالك ↔ شركة)',
                'content' => $this->defaultOwnerAgreementHtml(),
            ],
            DocumentTemplate::TYPE_CUSTODY_AGREEMENT => [
                'title' => 'قالب عقد الاعتماد',
                'content' => $this->defaultCustodyAgreementHtml(),
            ],
        ] as $type => $meta) {
            $exists = DocumentTemplate::query()->where('type', $type)->where('is_active', true)->exists();
            if ($exists) {
                continue;
            }
            DocumentTemplate::query()->create([
                'type' => $type,
                'title' => $meta['title'],
                'content' => $meta['content'],
                'format' => 'html',
                'version' => 1,
                'is_active' => true,
                'updated_by' => $userId,
            ]);
        }

        // لا ترقيات هنا — القراءة/الطباعة/فتح الإعدادات يجب ألا تعيد كتابة قوالب منشورة.
        // الترقية لمرة واحدة: upgradeStockTemplatesIfNeeded (ترحيل أو أمر نشر).
    }

    /**
     * ترقية القوالب الافتراضية القديمة مرة واحدة — فقط الصفوف بلا updated_by (لم ينشرها مستخدم).
     * لا تُستدعى من list/getActive حتى لا تُمحى تعديلات الإعدادات بعد الحفظ.
     */
    public function upgradeStockTemplatesIfNeeded(?int $userId = null): void
    {
        if (! $this->tableReady()) {
            return;
        }

        // Soft rename for the stock v1 handover template if it still uses legacy wording.
        // Never touch a row a user has published (updated_by set) — that wiped live edits.
        DocumentTemplate::query()
            ->where('type', DocumentTemplate::TYPE_HANDOVER_RECEIPT)
            ->where('is_active', true)
            ->where('version', 1)
            ->whereNull('updated_by')
            ->where(function ($q) {
                $q->where('title', 'like', '%محضر%')
                    ->orWhere('title', 'like', '%سند%')
                    ->orWhere('content', 'like', '%سند قبض%')
                    ->orWhere('content', 'like', '%محضر التسليم%');
            })
            ->update([
                'title' => 'إقرار استلام سيارة (ظهر العقد)',
                'content' => $this->defaultHandoverHtml(),
            ]);

        $this->upgradeStockOwnerAgreementTemplate($userId);
        $this->upgradeStockContractTemplate($userId);
        $this->upgradeStockHandoverTemplate($userId);
    }

    /**
     * Replace the legacy table handover receipt with the paper-form acknowledgment
     * used on the back of the rental contract (same visual language as rpm-paper-v4).
     */
    private function upgradeStockHandoverTemplate(?int $userId = null): void
    {
        $active = DocumentTemplate::query()
            ->where('type', DocumentTemplate::TYPE_HANDOVER_RECEIPT)
            ->where('is_active', true)
            ->orderByDesc('version')
            ->first();

        if (! $active) {
            return;
        }

        // قالب نشره مستخدم من الإعدادات — ممنوع استبداله بالافتراضي (كان يمحو التعديلات عند كل طباعة/قراءة).
        if ($this->isUserPublishedTemplate($active)) {
            return;
        }

        $content = (string) $active->content;

        if (str_contains($content, 'rpm-ack-v8')) {
            return;
        }

        // Any active ack that still shows «محضر إثبات» must be replaced (even if tagged v5).
        $hasSubtitleLine = str_contains($content, 'محضر إثبات');
        $needsV8Refresh = str_contains($content, 'rpm-ack-v7')
            && ! str_contains($content, 'rpm-vehicle-value-clause');
        $needsV7Refresh = str_contains($content, 'أتعه ')
            || (str_contains($content, 'rpm-ack-v6') && ! str_contains($content, '{{vehicle_year}}'));

        // Upgrade prior shipped ack (v1–v7 / witnesses) and legacy table stubs to the Word-form text.
        $looksLikeShippedAck = $needsV8Refresh
            || $needsV7Refresh
            || $hasSubtitleLine
            || str_contains($content, 'rpm-ack-v7')
            || str_contains($content, 'rpm-ack-v6')
            || str_contains($content, 'rpm-ack-v5')
            || str_contains($content, 'rpm-ack-v4')
            || str_contains($content, 'rpm-ack-v3')
            || str_contains($content, 'rpm-ack-v2')
            || str_contains($content, 'rpm-ack-v1')
            || str_contains($content, 'rpm-ack-witnesses')
            || str_contains($content, 'إقرار الاستلام والتسليم')
            || (str_contains($content, 'عداد الخروج') && str_contains($content, 'بيانات التأمين'))
            || (str_contains($content, 'print-head') && str_contains($content, 'عند التسليم'))
            || (str_contains($content, 'إقرار استلام سيارة') && str_contains($content, 'العشرون'));

        if (! $looksLikeShippedAck) {
            return;
        }

        $this->publish([
            'type' => DocumentTemplate::TYPE_HANDOVER_RECEIPT,
            'title' => 'إقرار استلام سيارة (ظهر العقد)',
            'content' => $this->defaultHandoverHtml(),
            'format' => 'html',
        ], null, $userId);
    }

    /**
     * Replace the short stock rental-contract stub with the paper-form legal default
     * when the active row still lacks the RPM contract clauses / party structure.
     */
    private function upgradeStockContractTemplate(?int $userId = null): void
    {
        $active = DocumentTemplate::query()
            ->where('type', DocumentTemplate::TYPE_CONTRACT)
            ->where('is_active', true)
            ->orderByDesc('version')
            ->first();

        if (! $active) {
            return;
        }

        if ($this->isUserPublishedTemplate($active)) {
            return;
        }

        $content = (string) $active->content;

        if (str_contains($content, 'rpm-paper-v6')) {
            return;
        }

        // Refresh stock v5 when the vehicle-value clause is not yet optional/stripable.
        $needsV6Refresh = str_contains($content, 'rpm-paper-v5')
            && ! str_contains($content, 'rpm-vehicle-value-clause');

        // Refresh stock v4/v3 when placeholders or copy still lag the current paper form.
        $needsV5Refresh = (str_contains($content, 'rpm-paper-v4') || str_contains($content, 'rpm-lc-'))
            && (
                str_contains($content, 'أجر السيد/ (')
                || str_contains($content, 'صادرة من : <span class="fill"></span>')
                || ! str_contains($content, '{{customer_id_issued_from}}')
                || ! str_contains($content, '{{vehicle_year}}')
            );

        // Upgrade only templates we previously shipped — never overwrite a custom published body.
        $looksLikeOurPriorDefault = $needsV6Refresh
            || $needsV5Refresh
            || str_contains($content, 'rpm-paper-v5')
            || str_contains($content, 'rpm-paper-v4')
            || str_contains($content, 'rpm-paper-v3')
            || str_contains($content, 'rpm-paper-v2')
            || str_contains($content, 'rpm-legal-contract')
            || (str_contains($content, 'بنود وشروط العقد') && str_contains($content, 'عقد تأجير'))
            || (str_contains($content, 'شروط التعاقد') && str_contains($content, 'rpm-lc-'));

        if (! $looksLikeOurPriorDefault) {
            return;
        }

        $this->publish([
            'type' => DocumentTemplate::TYPE_CONTRACT,
            'title' => 'عقد إيجار سيارة (نموذج الشركة)',
            'content' => $this->defaultContractHtml(),
            'format' => 'html',
        ], null, $userId);
    }

    /**
     * Replace the short stock owner-agreement template with the multi-page legal default
     * when the active row still looks like the early one-page stub (or lacks legal articles).
     */
    private function upgradeStockOwnerAgreementTemplate(?int $userId = null): void
    {
        $active = DocumentTemplate::query()
            ->where('type', DocumentTemplate::TYPE_OWNER_AGREEMENT)
            ->where('is_active', true)
            ->orderByDesc('version')
            ->first();

        if (! $active) {
            return;
        }

        if ($this->isUserPublishedTemplate($active)) {
            return;
        }

        $content = (string) $active->content;
        $isShortStub = ! str_contains($content, 'المادة الأولى')
            || mb_strlen(strip_tags($content)) < 1200;

        if (! $isShortStub) {
            return;
        }

        $this->publish([
            'type' => DocumentTemplate::TYPE_OWNER_AGREEMENT,
            'title' => 'عقد إدارة وتشغيل مركبة (مالك ↔ شركة)',
            'content' => $this->defaultOwnerAgreementHtml(),
            'format' => 'html',
        ], null, $userId);
    }

    /**
     * @return list<DocumentTemplate>
     */
    public function listAll(): array
    {
        if (! $this->tableReady()) {
            return [];
        }
        $this->ensureDefaults();

        return DocumentTemplate::query()
            ->orderBy('type')
            ->orderByDesc('version')
            ->get()
            ->all();
    }

    /**
     * @return list<DocumentTemplate>
     */
    public function listActive(): array
    {
        if (! $this->tableReady()) {
            return [];
        }
        $this->ensureDefaults();

        return DocumentTemplate::query()
            ->where('is_active', true)
            ->orderBy('type')
            ->get()
            ->all();
    }

    public function getActive(string $type): ?DocumentTemplate
    {
        $this->assertType($type);
        if (! $this->tableReady()) {
            return null;
        }
        $this->ensureDefaults();

        return DocumentTemplate::query()
            ->where('type', $type)
            ->where('is_active', true)
            ->orderByDesc('version')
            ->first();
    }

    /**
     * Publish a new version (deactivates previous active rows of the same type).
     *
     * @param  array{type:string,title?:string,content?:string,format?:string}  $data
     */
    /** قالب نشره مستخدم من لوحة الإعدادات (له updated_by) — لا يُستبدل تلقائياً. */
    private function isUserPublishedTemplate(DocumentTemplate $template): bool
    {
        return $template->updated_by !== null;
    }

    public function publish(array $data, ?UploadedFile $file = null, ?int $userId = null): DocumentTemplate
    {
        if (! $this->tableReady()) {
            throw new DomainException('جدول قوالب المستندات غير جاهز. نفّذ ترحيل قاعدة البيانات أولاً.');
        }

        $type = $data['type'];
        $this->assertType($type);

        $format = $data['format'] ?? 'html';
        $content = (string) ($data['content'] ?? '');
        $hasEditorContent = trim($content) !== '';
        $storagePath = null;

        if ($file) {
            $ext = strtolower((string) $file->getClientOriginalExtension());
            if (! in_array($ext, ['docx', 'html', 'htm', 'md', 'txt'], true)) {
                throw new DomainException('يُسمح بملفات docx أو html أو markdown فقط.');
            }
            $disk = (string) config('uploads.disk', 'local');
            $name = now()->format('YmdHis').'_'.Str::lower(Str::random(10)).'.'.$ext;
            $relative = 'templates/'.$name;
            Storage::disk($disk)->putFileAs('templates', $file, $name);
            $storagePath = $relative;

            // إن وُجد محتوى من المحرر في الطلب نُبقيه — ملف الاستبدال لا يطغى عليه (كان يمحو تعديلات الإعدادات).
            if (! $hasEditorContent) {
                if ($ext === 'docx') {
                    $absolute = Storage::disk($disk)->path($relative);
                    $extracted = $this->extractDocxToHtml($absolute);
                    if ($extracted !== '') {
                        $content = $extracted;
                    }
                    $format = 'docx';
                } elseif (in_array($ext, ['md', 'txt'], true)) {
                    $content = (string) file_get_contents(Storage::disk($disk)->path($relative));
                    $format = 'markdown';
                } else {
                    $content = (string) file_get_contents(Storage::disk($disk)->path($relative));
                    $format = 'html';
                }
            }
        }

        if (trim($content) === '') {
            throw new DomainException('محتوى القالب مطلوب.');
        }

        if (! in_array($format, ['html', 'markdown', 'docx'], true)) {
            throw new DomainException('صيغة القالب غير مدعومة.');
        }

        return DB::transaction(function () use ($type, $data, $content, $format, $storagePath, $userId) {
            $latest = DocumentTemplate::query()->where('type', $type)->lockForUpdate()->orderByDesc('version')->first();
            $nextVersion = ($latest?->version ?? 0) + 1;

            DocumentTemplate::query()->where('type', $type)->where('is_active', true)->update(['is_active' => false]);

            return DocumentTemplate::query()->create([
                'type' => $type,
                'title' => trim((string) ($data['title'] ?? $this->defaultTitle($type))),
                'content' => $content,
                'format' => $format,
                'storage_path' => $storagePath,
                'version' => $nextVersion,
                'is_active' => true,
                'updated_by' => $userId,
            ]);
        });
    }

    /**
     * @param  array<string, string|int|float|null>  $variables
     */
    public function render(string $content, string $format, array $variables): string
    {
        $html = $format === 'markdown' ? $this->markdownToHtml($content) : $content;

        return $this->substitute($html, $variables);
    }

    /**
     * Render a contract/ack template and strip optional vehicle-value clause blocks when disabled.
     *
     * @param  array<string, string>  $variables
     */
    public function renderForContract(Contract $contract, DocumentTemplate $template, array $variables): string
    {
        $html = $this->render($template->content, $template->format, $variables);
        if (! (bool) ($contract->include_vehicle_value_obligation ?? false)) {
            $html = $this->stripVehicleValueClauses($html);
        }

        return $html;
    }

    public function stripVehicleValueClauses(string $html): string
    {
        $stripped = preg_replace(
            '/<li\b[^>]*\brpm-vehicle-value-clause\b[^>]*>.*?<\/li>/is',
            '',
            $html
        );
        $stripped = preg_replace(
            '/<(span|p|div)\b[^>]*\brpm-vehicle-value-clause\b[^>]*>.*?<\/\1>/is',
            '',
            (string) $stripped
        );

        return (string) $stripped;
    }

    /**
     * @return array{html:string,variables:array<string,string>,template:DocumentTemplate}
     */
    public function preview(string $type, ?array $overrideVariables = null): array
    {
        $template = $this->getActive($type);
        if (! $template) {
            throw new DomainException('لا يوجد قالب نشط لهذا النوع.');
        }

        $variables = array_merge($this->sampleVariables($template->version), $overrideVariables ?? []);
        $html = $this->render($template->content, $template->format, $variables);

        return [
            'html' => $html,
            'variables' => $variables,
            'template' => $template,
        ];
    }

    /**
     * Build substitution map from a contract + tenant settings.
     *
     * @return array<string, string>
     */
    public function variablesForContract(Contract $contract, ?TenantSetting $tenant = null, ?DocumentTemplate $template = null): array
    {
        $tenant ??= TenantSetting::query()->first();
        $ops = is_array($tenant?->operational) ? $tenant->operational : [];
        $contract->loadMissing(['customer', 'vehicle', 'deposit', 'inspections']);

        $handover = $contract->inspections->firstWhere('type', 'handover');
        $ret = $contract->inspections->firstWhere('type', 'return');
        $vehicle = $contract->vehicle;
        $customer = $contract->customer;
        $deposit = $contract->deposit;

        $tz = (string) config('app.timezone', 'Africa/Cairo');
        $dayNames = ['الأحد', 'الإثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت'];
        $inTz = static function ($value) use ($tz) {
            if ($value === null) {
                return null;
            }

            return $value->copy()->timezone($tz);
        };

        // «إنه في يوم … الساعة» = الوقت الحالي بتوقيت مصر عند الطباعة/العرض.
        $issuedAt = now($tz);

        $durationDays = null;
        if ($contract->starts_at && $contract->planned_ends_at) {
            $durationDays = max(1, (int) $contract->starts_at->copy()->timezone($tz)->startOfDay()
                ->diffInDays($contract->planned_ends_at->copy()->timezone($tz)->startOfDay()) ?: 1);
        }

        $nationalId = (string) ($customer?->national_id ?: '');
        $passport = (string) ($customer?->passport_number ?: '');
        $nationality = trim((string) ($customer?->nationality ?: ''));
        // لا نفرض «مصري» إلا عند غياب الجنسية مع رقم قومي مصري من 14 رقمًا.
        if ($nationality === '' && preg_match('/^\d{14}$/', $nationalId) === 1) {
            $nationality = 'مصري';
        }
        $idDoc = $nationalId !== '' ? $nationalId : $passport;
        $idIssuedOn = $customer?->national_id_issued_on;

        $signer = $this->resolvePrintSignerName($ops);
        $deliveryOn = (bool) $contract->has_delivery_service;
        $deliveryMark = $deliveryOn ? '☑' : '☐';

        $startsAt = $inTz($contract->starts_at);
        $endsAt = $inTz($contract->planned_ends_at);
        $actualEndsAt = $inTz($contract->actual_ends_at);
        $handoverAt = $inTz($handover?->inspected_at);
        $returnAt = $inTz($ret?->inspected_at) ?? $actualEndsAt;
        $dailyKm = (int) ($contract->frozen_daily_km_allowance ?? $ops['default_daily_km'] ?? 0);
        $includeVehicleValue = (bool) ($contract->include_vehicle_value_obligation ?? false);
        $vehicleValueStr = $includeVehicleValue
            ? trim((string) ($contract->frozen_vehicle_value_text ?? ''))
            : '';

        $map = [
            'company_name' => $this->resolveCompanyName($tenant?->center_name),
            'company_phone' => (string) ($ops['print_phone'] ?? ''),
            'company_address' => (string) ($ops['print_address'] ?? ''),
            'company_cr' => (string) ($ops['print_commercial_register'] ?? ''),
            'company_tax' => (string) ($ops['print_tax_info'] ?? ''),
            'logo_src' => (string) ($tenant?->logo_path ?: '/brand/rpm-logo.webp'),
            'print_footer' => (string) ($tenant?->print_footer ?: ''),
            'print_signer_name' => $signer,
            'company_tagline' => (string) ($ops['company_tagline'] ?? 'لخدمات رجال الأعمال'),
            'contract_terms' => (string) ($ops['contract_terms'] ?? ''),
            'delivery_address' => (string) ($contract->delivery_service_notes ?: ''),
            'delivery_pickup_mark' => $deliveryMark,
            'delivery_dropoff_mark' => $deliveryMark,
            'contract_number' => (string) ($contract->number ?: ('C-'.$contract->id)),
            'contract_status' => (string) $contract->status,
            'contract_day' => $dayNames[(int) $issuedAt->dayOfWeek] ?? '',
            'contract_date' => $issuedAt->format('d/m/Y'),
            'contract_time' => $issuedAt->format('H:i'),
            'start_day' => $startsAt ? ($dayNames[(int) $startsAt->dayOfWeek] ?? '') : '',
            'start_date_only' => $startsAt?->format('d/m/Y') ?? '',
            'start_time' => $startsAt?->format('H:i') ?? '',
            'end_day' => $endsAt ? ($dayNames[(int) $endsAt->dayOfWeek] ?? '') : '',
            'end_date_only' => $endsAt?->format('d/m/Y') ?? '',
            'end_time' => $endsAt?->format('H:i') ?? '',
            'annual_km' => $dailyKm > 0 ? (string) ($dailyKm * 365) : '',
            'km_extra_after' => '1000',
            'rideshare_penalty' => '50000',
            'customer_name' => (string) ($customer?->full_name ?: ''),
            'customer_phone' => (string) ($customer?->phone ?: ''),
            'customer_national_id' => $nationalId,
            'customer_id_doc' => $idDoc,
            'customer_id_issued_from' => (string) ($customer?->national_id_issued_from ?: ''),
            'customer_id_issued_on' => $idIssuedOn ? CivilDate::formatMonthYear($idIssuedOn, '') : '',
            'customer_nationality' => $nationality,
            'customer_address' => (string) ($customer?->address ?: ''),
            'customer_license' => (string) ($customer?->license_number ?: ''),
            'customer_license_issued_from' => (string) ($customer?->license_issued_from ?: ''),
            'national_id_front' => (string) ($customer?->national_id_image_path ?: ''),
            'national_id_back' => (string) ($customer?->national_id_back_path ?: ''),
            'vehicle_plate' => (string) ($vehicle?->plate ?: ''),
            'vehicle_name' => trim(($vehicle?->brand ?? '').' '.($vehicle?->model ?? '')),
            'vehicle_brand' => (string) ($vehicle?->brand ?: ''),
            'vehicle_model' => (string) ($vehicle?->model ?: ''),
            'vehicle_year' => (string) ($vehicle?->year ?: ''),
            'vehicle_chassis' => (string) ($vehicle?->chassis_number ?: ''),
            'vehicle_engine' => (string) ($vehicle?->engine_number ?: ''),
            'vehicle_odometer' => (string) ($handover?->odometer ?? $contract->odometer_out ?? $vehicle?->odometer ?? ''),
            'vehicle_value' => $vehicleValueStr,
            'start_date' => $startsAt?->format('d/m/Y H:i') ?? '',
            'end_date' => $endsAt?->format('d/m/Y H:i') ?? '',
            'actual_end_date' => $actualEndsAt?->format('d/m/Y H:i') ?? '',
            'rental_duration' => $durationDays === null ? '' : ($durationDays === 1 ? 'يوم واحد' : $durationDays.' أيام'),
            'daily_rate' => (string) (int) $contract->frozen_daily_rate,
            // إن وُجد {{vehicle_daily_rate}} في قالب عقد الإيجار نعرض سعر العقد المثبّت لا سعر العربية الافتراضي.
            'vehicle_daily_rate' => (string) (int) $contract->frozen_daily_rate,
            'daily_km' => (string) $dailyKm,
            'excess_km_rate' => (string) (int) ($contract->frozen_excess_km_rate ?? $ops['excess_km_rate'] ?? 0),
            'deposit' => (string) (int) ($deposit?->original_amount ?? $contract->frozen_agreed_deposit ?? 0),
            'deposit_currency' => DepositCurrency::normalize($contract->frozen_deposit_currency ?? null),
            'deposit_status' => (string) ($deposit?->status ?? ''),
            'deposit_remaining' => (string) (int) ($deposit ? $deposit->remaining() : ($contract->frozen_agreed_deposit ?? 0)),
            'deposit_deductions' => (string) (int) ($deposit?->deductions_total ?? 0),
            'final_total' => $contract->final_total !== null ? (string) (int) $contract->final_total : '',
            'odometer_out' => (string) ($handover?->odometer ?? $contract->odometer_out ?? ''),
            'odometer_in' => (string) ($ret?->odometer ?? $contract->odometer_in ?? ''),
            'handover_at' => $handoverAt?->format('d/m/Y H:i') ?? '',
            'return_at' => $returnAt?->format('d/m/Y H:i') ?? '',
            'template_version' => (string) ($template?->version ?? ''),
        ];

        return $this->withAliases($map);
    }

    /**
     * @return array<string, string>
     */
    public function sampleVariables(int $version = 1): array
    {
        $map = [];
        foreach ($this->placeholderCatalog() as $row) {
            $map[$row['key']] = $row['sample'];
        }
        $map['template_version'] = (string) $version;

        return $this->withAliases($map);
    }

    /**
     * @param  array<string, string>  $map
     * @return array<string, string>
     */
    public function withAliases(array $map): array
    {
        $out = $map;
        foreach ($this->placeholderCatalog() as $row) {
            $value = (string) ($map[$row['key']] ?? '');
            $out[$row['key']] = $value;
            foreach ($row['aliases'] as $alias) {
                $out[$alias] = $value;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, string|int|float|null>  $variables
     */
    public function substitute(string $content, array $variables): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_\x{0600}-\x{06FF}]+)\s*\}\}/u',
            function (array $m) use ($variables) {
                $key = $m[1];
                if (! array_key_exists($key, $variables)) {
                    return $m[0];
                }
                $raw = (string) ($variables[$key] ?? '');

                return htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            },
            $content
        );
    }

    public function extractDocxToHtml(string $absolutePath): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new DomainException('استخراج ملفات Word غير متاح على هذا السيرفر.');
        }
        $zip = new ZipArchive;
        if ($zip->open($absolutePath) !== true) {
            throw new DomainException('تعذّر قراءة ملف Word المرفوع.');
        }
        $xml = $zip->getFromName('word/document.xml') ?: '';
        $zip->close();
        if ($xml === '') {
            return '';
        }

        $xml = preg_replace('/<w:tab[^\/]*\/>/', "\t", $xml) ?? $xml;
        $xml = preg_replace('/<w:br[^\/]*\/>/', "\n", $xml) ?? $xml;
        $xml = preg_replace('/<\/w:p>/', "\n\n", $xml) ?? $xml;
        $plain = trim(html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $plain = preg_replace("/[ \t]+/u", ' ', $plain) ?? $plain;
        $lines = preg_split("/\n+/", $plain) ?: [];

        return collect($lines)
            ->map(fn ($l) => trim($l))
            ->filter()
            ->map(fn ($l) => '<p>'.e($l).'</p>')
            ->implode("\n");
    }

    public function markdownToHtml(string $markdown): string
    {
        $escaped = htmlspecialchars($markdown, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $escaped = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $escaped) ?? $escaped;
        $escaped = preg_replace('/\*(.+?)\*/s', '<em>$1</em>', $escaped) ?? $escaped;
        $parts = preg_split("/\n{2,}/", $escaped) ?: [];

        return collect($parts)
            ->map(fn ($p) => '<p>'.nl2br(trim($p), false).'</p>')
            ->implode("\n");
    }

    public function present(DocumentTemplate $template): array
    {
        return [
            'id' => $template->id,
            'type' => $template->type,
            'title' => $template->title,
            'content' => $template->content,
            'format' => $template->format,
            'storage_path' => $template->storage_path,
            'version' => $template->version,
            'is_active' => $template->is_active,
            'updated_by' => $template->updated_by,
            'updated_at' => $template->updated_at?->toIso8601String(),
        ];
    }

    private function assertType(string $type): void
    {
        if (! in_array($type, DocumentTemplate::allowedTypes(), true)) {
            throw new DomainException('نوع القالب غير مدعوم.');
        }
    }

    /**
     * @return array<string, string>
     */
    public function variablesForOwnerAgreement(Vehicle $vehicle, ?TenantSetting $tenant = null, ?DocumentTemplate $template = null): array
    {
        $tenant ??= TenantSetting::query()->first();
        $ops = is_array($tenant?->operational) ? $tenant->operational : [];
        $vehicle->loadMissing('owner');
        $owner = $vehicle->owner;
        $number = 'OWN-'.($vehicle->code ?: ('V'.$vehicle->id));

        $map = [
            'company_name' => (string) ($tenant?->center_name ?: 'شركة التأجير'),
            'company_phone' => (string) ($ops['print_phone'] ?? ''),
            'company_address' => (string) ($ops['print_address'] ?? ''),
            'company_cr' => (string) ($ops['print_commercial_register'] ?? ''),
            'company_tax' => (string) ($ops['print_tax_info'] ?? ''),
            'logo_src' => (string) ($tenant?->logo_path ?: '/brand/rpm-logo.webp'),
            'print_footer' => (string) ($tenant?->print_footer ?: ''),
            'contract_terms' => (string) ($ops['owner_agreement_terms'] ?? $ops['contract_terms'] ?? ''),
            'owner_name' => (string) ($owner?->full_name ?: ''),
            'owner_phone' => (string) ($owner?->phone ?: ''),
            'owner_phone_alt' => (string) ($owner?->phone_alt ?: ''),
            'owner_national_id' => (string) ($owner?->national_id ?: ''),
            'owner_nationality' => trim((string) ($owner?->nationality ?: '')) !== ''
                ? (string) $owner->nationality
                : ((string) ($owner?->national_id ?: '') !== '' ? 'مصري' : ''),
            'owner_monthly_amount' => (string) (int) ($vehicle->owner_monthly_amount ?? 0),
            'owner_agreement_number' => $number,
            'owner_agreement_starts_on' => ($vehicle->owner_agreement_starts_on ?? $vehicle->owner_since)
                ? ($vehicle->owner_agreement_starts_on ?? $vehicle->owner_since)->format('d/m/Y')
                : '',
            'owner_agreement_ends_on' => $vehicle->owner_agreement_ends_on?->format('d/m/Y') ?? '',
            'agreement_date' => now()->format('d/m/Y'),
            'vehicle_plate' => (string) ($vehicle->plate ?: ''),
            'vehicle_name' => trim(($vehicle->brand ?? '').' '.($vehicle->model ?? '')),
            'vehicle_code' => (string) ($vehicle->code ?: ''),
            'vehicle_year' => (string) ($vehicle->year ?: ''),
            'vehicle_category' => (string) ($vehicle->category ?: ''),
            'vehicle_color' => (string) ($vehicle->color ?: ''),
            'vehicle_fuel' => (string) ($vehicle->fuel_type ?: ''),
            'vehicle_transmission' => (string) ($vehicle->transmission ?: ''),
            'vehicle_chassis' => (string) ($vehicle->chassis_number ?: ''),
            'vehicle_engine' => (string) ($vehicle->engine_number ?: ''),
            'vehicle_odometer' => (string) (int) ($vehicle->odometer ?? 0),
            'vehicle_daily_rate' => (string) (int) ($vehicle->daily_rate ?? 0),
            'vehicle_deposit' => (string) (int) ($vehicle->default_deposit ?? 0),
            'template_version' => (string) ($template?->version ?? ''),
        ];

        return $this->withAliases($map);
    }

    private function defaultTitle(string $type): string
    {
        return match ($type) {
            DocumentTemplate::TYPE_CONTRACT => 'عقد إيجار سيارة (نموذج الشركة)',
            DocumentTemplate::TYPE_HANDOVER_RECEIPT => 'إقرار استلام سيارة (ظهر العقد)',
            DocumentTemplate::TYPE_OWNER_AGREEMENT => 'عقد إدارة وتشغيل مركبة (مالك ↔ شركة)',
            DocumentTemplate::TYPE_CUSTODY_AGREEMENT => 'قالب عقد الاعتماد',
            default => 'قالب مستند',
        };
    }

    /** @param  array<string, mixed>|null  $ops */
    private function resolvePrintSignerName(?array $ops): string
    {
        $signer = trim((string) ($ops['print_signer_name'] ?? ''));
        $legacyWrong = [
            'محمد السيد عبد القادر',
            'أ/ محمد السيد عبد القادر',
            'أ/ محمد عبدالقادر',
            'أ/ محمد عبد القادر',
            'محمد عبدالقادر',
        ];

        if ($signer === '' || in_array($signer, $legacyWrong, true)) {
            return 'محمد عبد القادر محمد';
        }

        return $signer;
    }

    private function resolveCompanyName(?string $centerName): string
    {
        $name = trim((string) $centerName);
        $legacyDefaults = [
            'شركة آر بي إم للتأجير',
            'آر بي إم للتأجير و النقل و السياحة',
            'آر بي إم للتأجير والنقل والسياحة',
            'مركز آر بي إم للتأجير',
            'آر - بي - إم للنقل والسياحة',
        ];

        if ($name === '' || in_array($name, $legacyDefaults, true)) {
            return 'شركة آر بي إم للنقل والسياحة';
        }

        return $name;
    }

    private function defaultContractHtml(): string
    {
        return <<<'HTML'
<article class="rpm-legal-contract rpm-paper-v6">
  <header class="rpm-lc-head">
    <div class="rpm-lc-badge rpm-lc-badge--contact">
      <div class="rpm-lc-signer-short">أ/ {{print_signer_name}}</div>
      <div class="rpm-lc-phone-pill">
        <span class="rpm-lc-phone-dot" aria-hidden="true">
          <svg class="rpm-lc-ico-phone" viewBox="0 0 24 24"><path fill="#c40000" d="M6.6 10.8c1.4 2.8 3.8 5.1 6.6 6.6l2.2-2.2c.3-.3.7-.4 1.1-.2 1.2.4 2.5.6 3.8.6.6 0 1 .4 1 1V20c0 .6-.4 1-1 1C10.6 21 3 13.4 3 4c0-.6.4-1 1-1h3.5c.6 0 1 .4 1 1 0 1.3.2 2.6.6 3.8.1.4 0 .8-.3 1.1L6.6 10.8z"/></svg>
        </span>
        <span dir="ltr">/ {{company_phone}}</span>
      </div>
    </div>
    <div class="rpm-lc-badge rpm-lc-badge--title">
      <div class="rpm-lc-title-row">
        <svg class="rpm-lc-ico-handshake" viewBox="0 0 72 44" aria-hidden="true">
          <path fill="#fff" d="M10 28c0-4.5 3.5-8 8-8h6.5l8.5-8.5 4.2 4.2-6.8 6.8H38c2.8 0 5 2.2 5 5v4.5H10V28z"/>
          <path fill="#fff" d="M62 28c0-4.5-3.5-8-8-8h-5l-3.8-3.8-4.2 4.2 5.5 5.5H42c-2.8 0-5 2.2-5 5v4.5h25V28z"/>
          <rect x="22" y="25" width="28" height="8" rx="2.5" fill="#e10600"/>
          <rect x="24" y="27.2" width="24" height="3.5" rx="1.2" fill="#fff"/>
        </svg>
        <span>عقد إيجار سيارة</span>
      </div>
    </div>
    <div class="rpm-lc-badge rpm-lc-badge--brand">
      <div class="rpm-lc-brand-mark">RPM<span class="rpm-lc-swoosh-thru" aria-hidden="true"></span></div>
      <div class="rpm-lc-tagline">{{company_tagline}}</div>
    </div>
  </header>

  <p class="rpm-lc-meta">{{contract_number}}</p>

  <section class="rpm-lc-block">
    <p>
      إنه في يوم : <span class="fill">{{contract_day}}</span>
      الموافق : <span class="fill mid">{{contract_date}}</span> م ،
      الساعة : <span class="fill">{{contract_time}}</span> .
    </p>
    <p>
      أجر السيد / <strong>{{company_name}}</strong> ،
      ويمثله في التوقيع - <strong>{{print_signer_name}}</strong>
      - سجل تجاري / <span class="fill" dir="ltr">{{company_cr}}</span> .
    </p>
    <p>ومقرها/ <span class="fill wide">{{company_address}}</span> .</p>
    <div class="rpm-lc-party-tag">( الطرف الأول المؤجر )</div>
  </section>

  <section class="rpm-lc-block">
    <p class="rpm-lc-row">
      إلى السيد/ <span class="fill wide">{{customer_name}}</span>
      بطاقة الرقم القومي : <span class="fill mid" dir="ltr">{{customer_national_id}}</span>
      صادرة من : <span class="fill">{{customer_id_issued_from}}</span>
      تاريخ الصدور : <span class="fill">{{customer_id_issued_on}}</span>
      الجنسية : <span class="fill">{{customer_nationality}}</span>
    </p>
    <p class="rpm-lc-row">
      رخصة رقم : <span class="fill mid" dir="ltr">{{customer_license}}</span>
      صادرة من : <span class="fill">{{customer_license_issued_from}}</span>
      محل الإقامة : <span class="fill wide">{{customer_address}}</span>
      موبايل : <span class="fill mid" dir="ltr">{{customer_phone}}</span>
    </p>
    <div class="rpm-lc-party-tag">( الطرف الثاني المستأجر )</div>
  </section>

  <section class="rpm-lc-block rpm-lc-block--icon">
    <div class="rpm-lc-float-ico rpm-lc-float-ico--car" aria-hidden="true">
      <svg viewBox="0 0 64 40" fill="none">
        <path fill="#fff" d="M10 23l5.5-9h26l8 9H10z"/>
        <path fill="#fff" d="M8 23h48v5.5H8z"/>
        <circle cx="18" cy="30" r="4.2" fill="#111" stroke="#fff" stroke-width="2"/>
        <circle cx="46" cy="30" r="4.2" fill="#111" stroke="#fff" stroke-width="2"/>
        <path fill="#fff" d="M22 16.5h8.5l-2.2-3.5H24z"/>
      </svg>
    </div>
    <p>
      أجر الطرف الأول إلى الطرف الثاني السيارة رقم : <span class="fill">{{vehicle_plate}}</span> ،
      ماركة : <span class="fill">{{vehicle_brand}}</span> ،
      موديل : <span class="fill">{{vehicle_model}}</span> ،
      سنة الصنع : <span class="fill">{{vehicle_year}}</span> ،
    </p>
    <p>
      رقم الموتور : <span class="fill" dir="ltr">{{vehicle_engine}}</span> ،
      رقم الشاسية : <span class="fill" dir="ltr">{{vehicle_chassis}}</span> ،
      عداد الكيلو متر : <span class="fill">{{vehicle_odometer}}</span> .
    </p>
  </section>

  <section class="rpm-lc-block rpm-lc-block--icon">
    <div class="rpm-lc-float-ico rpm-lc-float-ico--cal" aria-hidden="true">
      <svg viewBox="0 0 24 24" fill="none">
        <rect x="3" y="5" width="18" height="16" rx="2.2" stroke="#fff" stroke-width="2"/>
        <path stroke="#fff" stroke-width="2" d="M3 10h18M8 3v4M16 3v4"/>
        <path fill="#fff" d="M7 13h3.2v3.2H7zm5 0h3.2v3.2H12z"/>
      </svg>
    </div>
    <p>
      وذلك لمدة : <span class="fill">{{rental_duration}}</span>
      إبتداءً من : <span class="fill mid">{{start_date}}</span>
      إلى : <span class="fill mid">{{end_date}}</span>
    </p>
    <p style="text-align:center">. قابلة للتجديد بموافقة الطرفين .</p>
    <p class="rpm-lc-delivery">
      خدمة توصيل — العنوان : <span class="fill wide">{{delivery_address}}</span>
      <span class="rpm-lc-check">{{delivery_pickup_mark}} استلام</span>
      <span class="rpm-lc-check">{{delivery_dropoff_mark}} تسليم</span>
    </p>
  </section>

  <div class="rpm-lc-terms-wrap">
    <div class="rpm-lc-terms-banner"><span>شروط التعاقد</span></div>
    <ol class="rpm-lc-terms">
      <li><span class="rpm-lc-band">البند الأول</span> تم دفع تأمين للسيارة مبلغ وقدره ( <span class="fill">{{deposit}}</span> ) <span class="fill">{{deposit_currency}}</span> يسترد للمستأجر عند إستلام السيارة بدون أي تلفيات أو مخالفات .</li>
      <li><span class="rpm-lc-band">البند الثاني</span> في حالة رغبة الطرف الثاني تجديد مدة الإيجار عليه أن يخطر الطرف الأول قبل انتهاء الإيجار بيوم واحد ، وإذا تأخر المستأجر عن التسليم كحد أقصى ، ساعتين ، تحسب يوم جديد ، ويتم سحب السيارة دون تنبيه على المستأجر إذا تغيب الطرف الثاني لمدة 24 ساعة .</li>
      <li><span class="rpm-lc-band">البند الثالث</span> معدل أستخدام السيارة لا يتعدي ( <span class="fill">{{daily_km}}</span> ) كيلو متر في اليوم الواحد ، ما يزيد عن ذلك يحسب بعد كل كيلو متر مبلغ ( <span class="fill">{{excess_km_rate}}</span> ) .</li>
      <li><span class="rpm-lc-band">البند الرابع</span> من حق الطرف الأول سحب السيارة من الطرف الثاني إذا وجدت بقيادة شخص آخر أو تم أستخدام السيارة إستخدام غير شرعي أو بطريقة غير قانونية ولا يحق للطرف الثاني إسترداد باقي مبلغ الإيجار .</li>
      <li><span class="rpm-lc-band">البند الخامس</span> إذا حدث لقدر الله أي حادث للسيارة فعلى الطرف الثاني أن يرجع للطرف الأول قبل أن يقوم بأي تصليح للسيارة ، مع العلم أن فترة توقف السيارة وقت الإصلاح على عاتق الطرف الثاني المستأجر وبنفس قيمة الإيجار اليومي المتفق عليها .</li>
      <li class="rpm-vehicle-value-clause"><span class="rpm-lc-band">البند السادس</span> في حالة عدم إعادة السيارة أو حدوث حادث جسيم للسيارة ولا يمكن إصلاحها بأي حال من الأحوال ، فالطرف الثاني يكون ملزم بدفع ثمن السيارة : ( <span class="fill wide">{{vehicle_value}}</span> ) .</li>
      <li><span class="rpm-lc-band">البند السابع</span> يقر الطرف الثاني بأنه إستلم السيارة بصفة أمانة وهي في حالة جيدة وأصبح مسؤولا عنها جنائيا ومدنيا وعن كل الأضرار التي تسببها هذه السيارة للغير وأيضا عن جميع المخالفات ، وكذلك يقر أنه على علم تام نافية للجهالة بجميع شروط وقوانين وتعليمات المرور والأمن العام ، كما يلتزم المستأجر في حالة حدوث سرقات أو أي جريمة جنائية تقع بسبب الطرف الثاني ( المستأجر ) أن يتحمل عقوبتها وحده دون أدنى مسؤولية على الطرف الأول المؤجر .</li>
      <li><span class="rpm-lc-band">البند الثامن</span> يلتزم الطرف الثاني بأنه ينفذ تعليمات المرور من حيث السرعة داخل الجمهورية والطرق الصحراوية والزراعية ، كما يلتزم بدفع قيمة المخالفات المرورية خلال فترة الإيجار وحتى تسليم السيارة وهي 400 للسرعة و 200 حزام أمان واستخدام الهاتف .</li>
      <li><span class="rpm-lc-band">البند التاسع</span> يلتزم المستأجر بدفع قيمة أربعة أيام إيجار في حالة سحب رخصة السيارة أو فقدها حتى يتم إسترداد الرخصة .</li>
    </ol>
  </div>

  <section class="rpm-lc-extra-terms" data-optional-terms="1">
    <div class="terms">{{contract_terms}}</div>
  </section>

  <footer class="rpm-lc-sign">
    <div class="rpm-lc-sign-col">
      <div class="rpm-lc-party-tag">الطرف الثاني ( المستأجر )</div>
      <p>الإسم : <span class="fill wide">{{customer_name}}</span></p>
      <p>الرقم القومي : <span class="fill mid" dir="ltr">{{customer_national_id}}</span></p>
      <p class="rpm-lc-sign-line">التوقيع : ____________________</p>
    </div>
    <div class="rpm-lc-stamp">
      <div class="rpm-lc-stamp-ring">
        <svg class="rpm-lc-stamp-car" viewBox="0 0 64 28" aria-hidden="true">
          <path fill="#111" d="M8 18l6-9h28l8 9H8z"/>
          <path fill="#111" d="M6 18h52v4H6z"/>
          <circle cx="16" cy="23" r="3.2" fill="#111"/>
          <circle cx="48" cy="23" r="3.2" fill="#111"/>
          <path fill="none" stroke="#111" stroke-width="1.4" d="M10 26c8 2 36 2 44 0"/>
        </svg>
        <div class="stamp-rpm">RPM</div>
        <div class="stamp-en">Rent A Car</div>
        <div class="stamp-ar-box">{{company_tagline}}</div>
        <div class="stamp-cr">سجل تجاري رقم {{company_cr}}</div>
        <div class="stamp-stars" aria-hidden="true">★★★★★</div>
      </div>
    </div>
    <div class="rpm-lc-sign-col">
      <div class="rpm-lc-party-tag">الطرف الأول ( المؤجر )</div>
      <p>الإسم : {{company_name}}</p>
      <p>ويمثله / {{print_signer_name}}</p>
      <p class="rpm-lc-sign-line">التوقيع : ____________________</p>
    </div>
  </footer>

  <p class="foot">{{print_footer}}</p>
</article>
HTML;
    }

    private function defaultHandoverHtml(): string
    {
        // Texts match the customer Word form (إقرار استلام سيارة) exactly — including typos; blanks are placeholders.
        return <<<'HTML'
<article class="rpm-ack-form rpm-ack-v8" dir="rtl">
  <p class="rpm-ack2-brandhead">RPM &nbsp;•&nbsp; RENT A CAR &nbsp;|&nbsp; شركة RBM للنقل والسياحة</p>
  <div class="rpm-ack2-seal-top-wrap" data-rpm-lock="1">
    <img class="rpm-ack2-seal rpm-ack2-seal--top" src="/brand/rpm-ack-seal-gold.png" width="120" height="120" alt="" />
  </div>

  <h1 class="rpm-ack2-title">إقرار استلام سيارة</h1>

  <table class="rpm-ack2-date">
    <tr>
      <th>تحريرا في يوم</th>
      <td>
        <span class="fill mid" dir="ltr">{{contract_date}}</span>
        &nbsp; الموافق :
        <span class="fill">{{contract_day}}</span>
        ، الساعة :
        <span class="fill">{{contract_time}}</span>
      </td>
    </tr>
  </table>

  <p class="rpm-ack2-line rpm-ack2-line--strong">
    أقر أنا الموقع أدناه /
    <span class="fill wide">{{customer_name}}</span>
    ، بطاقة رقم قومي / جواز السفر /
    <span class="fill mid" dir="ltr">{{customer_id_doc}}</span>
    . الجنسية /
    <span class="fill">{{customer_nationality}}</span>
    محل إقامة المستأجر /
    <span class="fill wide">{{customer_address}}</span>
  </p>

  <p class="rpm-ack2-line rpm-ack2-line--strong">
    بأنني تسلمت من السيد / RPM للنقل والسياحة &nbsp;- ويمثلها السيد /
    <span class="fill wide">{{print_signer_name}}</span>
    .
  </p>

  <p class="rpm-ack2-line rpm-ack2-line--strong">
    سيارة ماركة /
    <span class="fill">{{vehicle_brand}}</span>
    ، موديل/
    <span class="fill">{{vehicle_model}}</span>
    ، سنة الصنع /
    <span class="fill">{{vehicle_year}}</span>
    ، رقم الشاسيه /
    <span class="fill mid" dir="ltr">{{vehicle_chassis}}</span>
    رقم الموتور /
    <span class="fill mid" dir="ltr">{{vehicle_engine}}</span>
    ، لوحات معدنية /
    <span class="fill">{{vehicle_plate}}</span>
  </p>

  <p class="rpm-ack2-body">
    أقرّ أنا الموقّع أدناه بأنني إستلمت السيارة الموضحة بياناتها أعلاه من شركة RPM للنقل والسياحة ، وعاينت حالتها الظاهرة وإطلعت على قراءة العداد ومستوى الوقود/الشحن وما سُلّم معها من مفاتيح ووثائق وملحقات ، وأكون مسئولا مسئولية كاملة مدنيا وجنائيا عن السيارة موضوع الإقرار وعن كافة ما يلحق بالسيارة من تلف أو هلاك أو سرقة أو إصابتها بأي أضرار يقلل من قيمتها السعرية أو أي مخالفة لقواعد وقوانين المرور كما أتعهد أيضا بتسليمها بنفس الحالة الجيدة الخالية من أي أعطال أو عيوب أو نقص في الإجراء كما أستلمتها بناءا علي تصوير فيديو تم بوجود الطرفين وذلك في مدة تبدأ بتاريخ { <span class="fill mid">{{start_date_only}}</span> } ، وتنتهي في { <span class="fill mid">{{end_date_only}}</span> } عند تسليم السيارة أو عند الطلب قبل المؤجر وفي حالة أمتناعي عن ذلك أكون خائنا للأمانة<span class="rpm-vehicle-value-clause"> وملزم بسداد ثمن السيارة المذكور ، وهو ( <span class="fill wide">{{vehicle_value}}</span> ) ويكون هذا المقدار حسب أسعار مثلها في المعارض وأسواق السيارات</span> وذلك دون الحاجة إلي تنبيه أو إنذار وعدم الإخلال بحق التعويضات فضلا عن حق المالك أو المؤجر في مقاضاتي قانونيا وبوصفه مرتكبا جريمة خيانة الأمانة المعاقب عليها قانونيا ، ويكون ذلك من إختصاص المحاكم المختصة كما أتعهد بأنه لا يحق بيع السيارة أو تغيير أي شيء من معالمها الأساسية .
  </p>

  <div class="rpm-ack2-close">
    <div class="rpm-ack2-finger-row">
      <div class="rpm-ack2-finger-block">
        <div class="rpm-ack2-finger-oval" aria-hidden="true"></div>
        <p class="rpm-ack2-finger-label">البصمة</p>
      </div>
      <p class="rpm-ack2-finger-end">وهذا إقرار مني بذلك</p>
    </div>
    <div class="rpm-ack2-signs">
      <p class="rpm-ack2-sign-line">الإسم : <span class="fill wide">{{customer_name}}</span></p>
      <p class="rpm-ack2-sign-line">التوقيع : ____________________</p>
    </div>
  </div>

  <p class="rpm-ack2-foot">RPM &nbsp;•&nbsp; إقرار استلام سيارة &nbsp;•</p>
</article>
HTML;
    }

    private function defaultOwnerAgreementHtml(): string
    {
        return <<<'HTML'
<header class="print-head">
  <img class="print-logo" src="{{logo_src}}" alt="شعار الشركة" />
  <div>
    <h1>{{company_name}}</h1>
    <p class="muted">{{company_phone}} · {{company_address}}</p>
    <p class="muted">س.ت {{company_cr}} · بطاقة ضريبية {{company_tax}}</p>
  </div>
</header>

<p class="contract-title">عقد إدارة وتشغيل مركبة مملوكة للغير</p>
<p class="contract-sub">رقم العقد {{owner_agreement_number}} · تاريخ التحرير {{agreement_date}} · إصدار القالب {{template_version}}</p>

<p class="preamble">إنه في يوم {{agreement_date}}، وبمدينة مقر الطرف الثاني الموضّح أدناه، تحرر هذا العقد بين كلٍّ من:</p>

<h2>أطراف العقد</h2>
<table>
  <tr><th>الطرف الأول (المالك)</th><td>
    السيد/ة: {{owner_name}}<br>
    الجنسية: {{owner_nationality}}<br>
    الرقم القومي: <span dir="ltr">{{owner_national_id}}</span><br>
    هاتف: <span dir="ltr">{{owner_phone}}</span>
    <span class="muted"> · بديل: <span dir="ltr">{{owner_phone_alt}}</span></span>
  </td></tr>
  <tr><th>الطرف الثاني (شركة التشغيل)</th><td>
    {{company_name}}<br>
    العنوان: {{company_address}}<br>
    هاتف: <span dir="ltr">{{company_phone}}</span><br>
    السجل التجاري: {{company_cr}} · البطاقة الضريبية: {{company_tax}}
  </td></tr>
</table>
<p class="preamble">ويُشار إلى الطرفين مجتمعين بـ«الطرفين»، ويقرّان بأهليتهما القانونية الكاملة للتعاقد والتصرف، واتفاقهما على الأحكام التالية.</p>

<section class="article">
  <h3>المادة الأولى — تعريفات</h3>
  <ol>
    <li><strong>المركبة:</strong> السيارة المبيّنة بياناتها في المادة الثانية من هذا العقد وملحقاتها من مفاتيح ومستندات ورخصة تسيير وإكسسوارات عند التسليم.</li>
    <li><strong>التشغيل:</strong> إدارة المركبة وتأجيرها للغير عبر نشاط الطرف الثاني، بما يشمل التسليم والاستلام والصيانة الدورية والتحصيل وفق أنظمة الشركة.</li>
    <li><strong>المقابل الشهري:</strong> المبلغ المتفق عليه لصالح الطرف الأول مقابل تمكين الطرف الثاني من تشغيل المركبة خلال مدة العقد.</li>
  </ol>
</section>

<section class="article">
  <h3>المادة الثانية — محل العقد وبيانات المركبة</h3>
  <p>يوكّل الطرف الأول الطرف الثاني — دون نقل ملكية — بإدارة وتشغيل المركبة الآتية، ويقرّ بأنه المالك الشرعي لها وأنها خالية من أي نزاع يحول دون تشغيلها:</p>
  <table>
    <tr><th>الماركة / الموديل</th><td>{{vehicle_name}}</td></tr>
    <tr><th>سنة الصنع</th><td>{{vehicle_year}}</td></tr>
    <tr><th>الفئة</th><td>{{vehicle_category}}</td></tr>
    <tr><th>اللون</th><td>{{vehicle_color}}</td></tr>
    <tr><th>الوقود / ناقل الحركة</th><td>{{vehicle_fuel}} · {{vehicle_transmission}}</td></tr>
    <tr><th>رقم اللوحة</th><td>{{vehicle_plate}}</td></tr>
    <tr><th>رقم الشاسيه</th><td><span dir="ltr">{{vehicle_chassis}}</span></td></tr>
    <tr><th>رقم المحرك</th><td><span dir="ltr">{{vehicle_engine}}</span></td></tr>
    <tr><th>قراءة العداد عند التعاقد</th><td>{{vehicle_odometer}} كم</td></tr>
    <tr><th>الكود الداخلي لدى الشركة</th><td>{{vehicle_code}}</td></tr>
    <tr><th>سعر التأجير اليومي المعتمد</th><td>{{vehicle_daily_rate}} ج.م</td></tr>
    <tr><th>التأمين النقدي الافتراضي للمستأجر</th><td>{{vehicle_deposit}} ج.م</td></tr>
  </table>
</section>

<section class="article">
  <h3>المادة الثالثة — مدة العقد</h3>
  <ol>
    <li>تبدأ مدة هذا العقد من {{owner_agreement_starts_on}} وتنتهي في {{owner_agreement_ends_on}}، ما لم يُفسخ أو يُجدد وفق أحكامه.</li>
    <li>إذا انتهت المدة دون تجديد كتابي صريح، لا يُعدّ الاستمرار بعد انتهائها تجديداً تلقائياً إلا بموافقة الطرفين وتحديث بيانات التشغيل.</li>
  </ol>
</section>

<div class="page-break"></div>

<section class="article">
  <h3>المادة الرابعة — المقابل المالي وطريقة السداد</h3>
  <ol>
    <li>يلتزم الطرف الثاني بأن يؤدي للطرف الأول مقابلاً شهرياً قدره <strong>{{owner_monthly_amount}} جنيهاً مصرياً</strong> عن تشغيل المركبة محل العقد.</li>
    <li>يُسدَّد المقابل وفق دورة المستحقات المعتمدة لدى الشركة (شهر ميلادي)، ويُثبت بأي إيصال أو تحويل أو قيد محاسبي صادر من أنظمة الطرف الثاني.</li>
    <li>لا يشمل المقابل الشهري ما يتحمله الطرف الثاني من مصروفات تشغيل معتادة (وقود للمستأجرين، غسيل تشغيلي، عمولات تحصيل إلكترونية معتادة) ما لم يُتفق كتابةً على غير ذلك.</li>
    <li>أي زيادة أو تخفيض في المقابل الشهري لا يسري إلا باتفاق كتابي لاحق يُلحق بهذا العقد.</li>
  </ol>
</section>

<section class="article">
  <h3>المادة الخامسة — التزامات الطرف الثاني (شركة التشغيل)</h3>
  <ol>
    <li>تشغيل المركبة في نشاط التأجير بحسن نية ووفق قانون المرور المصري واللوائح المعمول بها، وبما لا يخالف النظام العام.</li>
    <li>إجراء الصيانة الدورية والوقائية اللازمة لسلامة التشغيل، وتوثيق أوامر الشغل والمصروفات ذات الصلة في سجلاته.</li>
    <li>التأمين الإجباري ساري المفعول عن المسؤولية المدنية الناشئة عن حوادث المركبات، والسعي لتوفير تغطية إضافية حسب سياسة الشركة المعلنة للمالك.</li>
    <li>تحصيل أجرة التأجير من العملاء والتعامل معهم بموجب عقود/إيصالات الشركة، دون أن يُنشئ ذلك علاقة تعاقدية مباشرة بين المالك والعميل المستأجر.</li>
    <li>إخطار الطرف الأول دون تأخير غير مبرر بأي حادث جسيم أو سرقة أو حجز أو نزاع جوهري يمس المركبة.</li>
    <li>إعادة المركبة عند انتهاء العقد أو فسخه بالحالة التي تقتضيها الاستعمال المألوف، مع مراعاة الاستهلاك الطبيعي.</li>
  </ol>
</section>

<section class="article">
  <h3>المادة السادسة — التزامات الطرف الأول (المالك)</h3>
  <ol>
    <li>تمكين الطرف الثاني من حيازة المركبة ومستنداتها اللازمة للتشغيل (رخصة التسيير، مفاتيح، أي أجهزة تتبع مثبتة إن وُجدت).</li>
    <li>ضمان أن المركبة مملوكة له خالصة من أي حق للغير يمنع التشغيل المشروع، والإفصاح عن أي رهن أو حظر مروري معلوم وقت التعاقد.</li>
    <li>تجديد رخصة التسيير والتوكيلات اللازمة على نفقته متى كانت مطلوبة قانوناً لاستمرار التشغيل، ما لم يُتفق على أن تتولاها الشركة مقابل خصم متفق عليه.</li>
    <li>عدم سحب المركبة أو تعطيل تشغيلها خلال مدة العقد دون سبب مشروع أو إخلال جسيم من الطرف الثاني وبعد إخطار كتابي يمهّل علاجاً معقولاً.</li>
  </ol>
</section>

<section class="article">
  <h3>المادة السابعة — الصيانة والحوادث والمخالفات</h3>
  <ol>
    <li>الصيانة الناتجة عن الاستعمال التشغيلي المعتاد يتحملها الطرف الثاني ضمن سياسة تشغيل الأسطول، ما لم يثبت أنها ناشئة عن عيب سابق أخفاه المالك.</li>
    <li>التلفيات الناتجة عن خطأ عميل مستأجر تُعالَج وفق عقود التأجير والتأمين؛ ويُبلَّغ المالك بنتائج المطالبات الجوهرية.</li>
    <li>المخالفات المرورية والغرامات التي تقع أثناء فترة التشغيل تُحمَّل على المتسبب وفق أنظمة الشركة وعقود العملاء، دون إخلال بحق الرجوع القانوني.</li>
    <li>في حال الحادث: يلتزم الطرف الثاني باتخاذ الإجراءات المعتادة (إبلاغ، معاينة، مطالبة تأمينية) والتعاون مع الجهات المختصة.</li>
  </ol>
</section>

<div class="page-break"></div>

<section class="article">
  <h3>المادة الثامنة — الأجهزة والملحقات والبيانات</h3>
  <ol>
    <li>أجهزة التتبع أو الملحقات المملوكة للشركة تبقى ملكاً له وتُزال عند انتهاء العلاقة إن أمكن دون ضرر غير مألوف بالمركبة.</li>
    <li>يقرّ الطرف الأول باطلاع الطرف الثاني على بيانات التشغيل اللازمة لإدارة الأسطول، مع التزام الشركة بسرية البيانات الشخصية للمالك وفق سياسة الخصوصية المعمول بها.</li>
  </ol>
</section>

<section class="article">
  <h3>المادة التاسعة — التنازل والتعاقد من الباطن</h3>
  <ol>
    <li>لا يجوز للطرف الأول التنازل عن هذا العقد للغير دون موافقة كتابية مسبقة من الطرف الثاني.</li>
    <li>تأجير المركبة لعملاء الشركة جزء أصيل من غرض التشغيل ولا يُعدّ تنازلاً عن العقد، ولا ينشئ علاقة مباشرة بين المالك وهؤلاء العملاء.</li>
  </ol>
</section>

<section class="article">
  <h3>المادة العاشرة — الفسخ والإنهاء</h3>
  <ol>
    <li>يجوز لأي طرف طلب فسخ العقد عند إخلال الطرف الآخر إخلالاً جوهرياً بالتزاماته، بعد إخطار كتابي ومنح مهلة خمسة عشر (١٥) يوماً لإزالة المخالفة ما لم يكن الإخلال غير قابل للإزالة.</li>
    <li>ينتهي العقد بانتهاء مدته أو بالاتفاق أو بهلاك المركبة كلياً أو بسحب ترخيص التشغيل المانع قانوناً للاستمرار.</li>
    <li>عند الإنهاء تُسوَّى المستحقات المالية حتى تاريخ التسليم الفعلي، وتُعاد المركبة ومستنداتها وفق محضر تسليم.</li>
  </ol>
</section>

<section class="article">
  <h3>المادة الحادية عشرة — القوة القاهرة</h3>
  <p>لا يُسأل أي طرف عن تأخير أو امتناع ناتج عن قوة قاهرة أو ظرف طارئ عام يخرج عن السيطرة المعقولة (ومن ذلك الكوارث والحظر الحكومي الشامل)، على أن يُخطر الطرف الآخر فور العلم ويبذل العناية لإزالة الأثر قدر الإمكان.</p>
</section>

<section class="article">
  <h3>المادة الثانية عشرة — القانون الواجب التطبيق وتسوية المنازعات</h3>
  <ol>
    <li>يخضع هذا العقد لأحكام القانون المصري، وبخاصة القانون المدني وقانون المرور والتشريعات ذات الصلة.</li>
    <li>تُختص محاكم جمهورية مصر العربية — دائرة مقر الطرف الثاني — بنظر أي نزاع ينشأ عن تفسير أو تنفيذ هذا العقد، ما لم يتفق الطرفان لاحقاً على التحكيم.</li>
  </ol>
</section>

<section class="article">
  <h3>المادة الثالثة عشرة — أحكام عامة</h3>
  <ol>
    <li>يمثّل هذا العقد الاتفاق الكامل بين الطرفين بشأن محله، ويلغي أي تفاهم شفهي سابق يتعارض معه.</li>
    <li>أي تعديل لا يُعتدّ به إلا مكتوباً وموقّعاً من الطرفين أو مثبتاً في أنظمة الشركة بموافقة صريحة.</li>
    <li>بطلان بند لا يؤثر على بقية البنود متى أمكن فصلها.</li>
    <li>حُرّر هذا العقد من نسختين أصليتين بيد كل طرف نسخة للعمل بموجبها.</li>
  </ol>
</section>

<section class="article">
  <h3>بنود إضافية متفق عليها</h3>
  <div class="terms">{{contract_terms}}</div>
</section>

<div class="sign-grid">
  <div class="sign-box">
    <h4>الطرف الأول — المالك</h4>
    <p>{{owner_name}}</p>
    <p class="muted">الجنسية: {{owner_nationality}} · قومي: <span dir="ltr">{{owner_national_id}}</span></p>
    <div class="sign-line">التوقيع · بصمة · التاريخ</div>
  </div>
  <div class="sign-box">
    <h4>الطرف الثاني — شركة التشغيل</h4>
    <p>{{company_name}}</p>
    <p class="muted">{{company_address}}</p>
    <div class="sign-line">الختم · توقيع المسؤول · التاريخ</div>
  </div>
</div>

<p class="foot">{{print_footer}} · عقد تشغيلي قابل للتعديل من إعدادات القوالب · يُنصح بمراجعة قانونية محلية قبل الاعتماد النهائي لدى الجهات الرسمية.</p>
HTML;
    }

    private function defaultCustodyAgreementHtml(): string
    {
        return <<<'HTML'
<header class="print-head">
  <img class="print-logo" src="{{logo_src}}" alt="شعار الشركة" />
  <div>
    <h1>{{company_name}}</h1>
    <p class="muted">{{company_phone}} · {{company_address}}</p>
    <p class="muted">سجل تجاري {{company_cr}} · بطاقة ضريبية {{company_tax}}</p>
  </div>
</header>
<h2 style="text-align:center">عقد اعتماد · رقم {{agreement_number}}</h2>
<p class="muted" style="text-align:center">إصدار القالب {{template_version}} · حُرّر في {{issued_on}}</p>
<p>إنه في يوم الموافق {{issued_on}} حُرّر هذا العقد فيما بين كل من:</p>
<h2>أولاً: الطرف الأول (المؤجر / مالك العربية)</h2>
<table>
  <tr><th>الاسم</th><td>{{owner_name}}</td></tr>
  <tr><th>الجنسية</th><td>{{owner_nationality}}</td></tr>
  <tr><th>الهاتف</th><td dir="ltr">{{owner_phone}}</td></tr>
  <tr><th>الرقم القومي</th><td dir="ltr">{{owner_national_id}}</td></tr>
</table>
<h2>ثانياً: الطرف الثاني (المستأجر / الشركة)</h2>
<table>
  <tr><th>اسم الشركة</th><td>{{company_name}}</td></tr>
  <tr><th>الهاتف</th><td dir="ltr">{{company_phone}}</td></tr>
  <tr><th>العنوان</th><td>{{company_address}}</td></tr>
  <tr><th>السجل التجاري</th><td>{{company_cr}}</td></tr>
  <tr><th>البطاقة الضريبية</th><td>{{company_tax}}</td></tr>
</table>
<p>وبعد أن أقر الطرفان بأهليتهما الكاملة للتصرفات القانونية والتعاقد، اتفقا وتراضيا على الآتي:</p>
<h2>تمهيد</h2>
<p>يمتلك الطرف الأول السيارة رقم اللوحة ({{vehicle_plate}}) ماركة/ {{vehicle_brand}} موديل/ {{vehicle_model}} سنة/ {{vehicle_year}} لون/ {{vehicle_color}} رقم الشاسيه/ {{vehicle_chassis}} رقم الموتور/ {{vehicle_engine}}. وحيث يرغب الطرف الأول في اعتماد السيارة لدى الطرف الثاني بصفته شركة لتأجير السيارات لتمكينه من تأجيرها للغير خلال مدة هذا العقد، لذلك اتفق الطرفان على ما يلي:</p>
<ol class="terms-list">
  <li>التمهيد السابق جزء لا يتجزأ من العقد ويُعتبر مكملاً له.</li>
  <li>اعتمد الطرف الثاني من الطرف الأول السيارة محل التمهيد السابق لاستغلالها في نشاط التأجير وفق أنظمة الشركة.</li>
  <li>مدة هذا العقد تبدأ من تاريخ {{start_date}} إلى تاريخ {{end_date}}.</li>
  <li>القيمة الإيجارية الشهرية المستحقة للمالك مبلغ وقدره ({{monthly_amount}}) جنيهاً لا غير، تُدفع أول كل شهر من المستأجر (الشركة) إلى المؤجر أو وكيله بموجب إيصال أو مخالصة أو إيداع بنكي يُعد الدليل الوحيد على السداد.</li>
  <li>يقر الطرف الثاني بأنه تسلم السيارة موضع هذا العقد بحالة جيدة وبكامل كمالياتها، وأصبح مسؤولاً عنها مسؤولية قانونية كاملة وعن أي حوادث أو مخالفات قد تنجم عنها خلال مدة الاعتماد.</li>
  <li>يلتزم المستأجر بإصلاح أي تلفيات قد تحدث للسيارة دون توقف عن دفع القيمة الإيجارية للمؤجر طوال مدة الإصلاح، وعلى المؤجر تقديم وثيقة التأمين ويتحمل المستأجر فرق ومصاريف الإصلاح، كما يلتزم المستأجر بالصيانة الدورية والعادية في مواعيدها والمحافظة عليها.</li>
  <li>يحق للطرف الأول أو وكيله الاطمئنان على السيارة ومشاهدتها كل 3 شهور، وعلى الطرف الثاني تمكينه من ذلك دون اعتراض.</li>
  <li>يلتزم الطرف الثاني بتسليم السيارة للطرف الأول أو وكيله بحالتها التي كانت وقت التعاقد عند انتهاء مدة العقد، ولا يمتد هذا العقد أو يُجدَّد إلا بموافقة كتابية من المؤجر أو وكيله.</li>
  <li>يلتزم الطرفان بتنفيذ كافة بنود هذا العقد. وفي حالة مخالفة أي بند يلتزم الطرف المخالف بدفع مبلغ (50000) جنيهاً فقط لا غير كشرط جزائي للطرف الآخر، ويكون العقد مفسوخاً تلقائياً.</li>
  <li>في حالة التأخر في الدفعة الإيجارية الشهرية عن ثلاثين يوماً يُعتبر العقد مفسوخاً ويحق للطرف الأول سحب السيارة مع استيفاء القيمة الإيجارية كاملة بالإضافة إلى الشرط الجزائي (50000) جنيهاً.</li>
  <li>تختص المحكمة المختصة بأي نزاع ينشأ عن هذا العقد، ويقر الطرفان بأن العنوان المدون بالعقد هو موطن المراسلات الرسمية بينهما.</li>
  <li>يلتزم الطرف الأول بتسهيل إجراءات تجديد الترخيص عند انتهائه أو سحبه لأي سبب، وبتسليم صورة من وثيقة التأمين والمستندات اللازمة، مع تحمل المستأجر نسبة التحمل التأميني وفروق التصليح.</li>
  <li>يتعهد الطرف الثاني بأن خط سير السيارة داخل جمهورية مصر العربية فقط، وأن السيارة خالية مما يخالف القانون.</li>
  <li>تحرر هذا العقد من نسختين بيد كل طرف نسخة للعمل بها عند اللزوم. ومن تاريخ وساعة التعاقد يكون الطرف الثاني مسؤولاً مسؤولية كاملة عن السيارة حتى نهاية المدة المتعاقد عليها.</li>
</ol>
<div class="terms">{{contract_terms}}</div>
<table style="margin-top:32px">
  <tr>
    <th style="width:50%;text-align:center">توقيع الطرف الأول (المؤجر)</th>
    <th style="width:50%;text-align:center">توقيع الطرف الثاني (الشركة)</th>
  </tr>
  <tr>
    <td style="height:72px;text-align:center">{{owner_name}}</td>
    <td style="height:72px;text-align:center">{{company_name}}</td>
  </tr>
</table>
<p class="foot">{{print_footer}}</p>
HTML;
    }
}
