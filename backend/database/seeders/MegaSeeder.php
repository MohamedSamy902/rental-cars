<?php

namespace Database\Seeders;

use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Expense;
use App\Models\InventoryItem;
use App\Models\Payment;
use App\Models\PettyCashAccount;
use App\Models\PettyCashMovement;
use App\Models\SalaryAdvance;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleAccessory;
use App\Models\VehicleDocument;
use App\Models\Violation;
use App\Models\WorkOrder;
use App\Models\WorkOrderLine;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Heavy-duty volume layer on top of DemoSeeder + E2EDataSeeder.
 * Idempotent: only pads counts up to the mega targets.
 */
class MegaSeeder extends Seeder
{
    private const TARGET_VEHICLES = 55;

    private const TARGET_CUSTOMERS = 60;

    private const TARGET_CONTRACTS = 110;

    private const TARGET_WORK_ORDERS = 35;

    private const TARGET_EMPLOYEES = 28;

    private const TARGET_VIOLATIONS = 25;

    private const TARGET_EXPENSES = 25;

    private const TARGET_AUDIT = 220;

    /** @var list<string> */
    private array $brands = ['هيونداي', 'تويوتا', 'كيا', 'نيسان', 'شيري', 'MG', 'بي واي دي', 'سكودا', 'شيفروليه', 'رينو'];

    /** @var list<string> */
    private array $models = ['إلنترا', 'أكسنت', 'كورولا', 'يارس', 'سبورتاج', 'صني', 'تيجو', 'ZS', 'أتو 3', 'أوكافيا', 'لانوس', 'لوجان', 'سيراتو', 'ريو', 'كروز'];

    /** @var list<string> */
    private array $categories = ['اقتصادي', 'SUV', 'عائلي', 'كهربائي', 'تجاري'];

    /** @var list<string> */
    private array $colors = ['أبيض', 'أسود', 'فضي', 'رمادي', 'أزرق', 'أحمر', 'بني'];

    /** @var list<string> */
    private array $firstNames = ['أحمد', 'محمد', 'محمود', 'يوسف', 'كريم', 'عمر', 'حسام', 'تامر', 'سارة', 'منى', 'هبة', 'نورا', 'دينا', 'ياسمين', 'علي', 'خالد', 'إبراهيم', 'مصطفى', 'فاطمة', 'مريم', 'ليلى', 'رانيا', 'سمير', 'وليد', 'باسم', 'إيمان', 'شيماء', 'أمل', 'جمال', 'رائد'];

    /** @var list<string> */
    private array $lastNames = ['محمود', 'حسن', 'علي', 'إبراهيم', 'حسين', 'عبدالله', 'فؤاد', 'منصور', 'كمال', 'سعيد', 'فهمي', 'راضي', 'جابر', 'نبيل', 'سامي'];

    /** @var list<string> */
    private array $jobTitles = ['فني صيانة', 'سائق توصيل', 'محاسب صندوق', 'مشرف أسطول', 'موظف استقبال', 'مسؤول مخزن', 'مسؤول عهدة', 'أخصائي موارد بشرية', 'مندوب مبيعات', 'مغسل عربيات'];

    /** @var list<string> */
    private array $plateLetters = ['أ', 'ب', 'ج', 'د', 'س', 'ص', 'ط', 'ع', 'ف', 'ق', 'ل', 'م', 'ن', 'هـ', 'و', 'ي'];

