<?php

namespace App\Services\Violations;

use App\Models\Contract;
use Carbon\Carbon;

class ViolationMatchService
{
    /**
     * يطابق بحيازة التسليم/الاستلام (ن٫١١) وليس مجرد starts_at.
     *
     * @return array{contract_id: int|null, customer_id: int|null, needs_review: bool, candidates: int}
     */
    public function match(int $vehicleId, string|\DateTimeInterface $occurredAt): array
    {
        $at = Carbon::parse($occurredAt);

        $candidates = Contract::query()
            ->where('vehicle_id', $vehicleId)
            ->whereIn('status', ['active', 'completed'])
            ->whereNotNull('odometer_out')
            ->where('starts_at', '<=', $at)
            ->where(function ($q) use ($at) {
                $q->whereNull('actual_ends_at')
                    ->orWhere('actual_ends_at', '>=', $at);
            })
            ->orderByDesc('starts_at')
            ->get(['id', 'customer_id', 'starts_at', 'actual_ends_at']);

        $count = $candidates->count();
        if ($count === 0) {
            return [
                'contract_id' => null,
                'customer_id' => null,
                'needs_review' => true,
                'candidates' => 0,
            ];
        }

        if ($count > 1) {
            return [
                'contract_id' => null,
                'customer_id' => null,
                'needs_review' => true,
                'candidates' => $count,
            ];
        }

        $hit = $candidates->first();

        return [
            'contract_id' => (int) $hit->id,
            'customer_id' => (int) $hit->customer_id,
            'needs_review' => false,
            'candidates' => 1,
        ];
    }
}
