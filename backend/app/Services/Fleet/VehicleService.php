<?php

namespace App\Services\Fleet;

use App\Models\DocumentTemplate;
use App\Models\PrintSnapshot;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleAddonCatalog;
use App\Models\VehicleBrand;
use App\Models\VehicleDocument;
use App\Models\VehicleDocumentHistory;
use App\Models\VehicleOwner;
use App\Models\VehicleTrackerSimRecharge;
use App\Services\Documents\DocumentTemplateService;
use DomainException;
use Illuminate\Support\Facades\DB;

class VehicleService
{
    public function __construct(
        private readonly DocumentTemplateService $templates,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $ownerInline
     * @param  list<int|array<string, mixed>>  $addonIds
     */
    public function create(array $data, ?array $ownerInline = null, array $addonIds = []): Vehicle
    {
        return DB::transaction(function () use ($data, $ownerInline, $addonIds) {
            if (! empty($ownerInline['full_name'])) {
                $owner = VehicleOwner::query()->create([
                    'full_name' => $ownerInline['full_name'],
                    'phone' => $ownerInline['phone'] ?? null,
                    'phone_alt' => $ownerInline['phone_alt'] ?? null,
                    'national_id' => $ownerInline['national_id'] ?? null,
                    'id_front_path' => $ownerInline['id_front_path'] ?? null,
                    'id_back_path' => $ownerInline['id_back_path'] ?? null,
                    'notes' => $ownerInline['notes'] ?? null,
                    'is_active' => true,
                ]);
                $data['owner_id'] = $owner->id;
                if (empty($data['owner_since'])) {
                    $data['owner_since'] = now()->toDateString();
                }
            }

            $data['plate'] = $this->normalizePlate($data);
            $this->assertUniquePlate($data['plate']);
            $this->assertChassisEngine($data, null, true);

            if (empty($data['code'])) {
                $data['code'] = $this->nextInternalCode();
            }

            if (! empty($data['owner_id']) && empty($data['owner_since'])) {
                $data['owner_since'] = now()->toDateString();
            }
            if (empty($data['owner_id'])) {
                $data['owner_id'] = null;
                $data['owner_monthly_amount'] = null;
            }

            $license = $data['license'] ?? null;
            unset(
                $data['rental_priority'],
                $data['owner_inline'],
                $data['addon_ids'],
                $data['plate_letters'],
                $data['plate_numbers'],
                $data['license'],
            );

            $vehicle = Vehicle::query()->create([
                ...$data,
                'odometer_source' => 'manual',
                'is_active' => $data['is_active'] ?? true,
            ]);

            $this->attachAddons($vehicle, $addonIds);
            $this->syncLicenseDocument($vehicle, is_array($license) ? $license : null);

            $vehicle = $vehicle->load(['owner', 'accessories', 'documents']);
            $this->syncOwnerAgreementSnapshot($vehicle);

            return $vehicle;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<int>|null  $addonIds  when non-null, sync catalog add-ons onto vehicle accessories
     */
    public function update(Vehicle $vehicle, array $data, ?array $addonIds = null): Vehicle
    {
        return DB::transaction(function () use ($vehicle, $data, $addonIds) {
            if (array_key_exists('plate', $data) || isset($data['plate_letters']) || isset($data['plate_numbers'])) {
                $data['plate'] = $this->normalizePlate(array_merge(
                    ['plate' => $vehicle->plate, 'plate_letters' => null, 'plate_numbers' => null],
                    $data,
                ));
                $this->assertUniquePlate($data['plate'], $vehicle->id);
            }
            $this->assertChassisEngine($data, $vehicle);

            if (array_key_exists('owner_id', $data) && empty($data['owner_id'])) {
                $data['owner_id'] = null;
                $data['owner_monthly_amount'] = null;
                $data['owner_agreement_starts_on'] = null;
                $data['owner_agreement_ends_on'] = null;
            }
            if (! empty($data['owner_id']) && empty($data['owner_since']) && ! $vehicle->owner_since) {
                $data['owner_since'] = now()->toDateString();
            }
            if (! empty($data['owner_id'])
                && empty($data['owner_agreement_starts_on'] ?? null)
                && ! empty($data['owner_agreement_ends_on'] ?? null)
                && ! $vehicle->owner_agreement_starts_on) {
                $data['owner_agreement_starts_on'] = $data['owner_since']
                    ?? $vehicle->owner_since?->toDateString()
                    ?? now()->toDateString();
            }

            // Internal code stays system-owned after create.
            $license = $data['license'] ?? null;
            unset(
                $data['code'],
                $data['plate_letters'],
                $data['plate_numbers'],
                $data['owner_inline'],
                $data['addon_ids'],
                $data['rental_priority'],
                $data['license'],
            );

            $vehicle->update($data);

            if ($addonIds !== null) {
                $this->syncAddonsFromCatalog($vehicle, $addonIds);
            }
            if (is_array($license)) {
                $this->syncLicenseDocument($vehicle, $license);
            }

            $vehicle = $vehicle->fresh(['owner', 'accessories', 'documents']);
            $this->syncOwnerAgreementSnapshot($vehicle);

            return $vehicle;
        });
    }

    /**
     * عند ربط مالك بالعربية: يُنشأ/يُحدَّث سجل عقد قابل للطباعة من قالب الإعدادات.
     */
    public function syncOwnerAgreementSnapshot(Vehicle $vehicle, ?int $userId = null): ?PrintSnapshot
    {
        if (! $vehicle->owner_id) {
            return null;
        }

        $this->templates->ensureDefaults($userId);
        $template = $this->templates->getActive(DocumentTemplate::TYPE_OWNER_AGREEMENT);
        if (! $template) {
            return null;
        }

        $variables = $this->templates->variablesForOwnerAgreement($vehicle, null, $template);
        $html = $this->templates->render($template->content, $template->format, $variables);

        return PrintSnapshot::query()->create([
            'type' => DocumentTemplate::TYPE_OWNER_AGREEMENT,
            'record_type' => 'vehicle',
            'record_id' => $vehicle->id,
            'payload' => [
                'html' => $html,
                'variables' => $variables,
                'template_id' => $template->id,
                'template_version' => $template->version,
                'title' => $template->title,
                'number' => $variables['owner_agreement_number'] ?? null,
            ],
            'printed_by' => $userId,
            'printed_at' => now(),
        ]);
    }

    /**
     * @return array{html:string,variables:array<string,string>,template:array<string,mixed>,snapshot_id:?int,number:string}
     */
    public function ownerAgreementPrintPayload(Vehicle $vehicle): array
    {
        if (! $vehicle->owner_id) {
            throw new DomainException('لا يوجد مالك مرتبط بهذه العربية لطباعة عقد المالك.');
        }

        $this->templates->ensureDefaults();
        $template = $this->templates->getActive(DocumentTemplate::TYPE_OWNER_AGREEMENT);
        if (! $template) {
            throw new DomainException('لا يوجد قالب نشط لعقد المالك. راجع الإعدادات.');
        }

        $variables = $this->templates->variablesForOwnerAgreement($vehicle->loadMissing('owner'), null, $template);
        $html = $this->templates->render($template->content, $template->format, $variables);
        $latest = PrintSnapshot::query()
            ->where('type', DocumentTemplate::TYPE_OWNER_AGREEMENT)
            ->where('record_type', 'vehicle')
            ->where('record_id', $vehicle->id)
            ->latest('id')
            ->first();

        return [
            'html' => $html,
            'variables' => $variables,
            'template' => $this->templates->present($template),
            'snapshot_id' => $latest?->id,
            'number' => (string) ($variables['owner_agreement_number'] ?? ''),
        ];
    }

    /**
     * Optional traffic license on create/edit — allowed missing or expired.
     *
     * @param  array{has_license?: bool|int|string|null, document_number?: string|null, expires_on?: string|null, issued_on?: string|null, file_path?: string|null, file_path_back?: string|null, notes?: string|null}|null  $license
     */
    private function syncLicenseDocument(Vehicle $vehicle, ?array $license): void
    {
        if ($license === null) {
            return;
        }

        $has = filter_var($license['has_license'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $existing = $vehicle->documents()
            ->where(function ($q) {
                $q->where('type', 'رخصة تسيير')
                    ->orWhere('type', 'like', '%رخص%')
                    ->orWhere('type', 'like', '%license%');
            })
            ->orderByDesc('id')
            ->first();

        if (! $has) {
            // Keep historical rows; do not force-delete — just leave absent.
            return;
        }

        $payload = [
            'type' => 'رخصة تسيير',
            'document_number' => array_key_exists('document_number', $license)
                ? ($license['document_number'] ?: null)
                : $existing?->document_number,
            'file_path' => array_key_exists('file_path', $license) && filled($license['file_path'])
                ? (string) $license['file_path']
                : ($existing?->file_path ?? 'manual/رخصة تسيير'),
            'file_path_back' => array_key_exists('file_path_back', $license)
                ? ($license['file_path_back'] ?: null)
                : $existing?->file_path_back,
            'issued_on' => array_key_exists('issued_on', $license)
                ? ($license['issued_on'] ?: null)
                : $existing?->issued_on?->toDateString(),
            'expires_on' => array_key_exists('expires_on', $license)
                ? ($license['expires_on'] ?: null)
                : $existing?->expires_on?->toDateString(),
            'notes' => array_key_exists('notes', $license)
                ? ($license['notes'] ?: null)
                : $existing?->notes,
        ];

        if ($existing) {
            $materialChange = (string) ($existing->expires_on?->toDateString() ?? '') !== (string) ($payload['expires_on'] ?? '')
                || (string) ($existing->issued_on?->toDateString() ?? '') !== (string) ($payload['issued_on'] ?? '')
                || (string) ($existing->file_path ?? '') !== (string) ($payload['file_path'] ?? '')
                || (string) ($existing->file_path_back ?? '') !== (string) ($payload['file_path_back'] ?? '')
                || (string) ($existing->document_number ?? '') !== (string) ($payload['document_number'] ?? '');

            if ($materialChange) {
                $this->renewLicense($vehicle, $existing, $payload, auth()->id());
            } else {
                $existing->update(['notes' => $payload['notes']]);
            }
        } else {
            $vehicle->documents()->create($payload);
        }
    }

    public function nextInternalCode(): string
    {
        $max = Vehicle::query()->withTrashed()
            ->where('code', 'like', 'VEH-%')
            ->pluck('code')
            ->map(function (string $code) {
                if (preg_match('/^VEH-(\d+)$/', $code, $m)) {
                    return (int) $m[1];
                }

                return 0;
            })
            ->max() ?? 0;

        return sprintf('VEH-%04d', $max + 1);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function normalizePlate(array $data): string
    {
        if (! empty($data['plate_letters']) || ! empty($data['plate_numbers'])) {
            $letters = $this->normalizePlateLetters((string) ($data['plate_letters'] ?? ''));
            $numbers = preg_replace('/\D+/u', '', (string) ($data['plate_numbers'] ?? '')) ?? '';
            if (mb_strlen($letters) < 2 || mb_strlen($letters) > 3) {
                throw new DomainException('حروف اللوحة يجب أن تكون حرفين أو ثلاثة.');
            }
            if ($numbers === '' || strlen($numbers) > 4) {
                throw new DomainException('أرقام اللوحة مطلوبة وبحد أقصى 4 أرقام.');
            }

            return trim($letters.' '.$numbers);
        }

        $plate = trim((string) ($data['plate'] ?? ''));
        if ($plate === '') {
            throw new DomainException('رقم اللوحة مطلوب.');
        }

        return $plate;
    }

    public function normalizePlateLetters(string $raw): string
    {
        $raw = trim($raw);
        $raw = preg_replace('/\s+/u', '', $raw) ?? '';

        return mb_strtoupper($raw);
    }

    public function assertUniquePlate(string $plate, ?int $ignoreId = null): void
    {
        $exists = Vehicle::query()
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->where('plate', $plate)
            ->exists();
        if ($exists) {
            throw new DomainException('رقم اللوحة مستخدم مسبقاً.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function assertChassisEngine(array $data, ?Vehicle $existing = null, bool $requireChassis = false): void
    {
        foreach (['chassis_number' => 'رقم الشاسيه', 'engine_number' => 'رقم المحرك'] as $key => $label) {
            $mustHave = $requireChassis && $key === 'chassis_number';
            if (! array_key_exists($key, $data) && ! $mustHave) {
                continue;
            }
            $value = trim((string) ($data[$key] ?? ''));
            if ($value === '') {
                if ($mustHave) {
                    throw new DomainException('رقم الشاسيه مطلوب.');
                }

                continue;
            }
            if (! preg_match('/^[A-Za-z0-9\-]+$/', $value)) {
                throw new DomainException($label.' يقبل حروفاً إنجليزية وأرقاماً وشرطة فقط.');
            }
            if (strlen($value) < 5 || strlen($value) > 32) {
                throw new DomainException($label.' يجب أن يكون بين 5 و 32 حرفاً.');
            }
            $dup = Vehicle::query()
                ->when($existing, fn ($q) => $q->where('id', '!=', $existing->id))
                ->where($key, $value)
                ->exists();
            if ($dup) {
                throw new DomainException($label.' مستخدم على عربية أخرى.');
            }
        }
    }

    /**
     * Archive the previous license row, then write the new active license fields.
     *
     * @param  array<string, mixed>  $data
     */
    public function renewLicense(Vehicle $vehicle, VehicleDocument $document, array $data, ?int $userId = null): VehicleDocument
    {
        return DB::transaction(function () use ($vehicle, $document, $data, $userId) {
            if ($document->vehicle_id !== $vehicle->id) {
                throw new DomainException('المستند لا يخص هذه العربية.');
            }

            VehicleDocumentHistory::query()->create([
                'vehicle_id' => $vehicle->id,
                'vehicle_document_id' => $document->id,
                'type' => $document->type,
                'document_number' => $document->document_number,
                'file_path' => $document->file_path,
                'file_path_back' => $document->file_path_back,
                'issued_on' => $document->issued_on,
                'expires_on' => $document->expires_on,
                'notes' => $document->notes,
                'archived_at' => now(),
                'archived_by' => $userId,
            ]);

            $document->update([
                'type' => $data['type'] ?? $document->type,
                'document_number' => array_key_exists('document_number', $data)
                    ? ($data['document_number'] ?: null)
                    : $document->document_number,
                'file_path' => $data['file_path'] ?? $document->file_path,
                'file_path_back' => $data['file_path_back'] ?? $document->file_path_back,
                'issued_on' => $data['issued_on'] ?? $document->issued_on,
                'expires_on' => $data['expires_on'] ?? null,
                'notes' => $data['notes'] ?? $document->notes,
            ]);

            return $document->fresh();
        });
    }

    /**
     * @param  list<int|array<string, mixed>>  $addonIds
     */
    private function attachAddons(Vehicle $vehicle, array $addonIds): void
    {
        foreach ($addonIds as $entry) {
            $id = is_array($entry) ? (int) ($entry['id'] ?? 0) : (int) $entry;
            if ($id < 1) {
                continue;
            }
            $catalog = VehicleAddonCatalog::query()->whereKey($id)->where('is_active', true)->first();
            if (! $catalog) {
                continue;
            }
            $vehicle->accessories()->create([
                'name' => $catalog->name,
                'default_qty' => is_array($entry) ? (int) ($entry['default_qty'] ?? $catalog->default_qty) : $catalog->default_qty,
                'required_each_rental' => $catalog->required_each_rental,
                'is_active' => true,
                'notes' => $catalog->notes,
                'sort_order' => $catalog->sort_order,
            ]);
        }
    }

    /**
     * Sync catalog-based add-ons onto vehicle accessories (create missing, remove deselected catalog matches).
     *
     * @param  list<int|array<string, mixed>>  $addonIds
     */
    private function syncAddonsFromCatalog(Vehicle $vehicle, array $addonIds): void
    {
        $selectedIds = [];
        foreach ($addonIds as $entry) {
            $id = is_array($entry) ? (int) ($entry['id'] ?? 0) : (int) $entry;
            if ($id > 0) {
                $selectedIds[] = $id;
            }
        }
        $selectedIds = array_values(array_unique($selectedIds));

        $catalogRows = VehicleAddonCatalog::query()
            ->whereIn('id', $selectedIds)
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        $selectedNames = $catalogRows->pluck('name')->map(fn ($n) => mb_strtolower(trim((string) $n)))->all();
        $allCatalogNames = VehicleAddonCatalog::query()
            ->pluck('name')
            ->map(fn ($n) => mb_strtolower(trim((string) $n)))
            ->all();

        $existing = $vehicle->accessories()->get();
        foreach ($existing as $acc) {
            $nameKey = mb_strtolower(trim((string) $acc->name));
            if (! in_array($nameKey, $allCatalogNames, true)) {
                // Manually added accessory — leave alone.
                continue;
            }
            if (! in_array($nameKey, $selectedNames, true)) {
                $acc->delete();
            }
        }

        $remainingNames = $vehicle->accessories()->pluck('name')
            ->map(fn ($n) => mb_strtolower(trim((string) $n)))
            ->all();

        foreach ($selectedIds as $id) {
            $catalog = $catalogRows->get($id);
            if (! $catalog) {
                continue;
            }
            $nameKey = mb_strtolower(trim((string) $catalog->name));
            if (in_array($nameKey, $remainingNames, true)) {
                $vehicle->accessories()
                    ->whereRaw('LOWER(name) = ?', [$nameKey])
                    ->update([
                        'is_active' => true,
                        'default_qty' => $catalog->default_qty,
                        'required_each_rental' => $catalog->required_each_rental,
                    ]);

                continue;
            }
            $vehicle->accessories()->create([
                'name' => $catalog->name,
                'default_qty' => $catalog->default_qty,
                'required_each_rental' => $catalog->required_each_rental,
                'is_active' => true,
                'notes' => $catalog->notes,
                'sort_order' => $catalog->sort_order,
            ]);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function brandOptions(): array
    {
        return VehicleBrand::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (VehicleBrand $b) => ['id' => $b->id, 'name' => $b->name])
            ->all();
    }

    /**
     * Add a brand to the master list from the vehicle create/edit flow (RSD 6.1.1 · UX 3.21).
     */
    public function createBrand(string $name): VehicleBrand
    {
        $name = trim($name);
        if ($name === '') {
            throw new DomainException('اسم الماركة مطلوب.');
        }
        if (mb_strlen($name) > 100) {
            throw new DomainException('اسم الماركة طويل جداً.');
        }

        $existing = VehicleBrand::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();

        if ($existing) {
            if (! $existing->is_active) {
                $existing->update(['is_active' => true]);
            }

            return $existing->fresh() ?? $existing;
        }

        $maxSort = (int) VehicleBrand::query()->max('sort_order');

        return VehicleBrand::query()->create([
            'name' => $name,
            'sort_order' => $maxSort + 1,
            'is_active' => true,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function addonCatalog(): array
    {
        return VehicleAddonCatalog::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')
            ->get()
            ->map(fn (VehicleAddonCatalog $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'category' => $a->category,
                'default_qty' => $a->default_qty,
                'required_each_rental' => $a->required_each_rental,
            ])
            ->all();
    }

    /**
     * يسجّل شحن شريحة التتبع: يحدّث العربية ويضيف سطراً في سجل الشحن.
     *
     * @param  array{amount:int,recharged_on?:string,next_recharge_on:string,notes?:?string}  $data
     * @return array{vehicle: Vehicle, recharge: VehicleTrackerSimRecharge}
     */
    public function recordTrackerSimRecharge(Vehicle $vehicle, array $data, ?User $actor = null): array
    {
        $sim = trim((string) ($vehicle->tracker_sim_number ?? ''));
        if ($sim === '') {
            throw new DomainException('لا يوجد رقم شريحة تتبع على هذه العربية.');
        }

        $rechargedOn = (string) ($data['recharged_on'] ?? now()->toDateString());
        $nextOn = (string) $data['next_recharge_on'];
        $amount = (int) $data['amount'];
        if ($amount < 1) {
            throw new DomainException('مبلغ الشحن يجب أن يكون أكبر من صفر.');
        }
        if ($nextOn < $rechargedOn) {
            throw new DomainException('موعد الشحن القادم لا يمكن أن يسبق تاريخ الشحن.');
        }

        return DB::transaction(function () use ($vehicle, $amount, $rechargedOn, $nextOn, $data, $actor) {
            $cycle = (int) ($vehicle->tracker_sim_recharge_cycle_days ?: 30);
            $fromDates = max(1, (int) round(
                (strtotime($nextOn) - strtotime($rechargedOn)) / 86400
            ));

            $vehicle->forceFill([
                'tracker_sim_last_recharged_on' => $rechargedOn,
                'tracker_sim_last_recharge_amount' => $amount,
                'tracker_sim_next_recharge_on' => $nextOn,
                'tracker_sim_recharge_cycle_days' => $fromDates > 0 ? $fromDates : $cycle,
            ])->save();

            $row = VehicleTrackerSimRecharge::query()->create([
                'vehicle_id' => $vehicle->id,
                'amount' => $amount,
                'recharged_on' => $rechargedOn,
                'next_recharge_on' => $nextOn,
                'created_by' => $actor?->id,
                'notes' => $data['notes'] ?? null,
            ]);

            return [
                'vehicle' => $vehicle->fresh(['owner', 'documents', 'accessories']) ?? $vehicle,
                'recharge' => $row,
            ];
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function trackerSimRechargeLog(Vehicle $vehicle, int $limit = 40): array
    {
        return VehicleTrackerSimRecharge::query()
            ->with('creator:id,name')
            ->where('vehicle_id', $vehicle->id)
            ->orderByDesc('recharged_on')
            ->orderByDesc('id')
            ->limit(max(1, min(100, $limit)))
            ->get()
            ->map(fn (VehicleTrackerSimRecharge $r) => [
                'id' => $r->id,
                'amount' => (int) $r->amount,
                'recharged_on' => $r->recharged_on?->toDateString(),
                'next_recharge_on' => $r->next_recharge_on?->toDateString(),
                'notes' => $r->notes,
                'created_by_name' => $r->creator?->name,
                'created_at' => $r->created_at?->toIso8601String(),
            ])
            ->all();
    }
}
