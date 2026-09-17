<?php

namespace Tests\Feature;

use App\Actions\GenerateInvoiceForConnection;
use App\Actions\MarkOverdueInvoices;
use App\Actions\ProcessOverdueBilling;
use App\Actions\ProvisionCustomerConnection;
use App\Actions\RecordPayment;
use App\Models\Customer;
use App\Models\CustomerConnection;
use App\Models\InternetPackage;
use App\Models\NetworkOperationLog;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Network\NetworkDriver;
use App\Services\Network\NetworkOperationResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class Phase6BGoNetworkEngineIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_laravel_provisioning_billing_suspension_and_payment_reactivation_cross_the_live_go_boundary(): void
    {
        if (env('NETWORK_ENGINE_LIVE_TEST') !== '1') {
            $this->markTestSkipped('Set NETWORK_ENGINE_LIVE_TEST=1 to run this test against a running local Go Network Engine.');
        }

        config()->set('network.driver', 'go');
        config()->set('network.go.url', env('GO_NETWORK_ENGINE_URL', 'http://127.0.0.1:8787'));
        config()->set('network.go.token', env('GO_NETWORK_ENGINE_TOKEN'));
        app()->forgetInstance(NetworkDriver::class);

        $reference = random_int(1000000, 9999999);
        $tenant = Tenant::factory()->create(['id' => $reference]);
        $user = User::factory()->for($tenant)->create();
        $customer = Customer::factory()->for($tenant)->create(['id' => $reference + 1]);
        $package = InternetPackage::factory()->for($tenant)->create(['network_profile' => 'HOME-20M', 'monthly_price' => 150000]);
        $router = Router::factory()->for($tenant)->create(['id' => $reference + 2, 'status' => 'available']);
        $connection = CustomerConnection::factory()->for($customer)->for($package)->for($router)->create(['tenant_id' => $tenant->id, 'status' => 'pending']);

        app(ProvisionCustomerConnection::class)->handle($connection, $user);

        $connection->refresh();
        $account = $connection->networkAccount;
        $this->assertSame('active', $connection->status);
        $this->assertSame('active', $account->status);
        $this->assertDatabaseHas('network_operation_logs', ['customer_connection_id' => $connection->id, 'operation' => 'CREATE_PPPOE', 'status' => 'success']);

        Carbon::setTestNow('2026-09-20');
        $invoice = app(GenerateInvoiceForConnection::class)->handle($connection, Carbon::parse('2026-08-01'), $user);
        $invoice->update(['due_date' => '2026-09-10']);
        app(MarkOverdueInvoices::class)->handle($tenant->id);
        $this->assertSame('active', $connection->fresh()->status);
        $this->assertNotNull($connection->fresh()->provisioned_at);
        $this->assertSame(1, app(ProcessOverdueBilling::class)->handle($tenant->id, $user));

        $this->assertSame('overdue', $invoice->fresh()->status);
        $this->assertSame('suspended', $connection->fresh()->status);
        $this->assertSame('disabled', $account->fresh()->status);
        $this->assertDatabaseHas('network_operation_logs', ['customer_connection_id' => $connection->id, 'operation' => 'DISABLE_PPPOE', 'status' => 'success']);

        app(RecordPayment::class)->handle($invoice, 150000, 'manual', 'PHASE6B-LIVE-'.$connection->id, $user);

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('active', $connection->fresh()->status);
        $this->assertSame('active', $account->fresh()->status);
        $this->assertDatabaseHas('network_operation_logs', ['customer_connection_id' => $connection->id, 'operation' => 'ENABLE_PPPOE', 'status' => 'success']);
        $this->assertSame(['CREATE_PPPOE', 'DISABLE_PPPOE', 'ENABLE_PPPOE'], NetworkOperationLog::query()->where('customer_connection_id', $connection->id)->pluck('operation')->all());
    }

    public function test_live_go_driver_returns_a_controlled_failure_when_the_engine_is_unavailable(): void
    {
        if (env('NETWORK_ENGINE_LIVE_TEST') !== '1') {
            $this->markTestSkipped('Set NETWORK_ENGINE_LIVE_TEST=1 to run this test against the local Go Network Engine boundary.');
        }

        config()->set('network.driver', 'go');
        config()->set('network.go.url', 'http://127.0.0.1:1');
        config()->set('network.go.token', env('GO_NETWORK_ENGINE_TOKEN'));
        app()->forgetInstance(NetworkDriver::class);
        $router = Router::factory()->for(Tenant::factory())->make(['id' => 99, 'tenant_id' => 99]);

        $result = app(NetworkDriver::class)->testConnection($router);

        $this->assertInstanceOf(NetworkOperationResult::class, $result);
        $this->assertFalse($result->successful);
        $this->assertContains($result->errorCode, ['NETWORK_ENGINE_UNAVAILABLE', 'NETWORK_ENGINE_TIMEOUT']);
        $this->assertContains($result->message, ['Go Network Engine is unavailable.', 'Go Network Engine request timed out.']);
    }
}
