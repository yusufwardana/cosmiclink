<?php

namespace App\Http\Controllers;

use App\Actions\ProvisionCustomerConnection;
use App\Models\Customer;
use App\Models\CustomerConnection;
use App\Models\InternetPackage;
use App\Models\Router;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class CustomerConnectionController extends Controller
{
    public function create(Customer $customer)
    {
        Gate::authorize('view', $customer);

        return view('connections.form', ['customer' => $customer, 'packages' => InternetPackage::where('tenant_id', Auth::user()->tenant_id)->where('status', 'active')->get(), 'routers' => Router::where('tenant_id', Auth::user()->tenant_id)->get()]);
    }

    public function store(Request $request, Customer $customer)
    {
        Gate::authorize('view', $customer);
        $data = $request->validate(['internet_package_id' => ['required', 'integer', 'exists:internet_packages,id'], 'router_id' => ['required', 'integer', 'exists:routers,id']]);
        $package = InternetPackage::findOrFail($data['internet_package_id']);
        $router = Router::findOrFail($data['router_id']);
        abort_unless($package->tenant_id === Auth::user()->tenant_id && $router->tenant_id === Auth::user()->tenant_id, 403);
        $connection = CustomerConnection::create($data + ['tenant_id' => Auth::user()->tenant_id, 'customer_id' => $customer->id, 'status' => 'pending']);

        return redirect()->route('customers.show', $customer)->with('status', 'Connection created.');
    }

    public function provision(CustomerConnection $connection, ProvisionCustomerConnection $provisioner)
    {
        Gate::authorize('update', $connection);
        $provisioner->handle($connection, Auth::user());

        return back()->with('status', $connection->fresh()->status === 'active' ? 'Connection provisioned.' : 'Provisioning failed.');
    }
}
