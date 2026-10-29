<?php

namespace App\Services\Contracts;

use App\Models\Contract;
use App\Models\WorkOrder;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * مصدر واحد لنسبة تقدّم الإيجار وعوامل الحدّ (رخصة / صيانة / المدة).
 */
class RentalProgressService
{
    public const WARNING_HOURS = 48;

    /**
     * @return array{
     *   status: string,
     *   tone: 'normal'|'warning'|'danger'|'muted',
     *   percent: int,
     *   elapsed_hours: float,
     *   remaining_hours: float|null,
     *   remaining_label: string,
     *   overdue_days: int,
     *   effective_ends_at: string|null,
     *   limiting_factor: string|null,
     *   limiting_label: string|null,
     *   markers: list<array{type: string, at: string, label: string}>,
     *   message: string
     * }
     */
    public function forContract(Contract $contract, ?CarbonInterface $now = null): array
    {
        $now = Carbon::parse($now ?? now())->timezone(config('app.timezone', 'Africa/Cairo'));
        $contract->loadMissing(['customer', 'vehicle']);

        if (in_array($contract->status, ['cancelled'], true)) {
            return $this->muted('ملغى', 'العقد ملغى.');
        }

        if ($contract->status === 'completed') {
            $actual = $contract->actual_ends_at ? Carbon::parse($contract->actual_ends_at) : null;
            $planned = Carbon::parse($contract->planned_ends_at);
            $early = $actual && $actual->lt($planned);

            return [
                'status' => 'completed',
                'tone' => 'muted',
                'percent' => 100,
                'elapsed_hours' => 0.0,
                'remaining_hours' => 0.0,
                'remaining_label' => $early ? 'أُغلق مبكراً' : 'مكتمل',
                'overdue_days' => 0,
                'effective_ends_at' => optional($actual)->toIso8601String(),
                'limiting_factor' => 'completed',
                'limiting_label' => 'نهاية المدة',
                'markers' => [],
                'message' => $early ? 'أُغلق العقد قبل نهاية المدة المخططة.' : 'انتهت مدة الإيجار.',
            ];
        }

        if (in_array($contract->status, ['pending', 'approved'], true) && Carbon::parse($contract->starts_at)->gt($now)) {
            $starts = Carbon::parse($contract->starts_at);
            $hoursToStart = max(0, $now->diffInMinutes($starts, false) / 60);

            return [
                'status' => 'not_started',
                'tone' => 'normal',
                'percent' => 0,
                'elapsed_hours' => 0.0,
                'remaining_hours' => $hoursToStart,
                'remaining_label' => 'لم يبدأ بعد',
                'overdue_days' => 0,
                'effective_ends_at' => Carbon::parse($contract->planned_ends_at)->toIso8601String(),
                'limiting_factor' => 'schedule',
                'limiting_label' => 'نهاية المدة',
                'markers' => $this->markers($contract),
                'message' => 'العقد لم يبدأ بعد.',
            ];
        }

        $start = Carbon::parse($contract->starts_at);
        $plannedEnd = Carbon::parse($contract->planned_ends_at);
        $markers = $this->markers($contract);

        $effectiveEnd = $plannedEnd->copy();
        $limiting = 'schedule';
        $limitingLabel = 'نهاية المدة';

        foreach ($markers as $marker) {
            $at = Carbon::parse($marker['at']);
            if ($at->lt($effectiveEnd) && $at->gte($start)) {
                $effectiveEnd = $at->copy();
                $limiting = $marker['type'];
                $limitingLabel = $marker['label'];
            }
        }

        $totalMinutes = max(1, $start->diffInMinutes($plannedEnd));
        $elapsedMinutes = max(0, $start->diffInMinutes($now));
        $percent = (int) min(100, max(0, round(($elapsedMinutes / $totalMinutes) * 100)));

        $remainingHours = $now->diffInMinutes($effectiveEnd, false) / 60;
        $overdueDays = 0;
        $tone = 'normal';
        $remainingLabel = '';

        if ($remainingHours < 0) {
            $overdueDays = (int) max(1, ceil(abs($remainingHours) / 24));
            $tone = 'danger';
            $remainingLabel = "متأخر {$overdueDays} يوم";
            $percent = 100;
            $message = "متأخر عن الموعد — ينتهي فعلياً بسبب: {$limitingLabel}.";
        } elseif ($remainingHours <= self::WARNING_HOURS) {
            $tone = 'warning';
            $remainingLabel = $this->formatRemaining($remainingHours);
            $message = "قرب الانتهاء — ينتهي فعلياً بسبب: {$limitingLabel}.";
        } else {
            $remainingLabel = $this->formatRemaining($remainingHours);
            $message = "ينتهي فعلياً بسبب: {$limitingLabel}.";
        }

        if ($contract->status === 'pending' || $contract->status === 'approved') {
            // started window already covered; approved covering now
        }

        return [
            'status' => $contract->status,
            'tone' => $tone,
            'percent' => $percent,
            'elapsed_hours' => round($elapsedMinutes / 60, 1),
            'remaining_hours' => round($remainingHours, 1),
            'remaining_label' => $remainingLabel,
            'overdue_days' => $overdueDays,
            'effective_ends_at' => $effectiveEnd->toIso8601String(),
            'limiting_factor' => $limiting,
            'limiting_label' => $limitingLabel,
            'markers' => $markers,
            'message' => $message,
        ];
    }

    /**
     * @return list<array{type: string, at: string, label: string}>
     */
    private function markers(Contract $contract): array
    {
        $out = [];
        $license = $contract->customer?->license_expires_on;
        if ($license) {
            $out[] = [
                'type' => 'license',
                'at' => Carbon::parse($license)->endOfDay()->toIso8601String(),
                'label' => 'انتهاء الرخصة',
            ];
        }

        $openWo = WorkOrder::query()
            ->where('vehicle_id', $contract->vehicle_id)
            ->whereIn('status', ['open', 'in_progress'])
            ->where('puts_in_maintenance', true)
            ->orderBy('opened_on')
            ->first();
        if ($openWo) {
            $out[] = [
                'type' => 'maintenance',
                'at' => Carbon::parse($openWo->opened_on)->startOfDay()->toIso8601String(),
                'label' => 'صيانة مفتوحة',
            ];
        }

        return $out;
    }

    private function formatRemaining(float $hours): string
    {
        if ($hours >= 48) {
            $days = (int) floor($hours / 24);

            return "متبقي {$days} يوم";
        }
        if ($hours >= 24) {
            return 'متبقي يوم تقريباً';
        }
        $h = (int) max(1, round($hours));

        return "متبقي {$h} ساعة";
    }

    /**
     * @return array<string, mixed>
     */
    private function muted(string $label, string $message): array
    {
        return [
            'status' => 'cancelled',
            'tone' => 'muted',
            'percent' => 0,
            'elapsed_hours' => 0.0,
            'remaining_hours' => null,
            'remaining_label' => $label,
            'overdue_days' => 0,
            'effective_ends_at' => null,
            'limiting_factor' => null,
            'limiting_label' => null,
            'markers' => [],
            'message' => $message,
        ];
    }
}
