<?php

namespace App\Services\Fleet;

use DomainException;

/**
 * Vehicle status for a given date and overlap checks (RSD 3.8 / 3.13).
 */
class VehicleAvailabilityService
{
    public const STATUS_AVAILABLE = 'available';

    public const STATUS_RESERVED = 'reserved';

    public const STATUS_RENTED = 'rented';

    public const STATUS_MAINTENANCE = 'maintenance';

    public const STATUS_OUT_OF_SERVICE = 'out_of_service';

    public const STATUS_SOLD = 'sold';

    /**
     * @param  array{
     *     manual_status?: string|null,
     *     has_active_handover?: bool,
     *     has_approved_covering_date?: bool,
     *     open_work_order_blocks?: bool
     * }  $context
     */
    public function statusOnDate(array $context): string
    {
        if (! empty($context['manual_status']) && in_array($context['manual_status'], [
            self::STATUS_MAINTENANCE,
            self::STATUS_OUT_OF_SERVICE,
            self::STATUS_SOLD,
        ], true)) {
            return $context['manual_status'];
        }

        if (! empty($context['open_work_order_blocks'])) {
            return self::STATUS_MAINTENANCE;
        }

        if (! empty($context['has_active_handover'])) {
            return self::STATUS_RENTED;
        }

        if (! empty($context['has_approved_covering_date'])) {
            return self::STATUS_RESERVED;
        }

        return self::STATUS_AVAILABLE;
    }

    /**
     * Refuse silent maintenance conversion when future bookings overlap.
     *
     * @param  list<array{id: int|string, number?: string}>  $conflictingContracts
     */
    public function assertCanEnterMaintenance(array $conflictingContracts, bool $forceAfterResolution = false): void
    {
        if ($conflictingContracts === [] || $forceAfterResolution) {
            return;
        }

        $ids = implode('، ', array_map(
            static fn (array $c) => (string) ($c['number'] ?? $c['id']),
            $conflictingContracts,
        ));

        throw new DomainException(
            "لا يمكن تحويل السيارة للصيانة لوجود عقود متعارضة: {$ids}. ألغِ أو عدّل العقود أولاً."
        );
    }
}
