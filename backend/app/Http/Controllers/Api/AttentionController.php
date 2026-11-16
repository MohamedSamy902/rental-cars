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
use Illuminate\Support\Facades\DB;
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
        $overdueReturns = $canContracts ? Contract::query()
            ->where('status', 'active')
            ->where('planned_ends_at', '<', now())
            ->count() : null;

        $pendingApprovals = $canContracts ? Contract::query()->where('status', 'pending')->count() : null;

        $returningToday = $canContracts ? Contract::query()
            ->where('status', 'active')
            ->whereDate('planned_ends_at', today())
            ->where('planned_ends_at', '>=', now())
            ->count() : null;

        $pickupsToday = $canContracts ? Contract::query()
            ->where('status', 'approved')
            ->whereDate('starts_at', today())
            ->count() : null;

        $canMaintenance = (bool) $user?->hasPermission('maintenance.view');
        $blockingWorkOrders = $canMaintenance ? WorkOrder::query()
            ->where('puts_in_maintenance', true)
            ->whereIn('status', ['open', 'in_progress'])
            ->count() : null;

        $openWorkOrders = $canMaintenance ? WorkOrder::query()
            ->whereIn('status', ['open', 'in_progress'])
            ->count() : null;

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