    public function run(): void
    {
        $admin = User::query()->where('email', 'admin@rpm.local')->firstOrFail();
        $sales = User::query()->where('email', 'sales@rpm.local')->first() ?? $admin;
        $ops = User::query()->where('email', 'ops@rpm.local')->first() ?? $admin;
        $maint = User::query()->where('email', 'maint@rpm.local')->first() ?? $admin;
        $accounts = User::query()->where('email', 'accounts@rpm.local')->first() ?? $admin;
        $hr = User::query()->where('email', 'hr@rpm.local')->first() ?? $admin;

        $actors = [
            (int) $admin->id,
            (int) $sales->id,
            (int) $ops->id,
            (int) $maint->id,
            (int) $accounts->id,
            (int) $hr->id,
        ];

        $this->seedVehicles();
        $this->seedCustomers();
        $employees = $this->seedEmployees((int) $hr->id, (int) $admin->id);
        $this->seedContracts((int) $sales->id, (int) $ops->id);
        $this->seedMaintenance($employees, (int) $maint->id);
        $this->seedViolationsAndExpenses((int) $accounts->id, (int) $admin->id, $employees);
        $this->seedAuditLogs($actors);

        $this->command?->info(sprintf(
            'MegaSeeder totals — vehicles:%d contracts:%d customers:%d employees:%d WO:%d violations:%d expenses:%d audit:%d',
            Vehicle::query()->count(),
            Contract::query()->count(),
            Customer::query()->count(),
            Employee::query()->count(),
            WorkOrder::query()->count(),
            Violation::query()->count(),
            Expense::query()->count(),
            AuditLog::query()->count(),
        ));
    }

    private function seedVehicles(): void
    {
        $have = Vehicle::query()->count();
        $need = max(0, self::TARGET_VEHICLES - $have);
        if ($need === 0) {
            return;
        }

        $manualPool = ['available', null, null, 'maintenance', 'out_of_service', null];
        for ($i = 1; $i <= $need; $i++) {
            $n = $have + $i;
            $code = sprintf('MG-%03d', $n);
            if (Vehicle::query()->where('code', $code)->exists()) {
                continue;
            }
            $brand = $this->brands[$n % count($this->brands)];
            $model = $this->models[$n % count($this->models)];
            $category = $this->categories[$n % count($this->categories)];
            $manual = $manualPool[$n % count($manualPool)];
            $odometer = 5000 + ($n * 1733) % 140000;
            $daily = 650 + ($n % 12) * 75;

            $v = Vehicle::query()->create([
                'code' => $code,
                'plate' => $this->plateFor($n),
                'brand' => $brand,
                'model' => $model,
                'year' => 2018 + ($n % 7),
                'category' => $category,
                'fuel_type' => $category === 'كهربائي' ? 'كهرباء' : 'بنزين',
                'odometer' => $odometer,
                'odometer_source' => 'manual',
                'daily_rate' => $daily,
                'default_deposit' => (int) round($daily * 3.2 / 100) * 100,
                'color' => $this->colors[$n % count($this->colors)],
                'transmission' => $n % 5 === 0 ? 'مانيوال' : 'أوتوماتيك',
                'seats' => $category === 'عائلي' ? 7 : 5,
                'manual_status' => $manual,
                'is_active' => true,
                'notes' => $n % 11 === 0 ? 'بذرة ميجا — حالة خاصة' : null,
            ]);

            foreach (['مثلث تحذير', 'طفاية حريق', 'حامل هاتف'] as $acc) {
                VehicleAccessory::query()->firstOrCreate(
                    ['vehicle_id' => $v->id, 'name' => $acc],
                    ['default_qty' => 1, 'required_each_rental' => $acc !== 'حامل هاتف'],
                );
            }

            $licenseExpires = $n % 9 === 0
                ? now()->subDays(3 + ($n % 20))->toDateString()
                : ($n % 7 === 0
                    ? now()->addDays(5 + ($n % 10))->toDateString()
                    : now()->addMonths(6 + ($n % 18))->toDateString());

            VehicleDocument::query()->firstOrCreate(
                ['vehicle_id' => $v->id, 'type' => 'رخصة السير'],
                [
                    'file_path' => 'demo/mega/'.$code.'/license.jpg',
                    'issued_on' => now()->subYear()->toDateString(),
                    'expires_on' => $licenseExpires,
                    'notes' => str_contains((string) $licenseExpires, now()->format('Y')) && $licenseExpires < now()->toDateString()
                        ? 'منتهية — بذرة ميجا'
                        : 'سارية — بذرة ميجا',
                ],
            );
            VehicleDocument::query()->firstOrCreate(
                ['vehicle_id' => $v->id, 'type' => 'تأمين إجباري'],
                [
                    'file_path' => 'demo/mega/'.$code.'/insurance.pdf',
                    'issued_on' => now()->subMonths(3)->toDateString(),
                    'expires_on' => now()->addMonths(9)->toDateString(),
                ],
            );
        }
    }

