<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\MonthLock;
use App\Models\Payment;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use App\Services\Accounting\AccountingService;
use App\Services\Contracts\ContractPricingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * يُثرى قاعدة الديمو الحية ببيانات متنوعة (حالات عقود / صيانة موعد أو عداد / إقفال شهر)
 * بدون مسح البيانات الحالية — idempotent قدر الإمكان.
 * يُرحِّل أيضاً إثبات الإيجار (قيود 4000) للعقود النشطة/المكتملة الناقصة حتى يظهر «إيراد إيجار» في الحسابات.
 */
class EnrichLiveDemoCommand extends Command
{
    protected $signature = 'rpm:enrich-live-demo';

    protected $description = 'تعبئة بيانات تجريبية متنوعة على البيئة الحية (عقود بكل الحالات، صيانة XOR، سجل إقفال، ترحيل إيراد إيجار)';

    public function handle(AccountingService $accounting, ContractPricingService $pricing): int
    {
        $actor = User::query()->orderBy('id')->first();
        if (! $actor) {
            $this->error('لا يوجد مستخدم لربط السجلات.');

            return self::FAILURE;
        }

        $created = [
            'customers_addressed' => $this->fillCustomerAddresses(),
            'contracts' => $this->ensureContractStatuses($actor->id),
            'work_orders' => $this->ensureWorkOrdersXor(),
            'expenses' => $this->ensureExpenses($actor->id),
            'payments' => $this->ensureSamplePayments($actor->id),
            'month_lock_audit' => $this->ensureMonthLockAudit($actor->id),
            'period_rent_filled' => $this->ensurePeriodRent($pricing),
            'rent_journals' => $this->ensureRentJournals($accounting, $actor->id),
        ];

        $this->info('تم إثراء البيانات التجريبية:');
        foreach ($created as $key => $count) {
            $this->line("  · {$key}: {$count}");
        }
        if (($created['rent_journals'] ?? 0) === 0 && ($created['contracts'] ?? 0) === 0) {
            $this->comment('ملاحظة: الأصفار تعني أن العينات والقيود موجودة مسبقاً (الأمر لا يعيد الكتابة).');
        }

        return self::SUCCESS;
    }

    private function fillCustomerAddresses(): int
    {
        $n = 0;
        $samples = [
            'حدائق حلوان — القاهرة',
            'المعادي — القاهرة',
            'مدينة نصر — القاهرة',
            'الشروق — القاهرة',
            '٦ أكتوبر — الجيزة',
        ];
        Customer::query()->where(function ($q) {
            $q->whereNull('address')->orWhere('address', '');
        })->orderBy('id')->limit(40)->get()->each(function (Customer $c, int $i) use (&$n, $samples) {
            $c->address = $samples[$i % count($samples)];
            $c->save();
            $n++;
        });

        return $n;
    }

    private function ensureContractStatuses(int $actorId): int
    {
        unset($actorId);
        $needed = ['pending', 'approved', 'active', 'completed', 'cancelled'];
        $created = 0;
        $vehicles = Vehicle::query()->where('is_active', true)->orderBy('id')->get();
        $customers = Customer::query()->orderBy('id')->get();
        if ($vehicles->isEmpty() || $customers->isEmpty()) {
            return 0;
        }

        foreach ($needed as $i => $status) {
            $exists = Contract::query()->where('status', $status)->exists();
            if ($exists) {
                continue;
            }
            $vehicle = $vehicles[$i % $vehicles->count()];
            $customer = $customers[$i % $customers->count()];
            $starts = now()->addDays(($i * 3) - 10)->startOfDay();
            $ends = $starts->copy()->addDays(5);
            $rate = (int) ($vehicle->daily_rate ?: 1000);
            $periodRent = $rate * 5;
            Contract::query()->create([
                'number' => 'DEMO-'.$status.'-'.now()->format('ymdHis').$i,
                'customer_id' => $customer->id,
                'vehicle_id' => $vehicle->id,
                'status' => $status,
                'starts_at' => $starts,
                'planned_ends_at' => $ends,
                'actual_ends_at' => in_array($status, ['completed', 'active'], true) ? ($status === 'completed' ? $ends->copy()->subDay() : null) : null,
                'approved_days' => 5,
                'frozen_daily_rate' => $rate,
                'frozen_agreed_deposit' => (int) ($vehicle->default_deposit ?: 2000),
                'period_rent' => in_array($status, ['active', 'completed'], true) ? $periodRent : null,
                'final_total' => $status === 'completed' ? $periodRent : null,
                'notes' => 'عينة ديمو — '.$status,
            ]);
            $created++;
        }

        return $created;
    }

