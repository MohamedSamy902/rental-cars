<?php

namespace App\Services\Violations;

use App\Models\Contract;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleViolationInquiry;
use Illuminate\Support\Carbon;

/**
 * قائمة استعلام المخالفات اليومي على لوحات الأسطول — بلا ترحيل محاسبي.
 */
class ViolationInquiryService
{
    /**
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function list(string $filter, ?string $search, bool $canViewNationalId, ?Carbon $on = null): array
    {
        $day = ($on ?? now())->toDateString();

        $inquiredIds = VehicleViolationInquiry::query()
            ->whereDate('inquired_on', $day)
            ->pluck('vehicle_id')
            ->all();

        $rentedIds = Contract::query()
            ->where('status', 'active')
            ->whereDate('starts_at', '<=', $day)
            ->where(function ($q) use ($day) {
                $q->whereNull('actual_ends_at')
                    ->orWhereDate('actual_ends_at', '>=', $day);
            })
            ->pluck('vehicle_id')
            ->unique()
            ->values()
            ->all();

        $q = Vehicle::query()
            ->with(['owner:id,full_name,phone,national_id'])
            ->where('is_active', true)
            ->orderBy('plate');

        if ($search = trim((string) $search)) {
            $q->where(function ($qq) use ($search) {
                $qq->where('plate', 'like', "%{$search}%")
                    ->orWhere('brand', 'like', "%{$search}%")
                    ->orWhere('model', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhereHas('owner', function ($o) use ($search) {
                        $o->where('full_name', 'like', "%{$search}%")
                            ->orWhere('phone', 'like', "%{$search}%")
                            ->orWhere('national_id', 'like', "%{$search}%");
                    });
            });
        }

        match ($filter) {
            'done_today' => $q->whereIn('id', $inquiredIds === [] ? [0] : $inquiredIds),
            'pending' => $q->whereNotIn('id', $inquiredIds === [] ? [0] : $inquiredIds),
            'rented_today' => $q->whereIn('id', $rentedIds === [] ? [0] : $rentedIds),
            default => null,
        };

        $vehicles = $q->get();

        $inquiryByVehicle = VehicleViolationInquiry::query()
            ->with('inquirer:id,name')
            ->whereDate('inquired_on', $day)
            ->whereIn('vehicle_id', $vehicles->pluck('id')->all() ?: [0])
            ->get()
            ->keyBy('vehicle_id');

        $rentedSet = array_fill_keys($rentedIds, true);
        $inquiredSet = array_fill_keys($inquiredIds, true);

        $data = $vehicles->map(function (Vehicle $v) use ($canViewNationalId, $inquiryByVehicle, $rentedSet, $day) {
            $inquiry = $inquiryByVehicle->get($v->id);
            $owner = $v->owner;

            return [
                'vehicle_id' => $v->id,
                'category' => $v->category,
                'plate' => $v->plate,
                'brand' => $v->brand,
                'model' => $v->model,
                'code' => $v->code,
                'owner_name' => $owner?->full_name,
                'owner_phone' => $owner?->phone,
                'owner_national_id' => $this->maskNationalId($owner?->national_id, $canViewNationalId),
                'rented_today' => isset($rentedSet[$v->id]),
                'inquired_today' => $inquiry !== null,
                'inquired_on' => $day,
                'inquired_by' => $inquiry?->inquired_by,
                'inquired_by_name' => $inquiry?->inquirer?->name,
                'inquired_at' => $inquiry?->created_at?->toIso8601String(),
            ];
        })->values()->all();

        return [
            'data' => $data,
            'meta' => [
                'date' => $day,
                'total' => count($data),
                'done_today' => count($inquiredIds),
                'pending' => max(0, Vehicle::query()->where('is_active', true)->count() - count($inquiredIds)),
                'rented_today' => count($rentedIds),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function mark(Vehicle $vehicle, User $user, ?string $notes = null, ?Carbon $on = null): array
    {
        $day = ($on ?? now())->toDateString();

        $row = VehicleViolationInquiry::query()->updateOrCreate(
            [
                'vehicle_id' => $vehicle->id,
                'inquired_on' => $day,
            ],
            [
                'inquired_by' => $user->id,
                'notes' => $notes,
            ],
        );
        $row->load('inquirer:id,name');
        $vehicle->loadMissing('owner:id,full_name,phone,national_id');

        $rentedToday = Contract::query()
            ->where('vehicle_id', $vehicle->id)
            ->where('status', 'active')
            ->whereDate('starts_at', '<=', $day)
            ->where(function ($q) use ($day) {
                $q->whereNull('actual_ends_at')
                    ->orWhereDate('actual_ends_at', '>=', $day);
            })
            ->exists();

        return [
            'vehicle_id' => $vehicle->id,
            'category' => $vehicle->category,
            'plate' => $vehicle->plate,
            'brand' => $vehicle->brand,
            'model' => $vehicle->model,
            'code' => $vehicle->code,
            'owner_name' => $vehicle->owner?->full_name,
            'owner_phone' => $vehicle->owner?->phone,
            'owner_national_id' => $this->maskNationalId(
                $vehicle->owner?->national_id,
                $user->hasPermission('sensitive.view_national_id'),
            ),
            'rented_today' => $rentedToday,
            'inquired_today' => true,
            'inquired_on' => $day,
            'inquired_by' => $row->inquired_by,
            'inquired_by_name' => $row->inquirer?->name,
            'inquired_at' => $row->created_at?->toIso8601String(),
        ];
    }

    private function maskNationalId(?string $nid, bool $canView): ?string
    {
        if ($nid === null || $nid === '') {
            return $nid;
        }
        if ($canView) {
            return $nid;
        }
        $len = mb_strlen($nid);
        $visible = min(4, $len);

        return str_repeat('*', max(0, $len - $visible)).mb_substr($nid, -$visible);
    }
}
