<?php

namespace App\Providers;

use App\Models\Customer;
use App\Models\CustomerConnection;
use App\Models\InternetPackage;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentRequest;
use App\Models\Router;
use App\Policies\CustomerConnectionPolicy;
use App\Policies\CustomerPolicy;
use App\Policies\InternetPackagePolicy;
use App\Policies\InvoicePolicy;
use App\Policies\PaymentPolicy;
use App\Policies\PaymentRequestPolicy;
use App\Policies\RouterPolicy;
use App\Services\Messaging\FakeMessagingProvider;
use App\Services\Messaging\MessagingProvider;
use App\Services\Monitoring\Contracts\MonitoringDriver;
use App\Services\Monitoring\FakeMonitoringDriver;
use App\Services\Network\FakeNetworkDriver;
use App\Services\Network\NetworkDriver;
use App\Services\Payments\FakePaymentGateway;
use App\Services\Payments\PaymentGateway;
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
        $this->app->bind(PaymentGateway::class, function ($app) {
            if (config('payments.gateway') !== 'fake') {
                throw new \RuntimeException('No payment gateway is configured.');
            }

            return $app->make(FakePaymentGateway::class);
        });
        $this->app->bind(MessagingProvider::class, function ($app) {
            if (config('messaging.provider') !== 'fake') {
                throw new \RuntimeException('No messaging provider is configured.');
            }

            return $app->make(FakeMessagingProvider::class);
        });
        $this->app->bind(MonitoringDriver::class, function ($app) {
            if (config('monitoring.driver') !== 'fake') {
                throw new \RuntimeException('No monitoring driver is configured.');
            }

            return $app->make(FakeMonitoringDriver::class);
        });
    }

    public function boot(): void
    {
        Gate::policy(Router::class, RouterPolicy::class);
        Gate::policy(Customer::class, CustomerPolicy::class);
        Gate::policy(InternetPackage::class, InternetPackagePolicy::class);
        Gate::policy(CustomerConnection::class, CustomerConnectionPolicy::class);
        Gate::policy(Invoice::class, InvoicePolicy::class);
        Gate::policy(Payment::class, PaymentPolicy::class);
        Gate::policy(PaymentRequest::class, PaymentRequestPolicy::class);
    }
}
