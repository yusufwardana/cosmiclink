<?php

namespace Tests\Feature;

use App\Actions\ProcessOverdueBilling;
use App\Actions\ProcessPaymentProviderEvent;
use App\Actions\SendCustomerMessage;
use App\Actions\SendPaymentReminder;
use App\Models\BillingAutomationAttempt;
use App\Models\Customer;
use App\Models\CustomerConnection;
use App\Models\InternetPackage;
use App\Models\Invoice;
use App\Models\MessageLog;
use App\Models\NetworkAccount;
use App\Models\NetworkOperationLog;
use App\Models\Payment;
use App\Models\PaymentProviderEvent;
use App\Models\PaymentRequest;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Payments\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class Phase3AutomationTest extends TestCase
{
    use RefreshDatabase;

    public function test_fake_payment_request_uses_invoice_balance_and_callback_settles_once(): void
    {
        [$tenant, $user] = $this->tenantWithUser();
        $invoice = $this->invoice($tenant, 'unpaid');
        $gateway = app(PaymentGateway::class);
        $request = $gateway->createPaymentRequest($invoice);

        $this->assertSame(150000, $request->amount);
        $this->assertSame('pending', $request->status);
        $event = $gateway->simulateSuccess($request);
        app(ProcessPaymentProviderEvent::class)->handle($event, $user);
        app(ProcessPaymentProviderEvent::class)->handle($event, $user);

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(1, Payment::where('invoice_id', $invoice->id)->count());
        $this->assertSame(1, PaymentProviderEvent::where('provider_event_id', $event['provider_event_id'])->count());
    }

    public function test_fake_payment_rejects_unknown_reference_and_amount_mismatch(): void
    {
        [$tenant] = $this->tenantWithUser();
        $invoice = $this->invoice($tenant, 'unpaid');
        $gateway = app(PaymentGateway::class);

        $this->expectException(\InvalidArgumentException::class);
        app(ProcessPaymentProviderEvent::class)->handle(['provider' => 'fake', 'provider_event_id' => 'EVT-UNKNOWN', 'provider_reference' => 'PAY-UNKNOWN', 'amount' => 150000, 'currency' => 'IDR'], User::factory()->for($tenant)->create());
    }

    public function test_fake_messaging_creates_auditable_message_log_and_handles_failure(): void
    {
        [$tenant, $user] = $this->tenantWithUser();
        $customer = Customer::factory()->for($tenant)->create(['phone' => '081234567890']);
        $invoice = $this->invoice($tenant, 'unpaid', $customer);

        $message = app(SendCustomerMessage::class)->handle($customer, 'invoice_created', ['amount' => 150000, 'period' => 'September 2026'], $user, $invoice);

        $this->assertSame('sent', $message->status);
        $this->assertSame('whatsapp', $message->channel);
        $this->assertStringContainsString('Rp150.000', $message->rendered_content);
        $this->assertStringStartsWith('+62', $message->recipient);
        $this->assertNotSame('', $message->provider_message_id);
    }

    public function test_tenant_cannot_access_another_tenants_payment_request(): void
    {
        [$tenantA, $userA] = $this->tenantWithUser();
        [$tenantB] = $this->tenantWithUser();
        $invoice = $this->invoice($tenantB, 'unpaid');
        $request = app(PaymentGateway::class)->createPaymentRequest($invoice);

        $this->actingAs($userA)->get(route('billing.payment-requests.show', $request))->assertForbidden();
    }

    public function test_payment_request_creation_is_idempotent(): void
    {
        [$tenant] = $this->tenantWithUser();
        $invoice = $this->invoice($tenant, 'unpaid');
        $gateway = app(PaymentGateway::class);

        $first = $gateway->createPaymentRequest($invoice);
        $second = $gateway->createPaymentRequest($invoice);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, PaymentRequest::where('invoice_id', $invoice->id)->count());
    }

    public function test_verified_payment_creates_payment_received_message(): void
    {
        [$tenant, $user] = $this->tenantWithUser();
        $invoice = $this->invoice($tenant, 'unpaid');
        $request = app(PaymentGateway::class)->createPaymentRequest($invoice);

        app(ProcessPaymentProviderEvent::class)->handle(app(PaymentGateway::class)->simulateSuccess($request), $user);

        $this->assertDatabaseHas('message_logs', ['invoice_id' => $invoice->id, 'template' => 'payment_received', 'status' => 'sent']);
    }

    public function test_messaging_failure_does_not_roll_back_verified_payment(): void
    {
        [$tenant, $user] = $this->tenantWithUser();
        $customer = Customer::factory()->for($tenant)->create(['phone' => '081239999999']);
        $invoice = $this->invoice($tenant, 'unpaid', $customer);
        $request = app(PaymentGateway::class)->createPaymentRequest($invoice);

        app(ProcessPaymentProviderEvent::class)->handle(app(PaymentGateway::class)->simulateSuccess($request), $user);

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertDatabaseHas('message_logs', ['customer_id' => $customer->id, 'template' => 'payment_received', 'status' => 'failed']);
    }

    public function test_seed_has_a_pending_simulated_payment_request_before_settlement(): void
    {
        $this->seed();
        $customer = Customer::where('name', 'Siti Rahma')->firstOrFail();
        $request = PaymentRequest::where('customer_id', $customer->id)->firstOrFail();

        $this->assertSame('081298765432', $customer->phone);
        $this->assertSame('fake', $request->provider);
        $this->assertSame('pending', $request->status);
        $this->assertSame(100000, $request->amount);
        $this->assertSame(0, PaymentProviderEvent::where('payment_request_id', $request->id)->count());
    }

    public function test_eligible_unpaid_invoice_gets_one_idempotent_payment_reminder(): void
    {
        [$tenant, $user] = $this->tenantWithUser();
        $invoice = $this->invoice($tenant, 'unpaid');
        $first = app(SendPaymentReminder::class)->handle($invoice, $user);
        $second = app(SendPaymentReminder::class)->handle($invoice, $user);

        $this->assertSame('sent', $first->status);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, MessageLog::where('invoice_id', $invoice->id)->where('template', 'payment_reminder')->count());
    }

    public function test_paid_or_cancelled_invoice_does_not_receive_a_reminder(): void
    {
        [$tenant, $user] = $this->tenantWithUser();
        $paid = $this->invoice($tenant, 'paid');
        $cancelled = $this->invoice($tenant, 'cancelled');

        $this->assertNull(app(SendPaymentReminder::class)->handle($paid, $user));
        $this->assertNull(app(SendPaymentReminder::class)->handle($cancelled, $user));
        $this->assertDatabaseCount('message_logs', 0);
    }

    public function test_missing_phone_is_recorded_as_skipped_reminder(): void
    {
        [$tenant, $user] = $this->tenantWithUser();
        $customer = Customer::factory()->for($tenant)->create(['phone' => null]);
        $invoice = $this->invoice($tenant, 'unpaid', $customer);

        $message = app(SendPaymentReminder::class)->handle($invoice, $user);

        $this->assertSame('skipped', $message->status);
        $this->assertSame('INVALID_PHONE', $message->failure_code);
    }

    public function test_invalid_phone_is_recorded_as_skipped_reminder(): void
    {
        [$tenant, $user] = $this->tenantWithUser();
        $customer = Customer::factory()->for($tenant)->create(['phone' => '12345']);
        $invoice = $this->invoice($tenant, 'unpaid', $customer);

        $message = app(SendPaymentReminder::class)->handle($invoice, $user);

        $this->assertSame('skipped', $message->status);
        $this->assertSame('INVALID_PHONE', $message->failure_code);
        $this->assertSame(1, MessageLog::where('invoice_id', $invoice->id)->count());
    }

    public function test_cross_tenant_reminder_is_rejected(): void
    {
        [$tenantA, $userA] = $this->tenantWithUser();
        [$tenantB] = $this->tenantWithUser();
        $invoice = $this->invoice($tenantB, 'unpaid');

        $this->expectException(HttpException::class);
        app(SendPaymentReminder::class)->handle($invoice, $userA);
    }

    public function test_messaging_failure_during_reminder_does_not_change_billing_or_network_state(): void
    {
        [$tenant, $user] = $this->tenantWithUser();
        $customer = Customer::factory()->for($tenant)->create(['phone' => '081299999999']);
        $invoice = $this->invoice($tenant, 'unpaid', $customer);
        $before = [$invoice->status, $invoice->paid_amount, $invoice->connection->status, $invoice->connection->networkAccount->status];

        $message = app(SendPaymentReminder::class)->handle($invoice, $user);

        $this->assertSame('failed', $message->status);
        $this->assertSame($before, [$invoice->fresh()->status, $invoice->fresh()->paid_amount, $invoice->connection->fresh()->status, $invoice->connection->networkAccount->fresh()->status]);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_complete_fake_payment_chain_suspends_and_reactivates_exactly_once(): void
    {
        [$tenant, $user] = $this->tenantWithUser();
        $connection = $this->invoice($tenant, 'overdue')->connection;
        $connection->update(['status' => 'active']);
        $invoice = $connection->invoices()->first();
        app(ProcessOverdueBilling::class)->handle($tenant->id, $user);
        $request = app(PaymentGateway::class)->createPaymentRequest($invoice->fresh());
        $event = app(PaymentGateway::class)->simulateSuccess($request);
        app(ProcessPaymentProviderEvent::class)->handle($event, $user);
        app(ProcessPaymentProviderEvent::class)->handle($event, $user);

        $templates = MessageLog::where('customer_id', $connection->customer_id)->pluck('template')->all();
        $this->assertContains('service_suspended', $templates);
        $this->assertContains('payment_received', $templates);
        $this->assertContains('service_reactivated', $templates);
        $this->assertSame(1, Payment::where('invoice_id', $invoice->id)->count());
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(0, $invoice->fresh()->outstanding());
        $this->assertSame('active', $connection->fresh()->status);
        $this->assertNull($connection->fresh()->suspension_reason);
        $this->assertSame('active', $connection->networkAccount->fresh()->status);
        $this->assertSame(1, NetworkOperationLog::where('customer_connection_id', $connection->id)->where('operation', 'ENABLE_PPPOE')->where('status', 'success')->count());
        $this->assertSame(1, BillingAutomationAttempt::where('customer_connection_id', $connection->id)->where('action', 'reactivate')->where('status', 'success')->count());
        $this->assertSame(1, MessageLog::where('invoice_id', $invoice->id)->where('template', 'payment_received')->count());
        $this->assertSame(1, MessageLog::where('customer_connection_id', $connection->id)->where('template', 'service_reactivated')->count());
    }

    private function invoice(Tenant $tenant, string $status, ?Customer $customer = null): Invoice
    {
        $customer ??= Customer::factory()->for($tenant)->create(['phone' => '081234567890']);
        $package = InternetPackage::factory()->for($tenant)->create(['monthly_price' => 150000]);
        $router = Router::factory()->for($tenant)->create();
        $account = NetworkAccount::create(['tenant_id' => $tenant->id, 'router_id' => $router->id, 'username' => $customer->customer_code, 'profile' => $package->network_profile, 'status' => 'active']);
        $connection = CustomerConnection::factory()->for($customer)->for($package)->for($router)->create(['tenant_id' => $tenant->id, 'network_account_id' => $account->id, 'status' => 'active', 'provisioned_at' => now()]);

        return Invoice::factory()->for($tenant)->for($customer)->for($connection)->create(['status' => $status, 'total' => 150000, 'paid_amount' => 0]);
    }

    private function tenantWithUser(): array
    {
        $tenant = Tenant::factory()->create();

        return [$tenant, User::factory()->for($tenant)->create()];
    }
}
