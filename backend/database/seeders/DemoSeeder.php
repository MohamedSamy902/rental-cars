<?php

namespace Database\Seeders;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\InventoryBatch;
use App\Models\InventoryItem;
use App\Models\Notification;
use App\Models\Role;
use App\Models\TenantSetting;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleAccessory;
use App\Models\WorkOrder;
use App\Services\Accounting\AccountingService;
use App\Support\PermissionCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        PermissionCatalog::ensureSeeded();
        $adminRole = Role::query()->where('name', 'super_admin')->firstOrFail();

        $user = User::query()->updateOrCreate(
            ['email' => 'admin@rpm.local'],
            [
                'name' => 'مدير النظام',
                'password' => Hash::make('password'),
                'phone' => '01000000000',
                'role_id' => $adminRole->id,
                'is_active' => true,
            ],
        );

        $tenant = TenantSetting::query()->firstOrCreate([]);
        $tenant->fill([
            'center_name' => 'مركز آر بي إم للتأجير',
            'multi_branch' => false,
            'inventory_mode' => 'unified',
            'colors' => [
                'nav' => '#0B1220',
                'brand' => '#0D9488',
                'bg' => '#F1F5F9',
            ],
            'feature_flags' => [
                'inventory' => true,
                'petty_cash' => true,
                'waitlist' => true,
                'showroom' => true,
                'department_notice' => true,
                'chat' => true,
                'evaluations' => true,
                'hr' => true,
            ],
            'print_footer' => 'مركز آر بي إم للتأجير — نسخة داخلية',
            'operational' => [
                'default_daily_km' => 200,
                'excess_km_rate' => 5,
                'currency' => 'EGP',
                'block_expired_license' => true,
                'block_expired_vehicle_docs' => true,
                'require_handover_signature' => true,
                'require_return_signature_always' => false,
            ],
        ])->save();

        app(AccountingService::class)->ensureChartOfAccounts();

        $vehicles = [
            ['code' => 'C-101', 'plate' => 'أ ب ج 1234', 'brand' => 'هيونداي', 'model' => 'إلنترا', 'year' => 2023, 'category' => 'اقتصادي', 'fuel_type' => 'بنزين', 'odometer' => 41200, 'daily_rate' => 900, 'default_deposit' => 3000, 'rental_priority' => 80],
            ['code' => 'C-214', 'plate' => 'س ص ع 7788', 'brand' => 'تويوتا', 'model' => 'كورولا', 'year' => 2022, 'category' => 'اقتصادي', 'fuel_type' => 'بنزين', 'odometer' => 61500, 'daily_rate' => 950, 'default_deposit' => 3000, 'rental_priority' => 70],
            ['code' => 'S-012', 'plate' => 'ل م ن 4501', 'brand' => 'كيا', 'model' => 'سبورتاج', 'year' => 2024, 'category' => 'SUV', 'fuel_type' => 'بنزين', 'odometer' => 18200, 'daily_rate' => 1400, 'default_deposit' => 5000, 'rental_priority' => 90],
            ['code' => 'C-088', 'plate' => 'ق ر ش 3320', 'brand' => 'نيسان', 'model' => 'صني', 'year' => 2021, 'category' => 'اقتصادي', 'fuel_type' => 'بنزين', 'odometer' => 89000, 'daily_rate' => 750, 'default_deposit' => 2500, 'rental_priority' => 40, 'manual_status' => 'maintenance'],
        ];

        foreach ($vehicles as $row) {
            $v = Vehicle::query()->updateOrCreate(
                ['code' => $row['code']],
                [...$row, 'odometer_source' => 'manual', 'is_active' => true],
            );
            VehicleAccessory::query()->firstOrCreate(
                ['vehicle_id' => $v->id, 'name' => 'مثلث تحذير'],
                ['default_qty' => 1, 'required_each_rental' => true],
            );
            VehicleAccessory::query()->firstOrCreate(
                ['vehicle_id' => $v->id, 'name' => 'طفاية حريق'],
                ['default_qty' => 1, 'required_each_rental' => true],
            );
        }

        $customers = [
            ['full_name' => 'أحمد محمود', 'phone' => '01011112222', 'birth_date' => '1990-05-12', 'license_number' => 'L-1001', 'license_expires_on' => '2028-01-01', 'national_id' => '29005121234567'],
            ['full_name' => 'منى حسين', 'phone' => '01022223333', 'birth_date' => '1993-08-20', 'license_number' => 'L-1002', 'license_expires_on' => '2027-06-01', 'national_id' => '29308201234567'],
            ['full_name' => 'يوسف علي', 'phone' => '01033334444', 'birth_date' => '1988-01-03', 'license_number' => 'L-1003', 'license_expires_on' => '2029-03-15', 'national_id' => '28801031234567'],
        ];
        foreach ($customers as $c) {
            Customer::query()->updateOrCreate(
                ['phone' => $c['phone']],
                [...$c, 'status' => 'active'],
            );
        }

        $corolla = Vehicle::query()->where('code', 'C-214')->first();
        $sportage = Vehicle::query()->where('code', 'S-012')->first();
        $elantra = Vehicle::query()->where('code', 'C-101')->first();
        $ahmed = Customer::query()->where('phone', '01011112222')->first();
        $mona = Customer::query()->where('phone', '01022223333')->first();
        $youssef = Customer::query()->where('phone', '01033334444')->first();

        Contract::query()->updateOrCreate(
            ['number' => 'CNT-00001'],
            [
                'customer_id' => $ahmed->id,
                'vehicle_id' => $corolla->id,
                'status' => 'active',
                'starts_at' => now()->subDays(2)->setTime(10, 0),
                'planned_ends_at' => now()->setTime(10, 0),
                'frozen_daily_rate' => 950,
                'frozen_daily_km_allowance' => 200,
                'frozen_excess_km_rate' => 5,
                'frozen_agreed_deposit' => 3000,
                'odometer_out' => 61000,
                'sales_user_id' => $user->id,
            ],
        );

        Contract::query()->updateOrCreate(
            ['number' => 'CNT-00002'],
            [
                'customer_id' => $mona->id,
                'vehicle_id' => $sportage->id,
                'status' => 'approved',
                'starts_at' => now()->addDay()->setTime(9, 0),
                'planned_ends_at' => now()->addDays(5)->setTime(9, 0),
                'frozen_daily_rate' => 1400,
                'frozen_daily_km_allowance' => 200,
                'frozen_excess_km_rate' => 5,
                'frozen_agreed_deposit' => 5000,
                'sales_user_id' => $user->id,
            ],
        );

        Contract::query()->updateOrCreate(
            ['number' => 'CNT-00003'],
            [
                'customer_id' => $youssef->id,
                'vehicle_id' => $elantra->id,
                'status' => 'pending',
                'starts_at' => now()->addDays(2)->setTime(12, 0),
                'planned_ends_at' => now()->addDays(4)->setTime(12, 0),
                'frozen_daily_rate' => 900,
                'frozen_daily_km_allowance' => 200,
                'frozen_excess_km_rate' => 5,
                'frozen_agreed_deposit' => 3000,
                'sales_user_id' => $user->id,
            ],
        );

        $oil = InventoryItem::query()->updateOrCreate(
            ['code' => 'OIL-5W30'],
            ['name' => 'زيت محرك 5W30', 'category' => 'زيوت', 'unit' => 'لتر', 'reorder_level' => 10, 'is_active' => true],
        );
        InventoryBatch::query()->firstOrCreate(
            ['inventory_item_id' => $oil->id, 'received_on' => now()->subDays(20)->toDateString(), 'unit_cost' => 180],
            ['quantity' => 20, 'remaining' => 14, 'supplier' => 'مورد الزيوت', 'recorded_by' => $user->id],
        );
        InventoryBatch::query()->firstOrCreate(
            ['inventory_item_id' => $oil->id, 'received_on' => now()->subDays(5)->toDateString(), 'unit_cost' => 200],
            ['quantity' => 10, 'remaining' => 10, 'supplier' => 'مورد الزيوت', 'recorded_by' => $user->id],
        );

        InventoryItem::query()->updateOrCreate(
            ['code' => 'FLT-OIL'],
            ['name' => 'فلتر زيت', 'category' => 'فلاتر', 'unit' => 'قطعة', 'reorder_level' => 5, 'is_active' => true],
        );

        $sunny = Vehicle::query()->where('code', 'C-088')->first();
        WorkOrder::query()->updateOrCreate(
            ['vehicle_id' => $sunny->id, 'status' => 'in_progress'],
            [
                'workshop' => 'ورشة النور',
                'opened_on' => now()->subDay()->toDateString(),
                'odometer' => 89000,
                'puts_in_maintenance' => true,
                'parts_cost' => 850,
                'labor_cost' => 300,
                'notes' => 'صيانة دورية',
            ],
        );

        Employee::query()->firstOrCreate(['name' => 'محمود فني'], [
            'job_title' => 'فني صيانة',
            'hired_on' => '2024-01-01',
            'base_salary' => 6000,
            'status' => 'active',
        ]);

        if (! Notification::query()->where('user_id', $user->id)->where('title', 'قرب انتهاء عقد CNT-00001')->exists()) {
            app(\App\Services\Notifications\NotificationDispatchService::class)->notify(
                $user,
                'قرب انتهاء عقد CNT-00001',
                'عقد أحمد محمود ينتهي اليوم — جهّز الاستلام.',
                'contract',
                1,
            );
        }
    }
}
