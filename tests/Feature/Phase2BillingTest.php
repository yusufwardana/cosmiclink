<?php

namespace Tests\Feature;

use App\Actions\GenerateInvoiceForConnection;
use App\Actions\MarkOverdueInvoices;
use App\Actions\ProcessOverdueBilling;
use App\Actions\RecordPayment;
use App\Models\Customer;
use App\Models\CustomerConnection;
use App\Models\InternetPackage;
use App\Models\Invoice;
use App\Models\NetworkAccount;
use App\Models\NetworkOperationLog;
use App\Models\Payment;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class Phase2BillingTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_generation_snapshots_price_and_is_idempotent(): void
    {
        [$tenant, $user] = $this->tenantWithUser();
        $connection = $this->connection($tenant, 'active');
        $package = $connection->internetPackage;
        Carbon::setTestNow('2026-09-10');

        $invoice = app(GenerateInvoiceForConnection::class)->handle($connection, Carbon::parse('2026-09-01'), $user);
        $package->update(['monthly_price' => 175000]);
        $again = app(GenerateInvoiceForConnection::class)->handle($connection, Carbon::parse('2026-09-01'), $user);

        $this->assertSame($invoice->id, $again->id);
        $this->assertSame(150000, $invoice->fresh()->total);
        $this->assertSame(150000, $invoice->items()->first()->unit_price);
        $this->assertSame('unpaid', $invoice->status);
    }

    public function test_overdue_enforcement_suspends_connection_and_records_billing_audit(): void
    {
        [$tenant, $user] = $this->tenantWithUser();
        $connection = $this->connection($tenant, 'active');
        Carbon::setTestNow('2026-09-20');
        $invoice = app(GenerateInvoiceForConnection::class)->handle($connection, Carbon::parse('2026-08-01'), $user);
        $invoice->update(['due_date' => '2026-09-10']);
        app(MarkOverdueInvoices::class)->handle($tenant->id);
        app(ProcessOverdueBilling::class)->handle($tenant->id, $user);

        $this->assertSame('overdue', $invoice->fresh()->status);
        $this->assertSame('suspended', $connection->fresh()->status);
        $this->assertSame('disabled', $connection->networkAccount->fresh()->status);
        $this->assertSame('billing_overdue', $connection->fresh()->suspension_reason);
        $this->assertDatabaseHas('billing_automation_attempts', ['invoice_id' => $invoice->id, 'action' => 'suspend', 'status' => 'success']);
        $this->assertDatabaseHas('network_operation_logs', ['customer_connection_id' => $connection->id, 'operation' => 'DISABLE_PPPOE', 'status' => 'success']);
    }

    public function test_payment_marks_invoice_paid_and_reactivates_only_billing_suspension(): void
    {
        [$tenant, $user] = $this->tenantWithUser();
        $connection = $this->connection($tenant, 'suspended');
        $connection->update(['suspension_reason' => 'billing_overdue']);
        $invoice = Invoice::factory()->for($tenant)->for($connection->customer)->for($connection)->create(['status' => 'overdue', 'total' => 150000, 'paid_amount' => 0]);
        NetworkAccount::whereKey($connection->network_account_id)->update(['status' => 'disabled']);
        app(RecordPayment::class)->handle($invoice, 150000, 'manual', 'PAY-001', $user);

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('active', $connection->fresh()->status);
        $this->assertSame('active', $connection->networkAccount->fresh()->status);
        $this->assertDatabaseHas('billing_automation_attempts', ['invoice_id' => $invoice->id, 'action' => 'reactivate', 'status' => 'success']);
    }

    public function test_partial_duplicate_and_overpayment_rules_are_enforced(): void
    {
        [$tenant, $user] = $this->tenantWithUser();
        $invoice = Invoice::factory()->for($tenant)->create(['total' => 150000, 'paid_amount' => 0, 'status' => 'unpaid']);
        app(RecordPayment::class)->handle($invoice, 50000, 'cash', 'PAY-PARTIAL', $user);

        $this->assertSame('unpaid', $invoice->fresh()->status);
        $this->assertSame(50000, $invoice->fresh()->paid_amount);
        $this->expectException(\InvalidArgumentException::class);
        app(RecordPayment::class)->handle($invoice, 100001, 'cash', 'PAY-OVER', $user);
    }

    public function test_unavailable_router_does_not_falsely_suspend_or_erase_payment_truth(): void
    {
        [$tenant, $user] = $this->tenantWithUser();
        $connection = $this->connection($tenant, 'active', 'unavailable');
        $invoice = Invoice::factory()->for($tenant)->for($connection->customer)->for($connection)->create(['status' => 'overdue', 'total' => 150000, 'paid_amount' => 0, 'due_date' => '2026-09-01']);
        Carbon::setTestNow('2026-09-20');
        app(ProcessOverdueBilling::class)->handle($tenant->id, $user);

        $this->assertSame('overdue', $invoice->fresh()->status);
        $this->assertSame('active', $connection->fresh()->status);
        $this->assertSame('failed', $connection->fresh()->metadata['last_billing_automation_status']);
        $this->assertDatabaseHas('billing_automation_attempts', ['invoice_id' => $invoice->id, 'action' => 'suspend', 'status' => 'failed']);
    }

    public function test_duplicate_payment_reference_is_rejected(): void
    {
        [$tenant, $user] = $this->tenantWithUser();
        $invoice = Invoice::factory()->for($tenant)->create(['total' => 150000, 'paid_amount' => 0]);
        app(RecordPayment::class)->handle($invoice, 50000, 'cash', 'PAY-DUP', $user);

        $this->expectException(\InvalidArgumentException::class);
        app(RecordPayment::class)->handle($invoice, 50000, 'cash', 'PAY-DUP', $user);
    }

    public function test_multiple_overdue_invoices_block_reactivation_until_all_are_paid(): void
    {
        [$tenant, $user] = $this->tenantWithUser();
        $connection = $this->connection($tenant, 'suspended');
        $connection->update(['suspension_reason' => 'billing_overdue']);
        $first = Invoice::factory()->for($tenant)->for($connection->customer)->for($connection)->create(['status' => 'overdue', 'total' => 100000, 'paid_amount' => 0, 'billing_period_start' => '2026-08-01', 'billing_period_end' => '2026-08-31']);
        $second = Invoice::factory()->for($tenant)->for($connection->customer)->for($connection)->create(['status' => 'overdue', 'total' => 50000, 'paid_amount' => 0, 'billing_period_start' => '2026-09-01', 'billing_period_end' => '2026-09-30']);

        app(RecordPayment::class)->handle($second, 50000, 'manual', 'PAY-SECOND', $user);

        $this->assertSame('suspended', $connection->fresh()->status);
        app(RecordPayment::class)->handle($first, 100000, 'manual', 'PAY-FIRST', $user);
        $this->assertSame('active', $connection->fresh()->status);
    }

    public function test_manual_suspension_is_not_auto_reactivated_by_payment(): void
    {
        [$tenant, $user] = $this->tenantWithUser();
        $connection = $this->connection($tenant, 'suspended');
        $connection->update(['suspension_reason' => 'manual']);
        $invoice = Invoice::factory()->for($tenant)->for($connection->customer)->for($connection)->create(['status' => 'unpaid', 'total' => 150000, 'paid_amount' => 0]);

        app(RecordPayment::class)->handle($invoice, 150000, 'manual', 'PAY-MANUAL', $user);

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('suspended', $connection->fresh()->status);
    }

    public function test_reactivation_failure_preserves_paid_invoice_and_suspended_connection(): void
    {
        [$tenant, $user] = $this->tenantWithUser();
        $connection = $this->connection($tenant, 'suspended', 'unavailable');
        $connection->update(['suspension_reason' => 'billing_overdue']);
        $invoice = Invoice::factory()->for($tenant)->for($connection->customer)->for($connection)->create(['status' => 'overdue', 'total' => 150000, 'paid_amount' => 0]);

        app(RecordPayment::class)->handle($invoice, 150000, 'manual', 'PAY-REACT-FAIL', $user);

        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame('suspended', $connection->fresh()->status);
        $this->assertDatabaseHas('billing_automation_attempts', ['invoice_id' => $invoice->id, 'action' => 'reactivate', 'status' => 'failed']);
    }

    public function test_invoice_and_payment_access_is_tenant_scoped(): void
    {
        [$tenantA, $userA] = $this->tenantWithUser();
        [$tenantB] = $this->tenantWithUser();
        $invoice = Invoice::factory()->for($tenantB)->create();
        $payment = Payment::factory()->for($tenantB)->for($invoice)->create(['customer_id' => $invoice->customer_id]);

        $this->actingAs($userA)->get(route('billing.invoices.show', $invoice))->assertForbidden();
        $this->actingAs($userA)->post(route('billing.payments.store', $invoice), [
            'amount' => 1000, 'method' => 'manual', 'payment_reference' => 'CROSS-TENANT',
        ])->assertForbidden();
        $this->assertSame($tenantA->id, $userA->tenant_id);
        $this->assertSame($tenantB->id, $payment->tenant_id);
    }

    public function test_overdue_detection_is_strictly_after_due_date_and_excludes_paid_invoices(): void
    {
        [$tenant] = $this->tenantWithUser();
        Carbon::setTestNow('2026-09-10');
        $dueToday = Invoice::factory()->for($tenant)->create(['due_date' => '2026-09-10', 'status' => 'unpaid']);
        $pastDue = Invoice::factory()->for($tenant)->create(['due_date' => '2026-09-09', 'status' => 'unpaid']);
        $paid = Invoice::factory()->for($tenant)->create(['due_date' => '2026-09-01', 'status' => 'paid', 'paid_amount' => 150000]);

        app(MarkOverdueInvoices::class)->handle($tenant->id);

        $this->assertSame('unpaid', $dueToday->fresh()->status);
        $this->assertSame('overdue', $pastDue->fresh()->status);
        $this->assertSame('paid', $paid->fresh()->status);
    }

    public function test_seeded_andi_is_a_clean_pre_enforcement_billing_demo(): void
    {
        $this->seed();
        $user = User::where('email', 'owner@demonet.test')->firstOrFail();
        $customer = Customer::where('name', 'Andi Pratama')->firstOrFail();
        $connection = $customer->connections()->with('networkAccount')->firstOrFail();
        $invoice = $connection->invoices()->where('status', 'overdue')->firstOrFail();

        $this->assertSame('active', $connection->status);
        $this->assertNull($connection->suspension_reason);
        $this->assertNull($connection->suspended_at);
        $this->assertSame('active', $connection->networkAccount->status);
        $this->assertSame(100000, $invoice->outstanding());
        $this->assertDatabaseCount('billing_automation_attempts', 0);
        $this->assertDatabaseMissing('network_operation_logs', ['customer_connection_id' => $connection->id, 'operation' => 'DISABLE_PPPOE']);

        app(ProcessOverdueBilling::class)->handle($connection->tenant_id, $user);
        $this->assertSame('suspended', $connection->fresh()->status);
        $this->assertSame('disabled', $connection->networkAccount->fresh()->status);
        app(RecordPayment::class)->handle($invoice, 100000, 'manual', 'DEMO-PAY-001', $user);
        $this->assertSame('active', $connection->fresh()->status);
        $this->assertSame('active', $connection->networkAccount->fresh()->status);
    }

    public function test_repeated_overdue_enforcement_does_not_repeat_suspension(): void
    {
        [$tenant, $user] = $this->tenantWithUser();
        $connection = $this->connection($tenant, 'active');
        $invoice = Invoice::factory()->for($tenant)->for($connection->customer)->for($connection)->create(['status' => 'overdue', 'total' => 150000, 'paid_amount' => 0]);

        app(ProcessOverdueBilling::class)->handle($tenant->id, $user);
        app(ProcessOverdueBilling::class)->handle($tenant->id, $user);

        $this->assertSame('suspended', $connection->fresh()->status);
        $this->assertSame(1, $connection->automationAttempts()->where('action', 'suspend')->where('status', 'success')->count());
        $this->assertSame(1, NetworkOperationLog::where('customer_connection_id', $connection->id)->where('operation', 'DISABLE_PPPOE')->count());
    }

    public function test_guest_root_redirects_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    private function connection(Tenant $tenant, string $status = 'active', string $routerStatus = 'available'): CustomerConnection
    {
        $customer = Customer::factory()->for($tenant)->create();
        $package = InternetPackage::factory()->for($tenant)->create(['monthly_price' => 150000]);
        $router = Router::factory()->for($tenant)->create(['status' => $routerStatus]);
        $account = NetworkAccount::create(['tenant_id' => $tenant->id, 'router_id' => $router->id, 'username' => $customer->customer_code, 'profile' => $package->network_profile, 'status' => $status === 'suspended' ? 'disabled' : 'active']);

        return CustomerConnection::factory()->for($customer)->for($package)->for($router)->create(['tenant_id' => $tenant->id, 'network_account_id' => $account->id, 'status' => $status, 'provisioned_at' => now()]);
    }

    private function tenantWithUser(): array
    {
        $tenant = Tenant::factory()->create();

        return [$tenant, User::factory()->for($tenant)->create()];
    }
}
