<?php

namespace App\Services\Accounting;

use App\Models\VehicleProfitMonthSummary;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * BE-8: بناء/قراءة ملخص ربحية شهري مشتق بنفس معادلات ProfitabilityService.
 * القراءة التشغيلية لا تُحوَّل هنا إلا عبر config('rpm.use_profit_month_summaries').
 */
class ProfitabilitySnapshotService
{
    public function __construct(private readonly ProfitabilityService $live) {}

    /**
     * يعيد بناء صفوف شهر واحد من التجميع المباشر (لا يمسّ جداول الأعمال).
     *
     * @return array{rows:int,fleet_operating_profit:int}
     */
    public function rebuildMonth(Carbon $monthStart): array
    {
        $monthStart = $monthStart->copy()->startOfMonth();
        $year = (int) $monthStart->format('Y');
        $month = (int) $monthStart->format('n');
        $snap = $this->live->computeMonthSnapshotLive($monthStart);
        $builtAt = now();

        DB::transaction(function () use ($year, $month, $snap, $builtAt) {
            VehicleProfitMonthSummary::query()
                ->where('year', $year)
                ->where('month', $month)
                ->delete();

            $rows = [];
            foreach ($snap['vehicles'] as $vid => $row) {
                $rows[] = [
                    'year' => $year,
                    'month' => $month,
                    'vehicle_id' => (int) $vid,
                    'label' => (string) ($row['label'] ?? ''),
                    'revenue' => (int) $row['revenue'],
                    'maintenance' => (int) $row['maintenance'],
                    'expenses' => (int) $row['expenses'],
                    'operating_profit' => (int) $row['operating_profit'],
                    'built_at' => $builtAt,
                    'created_at' => $builtAt,
                    'updated_at' => $builtAt,
                ];
            }

            if ($rows !== []) {
                foreach (array_chunk($rows, 200) as $chunk) {
                    VehicleProfitMonthSummary::query()->insert($chunk);
                }
            }
        });

        return [
            'rows' => count($snap['vehicles']),
            'fleet_operating_profit' => (int) $snap['operating_profit'],
        ];
    }

    /**
     * مقارنة حرفية لكل عربية: ملخص مشتق ≡ تجميع مباشر.
     *
     * @return list<string> قائمة اختلافات (فارغة = تطابق 100٪)
     */
    public function diffAgainstLive(Carbon $monthStart): array
    {
        $monthStart = $monthStart->copy()->startOfMonth();
        $year = (int) $monthStart->format('Y');
        $month = (int) $monthStart->format('n');
        $live = $this->live->computeMonthSnapshotLive($monthStart);
        $stored = VehicleProfitMonthSummary::query()
            ->where('year', $year)
            ->where('month', $month)
            ->get()
            ->keyBy('vehicle_id');

        $diffs = [];
        $liveIds = collect($live['vehicles'])->keys()->map(fn ($id) => (int) $id)->sort()->values();
        $storedIds = $stored->keys()->map(fn ($id) => (int) $id)->sort()->values();

        if ($liveIds->all() !== $storedIds->all()) {
            $diffs[] = 'vehicle_id set mismatch';
        }

        foreach ($live['vehicles'] as $vid => $row) {
            $s = $stored->get((int) $vid);
            if ($s === null) {
                $diffs[] = "missing vehicle {$vid}";
                continue;
            }
            foreach (['revenue', 'maintenance', 'expenses', 'operating_profit'] as $field) {
                if ((int) $s->{$field} !== (int) $row[$field]) {
                    $diffs[] = "vehicle {$vid}.{$field}: live={$row[$field]} snap={$s->{$field}}";
                }
            }
        }

        // قارن مجموع صفوف العربيات فقط — مصروف بلا vehicle_id يدخل أسطول live ولا صف عربية.
        $liveVehicleProfit = (int) collect($live['vehicles'])->sum('operating_profit');
        $fleetFromSnap = (int) $stored->sum('operating_profit');
        if ($fleetFromSnap !== $liveVehicleProfit) {
            $diffs[] = "sum vehicle operating_profit: live={$liveVehicleProfit} snap={$fleetFromSnap}";
        }

        return $diffs;
    }

    /**
     * قراءة اختيارية من الملخص — تُستخدم فقط إن فُعّل العلم بعد البرهان.
     *
     * @return array{label:string,revenue:int,maintenance:int,expenses:int,operating_profit:int,vehicles:array<int,array<string,mixed>>}|null
     */
    public function tryMonthSnapshot(Carbon $monthStart): ?array
    {
        if (! (bool) config('rpm.use_profit_month_summaries', false)) {
            return null;
        }

        $monthStart = $monthStart->copy()->startOfMonth();
        $year = (int) $monthStart->format('Y');
        $month = (int) $monthStart->format('n');
        $rows = VehicleProfitMonthSummary::query()
            ->where('year', $year)
            ->where('month', $month)
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $vehicles = [];
        foreach ($rows as $r) {
            $vehicles[(int) $r->vehicle_id] = [
                'vehicle_id' => (int) $r->vehicle_id,
                'label' => (string) $r->label,
                'revenue' => (int) $r->revenue,
                'maintenance' => (int) $r->maintenance,
                'expenses' => (int) $r->expenses,
                'operating_profit' => (int) $r->operating_profit,
            ];
        }

        return [
            'label' => $monthStart->translatedFormat('F Y'),
            'revenue' => (int) $rows->sum('revenue'),
            'maintenance' => (int) $rows->sum('maintenance'),
            'expenses' => (int) $rows->sum('expenses'),
            'operating_profit' => (int) $rows->sum('operating_profit'),
            'vehicles' => $vehicles,
        ];
    }
}
