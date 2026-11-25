<?php

namespace App\Console\Commands;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;

/**
 * OPS-1: يضخّ بيانات اصطناعية محلية للقياس — ممنوع على production.
 * لا يُعيد كتابة صفوف موجودة؛ يضيف صفوفاً جديدة فقط برموز SIZE-* .
 */
class PerfSizeLabCommand extends Command
{
    protected $signature = 'perf:size-lab
        {--vehicles=200 : عدد عربيات SIZE-* المستهدف }
        {--contracts=2000 : عدد عقود SIZE-* المستهدف }
        {--force : تأكيد التشغيل}';

    protected $description = 'مختبر حجم محلي (إضافة بيانات SIZE-* فقط)';

    public function handle(): int
    {
        if (App::environment('production')) {
            $this->error('مرفوض على production.');

            return self::FAILURE;
        }

        if (! $this->option('force')) {
            $this->warn('أضف --force للتشغيل. الهدف محلي للقياس فقط.');

            return self::FAILURE;
        }

        $targetVehicles = max(0, (int) $this->option('vehicles'));
        $targetContracts = max(0, (int) $this->option('contracts'));
        $admin = User::query()->orderBy('id')->first();
        if ($admin === null) {
            $this->error('لا يوجد مستخدم.');

            return self::FAILURE;
        }

        $customer = Customer::query()->first();
        if ($customer === null) {
            $customer = Customer::query()->create([
                'full_name' => 'عميل مختبر حجم',
                'phone' => '01000000099',
                'birth_date' => '1990-01-01',
                'license_number' => 'L-SIZE',
                'license_expires_on' => now()->addYear()->toDateString(),
                'status' => 'active',
            ]);
        }

        $haveV = Vehicle::query()->where('code', 'like', 'SIZE-%')->count();
        $needV = max(0, $targetVehicles - $haveV);
        $this->info("عربيات SIZE: موجود {$haveV} · يُضاف {$needV}");

        DB::transaction(function () use ($needV) {
            for ($i = 0; $i < $needV; $i++) {
                $n = Vehicle::query()->where('code', 'like', 'SIZE-%')->count() + 1;
                $code = sprintf('SIZE-%04d', $n);
                Vehicle::query()->create([
                    'code' => $code,
                    'plate' => sprintf('SZ %04d', $n),
                    'brand' => 'مختبر',
                    'model' => 'حجم',
                    'year' => 2022,
                    'category' => 'اقتصادي',
                    'fuel_type' => 'بنزين',
                    'odometer' => 1000 + $n,
                    'daily_rate' => 500,
                    'default_deposit' => 1000,
                    'is_active' => true,
                ]);
            }
        });

        $vehicles = Vehicle::query()->where('code', 'like', 'SIZE-%')->pluck('id')->all();
        if ($vehicles === []) {
            $vehicles = Vehicle::query()->limit(50)->pluck('id')->all();
        }

        $haveC = Contract::query()->where('number', 'like', 'SIZE-%')->count();
        $needC = max(0, $targetContracts - $haveC);
        $this->info("عقود SIZE: موجود {$haveC} · يُضاف {$needC}");

        $statuses = ['pending', 'approved', 'active', 'completed', 'cancelled'];
        DB::transaction(function () use ($needC, $vehicles, $customer, $admin, $statuses) {
            for ($i = 0; $i < $needC; $i++) {
                $n = Contract::query()->where('number', 'like', 'SIZE-%')->count() + 1;
                $vid = $vehicles[$i % count($vehicles)];
                $start = now()->subDays($i % 90)->startOfDay()->addHours(10);
                $days = 3 + ($i % 10);
                Contract::query()->create([
                    'number' => sprintf('SIZE-%06d', $n),
                    'customer_id' => $customer->id,
                    'vehicle_id' => $vid,
                    'status' => $statuses[$i % count($statuses)],
                    'starts_at' => $start,
                    'planned_ends_at' => $start->copy()->addDays($days),
                    'frozen_daily_rate' => 500,
                    'frozen_daily_km_allowance' => 200,
                    'frozen_excess_km_rate' => 5,
                    'frozen_agreed_deposit' => 1000,
                    'period_rent' => 500 * $days,
                    'discount' => 0,
                    'other_fees' => 0,
                    'sales_user_id' => $admin->id,
                ]);
            }
        });

        $this->info('تم. شغّل: php artisan perf:measure --json');

        return self::SUCCESS;
    }
}
