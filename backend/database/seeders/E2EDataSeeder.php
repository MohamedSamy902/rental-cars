<?php

namespace Database\Seeders;

use App\Models\AuditLog;
use App\Models\Contract;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\Employee;
use App\Models\EmployeeAssignment;
use App\Models\Expense;
use App\Models\Inspection;
use App\Models\InventoryBatch;
use App\Models\InventoryItem;
use App\Models\OwnerMonthDue;
use App\Models\OwnerPayout;
use App\Models\Payment;
use App\Models\PayrollLine;
use App\Models\PayrollLinePayout;
use App\Models\PayrollRun;
use App\Models\PettyCashAccount;
use App\Models\PettyCashMovement;
use App\Models\SalaryAdvance;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleAccessory;
use App\Models\VehicleAddonCatalog;
use App\Models\VehicleBrand;
use App\Models\VehicleDocument;
use App\Models\VehicleOwner;
use App\Models\Violation;
use App\Models\WaitlistRequest;
use App\Models\WorkOrder;
use App\Models\WorkOrderLine;
use App\Services\Notifications\NotificationDispatchService;
use Illuminate\Database\Seeder;

/**
 * Rich operational dataset on top of DemoSeeder — fills every module/tab
 * with realistic Arabic demo scenarios (idempotent).
 */
