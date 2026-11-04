<?php

namespace App\Providers;

use App\Models\Contract;
use App\Models\Customer;
use App\Models\MediaFile;
use App\Models\Vehicle;
use App\Policies\ContractPolicy;
use App\Policies\CustomerPolicy;
use App\Policies\MediaFilePolicy;
use App\Policies\VehiclePolicy;
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
        Gate::policy(Contract::class, ContractPolicy::class);
        Gate::policy(Customer::class, CustomerPolicy::class);
        Gate::policy(Vehicle::class, VehiclePolicy::class);
        Gate::policy(MediaFile::class, MediaFilePolicy::class);
    }
}
