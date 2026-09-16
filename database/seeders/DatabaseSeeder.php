<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\CustomerConnection;
use App\Models\InternetPackage;
use App\Models\Invoice;
use App\Models\NetworkAccount;
use App\Models\Payment;
use App\Models\PaymentRequest;
use App\Models\Router;
use App\Models\Tenant;
use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $tenant = Tenant::create(['name' => 'DemoNet ISP', 'slug' => 'demonet-isp']);
        $user = User::factory()->for($tenant)->create([
            'name' => 'DemoNet Owner',
            'email' => 'owner@demonet.test',
            'password' => 'development-only-password',
            'role' => 'owner',
        ]);
        $router = Router::factory()->for($tenant)->create(['name' => 'Demo Router 01']);
        $packages = collect([
            ['name' => 'Home 10', 'code' => 'HOME-10', 'download_mbps' => 10, 'upload_mbps' => 10, 'monthly_price' => 100000, 'network_profile' => 'HOME-10M'],
            ['name' => 'Home 20', 'code' => 'HOME-20', 'download_mbps' => 20, 'upload_mbps' => 20, 'monthly_price' => 150000, 'network_profile' => 'HOME-20M'],
            ['name' => 'Home 50', 'code' => 'HOME-50', 'download_mbps' => 50, 'upload_mbps' => 50, 'monthly_price' => 250000, 'network_profile' => 'HOME-50M'],
        ])->map(fn (array $data) => InternetPackage::create($data + ['tenant_id' => $tenant->id, 'status' => 'active']));
        foreach ([
            ['name' => 'Budi Santoso', 'phone' => '081234567890'],
            ['name' => 'Siti Rahma', 'phone' => '081298765432'],
            ['name' => 'Andi Pratama', 'phone' => '081287654321'],
        ] as $index => $demoCustomer) {
            $customer = Customer::create(['tenant_id' => $tenant->id, 'name' => $demoCustomer['name'], 'phone' => $demoCustomer['phone'], 'status' => 'active']);
            NetworkAccount::create([
                'tenant_id' => $tenant->id,
                'router_id' => $router->id,
                'username' => 'cust00'.($index + 1),
                'profile' => 'HOME-10M', 'status' => 'active',
                'metadata' => ['profiles' => ['HOME-10M', 'HOME-20M', 'HOME-50M']],
            ]);
            $connection = CustomerConnection::create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'internet_package_id' => $packages->first()->id, 'router_id' => $router->id, 'network_account_id' => NetworkAccount::latest('id')->first()->id, 'status' => 'active', 'suspension_reason' => null, 'suspended_at' => null, 'provisioned_at' => now()]);
            $invoice = Invoice::create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'customer_connection_id' => $connection->id, 'billing_period_start' => now()->startOfMonth(), 'billing_period_end' => now()->endOfMonth(), 'issue_date' => now()->toDateString(), 'due_date' => now()->subDays($index === 2 ? 3 : -7)->toDateString(), 'subtotal' => 100000, 'discount' => 0, 'total' => 100000, 'paid_amount' => $index === 0 ? 100000 : 0, 'status' => $index === 0 ? 'paid' : ($index === 2 ? 'overdue' : 'unpaid'), 'paid_at' => $index === 0 ? now() : null]);
            $invoice->items()->create(['customer_connection_id' => $connection->id, 'internet_package_id' => $packages->first()->id, 'description' => 'Home 10 snapshot', 'quantity' => 1, 'unit_price' => 100000, 'amount' => 100000]);
            if ($index === 0) {
                Payment::create(['tenant_id' => $tenant->id, 'invoice_id' => $invoice->id, 'customer_id' => $customer->id, 'payment_reference' => 'SEED-PAY-001', 'amount' => 100000, 'method' => 'manual', 'paid_at' => now(), 'status' => 'confirmed']);
            }
            if ($index === 1) {
                PaymentRequest::firstOrCreate(['provider_reference' => 'PAY-DEMO-SEED-001'], ['tenant_id' => $tenant->id, 'invoice_id' => $invoice->id, 'customer_id' => $customer->id, 'provider' => 'fake', 'amount' => 100000, 'currency' => 'IDR', 'status' => 'pending', 'payment_url' => 'SIMULATED PAYMENT', 'qr_payload' => 'SIMULATED-PAYMENT', 'expires_at' => now()->addDay(), 'metadata' => ['simulation' => true]]);
            }
        }
    }
}
