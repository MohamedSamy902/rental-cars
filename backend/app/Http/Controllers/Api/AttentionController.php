<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\EmployeeAssignment;
use App\Models\InventoryItem;
use App\Models\Notification;
use App\Models\PayrollRun;
use App\Models\Violation;
use App\Models\WorkOrder;
use App\Services\Fleet\VehicleOwnerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

/**
 * عدّادات انتباه خفيفة للقائمة والتبويبات — بدون حمولة لوحة اليوم الكاملة.
 */
class AttentionController extends Controller
{
    public function __construct(private readonly VehicleOwnerService $owners) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        $canContracts = (bool) $user?->hasPermission('contracts.view');
        $overdueReturns = null;
        $pendingApprovals = null;
        $returningToday = null;
        $pickupsToday = null;

        if ($canContracts) {
            $now = now()->toDateTimeString();
            $todayStart = now()->startOfDay()->toDateTimeString();
            $tomorrowStart = now()->copy()->addDay()->startOfDay()->toDateTimeString();

            // One scan for the four contract counters (same predicates as before).
            $c = Contract::query()
                ->toBase()
                ->selectRaw(
                    'SUM(CASE WHEN status = ? AND planned_ends_at < ? THEN 1 ELSE 0 END) as overdue_returns,
                    SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as pending_approvals,
                    SUM(CASE WHEN status = ? AND planned_ends_at >= ? AND planned_ends_at < ? THEN 1 ELSE 0 END) as returning_today,
                    SUM(CASE WHEN status = ? AND starts_at >= ? AND starts_at < ? THEN 1 ELSE 0 END) as pickups_today',
                    [
                        'active', $now,
                        'pending',
                        'active', $now, $tomorrowStart,
                        'approved', $todayStart, $tomorrowStart,
                    ]
                )
                ->first();

            $overdueReturns = (int) ($c->overdue_returns ?? 0);
            $pendingApprovals = (int) ($c->pending_approvals ?? 0);
            $returningToday = (int) ($c->returning_today ?? 0);
            $pickupsToday = (int) ($c->pickups_today ?? 0);
        }

        $canMaintenance = (bool) $user?->hasPermission('maintenance.view');
        $blockingWorkOrders = null;
        $openWorkOrders = null;
        if ($canMaintenance) {
            $wo = WorkOrder::query()
                ->toBase()
                ->selectRaw(
                    "SUM(CASE WHEN status IN ('open', 'in_progress') AND puts_in_maintenance = 1 THEN 1 ELSE 0 END) as blocking_work_orders,
                    SUM(CASE WHEN status IN ('open', 'in_progress') THEN 1 ELSE 0 END) as open_work_orders"
                )
                ->first();
            $blockingWorkOrders = (int) ($wo->blocking_work_orders ?? 0);
            $openWorkOrders = (int) ($wo->open_work_orders ?? 0);
        }

        $openViolations = $user?->hasPermission('violations.view') ? Violation::query()
            ->whereNotIn('status', ['paid', 'confirmed'])
            ->count() : null;

        $lowStock = $user?->hasPermission('inventory.view') ? InventoryItem::query()
            ->withSum('batches as remaining_sum', 'remaining')
            ->get()
            ->filter(fn (InventoryItem $item) => (int) ($item->remaining_sum ?? 0) <= (int) $item->reorder_level)
            ->count() : null;

        $unread = Notification::query()
            ->when($user, fn ($q) => $q->where('user_id', $user->id))
            ->whereNull('read_at')
            ->count();

        $pendingHrAcks = $user?->hasPermission('hr.view') && Schema::hasTable('employee_assignments')
            ? EmployeeAssignment::query()->where('status', 'pending_ack')->count()
            : ($user?->hasPermission('hr.view') ? 0 : null);

        $canPayroll = (bool) ($user?->hasPermission('hr.salaries') || $user?->hasPermission('sensitive.view_salaries'));
        $draftPayroll = $canPayroll ? PayrollRun::query()->where('status', 'draft')->count() : null;

        $blacklistedCustomers = $user?->hasPermission('customers.view')
            ? Customer::query()->where('status', 'blacklist')->count()
            : null;

        $ownersUnpaid = $user?->hasPermission('owners.view') ? $this->ownersWithUnpaidBalance() : null;

        return response()->json([
            'data' => [
                'overdue_returns' => $overdueReturns,
                'pending_approvals' => $pendingApprovals,
                'returning_today' => $returningToday,
                'pickups_today' => $pickupsToday,
                'blocking_work_orders' => $blockingWorkOrders,
                'open_work_orders' => $openWorkOrders,
                'open_violations' => $openViolations,
                'low_stock' => $lowStock,
                'unread_notifications' => $unread,
                'pending_hr_acks' => $pendingHrAcks,
                'draft_payroll_runs' => $draftPayroll,
                'blacklisted_customers' => $blacklistedCustomers,
                'owners_unpaid' => $ownersUnpaid,
            ],
        ]);
    }

    /**
     * ملاك لهم فلوس متأخرة: شهور مدنية مكتملة حسب عقد المالك − إجمالي المصروف المسجّل.
     */
    private function ownersWithUnpaidBalance(): int
    {
        return $this->owners->countOwnersWithOverdue();
    }
}