    private function seedCustomers(): void
    {
        $have = Customer::query()->count();
        $need = max(0, self::TARGET_CUSTOMERS - $have);
        for ($i = 1; $i <= $need; $i++) {
            $n = $have + $i;
            $phone = sprintf('0111%07d', $n);
            if (Customer::query()->where('phone', $phone)->exists()) {
                continue;
            }
            $name = $this->firstNames[$n % count($this->firstNames)].' '.$this->lastNames[$n % count($this->lastNames)];
            $status = $n % 17 === 0 ? 'blacklist' : 'active';
            Customer::query()->create([
                'full_name' => $name,
                'phone' => $phone,
                'birth_date' => sprintf('%d-%02d-%02d', 1975 + ($n % 25), 1 + ($n % 12), 1 + ($n % 27)),
                'license_number' => sprintf('LM-%04d', 3000 + $n),
                'license_expires_on' => $n % 8 === 0
                    ? now()->addDays(10)->toDateString()
                    : now()->addYears(2 + ($n % 4))->toDateString(),
                'national_id' => sprintf('2%013d', 9000000000000 + $n),
                'status' => $status,
                'notes' => $status === 'blacklist' ? 'قائمة سوداء — بذرة ميجا' : null,
            ]);
        }
    }

    /**
     * @return list<Employee>
     */
    private function seedEmployees(int $hrId, int $adminId): array
    {
        $have = Employee::query()->count();
        $need = max(0, self::TARGET_EMPLOYEES - $have);
        for ($i = 1; $i <= $need; $i++) {
            $n = $have + $i;
            $name = $this->firstNames[($n + 3) % count($this->firstNames)].' '.$this->jobTitles[$n % count($this->jobTitles)];
            if (Employee::query()->where('name', $name)->exists()) {
                $name .= ' #'.$n;
            }
            $emp = Employee::query()->create([
                'name' => $name,
                'job_title' => $this->jobTitles[$n % count($this->jobTitles)],
                'hired_on' => now()->subMonths(2 + ($n % 36))->toDateString(),
                'base_salary' => 3500 + ($n % 15) * 250,
                'status' => $n % 14 === 0 ? 'ended' : 'active',
            ]);

            if ($emp->status === 'active' && $n % 2 === 0) {
                $petty = PettyCashAccount::query()->firstOrCreate(
                    ['employee_id' => $emp->id],
                    [
                        'limit_amount' => 3000 + ($n % 5) * 1000,
                        'balance' => 1500 + ($n % 7) * 200,
                        'is_active' => true,
                    ],
                );
                if (! PettyCashMovement::query()->where('petty_cash_account_id', $petty->id)->exists()) {
                    PettyCashMovement::query()->create([
                        'petty_cash_account_id' => $petty->id,
                        'type' => 'topup',
                        'amount' => (int) $petty->limit_amount,
                        'moved_on' => now()->subDays(12)->toDateString(),
                        'performed_by' => $hrId,
                        'notes' => 'افتتاح عهدة — ميجا',
                    ]);
                    $spend = min(800, (int) $petty->balance);
                    if ($spend > 0) {
                        PettyCashMovement::query()->create([
                            'petty_cash_account_id' => $petty->id,
                            'type' => 'spend',
                            'amount' => $spend,
                            'moved_on' => now()->subDays(4)->toDateString(),
                            'performed_by' => $adminId,
                            'notes' => 'صرف تشغيلي — ميجا',
                        ]);
                        $petty->update(['balance' => max(0, (int) $petty->balance - $spend)]);
                    }
                }
            }

            if ($emp->status === 'active') {
                SalaryAdvance::query()->firstOrCreate(
                    [
                        'employee_id' => $emp->id,
                        'year' => (int) now()->year,
                        'month' => (int) now()->month,
                        'amount' => 300 + ($n % 8) * 100,
                        'notes' => 'سلفة ميجا',
                    ],
                    [
                        'advanced_on' => now()->subDays(3 + ($n % 10))->toDateString(),
                        'method' => 'cash',
                        'recorded_by' => $hrId,
                        'created_by' => $hrId,
                    ],
                );
            }
        }

        $active = Employee::query()->where('status', 'active')->get();
        $pads = InventoryItem::query()->where('code', 'BRK-PAD')->first();
        foreach ($active->take(8) as $idx => $emp) {
            if (EmployeeAssignment::query()->where('employee_id', $emp->id)->exists()) {
                continue;
            }
            EmployeeAssignment::query()->create([
                'employee_id' => $emp->id,
                'assignable_type' => $pads && $idx % 2 === 0 ? InventoryItem::class : 'manual',
                'assignable_id' => $pads && $idx % 2 === 0 ? $pads->id : null,
                'title' => $idx % 2 === 0 ? 'عهدة قطع غيار' : 'جهاز محمول / مفاتيح',
                'notes' => 'تسليم عهدة — بذرة ميجا',
                'status' => $idx % 3 === 0 ? 'pending_ack' : 'acknowledged',
                'assigned_by' => $hrId,
                'assigned_at' => now()->subDays(2 + $idx),
                'acknowledged_by' => $idx % 3 === 0 ? null : $hrId,
                'acknowledged_at' => $idx % 3 === 0 ? null : now()->subDays(1 + $idx),
            ]);
        }

        return Employee::query()->where('status', 'active')->get()->all();
    }

