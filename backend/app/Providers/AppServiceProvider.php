<?php

namespace App\Providers;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\EmployeeAssignment;
use App\Models\Expense;
use App\Models\InventoryItem;
use App\Models\MediaFile;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\PayrollRun;
use App\Models\Vehicle;
use App\Models\Violation;
use App\Models\WorkOrder;
use App\Policies\ContractPolicy;
use App\Policies\CustomerPolicy;
use App\Policies\MediaFilePolicy;
use App\Policies\VehiclePolicy;
use App\Services\Accounting\ProfitabilitySnapshotService;
use App\Support\PerfReadCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // جلسة SPA طويلة: لا تُقصّر بـ SANCTUM_EXPIRATION على created_at — الاعتماد على expires_at + AuthSession.
        config(['sanctum.expiration' => null]);

        Gate::policy(Contract::class, ContractPolicy::class);
        Gate::policy(Customer::class, CustomerPolicy::class);
        Gate::policy(Vehicle::class, VehiclePolicy::class);
        Gate::policy(MediaFile::class, MediaFilePolicy::class);

        // OPS-2: إبطال كاش العدّادات/الملخص عند أي كتابة مؤثرة.
        $bump = static function () {
            PerfReadCache::bump();
        };
        $bumpProfit = static function () {
            PerfReadCache::bump();
            try {
                app(ProfitabilitySnapshotService::class)->invalidateRecentMonths();
            } catch (\Throwable) {
                // جدول الملخص قد لا يكون مُهاجَراً بعد في بيئات قديمة.
            }
        };
        foreach ([
            Violation::class,
            InventoryItem::class,
            Notification::class,
            Customer::class,
            PayrollRun::class,
            EmployeeAssignment::class,
        ] as $modelClass) {
            /** @var class-string<Model> $modelClass */
            $modelClass::saved($bump);
            $modelClass::deleted($bump);
            if (in_array(SoftDeletes::class, class_uses_recursive($modelClass), true)) {
                $modelClass::restored($bump);
            }
        }
        foreach ([Contract::class, Payment::class, WorkOrder::class, Expense::class] as $modelClass) {
            /** @var class-string<Model> $modelClass */
            $modelClass::saved($bumpProfit);
            $modelClass::deleted($bumpProfit);
            if (in_array(SoftDeletes::class, class_uses_recursive($modelClass), true)) {
                $modelClass::restored($bumpProfit);
            }
        }
    }
}
