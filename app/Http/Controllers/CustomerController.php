<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\HealthObservation;
use App\Models\NetworkOperationLog;
use App\Models\OutageIncident;
use App\Models\InternetPackage;
use App\Models\Router;
use App\Services\GisNetworkMapSettings;
use App\Services\Network\CreateMonthlyCustomer;
use App\Services\Network\MacVendorLookupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class CustomerController extends Controller
{
    public function index()
    {
        return view('customers.index', ['customers' => Customer::where('tenant_id', Auth::user()->tenant_id)->with(['connections.router', 'connections.networkAccount', 'connections.discoveredNetworkResource'])->latest()->get()]);
    }

    public function create(GisNetworkMapSettings $gisSettings)
    {
        $tenant = Auth::user()->tenant_id;

        return view('customers.form', [
            'customer' => new Customer(['status' => 'active']),
            'gisSettings' => $gisSettings->get($tenant),
            'routers' => Router::where('tenant_id', $tenant)->orderBy('name')->get(),
            'packages' => InternetPackage::where('tenant_id', $tenant)->where('status', 'active')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, CreateMonthlyCustomer $creator)
    {
        $data = $this->validatedCustomerData($request, true);
        $creator->handle(Auth::user(), $data);

        return redirect()->route('customers.index')->with('status', 'Customer created.');
    }

    public function show(Customer $customer, MacVendorLookupService $vendors)
    {
        Gate::authorize('view', $customer);
        $customer->load(['connections.internetPackage', 'connections.router', 'connections.networkAccount', 'connections.discoveredNetworkResource', 'connections.deviceObservations', 'invoices', 'payments', 'messageLogs']);
        $health = HealthObservation::where('tenant_id', Auth::user()->tenant_id)->where('subject_type', 'connection')->whereIn('subject_id', $customer->connections->pluck('id'))->latest('observed_at')->get()->unique('subject_id')->keyBy('subject_id');
        $customer->connections->each(fn ($connection) => $connection->setRelation('networkHealth', $health->get($connection->id)));
        $discovery = $customer->connections->flatMap(fn ($connection) => $connection->discoveredNetworkResources()->latest('last_seen_at')->get()->take(1))->keyBy('customer_connection_id');
        $customer->connections->each(function ($connection) use ($discovery) {
            $connection->setRelation('networkDiscovery', $discovery->get($connection->id));
        });
        $customer->connections->each(function ($connection) use ($vendors) {
            $connection->deviceObservations->each(function ($observation) use ($vendors) {
                $observation->setRelation('macVendor', $vendors->lookup($observation->mac_address));
            });
        });
        $recentIncidents = OutageIncident::where('tenant_id', Auth::user()->tenant_id)->whereIn('status', ['detected', 'acknowledged', 'resolved'])->whereHas('affectedConnections', fn ($query) => $query->whereIn('customer_connections.id', $customer->connections->pluck('id')))->with('router')->latest('detected_at')->limit(5)->get();
        $recentLogs = NetworkOperationLog::where('tenant_id', Auth::user()->tenant_id)->whereIn('customer_connection_id', $customer->connections->pluck('id'))->with('customerConnection')->latest('created_at')->limit(10)->get();
        $trafficConnections = $customer->connections
            ->filter(fn ($connection) => $connection->tenant_id === Auth::user()->tenant_id && ($connection->networkAccount !== null || ! empty($connection->metadata['network_identity'])))
            ->map(fn ($connection) => [
                'id' => $connection->id,
                'code' => $connection->connection_code,
                'router_id' => $connection->router_id,
                'identity' => strtolower(trim((string) ($connection->metadata['network_identity'] ?? $connection->networkAccount?->username))),
            ])
            ->values()
            ->all();

        return view('customers.show', compact('customer', 'recentLogs', 'recentIncidents', 'trafficConnections'));
    }

    public function edit(Customer $customer, GisNetworkMapSettings $gisSettings)
    {
        Gate::authorize('update', $customer);

        return view('customers.form', ['customer' => $customer, 'gisSettings' => $gisSettings->get(Auth::user()->tenant_id)]);
    }

    public function update(Request $request, Customer $customer)
    {
        Gate::authorize('update', $customer);
        $data = $this->validatedCustomerData($request, false);
        if (array_key_exists('latitude', $data) || array_key_exists('longitude', $data)) {
            $data['location_updated_at'] = now();
        }
        $customer->update($data);

        return redirect()->route('customers.show', $customer)->with('status', 'Customer updated.');
    }

    private function validatedCustomerData(Request $request, bool $creating): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'notes' => ['nullable', 'string'],
            'status' => ['required', 'in:active,inactive'],
        ];

        if ($creating) {
            $rules += [
                'router_id' => ['required', 'integer'],
                'connection_mode' => ['required', 'in:simple_queue,hotspot'],
                'network_identity' => ['required', 'string', 'max:255'],
                'internet_package_id' => ['nullable', 'integer'],
            ];
        }

        $data = $request->validate($rules);
        if (array_key_exists('latitude', $data) || array_key_exists('longitude', $data)) {
            $data['location_updated_at'] = now();
        }

        return $data;
    }

    public function destroy(Customer $customer)
    {
        Gate::authorize('delete', $customer);
        abort_if($customer->connections()->exists(), 422, 'Customers with connections cannot be deleted.');
        $customer->delete();

        return redirect()->route('customers.index')->with('status', 'Customer deactivated.');
    }
}
