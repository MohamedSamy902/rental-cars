<?php

namespace App\Services\Documents;

use App\Models\Contract;
use App\Models\DocumentTemplate;
use App\Models\TenantSetting;
use App\Models\Vehicle;
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
     * @return list<array{key:string,aliases:list<string>,label:string,sample:string}>
     */
    public function placeholderCatalog(): array
    {
        return [
            ['key' => 'company_name', 'aliases' => ['اسم_الشركة', 'اسم_المركز'], 'label' => 'اسم الشركة / المركز', 'sample' => 'مركز آر بي إم للتأجير'],
            ['key' => 'company_phone', 'aliases' => ['هاتف_الشركة'], 'label' => 'هاتف الشركة', 'sample' => '01150100104'],
            ['key' => 'company_address', 'aliases' => ['عنوان_الشركة'], 'label' => 'عنوان الشركة', 'sample' => 'القاهرة'],
            ['key' => 'company_cr', 'aliases' => ['السجل_التجاري'], 'label' => 'السجل التجاري', 'sample' => '12345'],
            ['key' => 'company_tax', 'aliases' => ['البطاقة_الضريبية'], 'label' => 'البطاقة الضريبية', 'sample' => '67890'],
            ['key' => 'logo_src', 'aliases' => ['شعار_الشركة'], 'label' => 'مسار شعار الشركة', 'sample' => '/brand/rpm-logo.webp'],
            ['key' => 'print_footer', 'aliases' => ['تذييل_الطباعة'], 'label' => 'تذييل الطباعة', 'sample' => 'نسخة داخلية'],
            ['key' => 'contract_terms', 'aliases' => ['بنود_العقد'], 'label' => 'بنود وشروط العقد', 'sample' => 'يلتزم المستأجر بإعادة العربية في الموعد المحدد.'],
            ['key' => 'contract_number', 'aliases' => ['رقم_العقد'], 'label' => 'رقم العقد', 'sample' => 'C-1001'],
            ['key' => 'contract_status', 'aliases' => ['حالة_العقد'], 'label' => 'حالة العقد', 'sample' => 'نشط'],
            ['key' => 'customer_name', 'aliases' => ['اسم_العميل'], 'label' => 'اسم العميل', 'sample' => 'محمد أحمد'],
            ['key' => 'customer_phone', 'aliases' => ['هاتف_العميل', 'رقم_الهاتف'], 'label' => 'هاتف العميل', 'sample' => '01000000000'],
            ['key' => 'vehicle_plate', 'aliases' => ['رقم_اللوحة'], 'label' => 'رقم اللوحة', 'sample' => 'أ ب ج 1234'],
            ['key' => 'vehicle_name', 'aliases' => ['اسم_العربية', 'العربية'], 'label' => 'الماركة والموديل', 'sample' => 'هيونداي إلنترا'],
            ['key' => 'start_date', 'aliases' => ['تاريخ_البداية'], 'label' => 'تاريخ البداية', 'sample' => '2026-11-01'],
            ['key' => 'end_date', 'aliases' => ['تاريخ_النهاية', 'النهاية_المخططة'], 'label' => 'تاريخ النهاية المخططة', 'sample' => '2026-11-05'],
            ['key' => 'actual_end_date', 'aliases' => ['تاريخ_الاستلام_الفعلي'], 'label' => 'تاريخ الاستلام الفعلي', 'sample' => '2026-11-05'],
            ['key' => 'daily_rate', 'aliases' => ['السعر_اليومي'], 'label' => 'السعر اليومي', 'sample' => '800'],
            ['key' => 'deposit', 'aliases' => ['التأمين', 'التأمين_النقدي'], 'label' => 'مبلغ التأمين النقدي', 'sample' => '5000'],
            ['key' => 'deposit_status', 'aliases' => ['حالة_التأمين'], 'label' => 'حالة التأمين', 'sample' => 'مقبوض'],
            ['key' => 'deposit_remaining', 'aliases' => ['متبقي_التأمين'], 'label' => 'متبقي التأمين', 'sample' => '5000'],
            ['key' => 'deposit_deductions', 'aliases' => ['خصومات_التأمين'], 'label' => 'خصومات التأمين', 'sample' => '0'],
            ['key' => 'final_total', 'aliases' => ['الإجمالي_النهائي'], 'label' => 'الإجمالي النهائي', 'sample' => '3200'],
            ['key' => 'odometer_out', 'aliases' => ['عداد_الخروج'], 'label' => 'عداد الخروج', 'sample' => '45000'],
            ['key' => 'odometer_in', 'aliases' => ['عداد_العودة'], 'label' => 'عداد العودة', 'sample' => '45210'],
            ['key' => 'handover_at', 'aliases' => ['وقت_التسليم'], 'label' => 'وقت التسليم', 'sample' => '2026-11-01 10:00'],
            ['key' => 'return_at', 'aliases' => ['وقت_الاستلام'], 'label' => 'وقت الاستلام', 'sample' => '2026-11-05 18:00'],
            ['key' => 'template_version', 'aliases' => ['إصدار_القالب'], 'label' => 'رقم إصدار القالب', 'sample' => '1'],
            ['key' => 'owner_name', 'aliases' => ['اسم_المالك'], 'label' => 'اسم المالك', 'sample' => 'محمود علي'],
            ['key' => 'owner_phone', 'aliases' => ['هاتف_المالك'], 'label' => 'هاتف المالك', 'sample' => '01011112222'],
            ['key' => 'owner_phone_alt', 'aliases' => ['هاتف_المالك_البديل'], 'label' => 'هاتف المالك البديل', 'sample' => '01122223333'],
            ['key' => 'owner_national_id', 'aliases' => ['قومي_المالك'], 'label' => 'الرقم القومي للمالك', 'sample' => '29001011234567'],
            ['key' => 'owner_monthly_amount', 'aliases' => ['المبلغ_الشهري_للمالك'], 'label' => 'المبلغ الشهري للمالك', 'sample' => '8000'],
            ['key' => 'owner_agreement_number', 'aliases' => ['رقم_عقد_المالك'], 'label' => 'رقم عقد المالك (تلقائي)', 'sample' => 'OWN-VEH-0001'],
            ['key' => 'owner_agreement_starts_on', 'aliases' => ['بداية_عقد_المالك'], 'label' => 'بداية عقد المالك', 'sample' => '2026-01-01'],
            ['key' => 'owner_agreement_ends_on', 'aliases' => ['نهاية_عقد_المالك'], 'label' => 'نهاية عقد المالك', 'sample' => '2026-12-31'],
            ['key' => 'agreement_date', 'aliases' => ['تاريخ_تحرير_العقد'], 'label' => 'تاريخ تحرير العقد', 'sample' => '2026-11-08'],
            ['key' => 'vehicle_code', 'aliases' => ['كود_العربية'], 'label' => 'الكود الداخلي للعربية', 'sample' => 'VEH-0001'],
            ['key' => 'vehicle_year', 'aliases' => ['سنة_العربية'], 'label' => 'سنة الصنع', 'sample' => '2024'],
            ['key' => 'vehicle_category', 'aliases' => ['فئة_العربية'], 'label' => 'فئة العربية', 'sample' => 'اقتصادي'],
            ['key' => 'vehicle_color', 'aliases' => ['لون_العربية'], 'label' => 'لون العربية', 'sample' => 'أبيض'],
            ['key' => 'vehicle_fuel', 'aliases' => ['وقود_العربية'], 'label' => 'نوع الوقود', 'sample' => 'بنزين'],
            ['key' => 'vehicle_transmission', 'aliases' => ['ناقل_الحركة'], 'label' => 'ناقل الحركة', 'sample' => 'أوتوماتيك'],
            ['key' => 'vehicle_chassis', 'aliases' => ['رقم_الشاسيه'], 'label' => 'رقم الشاسيه', 'sample' => 'CHS-123456'],
            ['key' => 'vehicle_engine', 'aliases' => ['رقم_المحرك'], 'label' => 'رقم المحرك', 'sample' => 'ENG-654321'],
            ['key' => 'vehicle_odometer', 'aliases' => ['عداد_العربية'], 'label' => 'قراءة العداد عند التعاقد', 'sample' => '45000'],
            ['key' => 'vehicle_daily_rate', 'aliases' => ['سعر_التأجير_اليومي'], 'label' => 'سعر التأجير اليومي المعتمد', 'sample' => '800'],
            ['key' => 'vehicle_deposit', 'aliases' => ['تأمين_التأجير_الافتراضي'], 'label' => 'التأمين النقدي الافتراضي للتأجير', 'sample' => '5000'],
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
                'title' => 'قالب العقد الأساسي',
                'content' => $this->defaultContractHtml(),
            ],
            DocumentTemplate::TYPE_HANDOVER_RECEIPT => [
                'title' => 'قالب إيصال الاستلام والتأمين',
                'content' => $this->defaultHandoverHtml(),
            ],
            DocumentTemplate::TYPE_OWNER_AGREEMENT => [
                'title' => 'عقد إدارة وتشغيل مركبة (مالك ↔ مركز)',
                'content' => $this->defaultOwnerAgreementHtml(),
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

        // Soft rename for the stock v1 handover template if it still uses legacy wording.
        DocumentTemplate::query()
            ->where('type', DocumentTemplate::TYPE_HANDOVER_RECEIPT)
            ->where('is_active', true)
            ->where('version', 1)
            ->where(function ($q) {
                $q->where('title', 'like', '%محضر%')
                    ->orWhere('title', 'like', '%سند%')
                    ->orWhere('content', 'like', '%سند قبض%')
                    ->orWhere('content', 'like', '%محضر التسليم%');
            })
            ->update([
                'title' => 'قالب إيصال الاستلام والتأمين',
                'content' => $this->defaultHandoverHtml(),
            ]);

        $this->upgradeStockOwnerAgreementTemplate($userId);
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

        $content = (string) $active->content;
        $isShortStub = ! str_contains($content, 'المادة الأولى')
            || mb_strlen(strip_tags($content)) < 1200;

        if (! $isShortStub) {
            return;
        }

        $this->publish([
            'type' => DocumentTemplate::TYPE_OWNER_AGREEMENT,
            'title' => 'عقد إدارة وتشغيل مركبة (مالك ↔ مركز)',
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
    public function publish(array $data, ?UploadedFile $file = null, ?int $userId = null): DocumentTemplate
    {
        if (! $this->tableReady()) {
            throw new DomainException('جدول قوالب المستندات غير جاهز. نفّذ ترحيل قاعدة البيانات أولاً.');
        }

        $type = $data['type'];
        $this->assertType($type);

        $format = $data['format'] ?? 'html';
        $content = (string) ($data['content'] ?? '');
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

        $map = [
            'company_name' => (string) ($tenant?->center_name ?: 'مركز التأجير'),
            'company_phone' => (string) ($ops['print_phone'] ?? ''),
            'company_address' => (string) ($ops['print_address'] ?? ''),
            'company_cr' => (string) ($ops['print_commercial_register'] ?? ''),
            'company_tax' => (string) ($ops['print_tax_info'] ?? ''),
            'logo_src' => (string) ($tenant?->logo_path ?: '/brand/rpm-logo.webp'),
            'print_footer' => (string) ($tenant?->print_footer ?: ''),
            'contract_terms' => (string) ($ops['contract_terms'] ?? ''),
            'contract_number' => (string) ($contract->number ?: ('C-'.$contract->id)),
            'contract_status' => (string) $contract->status,
            'customer_name' => (string) ($customer?->full_name ?: ''),
            'customer_phone' => (string) ($customer?->phone ?: ''),
            'national_id_front' => (string) ($customer?->national_id_image_path ?: ''),
            'national_id_back' => (string) ($customer?->national_id_back_path ?: ''),
            'vehicle_plate' => (string) ($vehicle?->plate ?: ''),
            'vehicle_name' => trim(($vehicle?->brand ?? '').' '.($vehicle?->model ?? '')),
            'start_date' => $contract->starts_at?->format('Y-m-d H:i') ?? '',
            'end_date' => $contract->planned_ends_at?->format('Y-m-d H:i') ?? '',
            'actual_end_date' => $contract->actual_ends_at?->format('Y-m-d H:i') ?? '',
            'daily_rate' => (string) (int) $contract->frozen_daily_rate,
            'deposit' => (string) (int) ($deposit?->original_amount ?? $contract->frozen_agreed_deposit ?? 0),
            'deposit_status' => (string) ($deposit?->status ?? ''),
            'deposit_remaining' => (string) (int) ($deposit ? $deposit->remaining() : ($contract->frozen_agreed_deposit ?? 0)),
            'deposit_deductions' => (string) (int) ($deposit?->deductions_total ?? 0),
            'final_total' => $contract->final_total !== null ? (string) (int) $contract->final_total : '',
            'odometer_out' => (string) ($handover?->odometer ?? $contract->odometer_out ?? ''),
            'odometer_in' => (string) ($ret?->odometer ?? $contract->odometer_in ?? ''),
            'handover_at' => $handover?->inspected_at?->format('Y-m-d H:i') ?? '',
            'return_at' => $ret?->inspected_at?->format('Y-m-d H:i')
                ?? ($contract->actual_ends_at?->format('Y-m-d H:i') ?? ''),
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
            'company_name' => (string) ($tenant?->center_name ?: 'مركز التأجير'),
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
            'owner_monthly_amount' => (string) (int) ($vehicle->owner_monthly_amount ?? 0),
            'owner_agreement_number' => $number,
            'owner_agreement_starts_on' => $vehicle->owner_agreement_starts_on?->toDateString()
                ?? $vehicle->owner_since?->toDateString()
                ?? '',
            'owner_agreement_ends_on' => $vehicle->owner_agreement_ends_on?->toDateString() ?? '',
            'agreement_date' => now()->toDateString(),
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
            DocumentTemplate::TYPE_CONTRACT => 'قالب العقد الأساسي',
            DocumentTemplate::TYPE_HANDOVER_RECEIPT => 'قالب إيصال الاستلام والتأمين',
            DocumentTemplate::TYPE_OWNER_AGREEMENT => 'عقد إدارة وتشغيل مركبة (مالك ↔ مركز)',
            default => 'قالب مستند',
        };
    }

    private function defaultContractHtml(): string
    {
        return <<<'HTML'
<header class="print-head">
  <img class="print-logo" src="{{logo_src}}" alt="شعار الشركة" />
  <div>
    <h1>{{company_name}}</h1>
    <p class="muted">{{company_phone}} · {{company_address}}</p>
  </div>
</header>
<h2>عقد تأجير · رقم {{contract_number}}</h2>
<p class="muted"><span class="badge">{{contract_status}}</span> · إصدار القالب {{template_version}}</p>
<table>
  <tr><th>العميل</th><td>{{customer_name}} · <span dir="ltr">{{customer_phone}}</span></td></tr>
  <tr><th>العربية</th><td>{{vehicle_name}} · {{vehicle_plate}}</td></tr>
  <tr><th>البداية</th><td>{{start_date}}</td></tr>
  <tr><th>النهاية المخططة</th><td>{{end_date}}</td></tr>
  <tr><th>السعر اليومي</th><td>{{daily_rate}}</td></tr>
  <tr><th>التأمين النقدي</th><td>{{deposit}}</td></tr>
  <tr><th>الإجمالي النهائي</th><td>{{final_total}}</td></tr>
</table>
<h2>بنود وشروط العقد</h2>
<div class="terms">{{contract_terms}}</div>
<p class="foot">{{print_footer}}</p>
HTML;
    }

    private function defaultHandoverHtml(): string
    {
        return <<<'HTML'
<header class="print-head">
  <img class="print-logo" src="{{logo_src}}" alt="شعار الشركة" />
  <div>
    <h1>{{company_name}}</h1>
    <p class="muted">{{company_phone}} · {{company_address}}</p>
  </div>
</header>
<h2>إيصال الاستلام والتأمين</h2>
<p class="muted">عقد {{contract_number}} · إصدار القالب {{template_version}}</p>
<h2>بيانات العميل والعربية</h2>
<table>
  <tr><th>العميل</th><td>{{customer_name}} · <span dir="ltr">{{customer_phone}}</span></td></tr>
  <tr><th>العربية</th><td>{{vehicle_name}} · {{vehicle_plate}}</td></tr>
</table>
<h2>بيانات التأمين</h2>
<table>
  <tr><th>مبلغ التأمين</th><td>{{deposit}}</td></tr>
  <tr><th>حالة التأمين</th><td>{{deposit_status}}</td></tr>
  <tr><th>الخصومات</th><td>{{deposit_deductions}}</td></tr>
  <tr><th>المتبقي</th><td>{{deposit_remaining}}</td></tr>
</table>
<h2>عند التسليم</h2>
<table>
  <tr><th>عداد الخروج</th><td>{{odometer_out}}</td></tr>
  <tr><th>وقت التسليم</th><td>{{handover_at}}</td></tr>
</table>
<h2>عند الاستلام</h2>
<table>
  <tr><th>عداد العودة</th><td>{{odometer_in}}</td></tr>
  <tr><th>وقت الاستلام</th><td>{{return_at}}</td></tr>
</table>
<p class="foot">{{print_footer}}</p>
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
    الرقم القومي: <span dir="ltr">{{owner_national_id}}</span><br>
    هاتف: <span dir="ltr">{{owner_phone}}</span>
    <span class="muted"> · بديل: <span dir="ltr">{{owner_phone_alt}}</span></span>
  </td></tr>
  <tr><th>الطرف الثاني (مركز التشغيل)</th><td>
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
    <li><strong>التشغيل:</strong> إدارة المركبة وتأجيرها للغير عبر نشاط الطرف الثاني، بما يشمل التسليم والاستلام والصيانة الدورية والتحصيل وفق أنظمة المركز.</li>
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
    <tr><th>الكود الداخلي لدى المركز</th><td>{{vehicle_code}}</td></tr>
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
    <li>يُسدَّد المقابل وفق دورة المستحقات المعتمدة لدى المركز (شهر ميلادي)، ويُثبت بأي إيصال أو تحويل أو قيد محاسبي صادر من أنظمة الطرف الثاني.</li>
    <li>لا يشمل المقابل الشهري ما يتحمله الطرف الثاني من مصروفات تشغيل معتادة (وقود للمستأجرين، غسيل تشغيلي، عمولات تحصيل إلكترونية معتادة) ما لم يُتفق كتابةً على غير ذلك.</li>
    <li>أي زيادة أو تخفيض في المقابل الشهري لا يسري إلا باتفاق كتابي لاحق يُلحق بهذا العقد.</li>
  </ol>
</section>

<section class="article">
  <h3>المادة الخامسة — التزامات الطرف الثاني (مركز التشغيل)</h3>
  <ol>
    <li>تشغيل المركبة في نشاط التأجير بحسن نية ووفق قانون المرور المصري واللوائح المعمول بها، وبما لا يخالف النظام العام.</li>
    <li>إجراء الصيانة الدورية والوقائية اللازمة لسلامة التشغيل، وتوثيق أوامر الشغل والمصروفات ذات الصلة في سجلاته.</li>
    <li>التأمين الإجباري ساري المفعول عن المسؤولية المدنية الناشئة عن حوادث المركبات، والسعي لتوفير تغطية إضافية حسب سياسة المركز المعلنة للمالك.</li>
    <li>تحصيل أجرة التأجير من العملاء والتعامل معهم بموجب عقود/إيصالات المركز، دون أن يُنشئ ذلك علاقة تعاقدية مباشرة بين المالك والعميل المستأجر.</li>
    <li>إخطار الطرف الأول دون تأخير غير مبرر بأي حادث جسيم أو سرقة أو حجز أو نزاع جوهري يمس المركبة.</li>
    <li>إعادة المركبة عند انتهاء العقد أو فسخه بالحالة التي تقتضيها الاستعمال المألوف، مع مراعاة الاستهلاك الطبيعي.</li>
  </ol>
</section>

<section class="article">
  <h3>المادة السادسة — التزامات الطرف الأول (المالك)</h3>
  <ol>
    <li>تمكين الطرف الثاني من حيازة المركبة ومستنداتها اللازمة للتشغيل (رخصة التسيير، مفاتيح، أي أجهزة تتبع مثبتة إن وُجدت).</li>
    <li>ضمان أن المركبة مملوكة له خالصة من أي حق للغير يمنع التشغيل المشروع، والإفصاح عن أي رهن أو حظر مروري معلوم وقت التعاقد.</li>
    <li>تجديد رخصة التسيير والتوكيلات اللازمة على نفقته متى كانت مطلوبة قانوناً لاستمرار التشغيل، ما لم يُتفق على أن يتولاها المركز مقابل خصم متفق عليه.</li>
    <li>عدم سحب المركبة أو تعطيل تشغيلها خلال مدة العقد دون سبب مشروع أو إخلال جسيم من الطرف الثاني وبعد إخطار كتابي يمهّل علاجاً معقولاً.</li>
  </ol>
</section>

<section class="article">
  <h3>المادة السابعة — الصيانة والحوادث والمخالفات</h3>
  <ol>
    <li>الصيانة الناتجة عن الاستعمال التشغيلي المعتاد يتحملها الطرف الثاني ضمن سياسة تشغيل الأسطول، ما لم يثبت أنها ناشئة عن عيب سابق أخفاه المالك.</li>
    <li>التلفيات الناتجة عن خطأ عميل مستأجر تُعالَج وفق عقود التأجير والتأمين؛ ويُبلَّغ المالك بنتائج المطالبات الجوهرية.</li>
    <li>المخالفات المرورية والغرامات التي تقع أثناء فترة التشغيل تُحمَّل على المتسبب وفق أنظمة المركز وعقود العملاء، دون إخلال بحق الرجوع القانوني.</li>
    <li>في حال الحادث: يلتزم الطرف الثاني باتخاذ الإجراءات المعتادة (إبلاغ، معاينة، مطالبة تأمينية) والتعاون مع الجهات المختصة.</li>
  </ol>
</section>

<div class="page-break"></div>

<section class="article">
  <h3>المادة الثامنة — الأجهزة والملحقات والبيانات</h3>
  <ol>
    <li>أجهزة التتبع أو الملحقات المملوكة للمركز تبقى ملكاً له وتُزال عند انتهاء العلاقة إن أمكن دون ضرر غير مألوف بالمركبة.</li>
    <li>يقرّ الطرف الأول باطلاع الطرف الثاني على بيانات التشغيل اللازمة لإدارة الأسطول، مع التزام المركز بسرية البيانات الشخصية للمالك وفق سياسة الخصوصية المعمول بها.</li>
  </ol>
</section>

<section class="article">
  <h3>المادة التاسعة — التنازل والتعاقد من الباطن</h3>
  <ol>
    <li>لا يجوز للطرف الأول التنازل عن هذا العقد للغير دون موافقة كتابية مسبقة من الطرف الثاني.</li>
    <li>تأجير المركبة لعملاء المركز جزء أصيل من غرض التشغيل ولا يُعدّ تنازلاً عن العقد، ولا ينشئ علاقة مباشرة بين المالك وهؤلاء العملاء.</li>
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
    <li>أي تعديل لا يُعتدّ به إلا مكتوباً وموقّعاً من الطرفين أو مثبتاً في أنظمة المركز بموافقة صريحة.</li>
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
    <p class="muted">قومي: <span dir="ltr">{{owner_national_id}}</span></p>
    <div class="sign-line">التوقيع · بصمة · التاريخ</div>
  </div>
  <div class="sign-box">
    <h4>الطرف الثاني — مركز التشغيل</h4>
    <p>{{company_name}}</p>
    <p class="muted">{{company_address}}</p>
    <div class="sign-line">الختم · توقيع المسؤول · التاريخ</div>
  </div>
</div>

<p class="foot">{{print_footer}} · عقد تشغيلي قابل للتعديل من إعدادات القوالب · يُنصح بمراجعة قانونية محلية قبل الاعتماد النهائي لدى الجهات الرسمية.</p>
HTML;
    }
}
