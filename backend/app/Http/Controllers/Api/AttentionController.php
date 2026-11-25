<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Fleet\VehicleOwnerService;
use App\Support\PerfReadCache;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * عدّادات انتباه خفيفة للقائمة والتبويبات — بدون حمولة لوحة اليوم الكاملة.
 */
class AttentionController extends Controller
{
    public function __construct(private readonly VehicleOwnerService $owners) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $userId = (int) ($user?->id ?? 0);

        $payload = PerfReadCache::remember("attention:{$userId}", function () use ($user, $userId) {
            $canContracts = (bool) $user?->hasPermission('contracts.view');
            $canMaintenance = (bool) $user?->hasPermission('maintenance.view');
            $canViolations = (bool) $user?->hasPermission('violations.view');
            $canInventory = (bool) $user?->hasPermission('inventory.view');
            $canHr = (bool) $user?->hasPermission('hr.view');
            $canPayroll = (bool) ($user?->hasPermission('hr.salaries') || $user?->hasPermission('sensitive.view_salaries'));
            $canCustomers = (bool) $user?->hasPermission('customers.view');
            $canOwners = (bool) $user?->hasPermission('owners.view');

            $now = now()->toDateTimeString();
            $todayStart = now()->startOfDay()->toDateTimeString();
            $tomorrowStart = now()->copy()->addDay()->startOfDay()->toDateTimeString();

            // عدّادات قياسية في رحلة SQL واحدة (بدل ٧–١٠ count منفصلة).
            $row = DB::selectOne(
                'select
                    (select coalesce(sum(case when status = ? and planned_ends_at < ? then 1 else 0 end), 0)
                        from contracts where deleted_at is null) as overdue_returns,
                    (select coalesce(sum(case when status = ? then 1 else 0 end), 0)
                        from contracts where deleted_at is null) as pending_approvals,
                    (select coalesce(sum(case when status = ? and planned_ends_at >= ? and planned_ends_at < ? then 1 else 0 end), 0)
                        from contracts where deleted_at is null) as returning_today,
                    (select coalesce(sum(case when status = ? and starts_at >= ? and starts_at < ? then 1 else 0 end), 0)
                        from contracts where deleted_at is null) as pickups_today,
                    (select coalesce(sum(case when status in (?, ?) and puts_in_maintenance = 1 then 1 else 0 end), 0)
                        from work_orders where deleted_at is null) as blocking_work_orders,
                    (select coalesce(sum(case when status in (?, ?) then 1 else 0 end), 0)
                        from work_orders where deleted_at is null) as open_work_orders,
                    (select count(*) from violations
                        where status not in (?, ?) and deleted_at is null) as open_violations,
                    (select count(*) from inventory_items
                        where deleted_at is null
                          and (select coalesce(sum(remaining), 0) from inventory_batches
                               where inventory_batches.inventory_item_id = inventory_items.id)
                              <= inventory_items.reorder_level) as low_stock,
                    (select count(*) from notifications
                        where user_id = ? and read_at is null) as unread_notifications,
                    (select count(*) from employee_assignments
                        where status = ?) as pending_hr_acks,
                    (select count(*) from payroll_runs
                        where status = ?) as draft_payroll_runs,
                    (select count(*) from customers
                        where status = ? and deleted_at is null) as blacklisted_customers,
                    (select operational from tenant_settings limit 1) as operational_json
                ',
                [
                    'active', $now,
                    'pending',
                    'active', $now, $tomorrowStart,
                    'approved', $todayStart, $tomorrowStart,
                    'open', 'in_progress',
                    'open', 'in_progress',
                    'paid', 'confirmed',
                    $userId,
                    'pending_ack',
                    'draft',
                    'blacklist',
                ]
            );

            $ownersUnpaid = null;
            if ($canOwners) {
                $rawOps = $row->operational_json ?? null;
                if (is_string($rawOps)) {
                    $decoded = json_decode($rawOps, true);
                    $ops = is_array($decoded) ? $decoded : [];
                } elseif (is_array($rawOps)) {
                    $ops = $rawOps;
                } else {
                    $ops = [];
                }
                $from = $ops['owner_dues_counts_from'] ?? null;
                $countsFrom = (is_string($from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) ? $from : null;
                $ownersUnpaid = $this->owners->countOwnersWithOverdue($countsFrom);
            }

            return [
                'overdue_returns' => $canContracts ? (int) ($row->overdue_returns ?? 0) : null,
                'pending_approvals' => $canContracts ? (int) ($row->pending_approvals ?? 0) : null,
                'returning_today' => $canContracts ? (int) ($row->returning_today ?? 0) : null,
                'pickups_today' => $canContracts ? (int) ($row->pickups_today ?? 0) : null,
                'blocking_work_orders' => $canMaintenance ? (int) ($row->blocking_work_orders ?? 0) : null,
                'open_work_orders' => $canMaintenance ? (int) ($row->open_work_orders ?? 0) : null,
                'open_violations' => $canViolations ? (int) ($row->open_violations ?? 0) : null,
                'low_stock' => $canInventory ? (int) ($row->low_stock ?? 0) : null,
                'unread_notifications' => (int) ($row->unread_notifications ?? 0),
                'pending_hr_acks' => $canHr ? (int) ($row->pending_hr_acks ?? 0) : null,
                'draft_payroll_runs' => $canPayroll ? (int) ($row->draft_payroll_runs ?? 0) : null,
                'blacklisted_customers' => $canCustomers ? (int) ($row->blacklisted_customers ?? 0) : null,
                'owners_unpaid' => $ownersUnpaid,
            ];
        });

        return response()->json(['data' => $payload]);
    }
}
