<?php

namespace App\Services\Dashboard;

use App\Models\Contract;
use App\Models\InventoryItem;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Models\Violation;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\Schema;

/**
 * مهام تشغيلية للوحة اليوم — مفلترة بصلاحيات المستخدم الحالي.
 */
class HomeImportantTasksService
{
    public const LICENSE_LEAD_DAYS = 30;

    public const SIM_LEAD_DAYS = 14;

    public const OWNER_AGREEMENT_LEAD_DAYS = 30;

    public const MAINTENANCE_LEAD_DAYS = 7;

    public const LIMIT = 20;

    /**
     * @return list<array{
     *   id: string,
     *   rule: string,
     *   severity: 'critical'|'warning'|'info',
     *   title: string,
     *   subtitle: string,
     *   href: string,
     *   due_on: string|null
     * }>
     */
    public function forUser(User $user): array
    {
        $items = [];

        if ($this->can($user, 'contracts.view')) {
            $items = array_merge($items, $this->contractTasks());
        }
        if ($this->can($user, 'maintenance.view') || $this->can($user, 'vehicles.view')) {
            $items = array_merge($items, $this->maintenanceTasks());
        }
        if ($this->can($user, 'vehicles.view')) {
            $items = array_merge($items, $this->licenseTasks());
            $items = array_merge($items, $this->simRechargeTasks());
        }
        if ($this->can($user, 'vehicles.view') || $this->can($user, 'owners.view')) {
            $items = array_merge($items, $this->ownerAgreementTasks());
        }
        if ($this->can($user, 'violations.view')) {
            $items = array_merge($items, $this->violationTasks());
        }
        if ($this->can($user, 'inventory.view')) {
            $items = array_merge($items, $this->lowStockTasks());
        }

        usort($items, function (array $a, array $b) {
            $rank = ['critical' => 0, 'warning' => 1, 'info' => 2];
            $sa = $rank[$a['severity']] ?? 9;
            $sb = $rank[$b['severity']] ?? 9;
            if ($sa !== $sb) {
                return $sa <=> $sb;
            }
            $da = $a['due_on'] ?? '9999-12-31';
            $db = $b['due_on'] ?? '9999-12-31';

            return $da <=> $db;
        });

        return array_slice($items, 0, self::LIMIT);
    }

