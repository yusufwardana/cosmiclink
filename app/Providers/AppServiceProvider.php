<?php

namespace App\Providers;

use App\Models\Customer;
use App\Models\CustomerConnection;
use App\Models\InternetPackage;
use App\Models\Invoice;
use App\Models\NetworkAccount;
use App\Models\NetworkOperationLog;
use App\Models\OutageIncident;
use App\Models\Payment;
use App\Models\PaymentRequest;
use App\Models\Router;
use App\Models\User;
use App\Policies\CustomerConnectionPolicy;
use App\Policies\CustomerPolicy;
use App\Policies\InternetPackagePolicy;
use App\Policies\InvoicePolicy;
use App\Policies\NetworkAccountPolicy;
use App\Policies\NetworkOperationPolicy;
use App\Policies\OutageIncidentPolicy;
use App\Policies\PaymentPolicy;
use App\Policies\PaymentRequestPolicy;
use App\Policies\RouterPolicy;
use App\Services\Messaging\FakeMessagingProvider;
use App\Services\Messaging\MessagingProvider;
use App\Services\Monitoring\Contracts\MonitoringDriver;
use App\Services\Monitoring\FakeMonitoringDriver;
use App\Services\Monitoring\GoMonitoringDriver;
use App\Services\Network\FakeNetworkDriver;
use App\Services\Network\GoNetworkDiscoveryClient;
use App\Services\Network\GoNetworkDriver;
use App\Services\Network\NetworkDiscoveryClient;
use App\Services\Network\NetworkDriver;
use App\Services\Payments\FakePaymentGateway;
use App\Services\Payments\PaymentGateway;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(NetworkDriver::class, function ($app) {
            return match (config('network.driver')) {
                'fake' => $app->make(FakeNetworkDriver::class),
                'go' => $app->make(GoNetworkDriver::class),
                default => throw new \RuntimeException('Unsupported network driver configuration.'),
            };
        });
        $this->app->bind(NetworkDiscoveryClient::class, GoNetworkDiscoveryClient::class);
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
            return match (config('monitoring.driver')) {
                'fake' => $app->make(FakeMonitoringDriver::class),
                'engine' => $app->make(GoMonitoringDriver::class),
                default => throw new \RuntimeException('Unsupported monitoring driver configuration.'),
            };
        });
    }

    public function boot(): void
    {
        $this->registerTestDatabaseSafetyGuard();

        // Canonical network-operation capability. Tenancy is composed on top of
        // this by the object policies (see RouterPolicy::operate), so belonging
        // to a tenant can never by itself authorize a device operation.
        Gate::define('operate-network', fn (User $user): bool => $user->isNetworkOperator());

        Gate::policy(Router::class, RouterPolicy::class);
        Gate::policy(NetworkAccount::class, NetworkAccountPolicy::class);
        Gate::policy(NetworkOperationLog::class, NetworkOperationPolicy::class);
        Gate::policy(Customer::class, CustomerPolicy::class);
        Gate::policy(InternetPackage::class, InternetPackagePolicy::class);
        Gate::policy(CustomerConnection::class, CustomerConnectionPolicy::class);
        Gate::policy(Invoice::class, InvoicePolicy::class);
        Gate::policy(Payment::class, PaymentPolicy::class);
        Gate::policy(PaymentRequest::class, PaymentRequestPolicy::class);
        Gate::policy(OutageIncident::class, OutageIncidentPolicy::class);
    }

    private function registerTestDatabaseSafetyGuard(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->app['events']->listen(CommandStarting::class, function (CommandStarting $event): void {
            if (! $this->app->environment('testing') || ! $this->isDestructiveDatabaseCommand($event->command)) {
                return;
            }

            $configuredDatabase = trim((string) config('database.connections.'.config('database.default').'.database'));
            $configuredUrl = trim((string) config('database.connections.'.config('database.default').'.url'));

            if ($configuredDatabase === 'cosmiclink_test' && $configuredUrl === '') {
                return;
            }

            $observed = $configuredDatabase === '' ? '(empty)' : $configuredDatabase;

            throw new RuntimeException(
                'TEST DATABASE SAFETY GUARD: refusing destructive command ['.$event->command.']. '
                .'APP_ENV=testing must target exactly [cosmiclink_test] with no DB_URL override; '
                .'resolved database is ['.$observed.']. The runtime database [cosmiclink] and every '
                .'unknown database are protected.'
            );
        });
    }

    private function isDestructiveDatabaseCommand(?string $command): bool
    {
        if (! is_string($command) || trim($command) === '') {
            return false;
        }

        return preg_match(
            '/^(migrate:(fresh|refresh|reset|rollback)|migrate|db:(wipe|seed)|schema:dump)$/',
            trim($command)
        ) === 1;
    }
}
