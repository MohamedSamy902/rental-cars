<?php

namespace App\Services\Documents;

use App\Models\DocumentTemplate;
use App\Models\TenantSetting;
use App\Models\Vehicle;
use App\Models\VehicleCustodyAgreement;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class VehicleCustodyAgreementService
{
    public function __construct(private readonly DocumentTemplateService $templates) {}

    public function tableReady(): bool
    {
        try {
            return Schema::hasTable('vehicle_custody_agreements');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return list<VehicleCustodyAgreement>
     */
    public function listForVehicle(Vehicle $vehicle): array
    {
        if (! $this->tableReady()) {
            return [];
        }

        return VehicleCustodyAgreement::query()
            ->where('vehicle_id', $vehicle->id)
            ->with(['owner:id,full_name,phone,national_id', 'issuer:id,name'])
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->get()
            ->all();
    }

    public function findForVehicle(Vehicle $vehicle, int $agreementId): VehicleCustodyAgreement
    {
        if (! $this->tableReady()) {
            throw new DomainException('جدول عقود الاعتماد غير جاهز. نفّذ ترحيل قاعدة البيانات أولاً.');
        }

        $row = VehicleCustodyAgreement::query()
            ->where('vehicle_id', $vehicle->id)
            ->with(['owner:id,full_name,phone,national_id', 'issuer:id,name', 'template:id,type,title,version'])
            ->find($agreementId);

        if (! $row) {
            throw new DomainException('عقد الاعتماد غير موجود لهذه العربية.');
        }

        return $row;
    }

    /**
     * Issue an immutable custody agreement snapshot (عقد اعتماد).
     * Uses vehicle owner + center settings + active custody_agreement template.
     * Optional overrides only fill missing period/amount/number — never rewrite an issued row.
     *
     * @param  array{
     *   number?:string|null,
     *   starts_on?:string|null,
     *   ends_on?:string|null,
     *   monthly_amount?:int|string|null,
     * }  $overrides
     */
    public function issue(Vehicle $vehicle, array $overrides = [], ?int $userId = null): VehicleCustodyAgreement
    {
        if (! $this->tableReady()) {
            throw new DomainException('جدول عقود الاعتماد غير جاهز. نفّذ ترحيل قاعدة البيانات أولاً.');
        }

        $vehicle->loadMissing(['owner']);
        if (! $vehicle->owner_id || ! $vehicle->owner) {
            throw new DomainException('عقد الاعتماد للمالك الخارجي فقط — اربط العربية بمالك أولاً.');
        }

        $startsOn = $this->resolveDate(
            $overrides['starts_on'] ?? null,
            $vehicle->owner_agreement_starts_on?->toDateString(),
            'تاريخ بداية عقد الاعتماد مطلوب.'
        );
        $endsOn = $this->resolveDate(
            $overrides['ends_on'] ?? null,
            $vehicle->owner_agreement_ends_on?->toDateString(),
            'تاريخ نهاية عقد الاعتماد مطلوب.'
        );

        if ($endsOn < $startsOn) {
            throw new DomainException('تاريخ نهاية عقد الاعتماد يجب أن يكون بعد البداية أو مساوياً لها.');
        }

        $monthly = $overrides['monthly_amount'] ?? null;
        if ($monthly === null || $monthly === '') {
            $monthly = (int) ($vehicle->owner_monthly_amount ?? 0);
        }
        $monthly = (int) $monthly;
        if ($monthly < 0) {
            throw new DomainException('القيمة الإيجارية الشهرية غير صالحة.');
        }

        $explicitNumber = array_key_exists('number', $overrides) && trim((string) ($overrides['number'] ?? '')) !== '';
        $number = trim((string) ($overrides['number'] ?? ''));
        if ($number === '') {
            $number = $this->nextNumber();
        }
        if (mb_strlen($number) > 64) {
            throw new DomainException('رقم عقد الاعتماد طويل جداً.');
        }
        if (VehicleCustodyAgreement::query()->where('number', $number)->exists()) {
            if ($explicitNumber) {
                throw new DomainException('رقم عقد الاعتماد مستخدم مسبقاً. غيّر الرقم ثم أعد الإصدار.');
            }
            $number = $this->nextNumber();
        }

        $template = $this->templates->getActive(DocumentTemplate::TYPE_CUSTODY_AGREEMENT);
        if (! $template) {
            throw new DomainException('لا يوجد قالب نشط لعقد الاعتماد. احفظ القالب من الإعدادات أولاً.');
        }

        $variables = $this->variablesForVehicle($vehicle, [
            'number' => $number,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'monthly_amount' => $monthly,
        ], $template);

        $html = $this->templates->render($template->content, $template->format, $variables);

        try {
            return DB::transaction(function () use ($vehicle, $number, $startsOn, $endsOn, $monthly, $html, $template, $variables, $userId) {
                return VehicleCustodyAgreement::query()->create([
                    'vehicle_id' => $vehicle->id,
                    'owner_id' => (int) $vehicle->owner_id,
                    'number' => $number,
                    'starts_on' => $startsOn,
                    'ends_on' => $endsOn,
                    'monthly_amount' => $monthly,
                    'rendered_html' => $html,
                    'template_id' => $template->id,
                    'template_version' => (int) $template->version,
                    'variables_json' => $variables,
                    'issued_by' => $userId,
                    'issued_at' => now(),
                ]);
            });
        } catch (\Illuminate\Database\QueryException $e) {
            if (str_contains(strtolower($e->getMessage()), 'unique') || str_contains(strtolower($e->getMessage()), 'duplicate')) {
                throw new DomainException('رقم عقد الاعتماد مستخدم مسبقاً. غيّر الرقم ثم أعد الإصدار.');
            }
            throw $e;
        }
    }

    /**
     * @param  array{number:string,starts_on:string,ends_on:string,monthly_amount:int}  $period
     * @return array<string, string>
     */
    public function variablesForVehicle(Vehicle $vehicle, array $period, ?DocumentTemplate $template = null, ?TenantSetting $tenant = null): array
    {
        $tenant ??= TenantSetting::query()->first();
        $ops = is_array($tenant?->operational) ? $tenant->operational : [];
        $vehicle->loadMissing(['owner']);
        $owner = $vehicle->owner;

        $map = [
            'company_name' => (string) ($tenant?->center_name ?: 'مركز التأجير'),
            'company_phone' => (string) ($ops['print_phone'] ?? ''),
            'company_address' => (string) ($ops['print_address'] ?? ''),
            'company_cr' => (string) ($ops['print_commercial_register'] ?? ''),
            'company_tax' => (string) ($ops['print_tax_info'] ?? ''),
            'logo_src' => (string) ($tenant?->logo_path ?: '/brand/rpm-logo.webp'),
            'print_footer' => (string) ($tenant?->print_footer ?: ''),
            'contract_terms' => (string) ($ops['contract_terms'] ?? ''),
            'agreement_number' => (string) $period['number'],
            'contract_number' => (string) $period['number'],
            'start_date' => (string) $period['starts_on'],
            'end_date' => (string) $period['ends_on'],
            'monthly_amount' => (string) (int) $period['monthly_amount'],
            'owner_name' => (string) ($owner?->full_name ?: ''),
            'owner_phone' => (string) ($owner?->phone ?: ''),
            'owner_national_id' => (string) ($owner?->national_id ?: ''),
            'owner_notes' => (string) ($owner?->notes ?: ''),
            'vehicle_plate' => (string) ($vehicle->plate ?: ''),
            'vehicle_name' => trim(($vehicle->brand ?? '').' '.($vehicle->model ?? '')),
            'vehicle_brand' => (string) ($vehicle->brand ?: ''),
            'vehicle_model' => (string) ($vehicle->model ?: ''),
            'vehicle_year' => $vehicle->year !== null ? (string) (int) $vehicle->year : '',
            'vehicle_color' => (string) ($vehicle->color ?: ''),
            'vehicle_chassis' => (string) ($vehicle->chassis_number ?: ''),
            'vehicle_engine' => (string) ($vehicle->engine_number ?: ''),
            'issued_on' => now()->toDateString(),
            'template_version' => (string) ($template?->version ?? ''),
        ];

        return $this->templates->withAliases($map);
    }

    /**
     * @return array<string, mixed>
     */
    public function present(VehicleCustodyAgreement $row): array
    {
        $row->loadMissing(['owner:id,full_name,phone,national_id', 'issuer:id,name']);

        return [
            'id' => $row->id,
            'vehicle_id' => $row->vehicle_id,
            'owner_id' => $row->owner_id,
            'owner' => $row->owner ? [
                'id' => $row->owner->id,
                'full_name' => $row->owner->full_name,
                'phone' => $row->owner->phone,
                'national_id' => $row->owner->national_id,
            ] : null,
            'number' => $row->number,
            'starts_on' => $row->starts_on?->toDateString(),
            'ends_on' => $row->ends_on?->toDateString(),
            'monthly_amount' => (int) $row->monthly_amount,
            'template_id' => $row->template_id,
            'template_version' => (int) $row->template_version,
            'issued_by' => $row->issued_by,
            'issuer_name' => $row->issuer?->name,
            'issued_at' => $row->issued_at?->toIso8601String(),
            'created_at' => $row->created_at?->toIso8601String(),
            // Issued copies are immutable — HTML is frozen at issue time.
            'rendered_html' => $row->rendered_html,
            'immutable' => true,
        ];
    }

    private function nextNumber(): string
    {
        $prefix = 'CAD-'.now()->format('Y').'-';
        $latest = VehicleCustodyAgreement::query()
            ->where('number', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->value('number');

        $seq = 1;
        if (is_string($latest) && preg_match('/(\d+)$/', $latest, $m)) {
            $seq = ((int) $m[1]) + 1;
        }

        return $prefix.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    private function resolveDate(?string $override, ?string $fallback, string $missingMessage): string
    {
        $value = trim((string) ($override ?: $fallback ?: ''));
        if ($value === '') {
            throw new DomainException($missingMessage);
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new DomainException('صيغة التاريخ يجب أن تكون YYYY-MM-DD.');
        }

        return $value;
    }
}