    /**
     * يملأ period_rent للعقود النشطة/المكتملة إن كان فارغاً حتى تُحسب شرائح الإيجار.
     */
    private function ensurePeriodRent(ContractPricingService $pricing): int
    {
        $n = 0;
        Contract::query()
            ->whereIn('status', ['active', 'completed'])
            ->where(function ($q) {
                $q->whereNull('period_rent')->orWhere('period_rent', '<=', 0);
            })
            ->orderBy('id')
            ->limit(200)
            ->get()
            ->each(function (Contract $c) use ($pricing, &$n) {
                $start = $c->starts_at;
                $end = $c->actual_ends_at ?? $c->planned_ends_at;
                $rate = (int) $c->frozen_daily_rate;
                if (! $start || ! $end || $rate <= 0 || $end <= $start) {
                    return;
                }
                $days = $pricing->countRentalDays($start, $end);
                $rent = max(0, $days * $rate);
                if ($rent <= 0) {
                    return;
                }
                $c->period_rent = $rent;
                if ($c->status === 'completed' && (int) ($c->final_total ?? 0) <= 0) {
                    $c->final_total = $rent
                        + (int) ($c->excess_km_fee ?? 0)
                        + (int) ($c->damage_fee ?? 0)
                        + (int) ($c->other_fees ?? 0)
                        - (int) ($c->discount ?? 0);
                }
                $c->save();
                $n++;
            });

        return $n;
    }

    /**
     * يُثبت إيراد الإيجار (حساب 4000) لآخر 18 شهراً للعقود المتداخلة — يملأ بطاقات الحسابات.
     */
    private function ensureRentJournals(AccountingService $accounting, int $actorId): int
    {
        $accounting->ensureChartOfAccounts();
        $before = JournalEntry::query()->where('source', 'like', 'rent_recog:%')->count();

        DB::transaction(function () use ($accounting, $actorId) {
            for ($i = 0; $i < 18; $i++) {
                $cursor = now()->subMonthsNoOverflow($i);
                $accounting->recognizeRentForMonth((int) $cursor->year, (int) $cursor->month, $actorId);
            }
        });

        $after = JournalEntry::query()->where('source', 'like', 'rent_recog:%')->count();

        return max(0, $after - $before);
    }