    private function seedContracts(int $salesId, int $opsId): void
    {
        $have = Contract::query()->count();
        $need = max(0, self::TARGET_CONTRACTS - $have);
        if ($need === 0) {
            return;
        }

        $customers = Customer::query()->where('status', 'active')->pluck('id')->all();
        $vehicles = Vehicle::query()->where('is_active', true)->pluck('id')->all();
        if ($customers === [] || $vehicles === []) {
            return;
        }

        $statuses = [
            'active', 'active', 'approved', 'approved', 'pending',
            'completed', 'completed', 'completed', 'cancelled', 'pending',
        ];

        for ($i = 1; $i <= $need; $i++) {
            $n = $have + $i;
            $number = sprintf('CNT-M-%04d', $n);
            if (Contract::query()->where('number', $number)->exists()) {
                continue;
            }
            $status = $statuses[$n % count($statuses)];
            // Spread today's handovers + upcoming bookings
            if ($n % 13 === 0) {
                $status = 'approved';
                $starts = now()->setTime(9 + ($n % 6), 0);
                $ends = now()->addDays(2 + ($n % 4))->setTime(9, 0);
            } elseif ($n % 11 === 0) {
                $status = 'approved';
                $starts = now()->addDays(2 + ($n % 10))->setTime(10, 0);
                $ends = (clone $starts)->addDays(3 + ($n % 5));
            } elseif ($status === 'active') {
                $starts = now()->subDays(1 + ($n % 8))->setTime(10, 0);
                $ends = now()->addDays(1 + ($n % 6))->setTime(10, 0);
            } elseif ($status === 'completed') {
                $starts = now()->subDays(40 + ($n % 40))->setTime(10, 0);
                $ends = (clone $starts)->addDays(3 + ($n % 7));
            } elseif ($status === 'cancelled') {
                $starts = now()->subDays(5 + ($n % 10))->setTime(12, 0);
                $ends = (clone $starts)->addDays(3);
            } else {
                $starts = now()->addDays(1 + ($n % 14))->setTime(11, 0);
                $ends = (clone $starts)->addDays(2 + ($n % 6));
            }

            $daily = 700 + ($n % 15) * 50;
            $days = max(1, (int) $starts->diffInDays($ends));
            $period = $daily * $days;
            $customerId = $customers[$n % count($customers)];
            $vehicleId = $vehicles[$n % count($vehicles)];

            $row = [
                'number' => $number,
                'customer_id' => $customerId,
                'vehicle_id' => $vehicleId,
                'status' => $status,
                'starts_at' => $starts,
                'planned_ends_at' => $ends,
                'frozen_daily_rate' => $daily,
                'frozen_daily_km_allowance' => 200,
                'frozen_excess_km_rate' => 5,
                'frozen_agreed_deposit' => (int) round($daily * 3 / 100) * 100,
                'period_rent' => $period,
                'final_total' => $period,
                'sales_user_id' => $salesId,
                'notes' => 'عقد ميجا #'.$n,
            ];

            if ($status === 'active' || $status === 'completed') {
                $row['odometer_out'] = 10000 + $n * 17;
            }
            if ($status === 'completed') {
                $row['actual_ends_at'] = $ends;
                $row['odometer_in'] = ($row['odometer_out'] ?? 10000) + 200 + ($n % 400);
                $paidShare = $n % 3 === 0 ? 0.5 : 1.0;
                $row['final_total'] = $period + ($n % 5) * 50;
            }
            if ($status === 'cancelled') {
                $row['cancel_reason'] = 'إلغاء بذرة ميجا';
            }

            $contract = Contract::query()->create($row);

            if (in_array($status, ['active', 'approved', 'completed'], true) && $n % 2 === 0) {
                Deposit::query()->firstOrCreate(
                    ['contract_id' => $contract->id],
                    [
                        'original_amount' => (int) $contract->frozen_agreed_deposit,
                        'status' => $status === 'completed' ? 'returned' : 'received',
                        'received_at' => $starts,
                        'receipt_number' => 'DEP-M-'.$n,
                        'collection_method' => $n % 2 === 0 ? 'cash' : 'card',
                        'returned_at' => $status === 'completed' ? $ends : null,
                        'return_receipt_number' => $status === 'completed' ? 'DEP-M-'.$n.'-R' : null,
                        'return_method' => $status === 'completed' ? 'cash' : null,
                        'deductions_total' => 0,
                    ],
                );
            }

            if (in_array($status, ['active', 'completed'], true)) {
                $amount = (int) round(((int) $contract->final_total) * ($status === 'completed' && $n % 3 === 0 ? 0.5 : 1));
                Payment::query()->firstOrCreate(
                    ['reference' => 'PAY-M-'.$n],
                    [
                        'amount' => max(100, $amount),
                        'paid_at' => $status === 'completed' ? $ends : $starts,
                        'method' => $n % 3 === 0 ? 'transfer' : 'cash',
                        'direction' => 'in',
                        'link_type' => 'contract',
                        'link_id' => $contract->id,
                        'user_id' => $opsId,
                        'notes' => $status === 'completed' && $n % 3 === 0 ? 'دفعة جزئية — ميجا' : 'سداد — ميجا',
                    ],
                );
            }
        }
    }

