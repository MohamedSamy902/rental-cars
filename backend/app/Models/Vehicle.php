<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vehicle extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code', 'plate', 'chassis_number', 'engine_number', 'brand', 'model', 'year', 'color',
        'category', 'engine_capacity', 'transmission', 'fuel_type', 'seats', 'odometer',
        'odometer_source', 'daily_rate', 'default_deposit', 'target_monthly_income',
        'tracker_device_number', 'tracker_sim_number',
        'rental_priority', 'notes', 'is_active', 'manual_status', 'branch_id',
        'owner_id', 'owner_monthly_amount', 'owner_since',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'year' => 'integer',
            'odometer' => 'integer',
            'daily_rate' => 'integer',
            'default_deposit' => 'integer',
            'rental_priority' => 'integer',
            'owner_monthly_amount' => 'integer',
            'owner_since' => 'date',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(VehicleOwner::class, 'owner_id');
    }

    public function accessories(): HasMany
    {
        return $this->hasMany(VehicleAccessory::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(VehicleDocument::class);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    public static function isLicenseDocumentType(?string $type): bool
    {
        return (bool) preg_match('/رخص|license/iu', (string) $type);
    }

    /**
     * رخصة تسيير مكتملة للحجز: سجل رخصة + وش + تاريخ انتهاء.
     */
    public function hasCompleteLicense(): bool
    {
        if ($this->relationLoaded('documents')) {
            return $this->documents->contains(function (VehicleDocument $d) {
                return self::isLicenseDocumentType($d->type)
                    && filled($d->file_path)
                    && $d->expires_on !== null;
            });
        }

        return $this->documents()
            ->where(function ($q) {
                $q->where('type', 'like', '%رخص%')
                    ->orWhere('type', 'like', '%license%');
            })
            ->whereNotNull('file_path')
            ->where('file_path', '!=', '')
            ->whereNotNull('expires_on')
            ->exists();
    }

    /**
     * قيد Eloquent: عربيات برخصة تسيير مكتملة (وش + انتهاء).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Vehicle>  $query
     * @return \Illuminate\Database\Eloquent\Builder<Vehicle>
     */
    public function scopeWhereLicenseComplete($query)
    {
        return $query->whereHas('documents', function ($q) {
            $q->where(function ($qq) {
                $qq->where('type', 'like', '%رخص%')
                    ->orWhere('type', 'like', '%license%');
            })
                ->whereNotNull('file_path')
                ->where('file_path', '!=', '')
                ->whereNotNull('expires_on');
        });
    }
}
