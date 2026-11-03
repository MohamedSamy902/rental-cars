<?php

namespace App\Services\Documents;

use App\Models\Contract;
use App\Models\DocumentTemplate;
use App\Models\TenantSetting;
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
            ['key' => 'fuel_out', 'aliases' => ['بنزين_التسليم'], 'label' => 'مستوى البنزين عند التسليم', 'sample' => '80%'],
            ['key' => 'fuel_in', 'aliases' => ['بنزين_الاستلام'], 'label' => 'مستوى البنزين عند الاستلام', 'sample' => '60%'],
            ['key' => 'handover_at', 'aliases' => ['وقت_التسليم'], 'label' => 'وقت التسليم', 'sample' => '2026-11-01 10:00'],
            ['key' => 'return_at', 'aliases' => ['وقت_الاستلام'], 'label' => 'وقت الاستلام', 'sample' => '2026-11-05 18:00'],
            ['key' => 'template_version', 'aliases' => ['إصدار_القالب'], 'label' => 'رقم إصدار القالب', 'sample' => '1'],
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

        $fuelOut = $handover?->fuel_level;
        $fuelIn = $ret?->fuel_level;

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
            'fuel_out' => $fuelOut !== null ? ((string) $fuelOut).'%' : '',
            'fuel_in' => $fuelIn !== null ? ((string) $fuelIn).'%' : '',
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

    private function defaultTitle(string $type): string
    {
        return match ($type) {
            DocumentTemplate::TYPE_CONTRACT => 'قالب العقد الأساسي',
            DocumentTemplate::TYPE_HANDOVER_RECEIPT => 'قالب إيصال الاستلام والتأمين',
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
  <tr><th>مستوى البنزين</th><td>{{fuel_out}}</td></tr>
  <tr><th>وقت التسليم</th><td>{{handover_at}}</td></tr>
</table>
<h2>عند الاستلام</h2>
<table>
  <tr><th>عداد العودة</th><td>{{odometer_in}}</td></tr>
  <tr><th>مستوى البنزين</th><td>{{fuel_in}}</td></tr>
  <tr><th>وقت الاستلام</th><td>{{return_at}}</td></tr>
</table>
<p class="foot">{{print_footer}}</p>
HTML;
    }
}