    /**
     * @param  list<Employee>  $employees
     */
    private function seedMaintenance(array $employees, int $maintId): void
    {
        unset($maintId);
        $have = WorkOrder::query()->count();
        $need = max(0, self::TARGET_WORK_ORDERS - $have);
        if ($need === 0) {
            return;
        }

        $vehicles = Vehicle::query()->pluck('id')->all();
        $oil = InventoryItem::query()->where('code', 'OIL-5W30')->first();
        $pads = InventoryItem::query()->where('code', 'BRK-PAD')->first();
        $tech = $employees[0] ?? Employee::query()->where('status', 'active')->first();

        $statuses = ['open', 'in_progress', 'closed', 'closed', 'closed'];
        for ($i = 1; $i <= $need; $i++) {
            $n = $have + $i;
            $status = $statuses[$n % count($statuses)];
            $vehicleId = $vehicles[$n % count($vehicles)];
            $workshop = $n % 2 === 0 ? 'ورشة المخزون — ميجا' : 'ورشة العهدة — ميجا';
            // Avoid colliding with DemoSeeder unique-ish open WO on same vehicle+status
            $opened = now()->subDays(2 + ($n % 40));
            $wo = WorkOrder::query()->create([
                'vehicle_id' => $vehicleId,
                'workshop' => $workshop.' #'.$n,
                'opened_on' => $opened->toDateString(),
                'opened_at' => $opened,
                'closed_on' => $status === 'closed' ? $opened->copy()->addDay()->toDateString() : null,
                'odometer' => 20000 + $n * 111,
                'status' => $status,
                'puts_in_maintenance' => $status !== 'closed' && $n % 4 === 0,
                'parts_cost' => 200 + ($n % 10) * 80,
                'labor_cost' => 100 + ($n % 6) * 50,
                'notes' => 'أمر شغل ميجا #'.$n,
                'next_service_on' => now()->addDays(20 + ($n % 60))->toDateString(),
                'next_service_odometer' => 25000 + $n * 120,
            ]);

            $fromStock = $n % 2 === 0;
            $item = $fromStock ? ($oil ?: $pads) : null;
            $unit = $fromStock ? 180 : 350 + ($n % 5) * 40;
            WorkOrderLine::query()->create([
                'work_order_id' => $wo->id,
                'description' => $fromStock ? 'قطع من المخزون — ميجا' : 'شراء من العهدة — ميجا',
                'part_source' => $fromStock ? 'stock' : 'purchase',
                'inventory_item_id' => $fromStock && $item ? $item->id : null,
                'quantity' => 1 + ($n % 3),
                'unit_price' => $unit,
                'from_petty_cash' => ! $fromStock,
                'petty_cash_employee_id' => ! $fromStock && $tech ? $tech->id : null,
                'petty_cash_amount' => ! $fromStock ? $unit : 0,
            ]);
        }
    }

