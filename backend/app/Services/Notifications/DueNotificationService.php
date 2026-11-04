<?php

namespace App\Services\Notifications;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Evaluation;
use App\Models\InventoryItem;
use App\Models\NotificationReminder;
use App\Models\Payment;
use App\Models\PayrollLine;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleBattery;
use App\Models\VehicleConsumableState;
use App\Models\VehicleDocument;
use App\Models\VehicleTire;
use App\Models\Violation;
use App\Models\WorkOrder;
use App\Services\Contracts\ContractPricingService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * يمسح الشروط القائمة ويرسل إشعاراً أول مرة، ثم تذكيراً كل 24 ساعة
 * لنفس القاعدة ونفس السجل إلى أن يزول السبب.
 */
class DueNotificationService
{
    public const LEAD_DAYS = 2;

    public const REMINDER_HOURS = 24;

    public const LEAD_KM = 500;

    /** @var array<string, Collection<int, User>> */
    private array $recipientCache = [];

    public function __construct(
        private readonly NotificationDispatchService $notifications,
        private readonly ContractPricingService $pricing,
    ) {}

    /**
     * @return array{alerts: int, notifications: int}
     */
    public function scan(): array
    {
        $this->recipientCache = [];
        $alerts = 0;
        $rows = 0;

        foreach ($this->groups() as $rule => $bucket) {
            [$sentAlerts, $sentRows] = $this->publish($rule, $bucket);
            $alerts += $sentAlerts;
            $rows += $sentRows;
        }

        return ['alerts' => $alerts, 'notifications' => $rows];
    }

    /**
     * @return array<string, list<DueAlert>>
     */
    private function groups(): array
    {
        $scanned = [];
        $groups = [];
        foreach ($this->collect($scanned) as $alert) {
            $groups[$alert->ruleKey][] = $alert;
        }

        foreach (array_unique($scanned) as $rule) {
            $groups[$rule] ??= [];
        }

        return $groups;
    }

    /**
     * @param  list<string>  $scanned
     * @return list<DueAlert>
     */
    private function collect(array &$scanned): array
    {
        return array_merge(
            $this->contractScheduleAlerts($scanned),
            $this->contractMoneyAlerts($scanned),
            $this->depositAlerts($scanned),
            $this->ownerBalanceAlerts($scanned),
            $this->payrollAlerts($scanned),
            $this->violationAlerts($scanned),
            $this->lowStockAlerts($scanned),
            $this->documentAlerts($scanned),
            $this->maintenanceAlerts($scanned),
            $this->consumableAlerts($scanned),
            $this->tireAndBatteryAlerts($scanned),
            $this->assignmentAlerts($scanned),
            $this->evaluationAlerts($scanned),
        );
    }

    /**
     * @param  list<string>  $scanned
     */
    private function mark(array &$scanned, string ...$rules): void
    {
        foreach ($rules as $rule) {
            $scanned[] = $rule;
        }
    }

    /**
     * @param  list<DueAlert>  $alerts
     * @return array{0: int, 1: int}
     */
    private function publish(string $rule, array $alerts): array
    {
        $activeIds = [];
        $sentAlerts = 0;
        $sentRows = 0;

        foreach ($alerts as $alert) {
            $activeIds[] = $alert->subjectId;
            $rows = $this->dispatchIfDue($alert);
            if ($rows > 0) {
                $sentAlerts++;
                $sentRows += $rows;
            }
        }

        $cleanup = NotificationReminder::query()->where('rule_key', $rule);
        if ($activeIds !== []) {
            $cleanup->whereNotIn('subject_id', $activeIds);
        }
        $cleanup->delete();

        return [$sentAlerts, $sentRows];
    }

