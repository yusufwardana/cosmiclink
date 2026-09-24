<?php

namespace App\Services\Network;

use App\Models\Customer;
use App\Models\CustomerConnection;
use App\Models\InternetPackage;
use App\Models\Router;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateMonthlyCustomer
{
    public function handle(User $user, array $data): Customer
    {
        $router = Router::query()->where('tenant_id', $user->tenant_id)->find($data['router_id']);
        abort_unless($router, 403, 'The selected router is not available to this tenant.');

        $package = null;
        if (! empty($data['internet_package_id'])) {
            $package = InternetPackage::query()
                ->where('tenant_id', $user->tenant_id)
                ->where('status', 'active')
                ->find($data['internet_package_id']);
            abort_unless($package, 403, 'The selected package is not available to this tenant.');
        }

        $mode = $data['connection_mode'];
        $identity = trim((string) $data['network_identity']);
        $this->validateIdentity($mode, $identity);
        $accessMode = $mode === 'simple_queue' ? 'static_ip' : $mode;
        $networkMechanism = $mode === 'simple_queue' ? 'simple_queue' : $mode;

        $existingHotspot = $mode === 'hotspot' ? CustomerConnection::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('router_id', $router->id)
            ->where('metadata->connection_mode', 'hotspot')
            ->whereRaw("LOWER(metadata->>'network_identity') = ?", [strtolower($identity)])
            ->whereHas('customer')
            ->with('customer')
            ->first() : null;

        if ($existingHotspot) {

            throw ValidationException::withMessages([
                'network_identity' => sprintf('Hotspot account %s is already linked to %s.', $identity, $existingHotspot->customer?->name ?? 'another customer'),
            ]);
        }

        return DB::transaction(function () use ($user, $data, $router, $package, $mode, $identity, $accessMode, $networkMechanism) {
            $customer = Customer::create([
                'tenant_id' => $user->tenant_id,
                'name' => $data['name'],
                'phone' => $data['phone'] ?? null,
                'email' => $data['email'] ?? null,
                'address' => $data['address'] ?? null,
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'location_updated_at' => array_key_exists('latitude', $data) || array_key_exists('longitude', $data) ? now() : null,
                'notes' => $data['notes'] ?? null,
                'status' => $data['status'],
            ]);

            CustomerConnection::create([
                'tenant_id' => $user->tenant_id,
                'customer_id' => $customer->id,
                'internet_package_id' => $package?->id,
                'router_id' => $router->id,
                'status' => 'active',
                'metadata' => [
                    'connection_mode' => $mode,
                    'access_mode' => $accessMode,
                    'network_mechanism' => $networkMechanism,
                    'network_identity' => $identity,
                    'created_monthly' => true,
                ] + (array) ($data['metadata'] ?? []),
            ]);

            return $customer->fresh();
        });
    }

    private function validateIdentity(string $mode, string $identity): void
    {
        if ($mode === 'hotspot') {
            if ($identity === '') {
                throw ValidationException::withMessages(['network_identity' => 'Hotspot username is required.']);
            }

            return;
        }

        $slash = strrpos($identity, '/');
        $valid = $identity !== '' && ! str_contains($identity, ',') && str_ends_with($identity, '/32')
            && is_int($slash) && filter_var(substr($identity, 0, $slash), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;

        if (! $valid) {
            throw ValidationException::withMessages(['network_identity' => 'Simple Queue identity must be an individual IPv4 target such as 10.10.12.50/32.']);
        }
    }
}