    /**
     * @param  list<Employee>  $employees
     */
    private function seedViolationsAndExpenses(int $accountsId, int $adminId, array $employees): void
    {
        $vehicles = Vehicle::query()->pluck('id')->all();
        $customers = Customer::query()->pluck('id')->all();
        $contracts = Contract::query()->whereIn('status', ['active', 'completed'])->pluck('id')->all();
        $tech = $employees[0] ?? null;

        $vHave = Violation::query()->count();
        for ($i = 1; $i <= max(0, self::TARGET_VIOLATIONS - $vHave); $i++) {
            $n = $vHave + $i;
            $statuses = ['new', 'assigned', 'confirmed', 'paid', 'new'];
            Violation::query()->create([
                'vehicle_id' => $vehicles[$n % count($vehicles)],
                'occurred_at' => now()->subDays($n)->setTime(10 + ($n % 8), 15),
                'location' => ['محور 26 يوليو', 'الدائري', 'كورنيش النيل', 'مول مصر', 'مدينة نصر'][$n % 5],
                'amount' => 100 + ($n % 10) * 50,
                'contract_id' => $contracts !== [] ? $contracts[$n % count($contracts)] : null,
                'customer_id' => $customers !== [] ? $customers[$n % count($customers)] : null,
                'status' => $statuses[$n % count($statuses)],
                'confirmed_by' => $n % 3 === 0 ? $accountsId : null,
                'confirmed_at' => $n % 3 === 0 ? now()->subDays($n - 1) : null,
                'created_by' => $accountsId,
                'notes' => 'مخالفة ميجا #'.$n,
            ]);
        }

        $eHave = Expense::query()->count();
        for ($i = 1; $i <= max(0, self::TARGET_EXPENSES - $eHave); $i++) {
            $n = $eHave + $i;
            $fromPetty = $n % 3 === 0 && $tech;
            Expense::query()->create([
                'title' => ['غسيل أسطول', 'وقود', 'رسوم بوابة', 'صيانة طارئة', 'مستلزمات مكتب'][$n % 5].' #'.$n,
                'category' => ['تشغيل', 'وقود', 'طريق', 'صيانة', 'إدارية'][$n % 5],
                'amount' => 50 + ($n % 20) * 35,
                'spent_on' => now()->subDays($n % 45)->toDateString(),
                'payment_method' => $fromPetty ? 'petty_cash' : 'cash',
                'vehicle_id' => $n % 2 === 0 ? $vehicles[$n % count($vehicles)] : null,
                'employee_id' => $fromPetty ? $tech->id : null,
                'from_petty_cash' => (bool) $fromPetty,
                'recorded_by' => $n % 2 === 0 ? $accountsId : $adminId,
                'notes' => 'مصروف ميجا',
            ]);
        }
    }