    private function dispatchIfDue(DueAlert $alert): int
    {
        $reminder = NotificationReminder::query()->firstOrNew([
            'rule_key' => $alert->ruleKey,
            'subject_type' => $alert->subjectType,
            'subject_id' => $alert->subjectId,
        ]);

        if ($reminder->exists && $reminder->last_sent_at && $reminder->last_sent_at->greaterThan(now()->subHours(self::REMINDER_HOURS))) {
            return 0;
        }

        $users = $this->recipients($alert->permissions);
        if ($alert->extraUserId) {
            $extra = User::query()->whereKey($alert->extraUserId)->where('is_active', true)->first();
            if ($extra) {
                $users = $users->push($extra)->unique('id');
            }
        }

        if ($users->isEmpty()) {
            return 0;
        }

        $sent = 0;
        foreach ($users as $user) {
            try {
                $this->notifications->notify(
                    $user,
                    $alert->title,
                    $alert->body,
                    $alert->linkType,
                    $alert->linkId,
                );
                $sent++;
            } catch (\Throwable $e) {
                Log::warning('notifications.scan_failed', [
                    'rule' => $alert->ruleKey,
                    'subject_id' => $alert->subjectId,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($sent < 1) {
            return 0;
        }

        $reminder->last_sent_at = now();
        $reminder->save();

        return $sent;
    }

    /**
     * @param  list<string>  $permissions
     * @return Collection<int, User>
     */
    private function recipients(array $permissions): Collection
    {
        $cacheKey = implode('|', $permissions);

        $this->recipientCache[$cacheKey] ??= User::query()
            ->where('is_active', true)
            ->where(function ($query) use ($permissions) {
                $query->whereHas('role', fn ($role) => $role->where('name', 'super_admin'))
                    ->orWhereHas('role.permissions', fn ($permission) => $permission->whereIn('key', $permissions));
            })
            ->get();

        return $this->recipientCache[$cacheKey]->collect();
    }

    /**
     * @param  list<string>  $scanned
     * @return list<DueAlert>
     */
    private function contractScheduleAlerts(array &$scanned): array
    {
        if (! Schema::hasTable('contracts')) {
            return [];
        }

        $this->mark($scanned, 'contract_end', 'contract_start');

        $horizon = now()->copy()->addDays(self::LEAD_DAYS);
        $alerts = [];

        $ending = Contract::query()
            ->with(['customer:id,full_name', 'vehicle:id,plate'])
            ->where('status', 'active')
            ->where('planned_ends_at', '<=', $horizon)
            ->get();

        foreach ($ending as $contract) {
            $late = $contract->planned_ends_at !== null && $contract->planned_ends_at->isPast();
            $who = $this->party($contract);
            $alerts[] = new DueAlert(
                ruleKey: 'contract_end',
                subjectType: 'contract',
                subjectId: (int) $contract->id,
                title: $late
                    ? "استلام متأخر للعقد {$contract->number}"
                    : "قرب انتهاء العقد {$contract->number}",
                body: $late
                    ? "موعد استلام {$who} فات وما زال العقد نشطاً. المخطط: {$this->when($contract->planned_ends_at)}."
                    : "عقد {$who} ينتهي خلال يومين. المخطط: {$this->when($contract->planned_ends_at)}.",
                linkType: 'contract',
                linkId: (int) $contract->id,
                permissions: ['contracts.view'],
                extraUserId: $contract->sales_user_id ? (int) $contract->sales_user_id : null,
            );
        }

        $starting = Contract::query()
            ->with(['customer:id,full_name', 'vehicle:id,plate'])
            ->whereIn('status', ['pending', 'approved'])
            ->where('starts_at', '<=', $horizon)
            ->get();

        foreach ($starting as $contract) {
            $late = $contract->starts_at !== null && $contract->starts_at->isPast();
            $who = $this->party($contract);
            $pending = $contract->status === 'pending';
            $title = match (true) {
                $pending && $late => "حجز معلّق فات موعد بدايته {$contract->number}",
                $pending => "حجز معلّق يبدأ خلال يومين {$contract->number}",
                $late => "تسليم متأخر للحجز {$contract->number}",
                default => "موعد تسليم الحجز {$contract->number} خلال يومين",
            };
            $alerts[] = new DueAlert(
                ruleKey: 'contract_start',
                subjectType: 'contract',
                subjectId: (int) $contract->id,
                title: $title,
                body: "{$who}. البداية: {$this->when($contract->starts_at)} · الحالة: ".($pending ? 'بانتظار الاعتماد' : 'معتمد بانتظار التسليم').'.',
                linkType: 'contract',
                linkId: (int) $contract->id,
                permissions: ['contracts.view'],
                extraUserId: $contract->sales_user_id ? (int) $contract->sales_user_id : null,
            );
        }

        return $alerts;
    }

    /**
     * @param  list<string>  $scanned
     * @return list<DueAlert>
     */
    private function contractMoneyAlerts(array &$scanned): array
    {
        if (! Schema::hasTable('contracts') || ! Schema::hasTable('payments')) {
            return [];
        }

        $this->mark($scanned, 'contract_balance');

        $horizon = now()->copy()->addDays(self::LEAD_DAYS);
        $contracts = Contract::query()
            ->with(['customer:id,full_name'])
            ->whereIn('status', ['approved', 'active', 'completed'])
            ->where(function ($query) use ($horizon) {
                $query->whereNotNull('final_total')
                    ->orWhereNotNull('period_rent')
                    ->orWhere(function ($nearEnd) use ($horizon) {
                        $nearEnd->where('status', 'active')->where('planned_ends_at', '<=', $horizon);
                    });
            })
            ->get();

        if ($contracts->isEmpty()) {
            return [];
        }

        $nets = Payment::query()
            ->where('link_type', 'contract')
            ->whereIn('link_id', $contracts->pluck('id'))
            ->groupBy('link_id')
            ->selectRaw("link_id, SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END) as net")
            ->pluck('net', 'link_id')
            ->mapWithKeys(fn ($net, $id) => [(int) $id => (int) $net]);

        $alerts = [];
        foreach ($contracts as $contract) {
            $obligation = $this->obligation($contract);
            $net = (int) ($nets[$contract->id] ?? 0);
            $balance = $obligation - $net;
            if ($obligation < 1 || $balance < 1) {
                continue;
            }

            $who = $contract->customer?->full_name ?: 'العميل';
            $alerts[] = new DueAlert(
                ruleKey: 'contract_balance',
                subjectType: 'contract',
                subjectId: (int) $contract->id,
                title: "مبلغ مستحق على العقد {$contract->number}",
                body: "متبقي {$this->money($balance)} على {$who} من أصل {$this->money($obligation)}.",
                linkType: 'contract',
                linkId: (int) $contract->id,
                permissions: ['payments.view', 'contracts.view'],
                extraUserId: $contract->sales_user_id ? (int) $contract->sales_user_id : null,
            );
        }

        return $alerts;
    }

    /**
     * @param  list<string>  $scanned
     * @return list<DueAlert>
     */
    private function depositAlerts(array &$scanned): array
    {
        if (! Schema::hasTable('deposits') || ! Schema::hasTable('contracts')) {
            return [];
        }

        $this->mark($scanned, 'deposit_uncollected', 'deposit_return');

        $alerts = [];
        $horizon = now()->copy()->addDays(self::LEAD_DAYS);

        $open = Contract::query()
            ->with('deposit')
            ->whereIn('status', ['pending', 'approved', 'active'])
            ->where('frozen_agreed_deposit', '>', 0)
            ->where(function ($query) use ($horizon) {
                $query->where('status', 'active')->orWhere('starts_at', '<=', $horizon);
            })
            ->get();

        foreach ($open as $contract) {
            $deposit = $contract->deposit;
            if ($deposit && in_array($deposit->status, ['received', 'partial', 'settled', 'returned'], true)) {
                continue;
            }
            $alerts[] = new DueAlert(
                ruleKey: 'deposit_uncollected',
                subjectType: 'contract',
                subjectId: (int) $contract->id,
                title: "تأمين غير مقبوض على العقد {$contract->number}",
                body: "المبلغ المتفق {$this->money((int) $contract->frozen_agreed_deposit)} ولم يُقبض بعد.",
                linkType: 'contract',
                linkId: (int) $contract->id,
                permissions: ['deposits.view'],
                extraUserId: $contract->sales_user_id ? (int) $contract->sales_user_id : null,
            );
        }

        $held = Deposit::query()
            ->with('contract:id,number,status,customer_id')
            ->whereIn('status', ['received', 'partial'])
            ->whereHas('contract', fn ($query) => $query->where('status', 'completed'))
            ->get();

        foreach ($held as $deposit) {
            $remaining = $deposit->remaining();
            if ($remaining < 1 || ! $deposit->contract) {
                continue;
            }
            $alerts[] = new DueAlert(
                ruleKey: 'deposit_return',
                subjectType: 'deposit',
                subjectId: (int) $deposit->id,
                title: "تأمين بانتظار الرد للعقد {$deposit->contract->number}",
                body: "المتبقي لدى المركز {$this->money($remaining)} بعد إقفال العقد.",
                linkType: 'contract',
                linkId: (int) $deposit->contract_id,
                permissions: ['deposits.view'],
            );
        }

        return $alerts;
    }

    /**
     * @param  list<string>  $scanned
     * @return list<DueAlert>
     */
    private function ownerBalanceAlerts(array &$scanned): array
    {
        if (! Schema::hasTable('vehicle_owners') || ! Schema::hasTable('owner_month_dues') || ! Schema::hasTable('owner_payouts')) {
            return [];
        }

        $this->mark($scanned, 'owner_balance');

        $rows = DB::table('vehicle_owners as owners')
            ->whereNull('owners.deleted_at')
            ->where('owners.is_active', true)
            ->select('owners.id', 'owners.full_name')
            ->selectRaw('(SELECT COALESCE(SUM(dues.amount), 0) FROM owner_month_dues dues WHERE dues.owner_id = owners.id) as due_sum')
            ->selectRaw('(SELECT COALESCE(SUM(payouts.amount), 0) FROM owner_payouts payouts WHERE payouts.owner_id = owners.id) as paid_sum')
            ->get();

        $alerts = [];
        foreach ($rows as $row) {
            $balance = (int) $row->due_sum - (int) $row->paid_sum;
            if ($balance < 1) {
                continue;
            }
            $alerts[] = new DueAlert(
                ruleKey: 'owner_balance',
                subjectType: 'owner',
                subjectId: (int) $row->id,
                title: "مستحق مالك لم يُصرف: {$row->full_name}",
                body: "المتبقي {$this->money($balance)}.",
                linkType: 'owner',
                linkId: (int) $row->id,
                permissions: ['owners.view'],
            );
        }

        return $alerts;
    }

    /**
     * @param  list<string>  $scanned
     * @return list<DueAlert>
     */
    private function payrollAlerts(array &$scanned): array
    {
        if (! Schema::hasTable('payroll_lines') || ! Schema::hasTable('payroll_runs')) {
            return [];
        }

        $this->mark($scanned, 'payroll_unpaid');

        $lines = PayrollLine::query()
            ->with(['employee:id,name', 'run:id,year,month,status'])
            ->whereHas('run', fn ($query) => $query->where('status', 'approved'))
            ->whereColumn('net_amount', '>', 'paid_amount')
            ->get();

        $alerts = [];
        foreach ($lines as $line) {
            $remaining = $line->remaining();
            if ($remaining < 1) {
                continue;
            }
            $name = $line->employee?->name ?: 'موظف';
            $period = $line->run ? sprintf('%04d-%02d', $line->run->year, $line->run->month) : '';
            $alerts[] = new DueAlert(
                ruleKey: 'payroll_unpaid',
                subjectType: 'payroll_line',
                subjectId: (int) $line->id,
                title: "راتب لم يُصرف: {$name}",
                body: "متبقي {$this->money($remaining)} من مسير {$period}.",
                linkType: 'payroll',
                linkId: (int) $line->payroll_run_id,
                permissions: ['hr.salaries'],
            );
        }

        return $alerts;
    }

    /**
     * @param  list<string>  $scanned
     * @return list<DueAlert>
     */
    private function violationAlerts(array &$scanned): array
    {
        if (! Schema::hasTable('violations')) {
            return [];
        }

        $this->mark($scanned, 'violation_open');

        $alerts = [];
        $rows = Violation::query()->whereNotIn('status', ['paid', 'confirmed'])->get();
        foreach ($rows as $violation) {
            $alerts[] = new DueAlert(
                ruleKey: 'violation_open',
                subjectType: 'violation',
                subjectId: (int) $violation->id,
                title: "مخالفة بانتظار المتابعة #{$violation->id}",
                body: "المبلغ {$this->money((int) $violation->amount)} · الحالة: {$violation->status}.",
                linkType: 'violation',
                linkId: (int) $violation->id,
                permissions: ['violations.view'],
            );
        }

        return $alerts;
    }

    /**
     * @param  list<string>  $scanned
     * @return list<DueAlert>
     */
    private function lowStockAlerts(array &$scanned): array
    {
        if (! Schema::hasTable('inventory_items') || ! Schema::hasTable('inventory_batches')) {
            return [];
        }

        $this->mark($scanned, 'low_stock');

        $items = InventoryItem::query()
            ->where('is_active', true)
            ->withSum('batches as remaining_sum', 'remaining')
            ->get();

        $alerts = [];
        foreach ($items as $item) {
            $remaining = (int) ($item->remaining_sum ?? 0);
            $level = (int) $item->reorder_level;
            if ($remaining > $level) {
                continue;
            }
            $alerts[] = new DueAlert(
                ruleKey: 'low_stock',
                subjectType: 'inventory_item',
                subjectId: (int) $item->id,
                title: "صنف تحت حد التنبيه: {$item->name}",
                body: "الرصيد {$remaining} {$item->unit} وحد التنبيه {$level}.",
                linkType: 'inventory',
                linkId: (int) $item->id,
                permissions: ['inventory.view'],
            );
        }

        return $alerts;
    }

    /**
     * @param  list<string>  $scanned
     * @return list<DueAlert>
     */
    private function documentAlerts(array &$scanned): array
    {
        $alerts = [];
        $deadline = now()->copy()->startOfDay()->addDays(self::LEAD_DAYS)->toDateString();

        if (Schema::hasTable('vehicle_documents')) {
            $this->mark($scanned, 'vehicle_document');
            $documents = VehicleDocument::query()
                ->with('vehicle:id,plate,code')
                ->whereNotNull('expires_on')
                ->whereDate('expires_on', '<=', $deadline)
                ->get();
            foreach ($documents as $document) {
                $plate = $document->vehicle?->plate ?: 'عربية';
                $alerts[] = new DueAlert(
                    ruleKey: 'vehicle_document',
                    subjectType: 'vehicle_document',
                    subjectId: (int) $document->id,
                    title: "مستند عربية يقترب انتهاؤه: {$plate}",
                    body: "{$document->type} ينتهي في {$document->expires_on?->toDateString()}.",
                    linkType: 'vehicle',
                    linkId: (int) $document->vehicle_id,
                    permissions: ['vehicles.view'],
                );
            }
        }

        if (Schema::hasTable('customers')) {
            $this->mark($scanned, 'customer_license', 'customer_national_id', 'customer_passport');
            $customers = Customer::query()
                ->where(function ($query) use ($deadline) {
                    $query->whereDate('license_expires_on', '<=', $deadline)
                        ->orWhereDate('national_id_expires_on', '<=', $deadline)
                        ->orWhereDate('passport_expires_on', '<=', $deadline);
                })
                ->get();

            foreach ($customers as $customer) {
                $alerts = array_merge($alerts, $this->customerDocumentAlerts($customer, $deadline));
            }
        }

        return $alerts;
    }

    /**
     * @return list<DueAlert>
     */
    private function customerDocumentAlerts(Customer $customer, string $deadline): array
    {
        $map = [
            'customer_license' => ['license_expires_on', 'رخصة القيادة'],
            'customer_national_id' => ['national_id_expires_on', 'بطاقة الرقم القومي'],
            'customer_passport' => ['passport_expires_on', 'جواز السفر'],
        ];
        $alerts = [];
        foreach ($map as $rule => [$column, $label]) {
            $expires = $customer->{$column};
            if (! $expires || $expires->toDateString() > $deadline) {
                continue;
            }
            $alerts[] = new DueAlert(
                ruleKey: $rule,
                subjectType: 'customer',
                subjectId: (int) $customer->id,
                title: "{$label} تقترب من الانتهاء: {$customer->full_name}",
                body: "تاريخ الانتهاء {$expires->toDateString()}.",
                linkType: 'customer',
                linkId: (int) $customer->id,
                permissions: ['customers.view'],
            );
        }

        return $alerts;
    }

    /**
     * @param  list<string>  $scanned
     * @return list<DueAlert>
     */
    private function maintenanceAlerts(array &$scanned): array
    {
        if (! Schema::hasTable('work_orders') || ! Schema::hasColumn('work_orders', 'next_service_on')) {
            return [];
        }

        $this->mark($scanned, 'maintenance_date', 'maintenance_km');

        $orders = WorkOrder::query()
            ->with('vehicle:id,plate,odometer')
            ->whereNotIn('status', ['cancelled'])
            ->where(function ($query) {
                $query->whereNotNull('next_service_on')->orWhereNotNull('next_service_odometer');
            })
            ->orderByDesc('id')
            ->get()
            ->unique('vehicle_id');

        $alerts = [];
        foreach ($orders as $order) {
            $plate = $order->vehicle?->plate ?: 'عربية';
            if ($order->next_service_on && $this->dateDue($order->next_service_on)) {
                $alerts[] = new DueAlert(
                    ruleKey: 'maintenance_date',
                    subjectType: 'work_order',
                    subjectId: (int) $order->id,
                    title: "صيانة بالتاريخ: {$plate}",
                    body: "موعد الصيانة التالية {$order->next_service_on->toDateString()}.",
                    linkType: 'maintenance',
                    linkId: (int) $order->id,
                    permissions: ['maintenance.view', 'vehicles.view'],
                );
            }

            $dueOdo = $order->next_service_odometer;
            $current = (int) ($order->vehicle?->odometer ?? 0);
            if ($dueOdo && $current >= ((int) $dueOdo - self::LEAD_KM)) {
                $alerts[] = new DueAlert(
                    ruleKey: 'maintenance_km',
                    subjectType: 'work_order',
                    subjectId: (int) $order->id,
                    title: "صيانة بالكيلومتر: {$plate}",
                    body: "العداد الحالي {$current} واستحقاق الصيانة عند {$dueOdo}.",
                    linkType: 'maintenance',
                    linkId: (int) $order->id,
                    permissions: ['maintenance.view', 'vehicles.view'],
                );
            }
        }

        return $alerts;
    }

    /**
     * @param  list<string>  $scanned
     * @return list<DueAlert>
     */
    private function consumableAlerts(array &$scanned): array
    {
        if (! Schema::hasTable('vehicle_consumable_states')) {
            return [];
        }

        $this->mark($scanned, 'consumable_date', 'consumable_km');

        $states = VehicleConsumableState::query()
            ->with(['vehicle:id,plate,odometer', 'template:id,name'])
            ->where(function ($query) {
                $query->whereNull('status')->orWhere('status', '!=', 'replaced');
            })
            ->get();

        $alerts = [];
        foreach ($states as $state) {
            $name = $state->template?->name ?: 'قطعة';
            $plate = $state->vehicle?->plate ?: 'عربية';
            if ($state->due_on && $this->dateDue($state->due_on)) {
                $alerts[] = new DueAlert(
                    ruleKey: 'consumable_date',
                    subjectType: 'vehicle_consumable',
                    subjectId: (int) $state->id,
                    title: "قطعة استهلاكية بالتاريخ: {$name}",
                    body: "{$plate} · الاستحقاق {$state->due_on->toDateString()}.",
                    linkType: 'vehicle',
                    linkId: (int) $state->vehicle_id,
                    permissions: ['maintenance.view', 'vehicles.view'],
                );
            }
            $dueOdo = $state->due_odometer;
            $current = (int) ($state->vehicle?->odometer ?? 0);
            if ($dueOdo && $current >= ((int) $dueOdo - self::LEAD_KM)) {
                $alerts[] = new DueAlert(
                    ruleKey: 'consumable_km',
                    subjectType: 'vehicle_consumable',
                    subjectId: (int) $state->id,
                    title: "قطعة استهلاكية بالكيلومتر: {$name}",
                    body: "{$plate} · العداد {$current} والاستحقاق عند {$dueOdo}.",
                    linkType: 'vehicle',
                    linkId: (int) $state->vehicle_id,
                    permissions: ['maintenance.view', 'vehicles.view'],
                );
            }
        }

        return $alerts;
    }

    /**
     * @param  list<string>  $scanned
     * @return list<DueAlert>
     */
    private function tireAndBatteryAlerts(array &$scanned): array
    {
        $alerts = [];

        if (Schema::hasTable('vehicle_tires')) {
            $this->mark($scanned, 'tire_life');
            $tires = VehicleTire::query()
                ->with('vehicle:id,plate,odometer')
                ->whereNull('replaced_on')
                ->whereNotNull('expected_life_km')
                ->get();
            foreach ($tires as $tire) {
                $current = (int) ($tire->vehicle?->odometer ?? 0);
                $due = (int) $tire->odometer_at_install + (int) $tire->expected_life_km;
                if ($current < $due - self::LEAD_KM) {
                    continue;
                }
                $plate = $tire->vehicle?->plate ?: 'عربية';
                $alerts[] = new DueAlert(
                    ruleKey: 'tire_life',
                    subjectType: 'vehicle_tire',
                    subjectId: (int) $tire->id,
                    title: "إطار اقترب من نهاية عمره: {$plate}",
                    body: "الموضع {$tire->position} · العداد {$current} ونهاية العمر عند {$due}.",
                    linkType: 'vehicle',
                    linkId: (int) $tire->vehicle_id,
                    permissions: ['vehicles.view'],
                );
            }
        }

        if (Schema::hasTable('vehicle_batteries')) {
            $this->mark($scanned, 'battery_life');
            $batteries = VehicleBattery::query()
                ->with('vehicle:id,plate,odometer')
                ->whereNull('replaced_on')
                ->whereNotNull('expected_life_km')
                ->get();
            foreach ($batteries as $battery) {
                $current = (int) ($battery->vehicle?->odometer ?? 0);
                $due = (int) $battery->odometer_at_install + (int) $battery->expected_life_km;
                if ($current < $due - self::LEAD_KM) {
                    continue;
                }
                $plate = $battery->vehicle?->plate ?: 'عربية';
                $alerts[] = new DueAlert(
                    ruleKey: 'battery_life',
                    subjectType: 'vehicle_battery',
                    subjectId: (int) $battery->id,
                    title: "بطارية اقتربت من نهاية عمرها: {$plate}",
                    body: "العداد {$current} ونهاية العمر عند {$due}.",
                    linkType: 'vehicle',
                    linkId: (int) $battery->vehicle_id,
                    permissions: ['vehicles.view'],
                );
            }
        }

        return $alerts;
    }

    /**
     * @param  list<string>  $scanned
     * @return list<DueAlert>
     */
    private function assignmentAlerts(array &$scanned): array
    {
        if (! Schema::hasTable('employee_assignments')) {
            return [];
        }

        $this->mark($scanned, 'assignment_ack');

        $rows = EmployeeAssignment::query()
            ->with('employee:id,name')
            ->where('status', 'pending_ack')
            ->get();

        $alerts = [];
        foreach ($rows as $assignment) {
            $name = $assignment->employee?->name ?: 'موظف';
            $alerts[] = new DueAlert(
                ruleKey: 'assignment_ack',
                subjectType: 'employee_assignment',
                subjectId: (int) $assignment->id,
                title: "إسناد بانتظار التأكيد: {$name}",
                body: $assignment->title ?: 'تسليم عهدة أو أصل لم يُؤكَّد استلامه.',
                linkType: 'employee',
                linkId: (int) $assignment->employee_id,
                permissions: ['hr.view', 'hr.custody'],
            );
        }

        return $alerts;
    }

    /**
     * تقييم معلّق = موظف على رأس العمل بلا أي تقييم مسجّل.
     * الفترة في الشاشة نص حر، فلا تُفترض دورة شهرية حتى لا يتكرر التنبيه بلا حالة معلّقة.
     *
     * @param  list<string>  $scanned
     * @return list<DueAlert>
     */
    private function evaluationAlerts(array &$scanned): array
    {
        if (! Schema::hasTable('employees') || ! Schema::hasTable('evaluations')) {
            return [];
        }

        $this->mark($scanned, 'evaluation_due');

        $done = Evaluation::query()->pluck('employee_id')->map(fn ($id) => (int) $id)->unique()->all();
        $employees = Employee::query()
            ->where('status', 'active')
            ->when($done !== [], fn ($query) => $query->whereNotIn('id', $done))
            ->get();

        $alerts = [];
        foreach ($employees as $employee) {
            $alerts[] = new DueAlert(
                ruleKey: 'evaluation_due',
                subjectType: 'employee',
                subjectId: (int) $employee->id,
                title: "تقييم معلّق: {$employee->name}",
                body: 'لا يوجد تقييم مسجّل لهذا الموظف.',
                linkType: 'employee',
                linkId: (int) $employee->id,
                permissions: ['hr.view'],
            );
        }

        return $alerts;
    }

    private function obligation(Contract $contract): int
    {
        if ($contract->final_total !== null) {
            return (int) $contract->final_total;
        }

        if ($contract->period_rent !== null) {
            return max(0, (int) $contract->period_rent
                - (int) $contract->discount
                + (int) ($contract->excess_km_amount ?? 0)
                + (int) $contract->damage_fee
                + (int) $contract->other_fees
                + (int) ($contract->frozen_operating_insurance_fee ?? 0));
        }

        if ($contract->status !== 'active' || ! $this->momentDue($contract->planned_ends_at)) {
            return 0;
        }

        $rate = (int) ($contract->frozen_daily_rate ?? 0);
        if ($rate < 1 || ! $contract->starts_at || ! $contract->planned_ends_at) {
            return 0;
        }

        $days = (int) ($contract->approved_days ?: $this->pricing->countRentalDays($contract->starts_at, $contract->planned_ends_at));

        return $rate * max(1, $days);
    }

    private function momentDue(mixed $at): bool
    {
        if ($at === null || $at === '') {
            return false;
        }

        return Carbon::parse($at)->lessThanOrEqualTo(now()->copy()->addDays(self::LEAD_DAYS));
    }

    private function dateDue(mixed $date): bool
    {
        if ($date === null || $date === '') {
            return false;
        }

        return Carbon::parse($date)->startOfDay()->lessThanOrEqualTo(now()->copy()->startOfDay()->addDays(self::LEAD_DAYS));
    }

    private function party(Contract $contract): string
    {
        $name = $contract->customer?->full_name ?: 'العميل';
        $plate = $contract->vehicle?->plate;

        return $plate ? "{$name} · {$plate}" : $name;
    }

    private function when(mixed $at): string
    {
        if ($at === null) {
            return '—';
        }

        return Carbon::parse($at)->timezone(config('app.timezone'))->format('Y-m-d H:i');
    }

    private function money(int $amount): string
    {
        return number_format($amount, 0, '.', ',').' ج.م';
    }
}
