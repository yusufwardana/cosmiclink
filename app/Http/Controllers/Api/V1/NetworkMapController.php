<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\HealthObservation;
use App\Models\Router;
use App\Models\Customer;
use App\Services\GisNetworkMapSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class NetworkMapController extends Controller
{
    public function index(GisNetworkMapSettings $settings): JsonResponse
    {
        $tenantId = Auth::user()->tenant_id;
        $allRouters = Router::query()
            ->where('tenant_id', $tenantId)
            ->get(['id', 'name', 'latitude', 'longitude', 'status', 'location_updated_at']);

        $routers = $allRouters
            ->whereNotNull('latitude')
            ->whereNotNull('longitude');
        $unlocatedRouters = $allRouters
            ->filter(fn (Router $router) => $router->latitude === null || $router->longitude === null)
            ->values();

        $observations = HealthObservation::query()
            ->where('tenant_id', $tenantId)
            ->where('subject_type', 'router')
            ->whereIn('subject_id', $allRouters->pluck('id'))
            ->latest('observed_at')
            ->get()
            ->unique('subject_id')
            ->keyBy('subject_id');

        $mapRouter = function (Router $router) use ($observations) {
            $observation = $observations->get($router->id);
            $metadata = $observation?->metadata ?? [];

            return [
                'id' => $router->id,
                'name' => $router->name,
                'latitude' => $router->latitude,
                'longitude' => $router->longitude,
                'status' => $router->status,
                'monitoring_state' => $observation?->health_state ?? 'unknown',
                'online' => $observation?->online,
                'routeros_version' => $metadata['version'] ?? null,
                'board_name' => $metadata['board'] ?? null,
                'cpu_load' => $metadata['cpu_load_percent'] ?? null,
                'memory' => $metadata['memory_used_percent'] ?? null,
                'observed_at' => $observation?->observed_at?->toIso8601String(),
                'location_updated_at' => $router->location_updated_at?->toIso8601String(),
            ];
        };

        $allCustomers = Customer::query()
            ->where('tenant_id', $tenantId)
            ->with(['connections.router', 'connections.networkAccount', 'connections.discoveredNetworkResource'])
            ->get();

        $mapCustomer = function (Customer $customer): array {
            $connection = $customer->connections->first();
            $resource = $connection?->discoveredNetworkResource;

            return [
                'id' => $customer->id,
                'name' => $customer->name,
                'code' => $customer->customer_code,
                'status' => $customer->status,
                'latitude' => $customer->latitude,
                'longitude' => $customer->longitude,
                'connection_mode' => $connection?->metadata['connection_mode'] ?? null,
                'network_identity' => $connection?->metadata['network_identity'] ?? $resource?->normalized_data['target'] ?? null,
                'router' => $connection?->router?->name,
                'management_state' => $resource?->management_state,
            ];
        };

        $customers = $allCustomers->filter(fn (Customer $customer) => $customer->latitude !== null && $customer->longitude !== null);
        $unlocatedCustomers = $allCustomers->filter(fn (Customer $customer) => $customer->latitude === null || $customer->longitude === null);

        return response()->json(['data' => [
            'routers' => $routers->map($mapRouter)->values(),
            'unlocated_routers' => $unlocatedRouters->map($mapRouter)->values(),
            'customers' => $customers->map($mapCustomer)->values(),
            'unlocated_customers' => $unlocatedCustomers->map($mapCustomer)->values(),
            'settings' => $settings->get($tenantId),
        ]]);
    }

    public function updateLocation(Request $request, Router $router): JsonResponse
    {
        Gate::authorize('update', $router);

        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $router->update([
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
            'location_updated_at' => now(),
        ]);

        return response()->json(['data' => [
            'id' => $router->id,
            'latitude' => $router->latitude,
            'longitude' => $router->longitude,
            'location_updated_at' => $router->location_updated_at?->toIso8601String(),
        ]]);
    }

    public function updateCustomerLocation(Request $request, Customer $customer): JsonResponse
    {
        Gate::authorize('update', $customer);

        $data = $request->validate([
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $customer->update([
            'latitude' => $data['latitude'],
            'longitude' => $data['longitude'],
            'location_updated_at' => now(),
        ]);

        return response()->json(['data' => [
            'id' => $customer->id,
            'latitude' => $customer->latitude,
            'longitude' => $customer->longitude,
            'location_updated_at' => $customer->location_updated_at?->toIso8601String(),
        ]]);
    }
}