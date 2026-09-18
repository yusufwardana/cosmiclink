<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\HealthObservation;
use App\Models\NetworkOperationLog;
use App\Models\OutageIncident;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class CustomerController extends Controller
{
    public function index()
    {
        return view('customers.index', ['customers' => Customer::where('tenant_id', Auth::user()->tenant_id)->latest()->get()]);
    }

    public function create()
    {
        return view('customers.form', ['customer' => new Customer(['status' => 'active'])]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'phone' => ['nullable', 'string', 'max:50'], 'email' => ['nullable', 'email', 'max:255'], 'address' => ['nullable', 'string'], 'notes' => ['nullable', 'string'], 'status' => ['required', 'in:active,inactive']]);
        Customer::create($data + ['tenant_id' => Auth::user()->tenant_id]);

        return redirect()->route('customers.index')->with('status', 'Customer created.');
    }

    public function show(Customer $customer)
    {
        Gate::authorize('view', $customer);
        $customer->load(['connections.internetPackage', 'connections.router', 'connections.networkAccount', 'invoices', 'payments', 'messageLogs']);
        $health = HealthObservation::where('tenant_id', Auth::user()->tenant_id)->where('subject_type', 'connection')->whereIn('subject_id', $customer->connections->pluck('id'))->latest('observed_at')->get()->unique('subject_id')->keyBy('subject_id');
        $customer->connections->each(fn ($connection) => $connection->setRelation('networkHealth', $health->get($connection->id)));
        $discovery = $customer->connections->flatMap(fn ($connection) => $connection->discoveredNetworkResources()->latest('last_seen_at')->get()->take(1))->keyBy('customer_connection_id');
        $customer->connections->each(function ($connection) use ($discovery) {
            $connection->setRelation('networkDiscovery', $discovery->get($connection->id));
        });
        $recentIncidents = OutageIncident::where('tenant_id', Auth::user()->tenant_id)->whereIn('status', ['detected', 'acknowledged', 'resolved'])->whereHas('affectedConnections', fn ($query) => $query->whereIn('customer_connections.id', $customer->connections->pluck('id')))->with('router')->latest('detected_at')->limit(5)->get();
        $recentLogs = NetworkOperationLog::where('tenant_id', Auth::user()->tenant_id)->whereIn('customer_connection_id', $customer->connections->pluck('id'))->with('customerConnection')->latest('created_at')->limit(10)->get();

        return view('customers.show', compact('customer', 'recentLogs', 'recentIncidents'));
    }

    public function edit(Customer $customer)
    {
        Gate::authorize('update', $customer);

        return view('customers.form', compact('customer'));
    }

    public function update(Request $request, Customer $customer)
    {
        Gate::authorize('update', $customer);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'phone' => ['nullable', 'string', 'max:50'], 'email' => ['nullable', 'email', 'max:255'], 'address' => ['nullable', 'string'], 'notes' => ['nullable', 'string'], 'status' => ['required', 'in:active,inactive']]);
        $customer->update($data);

        return redirect()->route('customers.show', $customer)->with('status', 'Customer updated.');
    }

    public function destroy(Customer $customer)
    {
        Gate::authorize('delete', $customer);
        abort_if($customer->connections()->exists(), 422, 'Customers with connections cannot be deleted.');
        $customer->delete();

        return redirect()->route('customers.index')->with('status', 'Customer deactivated.');
    }
}
