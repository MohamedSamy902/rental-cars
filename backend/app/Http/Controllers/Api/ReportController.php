<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\JournalLine;
use App\Models\Payment;
use App\Models\Vehicle;
use App\Models\Violation;
use App\Services\Accounting\ProfitabilityService;
use App\Services\Accounting\ReportQueryService;
use App\Services\Fleet\OccupancyService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(
        private readonly OccupancyService $occupancy,
        private readonly ProfitabilityService $profitability,
        private readonly ReportQueryService $reports,
    ) {}

    public function summary(): JsonResponse
    {
        $arDebit = (int) JournalLine::query()
            ->whereHas('account', fn ($q) => $q->where('code', '1200'))
            ->sum('debit');
        $arCredit = (int) JournalLine::query()
            ->whereHas('account', fn ($q) => $q->where('code', '1200'))
            ->sum('credit');

        $rentByMonth = JournalLine::query()
            ->whereHas('account', fn ($q) => $q->where('code', '4000'))
            ->with('entry:id,entry_date')
            ->get()
            ->groupBy(fn ($line) => optional($line->entry?->entry_date)->format('Y-m') ?: 'unknown')
            ->map(fn ($group, $ym) => ['ym' => $ym, 'total' => (int) $group->sum('credit')])
            ->sortKeysDesc()
            ->take(6)
            ->values();

        $fleet = [
            'total' => Vehicle::query()->where('is_active', true)->count(),
            'maintenance' => Vehicle::query()->where('manual_status', 'maintenance')->count(),
            'rented' => Contract::query()->where('status', 'active')->count(),
            'reserved' => Contract::query()->where('status', 'approved')->count(),
        ];
        $fleet['available'] = max(0, $fleet['total'] - $fleet['maintenance'] - $fleet['rented'] - $fleet['reserved']);

        $from = now()->startOfMonth();
        $to = now();

        return response()->json([
            'data' => [
                'receivables' => max(0, $arDebit - $arCredit),
                'payments_in' => (int) Payment::query()->where('direction', 'in')->sum('amount'),
                'payments_out' => (int) Payment::query()->where('direction', 'out')->sum('amount'),
                'active_contracts' => Contract::query()->where('status', 'active')->count(),
                'completed_contracts' => Contract::query()->where('status', 'completed')->count(),
                'customers' => Customer::query()->count(),
                'blacklisted_customers' => Customer::query()->whereIn('status', ['blacklist', 'blacklisted'])->count(),
                'open_violations' => Violation::query()->whereNotIn('status', ['confirmed', 'paid'])->count(),
                'fleet' => $fleet,
                'rent_by_month' => $rentByMonth,
                'occupancy_pct' => $this->occupancy->percent($from, $to),
                'expected_revenue_active' => (int) Contract::query()
                    ->where('status', 'active')
                    ->get()
                    ->sum(function (Contract $c) {
                        $days = max(1, (int) ($c->approved_days ?: 1));

                        return $days * (int) $c->frozen_daily_rate;
                    }),
            ],
        ]);
    }

    public function profitabilityCompare(Request $request): JsonResponse
    {
        $anchor = $request->filled('month')
            ? Carbon::createFromFormat('Y-m', $request->string('month')->toString())->startOfMonth()
            : now()->startOfMonth();

        return response()->json(['data' => $this->profitability->monthOverMonth($anchor)]);
    }

    public function trialBalance(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return response()->json(['data' => $this->reports->trialBalance($from, $to)]);
    }

    public function incomeStatement(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return response()->json(['data' => $this->reports->incomeStatement($from, $to)]);
    }

    public function paymentMethodBalances(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        return response()->json(['data' => $this->reports->paymentMethodBalances($from, $to)]);
    }

    public function customerStatement(Customer $customer): JsonResponse
    {
        return response()->json(['data' => $this->reports->customerStatement($customer)]);
    }

    public function pettyCashBalances(): JsonResponse
    {
        return response()->json(['data' => $this->reports->pettyCashBalances()]);
    }

    /**
     * @return array{0:?Carbon,1:?Carbon}
     */
    private function range(Request $request): array
    {
        $from = $request->filled('from') ? Carbon::parse($request->string('from')->toString())->startOfDay() : null;
        $to = $request->filled('to') ? Carbon::parse($request->string('to')->toString())->endOfDay() : null;

        return [$from, $to];
    }
}
