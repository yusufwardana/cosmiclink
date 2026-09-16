<?php

namespace App\Providers;

use App\Models\Customer;
use App\Models\CustomerConnection;
use App\Models\InternetPackage;
use App\Models\Router;
use App\Policies\CustomerConnectionPolicy;
use App\Policies\CustomerPolicy;
use App\Policies\InternetPackagePolicy;
use App\Policies\RouterPolicy;
use App\Services\Network\FakeNetworkDriver;
use App\Services\Network\NetworkDriver;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(NetworkDriver::class, function ($app) {
            if (config('network.driver') !== 'fake') {
                throw new \RuntimeException('No real network driver is configured.');
            }

            return $app->make(FakeNetworkDriver::class);
        });
    }

    public function boot(): void
    {
        Gate::policy(Router::class, RouterPolicy::class);
        Gate::policy(Customer::class, CustomerPolicy::class);
        Gate::policy(InternetPackage::class, InternetPackagePolicy::class);
        Gate::policy(CustomerConnection::class, CustomerConnectionPolicy::class);
    }
}