    private function ensureWorkOrdersXor(): int
    {
        $created = 0;
        $vehicles = Vehicle::query()->where('is_active', true)->orderBy('id')->limit(6)->get();
        if ($vehicles->isEmpty()) {
            return 0;
        }

        // Clear illegal both-set rows to date-only
        WorkOrder::query()
            ->whereNotNull('next_service_on')
            ->whereNotNull('next_service_odometer')
            ->orderBy('id')
            ->limit(50)
            ->get()
            ->each(function (WorkOrder $wo) {
                $wo->next_service_odometer = null;
                $wo->save();
            });

        $dateVehicle = $vehicles[0];
        $odoVehicle = $vehicles[min(1, $vehicles->count() - 1)];

        if (! WorkOrder::query()->where('vehicle_id', $dateVehicle->id)->whereNotNull('next_service_on')->whereNull('next_service_odometer')->exists()) {
            WorkOrder::query()->create([
                'vehicle_id' => $dateVehicle->id,
                'status' => 'open',
                'workshop' => 'ديمو — موعد فقط',
                'opened_on' => now()->toDateString(),
                'opened_at' => now(),
                'odometer' => (int) $dateVehicle->odometer,
                'puts_in_maintenance' => false,
                'parts_cost' => 0,
                'labor_cost' => 0,
                'notes' => 'تذكير زمني فقط',
                'next_service_on' => now()->addDays(20)->toDateString(),
                'next_service_odometer' => null,
            ]);
            $created++;
        }

        if (! WorkOrder::query()->where('vehicle_id', $odoVehicle->id)->whereNotNull('next_service_odometer')->whereNull('next_service_on')->exists()) {
            WorkOrder::query()->create([
                'vehicle_id' => $odoVehicle->id,
                'status' => 'closed',
                'workshop' => 'ديمو — عداد فقط',
                'opened_on' => now()->subDays(5)->toDateString(),
                'opened_at' => now()->subDays(5),
                'closed_on' => now()->subDays(4)->toDateString(),
                'odometer' => (int) $odoVehicle->odometer,
                'puts_in_maintenance' => false,
                'parts_cost' => 250,
                'labor_cost' => 0,
                'notes' => 'تذكير بالكيلومتر فقط',
                'next_service_on' => null,
                'next_service_odometer' => (int) $odoVehicle->odometer + 5000,
            ]);
            $created++;
        }

        return $created;
    }

    private function ensureExpenses(int $actorId): int
    {
        if (Expense::query()->where('amount', '>', 0)->exists()) {
            return 0;
        }
        $vehicle = Vehicle::query()->orderBy('id')->first();
        Expense::query()->create([
            'title' => 'مصروف ديمو — وقود تشغيلي',
            'category' => 'تشغيل',
            'amount' => 750,
            'spent_on' => now()->toDateString(),
            'payment_method' => 'cash',
            'vehicle_id' => $vehicle?->id,
            'recorded_by' => $actorId,
            'notes' => 'عينة لدفتر الشهر',
        ]);

        return 1;
    }

    private function ensureSamplePayments(int $actorId): int
    {
        $contract = Contract::query()->whereIn('status', ['active', 'completed', 'approved'])->orderByDesc('id')->first();
        if (! $contract) {
            return 0;
        }
        if (Payment::query()->where('link_type', 'contract')->where('link_id', $contract->id)->exists()) {
            return 0;
        }
        Payment::query()->create([
            'amount' => 1500,
            'paid_at' => now(),
            'method' => 'cash',
            'direction' => 'in',
            'link_type' => 'contract',
            'link_id' => $contract->id,
            'user_id' => $actorId,
            'notes' => 'دفعة ديمو',
        ]);

        return 1;
    }

    private function ensureMonthLockAudit(int $actorId): int
    {
        $year = (int) now()->year;
        $month = (int) now()->month;
        $lock = MonthLock::query()->firstOrCreate(
            ['year' => $year, 'month' => $month],
            ['locked_at' => null, 'locked_by' => null, 'reopen_reason' => null],
        );

        $exists = AuditLog::query()
            ->where('record_type', MonthLock::class)
            ->where('record_id', $lock->id)
            ->whereIn('action', ['month_locked', 'month_reopened'])
            ->exists();
        if ($exists) {
            return 0;
        }

        AuditLog::query()->create([
            'user_id' => $actorId,
            'action' => 'month_locked',
            'record_type' => MonthLock::class,
            'record_id' => $lock->id,
            'before' => null,
            'after' => ['year' => $year, 'month' => $month, 'demo' => true],
        ]);
        AuditLog::query()->create([
            'user_id' => $actorId,
            'action' => 'month_reopened',
            'record_type' => MonthLock::class,
            'record_id' => $lock->id,
            'before' => null,
            'after' => ['year' => $year, 'month' => $month, 'reason' => 'إعادة فتح ديمو لعرض السجل'],
        ]);
        $lock->fill([
            'locked_at' => null,
            'locked_by' => null,
            'reopen_reason' => 'إعادة فتح ديمو لعرض السجل',
        ])->save();

        return 2;
    }
}