class E2EDataSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()->where('email', 'admin@rpm.local')->firstOrFail();
        $sales = User::query()->where('email', 'sales@rpm.local')->first();
        $ops = User::query()->where('email', 'ops@rpm.local')->first();
        $maint = User::query()->where('email', 'maint@rpm.local')->first();
        $accounts = User::query()->where('email', 'accounts@rpm.local')->first();
        $hr = User::query()->where('email', 'hr@rpm.local')->first();

        $actorId = (int) $admin->id;
        $salesId = (int) ($sales?->id ?? $admin->id);
        $opsId = (int) ($ops?->id ?? $admin->id);
        $maintId = (int) ($maint?->id ?? $admin->id);
        $accountsId = (int) ($accounts?->id ?? $admin->id);
        $hrId = (int) ($hr?->id ?? $admin->id);

        $this->seedBrandsAndAddons();
        $this->seedExtraFleet($actorId);
        $this->seedCustomers();
        $this->seedInventory($actorId);
        $employees = $this->seedHrPettyAndCustody($actorId, $hrId);
        $this->seedContractsPaymentsAndFleetPrep($actorId, $salesId, $opsId);
        $this->seedMaintenance($actorId, $maintId, $employees['tech'], $employees['driver']);
        $this->seedViolationsAndExpenses($actorId, $accountsId, $employees['tech']);
        $this->seedWaitlist($actorId);
        $this->seedOwnersFinance($actorId, $accountsId);
        $this->seedPayrollAndAdvances($actorId, $hrId, $employees);
        $this->seedAuditTrail($actorId, $salesId, $opsId, $maintId, $accountsId, $hrId);
        $this->seedNotifications($admin, $ops, $maint, $accounts);
    }

    private function seedBrandsAndAddons(): void
    {
        foreach ([
            ['name' => 'هيونداي', 'sort_order' => 1],
            ['name' => 'تويوتا', 'sort_order' => 2],
            ['name' => 'كيا', 'sort_order' => 3],
            ['name' => 'نيسان', 'sort_order' => 4],
            ['name' => 'شيري', 'sort_order' => 5],
            ['name' => 'MG', 'sort_order' => 6],
            ['name' => 'بي واي دي', 'sort_order' => 7],
        ] as $brand) {
            VehicleBrand::query()->updateOrCreate(
                ['name' => $brand['name']],
                ['sort_order' => $brand['sort_order'], 'is_active' => true],
            );
        }

        foreach ([
            ['name' => 'مثلث تحذير', 'category' => 'سلامة', 'default_qty' => 1, 'required_each_rental' => true, 'sort_order' => 1],
            ['name' => 'طفاية حريق', 'category' => 'سلامة', 'default_qty' => 1, 'required_each_rental' => true, 'sort_order' => 2],
            ['name' => 'حامل هاتف', 'category' => 'راحة', 'default_qty' => 1, 'required_each_rental' => false, 'sort_order' => 3],
            ['name' => 'شاحن يو إس بي', 'category' => 'راحة', 'default_qty' => 1, 'required_each_rental' => false, 'sort_order' => 4],
            ['name' => 'كفر احتياطي', 'category' => 'سلامة', 'default_qty' => 1, 'required_each_rental' => true, 'sort_order' => 5],
            ['name' => 'مظلة شمسية', 'category' => 'راحة', 'default_qty' => 1, 'required_each_rental' => false, 'sort_order' => 6],
        ] as $addon) {
            VehicleAddonCatalog::query()->updateOrCreate(
                ['name' => $addon['name']],
                $addon + ['is_active' => true, 'notes' => 'مستلزم معاينة'],
            );
        }
    }

    private function seedExtraFleet(int $actorId): void
    {
        $rows = [
            [
                'code' => 'C-301',
                'plate' => 'أ ن م 3010',
                'brand' => 'هيونداي',
                'model' => 'أكسنت',
                'year' => 2020,
                'category' => 'اقتصادي',
                'fuel_type' => 'بنزين',
                'odometer' => 102400,
                'daily_rate' => 700,
                'default_deposit' => 2500,
                'color' => 'فضي',
                'transmission' => 'أوتوماتيك',
                'seats' => 5,
            ],
            [
                'code' => 'C-402',
                'plate' => 'ب ت ث 4020',
                'brand' => 'تويوتا',
                'model' => 'يارس',
                'year' => 2023,
                'category' => 'اقتصادي',
                'fuel_type' => 'بنزين',
                'odometer' => 27800,
                'daily_rate' => 850,
                'default_deposit' => 3000,
                'color' => 'أبيض',
                'transmission' => 'أوتوماتيك',
                'seats' => 5,
            ],
            [
                'code' => 'V-510',
                'plate' => 'ج ح خ 5100',
                'brand' => 'كيا',
                'model' => 'كارنيفال',
                'year' => 2022,
                'category' => 'عائلي',
                'fuel_type' => 'بنزين',
                'odometer' => 45600,
                'daily_rate' => 1800,
                'default_deposit' => 6000,
                'color' => 'أسود',
                'transmission' => 'أوتوماتيك',
                'seats' => 7,
            ],
            [
                'code' => 'E-120',
                'plate' => 'د ذ ر 1200',
                'brand' => 'بي واي دي',
                'model' => 'أتو 3',
                'year' => 2024,
                'category' => 'كهربائي',
                'fuel_type' => 'كهرباء',
                'odometer' => 9100,
                'daily_rate' => 1600,
                'default_deposit' => 5500,
                'color' => 'أزرق',
                'transmission' => 'أوتوماتيك',
                'seats' => 5,
            ],
            [
                'code' => 'C-077',
                'plate' => 'ز س ش 0770',
                'brand' => 'نيسان',
                'model' => 'سنترا',
                'year' => 2019,
                'category' => 'اقتصادي',
                'fuel_type' => 'بنزين',
                'odometer' => 141000,
                'daily_rate' => 650,
                'default_deposit' => 2000,
                'manual_status' => 'out_of_service',
                'color' => 'رمادي',
                'transmission' => 'مانيوال',
                'seats' => 5,
                'notes' => 'خارج الخدمة — بانتظار بيع/تشليح',
            ],
            [
                'code' => 'S-220',
                'plate' => 'ص ض ط 2200',
                'brand' => 'MG',
                'model' => 'RX5',
                'year' => 2024,
                'category' => 'SUV',
                'fuel_type' => 'بنزين',
                'odometer' => 12400,
                'daily_rate' => 1500,
                'default_deposit' => 5000,
                'color' => 'أحمر',
                'transmission' => 'أوتوماتيك',
                'seats' => 5,
            ],
        ];

        foreach ($rows as $row) {
            $v = Vehicle::query()->updateOrCreate(
                ['code' => $row['code']],
                [...$row, 'odometer_source' => 'manual', 'is_active' => true],
            );
            $this->ensureAccessories($v);
            $this->ensureVehicleDocs($v, $actorId);
        }

        // Enrich existing demo fleet with docs/accessories
        foreach (Vehicle::query()->whereIn('code', ['C-101', 'C-214', 'S-012', 'C-088', 'VEH-9001', 'VEH-9002'])->get() as $v) {
            $this->ensureAccessories($v);
            $this->ensureVehicleDocs($v, $actorId);
        }
    }

    private function ensureAccessories(Vehicle $v): void
    {
        foreach ([
            ['name' => 'مثلث تحذير', 'default_qty' => 1, 'required_each_rental' => true],
            ['name' => 'طفاية حريق', 'default_qty' => 1, 'required_each_rental' => true],
            ['name' => 'حامل هاتف', 'default_qty' => 1, 'required_each_rental' => false],
            ['name' => 'شاحن يو إس بي', 'default_qty' => 1, 'required_each_rental' => false],
        ] as $acc) {
            VehicleAccessory::query()->firstOrCreate(
                ['vehicle_id' => $v->id, 'name' => $acc['name']],
                $acc,
            );
        }
    }

    private function ensureVehicleDocs(Vehicle $v, int $actorId): void
    {
        unset($actorId); // reserved for future media linkage
        VehicleDocument::query()->firstOrCreate(
            ['vehicle_id' => $v->id, 'type' => 'رخصة السير'],
            [
                'file_path' => 'demo/vehicles/'.$v->code.'/license-front.jpg',
                'file_path_back' => 'demo/vehicles/'.$v->code.'/license-back.jpg',
                'issued_on' => now()->subYears(1)->toDateString(),
                'expires_on' => now()->addMonths(10)->toDateString(),
                'notes' => 'رخصة سارية — بذرة معاينة',
            ],
        );
        VehicleDocument::query()->firstOrCreate(
            ['vehicle_id' => $v->id, 'type' => 'تأمين إجباري'],
            [
                'file_path' => 'demo/vehicles/'.$v->code.'/insurance.pdf',
                'issued_on' => now()->subMonths(4)->toDateString(),
                'expires_on' => now()->addMonths(8)->toDateString(),
                'notes' => 'تأمين إجباري ساري',
            ],
        );
        // One near-expiry doc for attention signals
        if ($v->code === 'C-301') {
            VehicleDocument::query()->updateOrCreate(
                ['vehicle_id' => $v->id, 'type' => 'فحص فني'],
                [
                    'file_path' => 'demo/vehicles/'.$v->code.'/inspection.pdf',
                    'issued_on' => now()->subYear()->toDateString(),
                    'expires_on' => now()->addDays(5)->toDateString(),
                    'notes' => 'يفحص قريباً — إشارة انتباه',
                ],
            );
        }
    }

    private function seedCustomers(): void
    {
        $rows = [
            [
                'full_name' => 'سارة إبراهيم',
                'phone' => '01044445555',
                'birth_date' => '1995-11-02',
                'license_number' => 'L-2001',
                'license_expires_on' => now()->addYears(2)->toDateString(),
                'national_id' => '29511021234567',
                'status' => 'active',
            ],
            [
                'full_name' => 'كريم حسن',
                'phone' => '01055557777',
                'birth_date' => '1985-04-18',
                'license_number' => 'L-2002',
                'license_expires_on' => now()->addMonths(1)->toDateString(),
                'national_id' => '28504181234567',
                'status' => 'active',
                'notes' => 'رخصة تنتهي قريباً',
            ],
            [
                'full_name' => 'هبة منصور',
                'phone' => '01066668888',
                'birth_date' => '1991-07-30',
                'license_number' => 'L-2003',
                'license_expires_on' => now()->addYears(4)->toDateString(),
                'national_id' => '29107301234567',
                'status' => 'active',
            ],
            [
                'full_name' => 'عميل قائمة سوداء',
                'phone' => '01077779999',
                'birth_date' => '1980-02-14',
                'license_number' => 'L-2099',
                'license_expires_on' => now()->addYear()->toDateString(),
                'national_id' => '28002141234567',
                'status' => 'blacklist',
                'notes' => 'تجاوز متكرر — قائمة سوداء للمعاينة',
            ],
        ];

        foreach ($rows as $c) {
            Customer::query()->updateOrCreate(
                ['phone' => $c['phone']],
                $c,
            );
        }
    }

    private function seedInventory(int $actorId): void
    {
        $pads = InventoryItem::query()->updateOrCreate(
            ['code' => 'BRK-PAD'],
            ['name' => 'فحمات فرامل أمامية', 'category' => 'فرامل', 'unit' => 'طقم', 'reorder_level' => 4, 'is_active' => true],
        );
        InventoryBatch::query()->firstOrCreate(
            ['inventory_item_id' => $pads->id, 'received_on' => now()->subDays(12)->toDateString(), 'unit_cost' => 650],
            ['quantity' => 8, 'remaining' => 6, 'supplier' => 'قطع غيار النيل', 'recorded_by' => $actorId],
        );

        $wiper = InventoryItem::query()->updateOrCreate(
            ['code' => 'WIP-22'],
            ['name' => 'مساحات زجاج 22"', 'category' => 'هياكل', 'unit' => 'زوج', 'reorder_level' => 6, 'is_active' => true],
        );
        InventoryBatch::query()->firstOrCreate(
            ['inventory_item_id' => $wiper->id, 'received_on' => now()->subDays(3)->toDateString(), 'unit_cost' => 90],
            ['quantity' => 3, 'remaining' => 3, 'supplier' => 'مستلزمات السيارات', 'recorded_by' => $actorId],
        );

        // Low-stock signal (remaining under reorder)
        $coolant = InventoryItem::query()->updateOrCreate(
            ['code' => 'CLT-GRN'],
            ['name' => 'مياه ردّياتير', 'category' => 'سوائل', 'unit' => 'لتر', 'reorder_level' => 20, 'is_active' => true],
        );
        InventoryBatch::query()->firstOrCreate(
            ['inventory_item_id' => $coolant->id, 'received_on' => now()->subDays(40)->toDateString(), 'unit_cost' => 45],
            ['quantity' => 15, 'remaining' => 4, 'supplier' => 'مورد السوائل', 'recorded_by' => $actorId],
        );

        InventoryItem::query()->updateOrCreate(
            ['code' => 'AIR-FLT'],
            ['name' => 'فلتر هواء', 'category' => 'فلاتر', 'unit' => 'قطعة', 'reorder_level' => 8, 'is_active' => true],
        );
    }

    /**
     * @return array{tech: Employee, driver: Employee, cashier: Employee}
     */
    private function seedHrPettyAndCustody(int $actorId, int $hrId): array
    {
        $tech = Employee::query()->updateOrCreate(
            ['name' => 'محمود فني'],
            ['job_title' => 'فني صيانة', 'hired_on' => '2024-01-01', 'base_salary' => 6000, 'status' => 'active'],
        );
        $driver = Employee::query()->updateOrCreate(
            ['name' => 'عمرو سائق'],
            ['job_title' => 'سائق توصيل', 'hired_on' => '2023-06-15', 'base_salary' => 4500, 'status' => 'active'],
        );
        $cashier = Employee::query()->updateOrCreate(
            ['name' => 'نورا كاشير'],
            ['job_title' => 'محاسب صندوق', 'hired_on' => '2024-03-01', 'base_salary' => 5500, 'status' => 'active'],
        );
        Employee::query()->updateOrCreate(
            ['name' => 'سامي سابق'],
            ['job_title' => 'فني صيانة', 'hired_on' => '2022-01-01', 'base_salary' => 5000, 'status' => 'ended'],
        );

        $techPetty = PettyCashAccount::query()->updateOrCreate(
            ['employee_id' => $tech->id],
            ['limit_amount' => 15000, 'balance' => 10500, 'is_active' => true],
        );
        if (! PettyCashMovement::query()->where('petty_cash_account_id', $techPetty->id)->where('notes', 'تعزيز عهدة — بذرة E2E')->exists()) {
            PettyCashMovement::query()->create([
                'petty_cash_account_id' => $techPetty->id,
                'type' => 'topup',
                'amount' => 1500,
                'moved_on' => now()->subDays(2)->toDateString(),
                'performed_by' => $actorId,
                'notes' => 'تعزيز عهدة — بذرة E2E',
            ]);
        }

        $driverPetty = PettyCashAccount::query()->updateOrCreate(
            ['employee_id' => $driver->id],
            ['limit_amount' => 5000, 'balance' => 3200, 'is_active' => true],
        );
        if (! PettyCashMovement::query()->where('petty_cash_account_id', $driverPetty->id)->exists()) {
            PettyCashMovement::query()->create([
                'petty_cash_account_id' => $driverPetty->id,
                'type' => 'topup',
                'amount' => 5000,
                'moved_on' => now()->subDays(15)->toDateString(),
                'performed_by' => $hrId,
                'notes' => 'افتتاح عهدة سائق',
            ]);
            PettyCashMovement::query()->create([
                'petty_cash_account_id' => $driverPetty->id,
                'type' => 'spend',
                'amount' => 1800,
                'moved_on' => now()->subDays(4)->toDateString(),
                'performed_by' => $hrId,
                'notes' => 'وقود ورسوم طريق',
            ]);
        }

        $pads = InventoryItem::query()->where('code', 'BRK-PAD')->first();
        if ($pads && ! EmployeeAssignment::query()->where('employee_id', $tech->id)->where('title', 'عهدة فحمات فرامل')->exists()) {
            EmployeeAssignment::query()->create([
                'employee_id' => $tech->id,
                'assignable_type' => InventoryItem::class,
                'assignable_id' => $pads->id,
                'title' => 'عهدة فحمات فرامل',
                'notes' => 'طقم احتياطي مع الفني',
                'status' => 'acknowledged',
                'assigned_by' => $hrId,
                'assigned_at' => now()->subDays(6),
                'acknowledged_by' => $hrId,
                'acknowledged_at' => now()->subDays(5),
            ]);
        }
        if (! EmployeeAssignment::query()->where('employee_id', $driver->id)->where('title', 'جهاز تتبّع محمول')->exists()) {
            EmployeeAssignment::query()->create([
                'employee_id' => $driver->id,
                'assignable_type' => 'manual',
                'assignable_id' => null,
                'title' => 'جهاز تتبّع محمول',
                'notes' => 'بانتظار إقرار الاستلام',
                'status' => 'pending_ack',
                'assigned_by' => $hrId,
                'assigned_at' => now()->subDay(),
            ]);
        }

        return ['tech' => $tech, 'driver' => $driver, 'cashier' => $cashier];
    }

    private function seedContractsPaymentsAndFleetPrep(int $actorId, int $salesId, int $opsId): void
    {
        $ahmed = Customer::query()->where('phone', '01011112222')->firstOrFail();
        $mona = Customer::query()->where('phone', '01022223333')->firstOrFail();
        $sara = Customer::query()->where('phone', '01044445555')->firstOrFail();
        $karim = Customer::query()->where('phone', '01055557777')->firstOrFail();
        $heba = Customer::query()->where('phone', '01066668888')->firstOrFail();

        $elantra = Vehicle::query()->where('code', 'C-101')->firstOrFail();
        $corolla = Vehicle::query()->where('code', 'C-214')->firstOrFail();
        $accent = Vehicle::query()->where('code', 'C-301')->firstOrFail();
        $yaris = Vehicle::query()->where('code', 'C-402')->firstOrFail();
        $carnival = Vehicle::query()->where('code', 'V-510')->firstOrFail();
        $rx5 = Vehicle::query()->where('code', 'S-220')->firstOrFail();
        $byd = Vehicle::query()->where('code', 'E-120')->firstOrFail();

        // Enrich active rental CNT-00001 with deposit + partial payment + handover inspection
        $active = Contract::query()->where('number', 'CNT-00001')->first();
        if ($active) {
            $active->fill([
                'period_rent' => 1900,
                'discount' => 0,
                'final_total' => 1900,
                'odometer_out' => 61000,
                'accessories_json' => [
                    ['name' => 'مثلث تحذير', 'qty' => 1],
                    ['name' => 'طفاية حريق', 'qty' => 1],
                ],
            ])->save();

            Deposit::query()->firstOrCreate(
                ['contract_id' => $active->id],
                [
                    'original_amount' => 3000,
                    'status' => 'received',
                    'received_at' => now()->subDays(2)->setTime(10, 15),
                    'receipt_number' => 'DEP-00001',
                    'collection_method' => 'cash',
                    'deductions_total' => 0,
                ],
            );
            Payment::query()->firstOrCreate(
                ['reference' => 'PAY-CNT-00001-P1'],
                [
                    'amount' => 1000,
                    'paid_at' => now()->subDays(2)->setTime(10, 20),
                    'method' => 'cash',
                    'direction' => 'in',
                    'link_type' => 'contract',
                    'link_id' => $active->id,
                    'user_id' => $salesId,
                    'notes' => 'دفعة جزئية عند التسليم',
                ],
            );
            Inspection::query()->firstOrCreate(
                ['contract_id' => $active->id, 'type' => 'handover'],
                [
                    'inspected_at' => now()->subDays(2)->setTime(10, 5),
                    'odometer' => 61000,
                    'notes' => 'تسليم نظيف — بدون أضرار ظاهرة',
                    'inspector_id' => $opsId,
                    'accessories_json' => [
                        ['name' => 'مثلث تحذير', 'ok' => true],
                        ['name' => 'طفاية حريق', 'ok' => true],
                    ],
                ],
            );
        }

        // Today's handover (تسليم العربية) — approved, starts today
        $todayHandover = Contract::query()->updateOrCreate(
            ['number' => 'CNT-TODAY-01'],
            [
                'customer_id' => $sara->id,
                'vehicle_id' => $yaris->id,
                'status' => 'approved',
                'starts_at' => now()->setTime(11, 0),
                'planned_ends_at' => now()->addDays(3)->setTime(11, 0),
                'frozen_daily_rate' => 850,
                'frozen_daily_km_allowance' => 200,
                'frozen_excess_km_rate' => 5,
                'frozen_agreed_deposit' => 3000,
                'sales_user_id' => $salesId,
                'notes' => 'تسليم اليوم — جاهز لأسطول التحضير',
            ],
        );
        Deposit::query()->firstOrCreate(
            ['contract_id' => $todayHandover->id],
            [
                'original_amount' => 3000,
                'status' => 'received',
                'received_at' => now()->subHours(2),
                'receipt_number' => 'DEP-TODAY-01',
                'collection_method' => 'card',
                'deductions_total' => 0,
            ],
        );

        // Future booking / upcoming
        Contract::query()->updateOrCreate(
            ['number' => 'CNT-FUTURE-01'],
            [
                'customer_id' => $karim->id,
                'vehicle_id' => $carnival->id,
                'status' => 'approved',
                'starts_at' => now()->addDays(4)->setTime(9, 0),
                'planned_ends_at' => now()->addDays(9)->setTime(9, 0),
                'frozen_daily_rate' => 1800,
                'frozen_daily_km_allowance' => 250,
                'frozen_excess_km_rate' => 6,
                'frozen_agreed_deposit' => 6000,
                'sales_user_id' => $salesId,
                'notes' => 'حجز قادم — عائلي',
            ],
        );

        Contract::query()->updateOrCreate(
            ['number' => 'CNT-FUTURE-02'],
            [
                'customer_id' => $heba->id,
                'vehicle_id' => $byd->id,
                'status' => 'pending',
                'starts_at' => now()->addDays(6)->setTime(14, 0),
                'planned_ends_at' => now()->addDays(10)->setTime(14, 0),
                'frozen_daily_rate' => 1600,
                'frozen_daily_km_allowance' => 200,
                'frozen_excess_km_rate' => 5,
                'frozen_agreed_deposit' => 5500,
                'sales_user_id' => $salesId,
                'notes' => 'حجز معلّق بانتظار الاعتماد',
            ],
        );

        // Second active rental (rented status via handover)
        $active2 = Contract::query()->updateOrCreate(
            ['number' => 'CNT-ACTIVE-02'],
            [
                'customer_id' => $mona->id,
                'vehicle_id' => $rx5->id,
                'status' => 'active',
                'starts_at' => now()->subDays(5)->setTime(12, 0),
                'planned_ends_at' => now()->addDays(2)->setTime(12, 0),
                'frozen_daily_rate' => 1500,
                'frozen_daily_km_allowance' => 200,
                'frozen_excess_km_rate' => 5,
                'frozen_agreed_deposit' => 5000,
                'odometer_out' => 12000,
                'period_rent' => 10500,
                'final_total' => 10500,
                'sales_user_id' => $salesId,
            ],
        );
        Deposit::query()->firstOrCreate(
            ['contract_id' => $active2->id],
            [
                'original_amount' => 5000,
                'status' => 'received',
                'received_at' => now()->subDays(5)->setTime(12, 10),
                'receipt_number' => 'DEP-ACTIVE-02',
                'collection_method' => 'cash',
                'deductions_total' => 0,
            ],
        );
        Payment::query()->firstOrCreate(
            ['reference' => 'PAY-CNT-ACTIVE-02-FULL'],
            [
                'amount' => 10500,
                'paid_at' => now()->subDays(5)->setTime(12, 15),
                'method' => 'transfer',
                'direction' => 'in',
                'link_type' => 'contract',
                'link_id' => $active2->id,
                'user_id' => $opsId,
                'notes' => 'سداد كامل مقدم',
            ],
        );

        // Completed contract — full settlement
        $done = Contract::query()->updateOrCreate(
            ['number' => 'CNT-DONE-01'],
            [
                'customer_id' => $ahmed->id,
                'vehicle_id' => $accent->id,
                'status' => 'completed',
                'starts_at' => now()->subDays(20)->setTime(10, 0),
                'planned_ends_at' => now()->subDays(15)->setTime(10, 0),
                'actual_ends_at' => now()->subDays(15)->setTime(11, 30),
                'frozen_daily_rate' => 700,
                'frozen_daily_km_allowance' => 200,
                'frozen_excess_km_rate' => 5,
                'frozen_agreed_deposit' => 2500,
                'odometer_out' => 100000,
                'odometer_in' => 101150,
                'allowed_km' => 1000,
                'excess_km' => 150,
                'excess_km_amount' => 750,
                'period_rent' => 3500,
                'discount' => 100,
                'damage_fee' => 0,
                'other_fees' => 0,
                'final_total' => 4150,
                'sales_user_id' => $salesId,
                'notes' => 'عقد مكتمل — سداد كامل',
            ],
        );
        Deposit::query()->firstOrCreate(
            ['contract_id' => $done->id],
            [
                'original_amount' => 2500,
                'status' => 'returned',
                'received_at' => now()->subDays(20)->setTime(10, 10),
                'receipt_number' => 'DEP-DONE-01',
                'collection_method' => 'cash',
                'returned_at' => now()->subDays(15)->setTime(11, 40),
                'return_receipt_number' => 'DEP-DONE-01-R',
                'return_method' => 'cash',
                'deductions_total' => 0,
            ],
        );
        Payment::query()->firstOrCreate(
            ['reference' => 'PAY-CNT-DONE-01'],
            [
                'amount' => 4150,
                'paid_at' => now()->subDays(15)->setTime(11, 35),
                'method' => 'cash',
                'direction' => 'in',
                'link_type' => 'contract',
                'link_id' => $done->id,
                'user_id' => $opsId,
                'notes' => 'تسوية نهائية شاملة كيلومتر زائد',
            ],
        );
        Inspection::query()->firstOrCreate(
            ['contract_id' => $done->id, 'type' => 'return'],
            [
                'inspected_at' => now()->subDays(15)->setTime(11, 25),
                'odometer' => 101150,
                'notes' => 'استلام سليم',
                'inspector_id' => $opsId,
            ],
        );

        // Completed with remaining balance (partial pay scenario)
        $owed = Contract::query()->updateOrCreate(
            ['number' => 'CNT-OWED-01'],
            [
                'customer_id' => $karim->id,
                'vehicle_id' => $elantra->id,
                'status' => 'completed',
                'starts_at' => now()->subDays(12)->setTime(9, 0),
                'planned_ends_at' => now()->subDays(8)->setTime(9, 0),
                'actual_ends_at' => now()->subDays(8)->setTime(10, 0),
                'frozen_daily_rate' => 900,
                'frozen_daily_km_allowance' => 200,
                'frozen_excess_km_rate' => 5,
                'frozen_agreed_deposit' => 3000,
                'odometer_out' => 40500,
                'odometer_in' => 41200,
                'period_rent' => 3600,
                'damage_fee' => 400,
                'final_total' => 4000,
                'sales_user_id' => $salesId,
                'notes' => 'مكتمل مع متبقي جزئي',
            ],
        );
        // Only half paid — remaining due attention
        Payment::query()->firstOrCreate(
            ['reference' => 'PAY-CNT-OWED-01-P1'],
            [
                'amount' => 2000,
                'paid_at' => now()->subDays(8)->setTime(10, 15),
                'method' => 'cash',
                'direction' => 'in',
                'link_type' => 'contract',
                'link_id' => $owed->id,
                'user_id' => $salesId,
                'notes' => 'دفعة جزئية عند الإقفال',
            ],
        );

        // Cancelled booking
        Contract::query()->updateOrCreate(
            ['number' => 'CNT-CANCEL-01'],
            [
                'customer_id' => $heba->id,
                'vehicle_id' => $corolla->id,
                'status' => 'cancelled',
                'starts_at' => now()->subDays(3)->setTime(8, 0),
                'planned_ends_at' => now()->addDay()->setTime(8, 0),
                'frozen_daily_rate' => 950,
                'frozen_daily_km_allowance' => 200,
                'frozen_excess_km_rate' => 5,
                'frozen_agreed_deposit' => 3000,
                'sales_user_id' => $salesId,
                'cancel_reason' => 'إلغاء من العميل قبل التسليم',
                'notes' => 'ملغى للمعاينة',
            ],
        );
    }

    private function seedMaintenance(int $actorId, int $maintId, Employee $tech, Employee $driver): void
    {
        unset($driver);
        $sunny = Vehicle::query()->where('code', 'C-088')->firstOrFail();
        $accent = Vehicle::query()->where('code', 'C-301')->firstOrFail();
        $sportage = Vehicle::query()->where('code', 'S-012')->firstOrFail();
        $oil = InventoryItem::query()->where('code', 'OIL-5W30')->first();
        $pads = InventoryItem::query()->where('code', 'BRK-PAD')->first();

        // Enrich open WO on sunny with mixed lines
        $openWo = WorkOrder::query()->where('vehicle_id', $sunny->id)->where('status', 'in_progress')->first();
        if ($openWo) {
            $openWo->fill([
                'workshop' => 'ورشة النور',
                'opened_on' => now()->subDay()->toDateString(),
                'opened_at' => now()->subDay(),
                'odometer' => 89000,
                'puts_in_maintenance' => true,
                'parts_cost' => 1200,
                'labor_cost' => 400,
                'notes' => 'صيانة دورية — قيد التنفيذ',
                'next_service_on' => now()->addMonths(3)->toDateString(),
                'next_service_odometer' => 95000,
            ])->save();

            if ($oil && ! WorkOrderLine::query()->where('work_order_id', $openWo->id)->where('description', 'زيت من المخزون')->exists()) {
                WorkOrderLine::query()->create([
                    'work_order_id' => $openWo->id,
                    'description' => 'زيت من المخزون',
                    'part_source' => 'stock',
                    'inventory_item_id' => $oil->id,
                    'quantity' => 4,
                    'unit_price' => 180,
                    'from_petty_cash' => false,
                ]);
            }
            if (! WorkOrderLine::query()->where('work_order_id', $openWo->id)->where('description', 'قطعة مشتراة من العهدة')->exists()) {
                WorkOrderLine::query()->create([
                    'work_order_id' => $openWo->id,
                    'description' => 'قطعة مشتراة من العهدة',
                    'part_source' => 'purchase',
                    'quantity' => 1,
                    'unit_price' => 480,
                    'from_petty_cash' => true,
                    'petty_cash_employee_id' => $tech->id,
                    'petty_cash_amount' => 480,
                ]);
            }
        }

        // Closed WO funded from stock — with next service triggers
        $closed = WorkOrder::query()->updateOrCreate(
            ['vehicle_id' => $accent->id, 'workshop' => 'ورشة المخزون — بذرة', 'status' => 'closed'],
            [
                'opened_on' => now()->subDays(18)->toDateString(),
                'opened_at' => now()->subDays(18),
                'closed_on' => now()->subDays(17)->toDateString(),
                'odometer' => 101200,
                'puts_in_maintenance' => false,
                'parts_cost' => 650,
                'labor_cost' => 250,
                'notes' => 'تغيير فحمات — ممول من المخزون',
                'next_service_on' => now()->addDays(40)->toDateString(),
                'next_service_odometer' => 106000,
            ],
        );
        if ($pads && ! WorkOrderLine::query()->where('work_order_id', $closed->id)->exists()) {
            WorkOrderLine::query()->create([
                'work_order_id' => $closed->id,
                'description' => 'فحمات فرامل من المخزون',
                'part_source' => 'stock',
                'inventory_item_id' => $pads->id,
                'quantity' => 1,
                'unit_price' => 650,
                'from_petty_cash' => false,
            ]);
        }

        // Closed WO from petty cash / custody
        $custodyWo = WorkOrder::query()->updateOrCreate(
            ['vehicle_id' => $sportage->id, 'workshop' => 'ورشة العهدة — بذرة', 'status' => 'closed'],
            [
                'opened_on' => now()->subDays(9)->toDateString(),
                'opened_at' => now()->subDays(9),
                'closed_on' => now()->subDays(8)->toDateString(),
                'odometer' => 18000,
                'puts_in_maintenance' => false,
                'parts_cost' => 350,
                'labor_cost' => 150,
                'notes' => 'إصلاح بسيط ممول من عهدة الفني',
                'next_service_on' => now()->addMonths(2)->toDateString(),
                'next_service_odometer' => 23000,
            ],
        );
        if (! WorkOrderLine::query()->where('work_order_id', $custodyWo->id)->exists()) {
            WorkOrderLine::query()->create([
                'work_order_id' => $custodyWo->id,
                'description' => 'لمبة وفلتر هواء — شراء نقدي',
                'part_source' => 'purchase',
                'quantity' => 1,
                'unit_price' => 350,
                'from_petty_cash' => true,
                'petty_cash_employee_id' => $tech->id,
                'petty_cash_amount' => 350,
            ]);
        }

        // Open WO without putting vehicle in maintenance (scheduled)
        WorkOrder::query()->updateOrCreate(
            ['vehicle_id' => Vehicle::query()->where('code', 'C-402')->value('id'), 'status' => 'open', 'workshop' => 'صيانة مجدولة'],
            [
                'opened_on' => now()->toDateString(),
                'opened_at' => now(),
                'odometer' => 27800,
                'puts_in_maintenance' => false,
                'parts_cost' => 0,
                'labor_cost' => 0,
                'notes' => 'فحص دوري مجدول — لا يحجز العربية',
                'next_service_on' => now()->addMonths(6)->toDateString(),
                'next_service_odometer' => 35000,
            ],
        );

        AuditLog::query()->firstOrCreate(
            [
                'user_id' => $maintId,
                'action' => 'work_order_opened',
                'record_type' => WorkOrder::class,
                'record_id' => (string) ($openWo?->id ?? $closed->id),
            ],
            [
                'before' => null,
                'after' => ['vehicle_plate' => $sunny->plate, 'workshop' => 'ورشة النور'],
            ],
        );
        unset($actorId);
    }

    private function seedViolationsAndExpenses(int $actorId, int $accountsId, Employee $tech): void
    {
        $active = Contract::query()->where('number', 'CNT-00001')->first();
        $corolla = Vehicle::query()->where('code', 'C-214')->first();
        $ahmed = Customer::query()->where('phone', '01011112222')->first();
        $rx5 = Vehicle::query()->where('code', 'S-220')->first();

        if ($corolla && $active && $ahmed) {
            Violation::query()->firstOrCreate(
                ['vehicle_id' => $corolla->id, 'location' => 'محور 26 يوليو', 'amount' => 500],
                [
                    'occurred_at' => now()->subDays(1)->setTime(16, 40),
                    'contract_id' => $active->id,
                    'customer_id' => $ahmed->id,
                    'status' => 'new',
                    'notes' => 'تجاوز سرعة — بانتظار الإسناد',
                ],
            );
            Violation::query()->firstOrCreate(
                ['vehicle_id' => $corolla->id, 'location' => 'كوبري أكتوبر', 'amount' => 200],
                [
                    'occurred_at' => now()->subDays(10)->setTime(9, 15),
                    'contract_id' => $active->id,
                    'customer_id' => $ahmed->id,
                    'status' => 'confirmed',
                    'confirmed_by' => $accountsId,
                    'confirmed_at' => now()->subDays(9),
                    'collection_note' => 'تُحصّل من العميل عند الاستلام',
                    'notes' => 'مخالفة مؤكدة',
                ],
            );
        }
        if ($rx5) {
            Violation::query()->firstOrCreate(
                ['vehicle_id' => $rx5->id, 'location' => 'مول مصر — موقف', 'amount' => 100],
                [
                    'occurred_at' => now()->subDays(3)->setTime(20, 0),
                    'status' => 'paid',
                    'collection_note' => 'سدّدها العميل نقداً',
                    'notes' => 'وقوف خاطئ — مدفوعة',
                ],
            );
        }

        Expense::query()->firstOrCreate(
            ['title' => 'غسيل أسطول أسبوعي', 'spent_on' => now()->subDays(2)->toDateString()],
            [
                'category' => 'تشغيل',
                'amount' => 850,
                'payment_method' => 'cash',
                'vehicle_id' => null,
                'from_petty_cash' => false,
                'recorded_by' => $accountsId,
                'notes' => 'مصروف موقع — غسيل',
            ],
        );
        Expense::query()->firstOrCreate(
            ['title' => 'وقود توصيل', 'spent_on' => now()->subDays(1)->toDateString()],
            [
                'category' => 'وقود',
                'amount' => 420,
                'payment_method' => 'petty_cash',
                'vehicle_id' => $corolla?->id,
                'employee_id' => $tech->id,
                'from_petty_cash' => true,
                'recorded_by' => $actorId,
                'notes' => 'من عهدة الفني',
            ],
        );
        Expense::query()->firstOrCreate(
            ['title' => 'رسوم بوابة طريق', 'spent_on' => now()->subDays(5)->toDateString()],
            [
                'category' => 'طريق',
                'amount' => 75,
                'payment_method' => 'cash',
                'vehicle_id' => $rx5?->id,
                'from_petty_cash' => false,
                'recorded_by' => $accountsId,
                'notes' => 'مصروف مرتبط بعربية',
            ],
        );
    }

    private function seedWaitlist(int $actorId): void
    {
        $sara = Customer::query()->where('phone', '01044445555')->first();
        $karim = Customer::query()->where('phone', '01055557777')->first();
        $sportage = Vehicle::query()->where('code', 'S-012')->first();

        if ($sara) {
            WaitlistRequest::query()->firstOrCreate(
                ['customer_id' => $sara->id, 'category' => 'SUV', 'status' => 'waiting'],
                [
                    'preferred_vehicle_id' => $sportage?->id,
                    'desired_start' => now()->addDays(7)->toDateString(),
                    'desired_end' => now()->addDays(12)->toDateString(),
                    'priority' => 2,
                    'expires_at' => now()->addDays(14),
                    'created_by' => $actorId,
                    'notes' => 'تفضّل سبورتاج أو مشابه',
                ],
            );
        }
        if ($karim) {
            WaitlistRequest::query()->firstOrCreate(
                ['customer_id' => $karim->id, 'category' => 'اقتصادي', 'status' => 'notified'],
                [
                    'desired_start' => now()->addDays(2)->toDateString(),
                    'desired_end' => now()->addDays(5)->toDateString(),
                    'priority' => 1,
                    'notified_at' => now()->subHours(5),
                    'expires_at' => now()->addDays(3),
                    'created_by' => $actorId,
                    'notes' => 'تم إخطار العميل بتوفر عربية',
                ],
            );
        }
    }

    private function seedOwnersFinance(int $actorId, int $accountsId): void
    {
        $owner = VehicleOwner::query()->where('phone', '01055556666')->first();
        if (! $owner) {
            return;
        }
        $v1 = Vehicle::query()->where('code', 'VEH-9001')->first();
        $v2 = Vehicle::query()->where('code', 'VEH-9002')->first();
        $ym = now()->format('Y-m');
        $prev = now()->subMonth()->format('Y-m');

        if ($v1) {
            OwnerMonthDue::query()->firstOrCreate(
                ['owner_id' => $owner->id, 'vehicle_id' => $v1->id, 'year_month' => $ym],
                ['amount' => (int) $v1->owner_monthly_amount, 'created_by' => $accountsId],
            );
            OwnerMonthDue::query()->firstOrCreate(
                ['owner_id' => $owner->id, 'vehicle_id' => $v1->id, 'year_month' => $prev],
                ['amount' => (int) $v1->owner_monthly_amount, 'created_by' => $accountsId],
            );
        }
        if ($v2) {
            OwnerMonthDue::query()->firstOrCreate(
                ['owner_id' => $owner->id, 'vehicle_id' => $v2->id, 'year_month' => $ym],
                ['amount' => (int) $v2->owner_monthly_amount, 'created_by' => $accountsId],
            );
        }

        if ($v1 && ! OwnerPayout::query()->where('owner_id', $owner->id)->where('notes', 'صرف مستحقات الشهر السابق — بذرة')->exists()) {
            OwnerPayout::query()->create([
                'owner_id' => $owner->id,
                'vehicle_id' => $v1->id,
                'amount' => (int) $v1->owner_monthly_amount,
                'paid_on' => now()->subDays(3)->toDateString(),
                'payment_method' => 'transfer',
                'notes' => 'صرف مستحقات الشهر السابق — بذرة',
                'recorded_by' => $accountsId,
            ]);
        }
        unset($actorId);
    }

    /**
     * @param  array{tech: Employee, driver: Employee, cashier: Employee}  $employees
     */
    private function seedPayrollAndAdvances(int $actorId, int $hrId, array $employees): void
    {
        $year = (int) now()->year;
        $month = (int) now()->month;

        foreach ([
            [$employees['tech'], 1500, 'سلفة صيانة طارئة'],
            [$employees['driver'], 800, 'سلفة وقود'],
            [$employees['cashier'], 500, 'سلفة شخصية'],
        ] as [$emp, $amount, $notes]) {
            SalaryAdvance::query()->firstOrCreate(
                [
                    'employee_id' => $emp->id,
                    'year' => $year,
                    'month' => $month,
                    'amount' => $amount,
                    'notes' => $notes,
                ],
                [
                    'advanced_on' => now()->subDays(6)->toDateString(),
                    'method' => 'cash',
                    'recorded_by' => $hrId,
                    'created_by' => $hrId,
                ],
            );
            // Prior month advance for cumulative feel
            SalaryAdvance::query()->firstOrCreate(
                [
                    'employee_id' => $emp->id,
                    'year' => (int) now()->subMonth()->year,
                    'month' => (int) now()->subMonth()->month,
                    'amount' => (int) round($amount * 0.5),
                    'notes' => $notes.' (شهر سابق)',
                ],
                [
                    'advanced_on' => now()->subMonth()->toDateString(),
                    'method' => 'cash',
                    'recorded_by' => $hrId,
                    'created_by' => $hrId,
                ],
            );
        }

        $run = PayrollRun::query()->updateOrCreate(
            ['year' => $year, 'month' => $month],
            [
                'status' => 'approved',
                'total_amount' => 0,
                'created_by' => $hrId,
                'approved_by' => $actorId,
                'approved_at' => now()->subDay(),
                'notes' => 'مسير رواتب معاينة — الشهر الحالي',
            ],
        );

        $total = 0;
        foreach ([$employees['tech'], $employees['driver'], $employees['cashier']] as $emp) {
            $advance = (int) SalaryAdvance::query()
                ->where('employee_id', $emp->id)
                ->where('year', $year)
                ->where('month', $month)
                ->sum('amount');
            $base = (int) $emp->base_salary;
            $net = max(0, $base - $advance);
            $line = PayrollLine::query()->updateOrCreate(
                ['payroll_run_id' => $run->id, 'employee_id' => $emp->id],
                [
                    'base_salary' => $base,
                    'allowances' => 0,
                    'deductions' => 0,
                    'carried_in' => 0,
                    'advances' => $advance,
                    'net_amount' => $net,
                    'paid_amount' => (int) round($net * 0.6),
                    'carried_over' => (int) round($net * 0.4),
                    'notes' => 'صرف جزئي مع ترحيل',
                ],
            );
            $total += $net;
            if (! PayrollLinePayout::query()->where('payroll_line_id', $line->id)->exists()) {
                PayrollLinePayout::query()->create([
                    'payroll_line_id' => $line->id,
                    'amount' => (int) $line->paid_amount,
                    'paid_on' => now()->subDay()->toDateString(),
                    'method' => 'transfer',
                    'notes' => 'صرف جزئي — بذرة',
                    'created_by' => $hrId,
                ]);
            }
            SalaryAdvance::query()
                ->where('employee_id', $emp->id)
                ->where('year', $year)
                ->where('month', $month)
                ->whereNull('payroll_line_id')
                ->update(['payroll_line_id' => $line->id]);
        }
        $run->update(['total_amount' => $total]);
    }

    private function seedAuditTrail(int $actorId, int $salesId, int $opsId, int $maintId, int $accountsId, int $hrId): void
    {
        $entries = [
            [
                'user_id' => $salesId,
                'action' => 'created',
                'record_type' => Contract::class,
                'record_id' => (string) (Contract::query()->where('number', 'CNT-TODAY-01')->value('id') ?? 0),
                'after' => ['number' => 'CNT-TODAY-01'],
            ],
            [
                'user_id' => $opsId,
                'action' => 'approved',
                'record_type' => Contract::class,
                'record_id' => (string) (Contract::query()->where('number', 'CNT-TODAY-01')->value('id') ?? 0),
                'after' => ['number' => 'CNT-TODAY-01'],
            ],
            [
                'user_id' => $opsId,
                'action' => 'handover',
                'record_type' => Contract::class,
                'record_id' => (string) (Contract::query()->where('number', 'CNT-00001')->value('id') ?? 0),
                'after' => ['number' => 'CNT-00001'],
            ],
            [
                'user_id' => $opsId,
                'action' => 'payment_recorded',
                'record_type' => Contract::class,
                'record_id' => (string) (Contract::query()->where('number', 'CNT-00001')->value('id') ?? 0),
                'after' => ['number' => 'CNT-00001', 'amount' => 1000],
            ],
            [
                'user_id' => $opsId,
                'action' => 'completed',
                'record_type' => Contract::class,
                'record_id' => (string) (Contract::query()->where('number', 'CNT-DONE-01')->value('id') ?? 0),
                'after' => ['number' => 'CNT-DONE-01'],
            ],
            [
                'user_id' => $opsId,
                'action' => 'deposit_received',
                'record_type' => Deposit::class,
                'record_id' => (string) (Deposit::query()->latest('id')->value('id') ?? 0),
                'after' => ['amount' => 3000, 'number' => 'CNT-TODAY-01'],
            ],
            [
                'user_id' => $maintId,
                'action' => 'work_order_closed',
                'record_type' => WorkOrder::class,
                'record_id' => (string) (WorkOrder::query()->where('status', 'closed')->value('id') ?? 0),
                'after' => ['vehicle_plate' => Vehicle::query()->where('code', 'C-301')->value('plate')],
            ],
            [
                'user_id' => $hrId,
                'action' => 'salary_advance_recorded',
                'record_type' => SalaryAdvance::class,
                'record_id' => (string) (SalaryAdvance::query()->latest('id')->value('id') ?? 0),
                'after' => ['employee_name' => 'محمود فني', 'amount' => 1500],
            ],
            [
                'user_id' => $hrId,
                'action' => 'payroll_payout',
                'record_type' => PayrollLine::class,
                'record_id' => (string) (PayrollLine::query()->latest('id')->value('id') ?? 0),
                'after' => ['amount' => 3600, 'employee_name' => 'محمود فني'],
            ],
            [
                'user_id' => $accountsId,
                'action' => 'petty_cash_topup',
                'record_type' => PettyCashAccount::class,
                'record_id' => (string) (PettyCashAccount::query()->latest('id')->value('id') ?? 0),
                'after' => ['employee_name' => 'عمرو سائق', 'amount' => 5000],
            ],
            [
                'user_id' => $actorId,
                'action' => 'vehicle_updated',
                'record_type' => Vehicle::class,
                'record_id' => (string) (Vehicle::query()->where('code', 'C-402')->value('id') ?? 0),
                'after' => ['plate' => Vehicle::query()->where('code', 'C-402')->value('plate')],
            ],
            [
                'user_id' => $salesId,
                'action' => 'customer_updated',
                'record_type' => Customer::class,
                'record_id' => (string) (Customer::query()->where('phone', '01044445555')->value('id') ?? 0),
                'after' => ['full_name' => 'سارة إبراهيم'],
            ],
            [
                'user_id' => $opsId,
                'action' => 'cancelled',
                'record_type' => Contract::class,
                'record_id' => (string) (Contract::query()->where('number', 'CNT-CANCEL-01')->value('id') ?? 0),
                'after' => ['number' => 'CNT-CANCEL-01'],
            ],
        ];

        foreach ($entries as $row) {
            if (($row['record_id'] ?? '0') === '0') {
                continue;
            }
            AuditLog::query()->firstOrCreate(
                [
                    'user_id' => $row['user_id'],
                    'action' => $row['action'],
                    'record_type' => $row['record_type'],
                    'record_id' => $row['record_id'],
                ],
                [
                    'before' => null,
                    'after' => $row['after'],
                ],
            );
        }
    }

    private function seedNotifications(?User $admin, ?User $ops, ?User $maint, ?User $accounts): void
    {
        $dispatcher = app(NotificationDispatchService::class);
        $targets = array_filter([$admin, $ops, $maint, $accounts]);

        foreach ($targets as $user) {
            if (! $user) {
                continue;
            }
            if (! \App\Models\Notification::query()->where('user_id', $user->id)->where('title', 'تسليم اليوم — CNT-TODAY-01')->exists()) {
                $dispatcher->notify(
                    $user,
                    'تسليم اليوم — CNT-TODAY-01',
                    'عقد سارة إبراهيم جاهز للتسليم اليوم على ياريس C-402.',
                    'contract',
                    (int) (Contract::query()->where('number', 'CNT-TODAY-01')->value('id') ?? 0) ?: null,
                );
            }
            if (! \App\Models\Notification::query()->where('user_id', $user->id)->where('title', 'مخزون منخفض — مياه ردّياتير')->exists()) {
                $dispatcher->notify(
                    $user,
                    'مخزون منخفض — مياه ردّياتير',
                    'المتبقي تحت حد إعادة الطلب — راجع المخزون.',
                    'inventory',
                    (int) (InventoryItem::query()->where('code', 'CLT-GRN')->value('id') ?? 0) ?: null,
                );
            }
        }
    }
}
