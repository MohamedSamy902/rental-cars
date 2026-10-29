<?php

namespace App\Services\Fleet;

use App\Models\ConsumablePartTemplate;
use App\Models\TenantSetting;
use App\Models\Vehicle;
use App\Models\VehicleBattery;
use App\Models\VehicleConsumableState;
use App\Models\VehicleDowntimeDay;
use App\Models\VehicleTire;
use Carbon\Carbon;

class VehicleAssetService
{
    public function lostIncomeDailyBasis(): int
    {
        $ops = TenantSetting::query()->value('operational') ?? [];

        return max(0, (int) ($ops['lost_income_daily_basis'] ?? 0));
    }

    public function defaultTireLifeKm(): int
    {
        $ops = TenantSetting::query()->value('operational') ?? [];

        return max(1, (int) ($ops['tire_life_km'] ?? 40000));
    }

    public function defaultBatteryLifeKm(): int
    {
        $ops = TenantSetting::query()->value('operational') ?? [];

        return max(1, (int) ($ops['battery_life_km'] ?? 60000));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function addTire(Vehicle $vehicle, array $data): VehicleTire
    {
        return VehicleTire::query()->create([
            'vehicle_id' => $vehicle->id,
            'position' => $data['position'],
            'brand' => $data['brand'] ?? null,
            'installed_on' => $data['installed_on'],
            'odometer_at_install' => (int) $data['odometer_at_install'],
            'expected_life_km' => (int) ($data['expected_life_km'] ?? $this->defaultTireLifeKm()),
            'replaced_on' => $data['replaced_on'] ?? null,
            'odometer_at_replace' => $data['odometer_at_replace'] ?? null,
            'cost' => $data['cost'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function addBattery(Vehicle $vehicle, array $data): VehicleBattery
    {
        return VehicleBattery::query()->create([
            'vehicle_id' => $vehicle->id,
            'brand' => $data['brand'] ?? null,
            'installed_on' => $data['installed_on'],
            'odometer_at_install' => (int) $data['odometer_at_install'],
            'expected_life_km' => (int) ($data['expected_life_km'] ?? $this->defaultBatteryLifeKm()),
            'replaced_on' => $data['replaced_on'] ?? null,
            'odometer_at_replace' => $data['odometer_at_replace'] ?? null,
            'cost' => $data['cost'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function openDowntime(Vehicle $vehicle, array $data): VehicleDowntimeDay
    {
        $starts = Carbon::parse($data['starts_at']);
        $ends = isset($data['ends_at']) ? Carbon::parse($data['ends_at']) : null;
        $days = $ends
            ? max(1, (int) $starts->copy()->startOfDay()->diffInDays($ends->copy()->startOfDay()) + 1)
            : max(1, (int) $starts->copy()->startOfDay()->diffInDays(now()->startOfDay()) + 1);
        $basis = $this->lostIncomeDailyBasis();
        if ($basis < 1) {
            $basis = (int) $vehicle->daily_rate;
        }

        return VehicleDowntimeDay::query()->create([
            'vehicle_id' => $vehicle->id,
            'reason' => $data['reason'],
            'starts_at' => $starts,
            'ends_at' => $ends,
            'days_count' => $days,
            'lost_revenue_estimate' => (int) round($days * $basis),
            'work_order_id' => $data['work_order_id'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function upsertTemplate(array $data, ?ConsumablePartTemplate $template = null): ConsumablePartTemplate
    {
        $template ??= new ConsumablePartTemplate;
        $template->fill([
            'name' => $data['name'],
            'category' => $data['category'] ?? null,
            'default_life_km' => $data['default_life_km'] ?? null,
            'default_life_days' => $data['default_life_days'] ?? null,
            'is_active' => $data['is_active'] ?? true,
            'notes' => $data['notes'] ?? null,
        ])->save();

        return $template;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function setVehicleConsumable(Vehicle $vehicle, ConsumablePartTemplate $template, array $data): VehicleConsumableState
    {
        $lifeKm = $data['life_km'] ?? $template->default_life_km;
        $lifeDays = $data['life_days'] ?? $template->default_life_days;
        $installedOn = isset($data['installed_on']) ? Carbon::parse($data['installed_on']) : null;
        $odo = isset($data['odometer_at_install']) ? (int) $data['odometer_at_install'] : null;

        return VehicleConsumableState::query()->updateOrCreate(
            ['vehicle_id' => $vehicle->id, 'template_id' => $template->id],
            [
                'installed_on' => $installedOn,
                'odometer_at_install' => $odo,
                'life_km' => $lifeKm,
                'life_days' => $lifeDays,
                'due_on' => $installedOn && $lifeDays ? $installedOn->copy()->addDays((int) $lifeDays) : null,
                'due_odometer' => $odo && $lifeKm ? $odo + (int) $lifeKm : null,
                'status' => $data['status'] ?? 'ok',
                'notes' => $data['notes'] ?? null,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function dossierExtras(Vehicle $vehicle): array
    {
        return [
            'tires' => VehicleTire::query()->where('vehicle_id', $vehicle->id)->latest('id')->get(),
            'batteries' => VehicleBattery::query()->where('vehicle_id', $vehicle->id)->latest('id')->get(),
            'downtime' => VehicleDowntimeDay::query()->where('vehicle_id', $vehicle->id)->latest('starts_at')->get(),
            'consumables' => VehicleConsumableState::query()->with('template')->where('vehicle_id', $vehicle->id)->get(),
            'defaults' => [
                'tire_life_km' => $this->defaultTireLifeKm(),
                'battery_life_km' => $this->defaultBatteryLifeKm(),
                'lost_income_daily_basis' => $this->lostIncomeDailyBasis() ?: (int) $vehicle->daily_rate,
            ],
        ];
    }
}