    /**
     * @param  list<int>  $actors
     */
    private function seedAuditLogs(array $actors): void
    {
        $have = AuditLog::query()->count();
        $need = max(0, self::TARGET_AUDIT - $have);
        if ($need === 0) {
            return;
        }

        $contracts = Contract::query()->orderBy('id')->get(['id', 'number']);
        $vehicles = Vehicle::query()->orderBy('id')->get(['id', 'plate']);
        $customers = Customer::query()->orderBy('id')->get(['id', 'full_name']);
        $employees = Employee::query()->orderBy('id')->get(['id', 'name']);

        $catalog = [
            'created', 'approved', 'handover', 'payment_recorded', 'completed', 'cancelled',
            'vehicle_updated', 'customer_updated', 'work_order_opened', 'work_order_closed',
            'salary_advance_recorded', 'petty_cash_topup', 'payroll_payout', 'deposit_received',
        ];

        $now = now();
        $rows = [];
        $vCount = max(1, $vehicles->count());
        $cCount = max(1, $contracts->count());
        $cuCount = max(1, $customers->count());
        $eCount = max(1, $employees->count());

        for ($i = 0; $i < $need; $i++) {
            $n = $have + $i + 1;
            $action = $catalog[$n % count($catalog)];
            $actor = $actors[$n % count($actors)];

            switch ($action) {
                case 'vehicle_updated':
                    $vehicle = $vehicles[$n % $vCount] ?? null;
                    $type = Vehicle::class;
                    $recordId = (int) ($vehicle->id ?? 1);
                    $after = ['plate' => $vehicle->plate ?? 'أ ب ج 0001'];
                    break;
                case 'customer_updated':
                    $customer = $customers[$n % $cuCount] ?? null;
                    $type = Customer::class;
                    $recordId = (int) ($customer->id ?? 1);
                    $after = ['full_name' => $customer->full_name ?? 'عميل'];
                    break;
                case 'work_order_opened':
                case 'work_order_closed':
                    $vehicle = $vehicles[$n % $vCount] ?? null;
                    $type = WorkOrder::class;
                    $recordId = max(1, $n);
                    $after = ['vehicle_plate' => $vehicle->plate ?? 'أ ب ج 0001'];
                    break;
                case 'salary_advance_recorded':
                case 'petty_cash_topup':
                    $employee = $employees[$n % $eCount] ?? null;
                    $type = $action === 'salary_advance_recorded' ? SalaryAdvance::class : PettyCashAccount::class;
                    $recordId = (int) ($employee->id ?? 1);
                    $after = ['employee_name' => $employee->name ?? 'موظف', 'amount' => 500 + ($n % 10) * 100];
                    break;
                case 'payroll_payout':
                case 'deposit_received':
                case 'payment_recorded':
                    $contract = $contracts[$n % $cCount] ?? null;
                    $type = Contract::class;
                    $recordId = (int) ($contract->id ?? 1);
                    $after = [
                        'number' => $contract->number ?? 'CNT-M-0001',
                        'amount' => 500 + ($n % 25) * 100,
                    ];
                    break;
                default:
                    $contract = $contracts[$n % $cCount] ?? null;
                    $type = Contract::class;
                    $recordId = (int) ($contract->id ?? 1);
                    $after = ['number' => $contract->number ?? 'CNT-M-0001'];
                    break;
            }

            $created = $now->copy()->subMinutes($need - $i);
            $rows[] = [
                'user_id' => $actor,
                'action' => $action,
                'record_type' => $type,
                'record_id' => $recordId,
                'before' => null,
                'after' => json_encode($after, JSON_UNESCAPED_UNICODE),
                'created_at' => $created,
                'updated_at' => $created,
            ];

            if (count($rows) >= 100) {
                DB::table('audit_logs')->insert($rows);
                $rows = [];
            }
        }
        if ($rows !== []) {
            DB::table('audit_logs')->insert($rows);
        }
    }

    private function plateFor(int $n): string
    {
        $a = $this->plateLetters[$n % count($this->plateLetters)];
        $b = $this->plateLetters[($n * 3) % count($this->plateLetters)];
        $c = $this->plateLetters[($n * 7) % count($this->plateLetters)];

        return sprintf('%s %s %s %04d', $a, $b, $c, 1000 + ($n % 9000));
    }
}
