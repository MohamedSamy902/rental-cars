<?php

namespace App\Support;

use App\Models\User;

/**
 * Owner identity stays behind owners.view.
 * The monthly owner obligation is an owner or finance figure
 * (owners.view, reports.view, or accounting.view).
 * Agreement start/end dates stay on the vehicle because rental eligibility uses them.
 */
final class VehicleOwnerVisibility
{
    public static function canSeeProfile(?User $user): bool
    {
        return (bool) $user?->hasPermission('owners.view');
    }

    public static function canSeeAmount(?User $user): bool
    {
        return self::canSeeProfile($user)
            || (bool) $user?->hasPermission('reports.view')
            || (bool) $user?->hasPermission('accounting.view');
    }

    /**
     * @param  array<string, mixed>  $vehicle
     * @return array<string, mixed>
     */
    public static function redactVehicle(array $vehicle, ?User $user): array
    {
        if (! self::canSeeProfile($user)) {
            $vehicle['owner_id'] = null;
            $vehicle['owner'] = null;
            $vehicle['owner_since'] = null;
        }
        if (! self::canSeeAmount($user)) {
            $vehicle['owner_monthly_amount'] = null;
        }

        return $vehicle;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function redactEmbedded(array $payload, ?User $user): array
    {
        if (isset($payload['vehicle']) && is_array($payload['vehicle'])) {
            $payload['vehicle'] = self::redactVehicle($payload['vehicle'], $user);
        }

        return $payload;
    }
}
