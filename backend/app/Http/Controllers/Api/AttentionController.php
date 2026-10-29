<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\InventoryItem;
use App\Models\Notification;
use App\Models\Violation;
use App\Models\WorkOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * عدّادات انتباه خفيفة للقائمة والتبويبات — بدون حمولة لوحة اليوم الكاملة.
 */
class AttentionController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        $overdueReturns = Contract::query()
            ->where('status', 'active')
            ->where('planned_ends_at', '<', now())
            ->count();

        $pendingApprovals = Contract::query()->where('status', 'pending')->count();

        $returningToday = Contract::query()
            ->where('status', 'active')
            ->whereDate('planned_ends_at', today())
            ->where('planned_ends_at', '>=', now())
            ->count();

        $pickupsToday = Contract::query()
            ->where('status', 'approved')
            ->whereDate('starts_at', today())
            ->count();

        $blockingWorkOrders = WorkOrder::query()
            ->where('puts_in_maintenance', true)
            ->whereIn('status', ['open', 'in_progress'])
            ->count();

        $openWorkOrders = WorkOrder::query()
            ->whereIn('status', ['open', 'in_progress'])
            ->count();

        $openViolations = Violation::query()
            ->whereNotIn('status', ['paid', 'confirmed'])
            ->count();

        $lowStock = InventoryItem::query()
            ->withSum('batches as remaining_sum', 'remaining')
            ->get()
            ->filter(fn (InventoryItem $item) => (int) ($item->remaining_sum ?? 0) <= (int) $item->reorder_level)
            ->count();

        $unread = Notification::query()
            ->when($user, fn ($q) => $q->where('user_id', $user->id))
            ->whereNull('read_at')
            ->count();

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
            ],
        ]);
    }
}
