<?php

namespace App\Services\Fleet;

use App\Models\Vehicle;
use App\Models\VehicleAddonCatalog;
use App\Models\VehicleBrand;
use App\Models\VehicleDocument;
use App\Models\VehicleDocumentHistory;
use App\Models\VehicleOwner;
use DomainException;
use Illuminate\Support\Facades\DB;

class VehicleService
{
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
            $this->assertChassisEngine($data);

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

            $vehicle = Vehicle::query()->create([
                ...$data,
                'odometer_source' => 'manual',
                'rental_priority' => $data['rental_priority'] ?? 50,
                'is_active' => $data['is_active'] ?? true,
            ]);

            $this->attachAddons($vehicle, $addonIds);

            return $vehicle->load(['owner', 'accessories']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Vehicle $vehicle, array $data): Vehicle
    {
        return DB::transaction(function () use ($vehicle, $data) {
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
            }
            if (! empty($data['owner_id']) && empty($data['owner_since']) && ! $vehicle->owner_since) {
                $data['owner_since'] = now()->toDateString();
            }

            // Internal code stays system-owned after create.
            unset($data['code'], $data['plate_letters'], $data['plate_numbers'], $data['owner_inline'], $data['addon_ids']);

            $vehicle->update($data);

            return $vehicle->fresh(['owner', 'accessories', 'documents']);
        });
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
    public function assertChassisEngine(array $data, ?Vehicle $existing = null): void
    {
        foreach (['chassis_number' => 'رقم الشاسيه', 'engine_number' => 'رقم المحرك'] as $key => $label) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $value = trim((string) ($data[$key] ?? ''));
            if ($value === '') {
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
                throw new DomainException($label.' مستخدم على مركبة أخرى.');
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
                throw new DomainException('المستند لا يخص هذه المركبة.');
            }

            VehicleDocumentHistory::query()->create([
                'vehicle_id' => $vehicle->id,
                'vehicle_document_id' => $document->id,
                'type' => $document->type,
                'file_path' => $document->file_path,
                'issued_on' => $document->issued_on,
                'expires_on' => $document->expires_on,
                'notes' => $document->notes,
                'archived_at' => now(),
                'archived_by' => $userId,
            ]);

            $document->update([
                'type' => $data['type'] ?? $document->type,
                'file_path' => $data['file_path'] ?? $document->file_path,
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
}
