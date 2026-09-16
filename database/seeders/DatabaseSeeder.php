<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\CustomerConnection;
use App\Models\InternetPackage;
use App\Models\NetworkAccount;
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
        foreach (['Budi Santoso', 'Siti Rahma', 'Andi Pratama'] as $index => $name) {
            $customer = Customer::create(['tenant_id' => $tenant->id, 'name' => $name, 'status' => 'active']);
            NetworkAccount::create([
                'tenant_id' => $tenant->id,
                'router_id' => $router->id,
                'username' => 'cust00'.($index + 1),
                'profile' => 'HOME-10M', 'status' => $index === 2 ? 'disabled' : 'active',
                'metadata' => ['profiles' => ['HOME-10M', 'HOME-20M', 'HOME-50M']],
            ]);
            CustomerConnection::create(['tenant_id' => $tenant->id, 'customer_id' => $customer->id, 'internet_package_id' => $packages->first()->id, 'router_id' => $router->id, 'network_account_id' => NetworkAccount::latest('id')->first()->id, 'status' => 'active', 'provisioned_at' => now()]);
        }
    }
}
