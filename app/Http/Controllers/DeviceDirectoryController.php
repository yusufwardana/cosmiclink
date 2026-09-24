<?php

namespace App\Http\Controllers;

use App\Models\DeviceObservation;
use App\Services\Network\MacVendorLookupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class DeviceDirectoryController extends Controller
{
    public function index(Request $request, MacVendorLookupService $vendors)
    {
        $tenantId = Auth::user()->tenant_id;
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'scope' => ['nullable', 'in:all,linked,unmapped'],
            'source' => ['nullable', 'in:all,arp,arp_dhcp,hotspot,hotspot_dhcp'],
            'per_page' => ['nullable', 'integer', 'in:25,50,100'],
        ]);
        $search = trim((string) ($filters['q'] ?? ''));
        $scope = $filters['scope'] ?? 'all';
        $source = $filters['source'] ?? 'all';
        $perPage = (int) ($filters['per_page'] ?? 25);

        $query = DeviceObservation::query()->where('tenant_id', $tenantId)->with(['customerConnection.customer', 'customerConnection.router', 'router'])->latest('last_seen_at');
        if ($scope === 'linked') $query->whereNotNull('customer_connection_id');
        if ($scope === 'unmapped') $query->whereNull('customer_connection_id');
        if ($source !== 'all') {
            $query->where(function ($q) use ($source) {
                $dhcp = "jsonb_exists(metadata::jsonb, 'dhcp_hostname')";
                if ($source === 'arp') $q->where('source', 'arp')->whereRaw("NOT ($dhcp)");
                elseif ($source === 'arp_dhcp') $q->where('source', 'arp')->whereRaw($dhcp);
                elseif ($source === 'hotspot') $q->where('source', 'hotspot_active')->whereRaw("NOT ($dhcp)");
                else $q->where('source', 'hotspot_active')->whereRaw($dhcp);
            });
        }
        if ($search !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
            $query->where(function ($q) use ($like) {
                $q->where('ip_address', 'ilike', $like)->orWhere('mac_address', 'ilike', $like)->orWhere('network_identity', 'ilike', $like)->orWhereRaw("metadata->>'dhcp_hostname' ILIKE ?", [$like])->orWhereHas('customerConnection.customer', fn ($customer) => $customer->where('name', 'ilike', $like));
            });
        }
        $observations = $query->paginate($perPage)->appends($request->query());
        $observations->setCollection($observations->getCollection()->map(function (DeviceObservation $observation) use ($vendors) {
            $connection = $observation->customerConnection;
            return ['observation' => $observation, 'vendor' => $vendors->lookup($observation->mac_address)?->vendor, 'device_name' => $observation->metadata['dhcp_hostname'] ?? 'Unknown device', 'customer' => $connection?->customer, 'access_mode' => $connection?->access_mode_label, 'source' => $this->sourceLabel($observation)];
        }));

        return view('network.devices.index', compact('observations', 'search', 'scope', 'source', 'perPage'));
    }

    public function show(DeviceObservation $observation, MacVendorLookupService $vendors)
    {
        abort_unless($observation->tenant_id === Auth::user()->tenant_id, 404);
        $observation->load(['customerConnection.customer', 'customerConnection.router', 'router']);
        return view('network.devices.show', ['observation' => $observation, 'vendor' => $vendors->lookup($observation->mac_address)?->vendor, 'deviceName' => $observation->metadata['dhcp_hostname'] ?? 'Unknown device', 'source' => $this->sourceLabel($observation)]);
    }

    private function sourceLabel(DeviceObservation $observation): string
    {
        $dhcp = array_key_exists('dhcp_hostname', (array) $observation->metadata) || array_key_exists('dhcp_client_id', (array) $observation->metadata);
        return match ([$observation->source, $dhcp]) { ['arp', true] => 'ARP + DHCP', ['arp', false] => 'ARP', ['hotspot_active', true] => 'HOTSPOT + DHCP', ['hotspot_active', false] => 'HOTSPOT', default => strtoupper((string) $observation->source) };
    }
}