    private function can(User $user, string $permission): bool
    {
        return $user->hasPermission($permission);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function contractTasks(): array
    {
        $items = [];

        $overdue = Contract::query()
            ->with(['customer:id,full_name', 'vehicle:id,brand,model,plate'])
            ->where('status', 'active')
            ->where('planned_ends_at', '<', now())
            ->orderBy('planned_ends_at')
            ->limit(8)
            ->get();
        foreach ($overdue as $c) {
            $items[] = $this->item(
                'contract_overdue:'.$c->id,
                'contract_overdue',
                'critical',
                'استلام متأخر · '.$c->number,
                $this->contractSubtitle($c),
                '/contracts/'.$c->id,
                $c->planned_ends_at?->toDateString(),
            );
        }

        $pending = Contract::query()
            ->with(['customer:id,full_name', 'vehicle:id,brand,model,plate'])
            ->where('status', 'pending')
            ->orderBy('starts_at')
            ->limit(6)
            ->get();
        foreach ($pending as $c) {
            $items[] = $this->item(
                'contract_pending:'.$c->id,
                'contract_pending',
                'critical',
                'اعتماد حجز معلّق · '.$c->number,
                $this->contractSubtitle($c),
                '/contracts/'.$c->id,
                $c->starts_at?->toDateString(),
            );
        }

        $pickups = Contract::query()
            ->with(['customer:id,full_name', 'vehicle:id,brand,model,plate'])
            ->where('status', 'approved')
            ->whereDate('starts_at', today())
            ->orderBy('starts_at')
            ->limit(6)
            ->get();
        foreach ($pickups as $c) {
            $items[] = $this->item(
                'contract_pickup:'.$c->id,
                'contract_pickup',
                'warning',
                'تسليم اليوم · '.$c->number,
                $this->contractSubtitle($c),
                '/contracts/'.$c->id,
                $c->starts_at?->toDateString(),
            );
        }

        $returns = Contract::query()
            ->with(['customer:id,full_name', 'vehicle:id,brand,model,plate'])
            ->where('status', 'active')
            ->whereDate('planned_ends_at', today())
            ->where('planned_ends_at', '>=', now())
            ->orderBy('planned_ends_at')
            ->limit(6)
            ->get();
        foreach ($returns as $c) {
            $items[] = $this->item(
                'contract_return:'.$c->id,
                'contract_return',
                'warning',
                'استلام اليوم · '.$c->number,
                $this->contractSubtitle($c),
                '/contracts/'.$c->id,
                $c->planned_ends_at?->toDateString(),
            );
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function maintenanceTasks(): array
    {
        $items = [];

        $open = WorkOrder::query()
            ->with('vehicle:id,plate,brand,model')
            ->whereIn('status', ['open', 'in_progress'])
            ->orderByDesc('puts_in_maintenance')
            ->orderBy('opened_on')
            ->limit(8)
            ->get();
        foreach ($open as $wo) {
            $label = $wo->vehicle?->plate ?: ('#'.$wo->id);
            $blocking = (bool) $wo->puts_in_maintenance;
            $items[] = $this->item(
                'work_order:'.$wo->id,
                'work_order_open',
                $blocking ? 'critical' : 'warning',
                ($blocking ? 'صيانة موقّفة للعربية · ' : 'أمر شغل مفتوح · ').$label,
                trim(($wo->vehicle?->brand ?? '').' '.($wo->vehicle?->model ?? '').' · '.($wo->workshop ?: 'ورشة')),
                '/maintenance',
                $wo->opened_on?->toDateString(),
            );
        }

        if (Schema::hasColumn('work_orders', 'next_service_on')) {
            $deadline = now()->addDays(self::MAINTENANCE_LEAD_DAYS)->toDateString();
            $due = WorkOrder::query()
                ->with('vehicle:id,plate,brand,model')
                ->where('status', 'closed')
                ->whereNotNull('next_service_on')
                ->whereDate('next_service_on', '<=', $deadline)
                ->orderBy('next_service_on')
                ->limit(8)
                ->get();
            foreach ($due as $wo) {
                $label = $wo->vehicle?->plate ?: ('#'.$wo->id);
                $on = $wo->next_service_on?->toDateString();
                $severity = $on && $on < now()->toDateString() ? 'critical' : 'warning';
                $items[] = $this->item(
                    'maintenance_due:'.$wo->id,
                    'maintenance_due',
                    $severity,
                    'صيانة مستحقة · '.$label,
                    'الموعد '.($on ?: '—').($wo->next_service_odometer ? ' · عداد '.$wo->next_service_odometer : ''),
                    $wo->vehicle_id ? '/vehicles/'.$wo->vehicle_id : '/maintenance',
                    $on,
                );
            }
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function licenseTasks(): array
    {
        if (! Schema::hasTable('vehicle_documents')) {
            return [];
        }

        $deadline = now()->addDays(self::LICENSE_LEAD_DAYS)->toDateString();
        $docs = VehicleDocument::query()
            ->with('vehicle:id,plate,brand,model')
            ->where(function ($q) {
                $q->where('type', 'رخصة تسيير')
                    ->orWhere('type', 'like', '%رخص%')
                    ->orWhere('type', 'like', '%license%');
            })
            ->whereNotNull('expires_on')
            ->whereDate('expires_on', '<=', $deadline)
            ->orderBy('expires_on')
            ->limit(10)
            ->get();

        $items = [];
        foreach ($docs as $doc) {
            $plate = $doc->vehicle?->plate ?: 'عربية';
            $on = $doc->expires_on?->toDateString();
            $severity = $on && $on < now()->toDateString() ? 'critical' : 'warning';
            $items[] = $this->item(
                'license:'.$doc->id,
                'license_expiring',
                $severity,
                'رخصة تسيير قاربت الانتهاء · '.$plate,
                'تنتهي '.($on ?: '—').' · '.trim(($doc->vehicle?->brand ?? '').' '.($doc->vehicle?->model ?? '')),
                $doc->vehicle_id ? '/vehicles/'.$doc->vehicle_id : '/vehicles?tab=license_expiring',
                $on,
            );
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function simRechargeTasks(): array
    {
        if (! Schema::hasColumn('vehicles', 'tracker_sim_next_recharge_on')) {
            return [];
        }

        $deadline = now()->addDays(self::SIM_LEAD_DAYS)->toDateString();
        $vehicles = Vehicle::query()
            ->where('is_active', true)
            ->whereNotNull('tracker_sim_next_recharge_on')
            ->whereDate('tracker_sim_next_recharge_on', '<=', $deadline)
            ->orderBy('tracker_sim_next_recharge_on')
            ->limit(8)
            ->get(['id', 'plate', 'brand', 'model', 'tracker_sim_number', 'tracker_sim_next_recharge_on', 'tracker_sim_last_recharged_on']);

        $items = [];
        foreach ($vehicles as $v) {
            $on = $v->tracker_sim_next_recharge_on?->toDateString();
            $severity = $on && $on < now()->toDateString() ? 'critical' : 'warning';
            $sim = $v->tracker_sim_number ? 'شريحة '.$v->tracker_sim_number : 'بلا رقم شريحة';
            $items[] = $this->item(
                'sim:'.$v->id,
                'sim_recharge',
                $severity,
                'شحن شريحة تتبع · '.$v->plate,
                $sim.' · القادم '.($on ?: '—'),
                '/vehicles?tab=sim_recharge',
                $on,
            );
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function ownerAgreementTasks(): array
    {
        if (! Schema::hasColumn('vehicles', 'owner_agreement_ends_on')) {
            return [];
        }

        $deadline = now()->addDays(self::OWNER_AGREEMENT_LEAD_DAYS)->toDateString();
        $vehicles = Vehicle::query()
            ->with('owner:id,full_name')
            ->where('is_active', true)
            ->whereNotNull('owner_agreement_ends_on')
            ->whereDate('owner_agreement_ends_on', '<=', $deadline)
            ->orderBy('owner_agreement_ends_on')
            ->limit(8)
            ->get([
                'id', 'plate', 'brand', 'model', 'owner_id',
                'owner_agreement_starts_on', 'owner_agreement_ends_on',
            ]);

        $items = [];
        foreach ($vehicles as $v) {
            $on = $v->owner_agreement_ends_on?->toDateString();
            $severity = $on && $on < now()->toDateString() ? 'critical' : 'warning';
            $owner = $v->owner?->full_name ?: 'مالك';
            $items[] = $this->item(
                'owner_agreement:'.$v->id,
                'owner_agreement_end',
                $severity,
                'عقد مالك ينتهي · '.$v->plate,
                $owner.' · نهاية '.($on ?: '—'),
                '/vehicles?tab=owner_agreement_expiring',
                $on,
            );
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function violationTasks(): array
    {
        $rows = Violation::query()
            ->with('vehicle:id,plate')
            ->whereNotIn('status', ['paid', 'confirmed'])
            ->orderByDesc('occurred_at')
            ->limit(6)
            ->get();

        $items = [];
        foreach ($rows as $v) {
            $plate = $v->vehicle?->plate ?: 'عربية';
            $items[] = $this->item(
                'violation:'.$v->id,
                'violation_open',
                'warning',
                'مخالفة مفتوحة · '.$plate,
                'مبلغ '.(int) $v->amount.' · '.($v->occurred_at?->toDateString() ?: '—'),
                '/violations',
                $v->occurred_at?->toDateString(),
            );
        }

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function lowStockTasks(): array
    {
        if (! Schema::hasTable('inventory_items')) {
            return [];
        }

        $items = [];
        $rows = InventoryItem::query()
            ->where('is_active', true)
            ->withSum('batches as remaining_sum', 'remaining')
            ->orderBy('name')
            ->limit(40)
            ->get()
            ->filter(fn (InventoryItem $item) => (int) ($item->remaining_sum ?? 0) <= (int) $item->reorder_level)
            ->take(6);

        foreach ($rows as $item) {
            $remaining = (int) ($item->remaining_sum ?? 0);
            $items[] = $this->item(
                'stock:'.$item->id,
                'low_stock',
                'warning',
                'نقص مخزون · '.$item->name,
                'المتبقي '.$remaining.' · حد التنبيه '.(int) $item->reorder_level,
                '/inventory',
                null,
            );
        }

        return $items;
    }

    private function contractSubtitle(Contract $c): string
    {
        return trim(
            ($c->customer?->full_name ?? 'عميل')
            .' · '
            .trim(($c->vehicle?->brand ?? '').' '.($c->vehicle?->model ?? ''))
            .' · '
            .($c->vehicle?->plate ?? '')
        );
    }

    /**
     * @return array{
     *   id: string,
     *   rule: string,
     *   severity: string,
     *   title: string,
     *   subtitle: string,
     *   href: string,
     *   due_on: string|null
     * }
     */
    private function item(
        string $id,
        string $rule,
        string $severity,
        string $title,
        string $subtitle,
        string $href,
        ?string $dueOn,
    ): array {
        return [
            'id' => $id,
            'rule' => $rule,
            'severity' => $severity,
            'title' => $title,
            'subtitle' => $subtitle,
            'href' => $href,
            'due_on' => $dueOn,
        ];
    }
}
