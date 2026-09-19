<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReconciliationEvidence extends Model
{
    protected $table = 'network_reconciliation_evidence';

    protected $fillable = [
        'tenant_id',
        'router_id',
        'adopted_resource_id',
        'compared_resource_id',
        'discovery_snapshot_id',
        'network_account_id',
        'customer_connection_id',
        'outcome',
        'discovered_at',
        'reconciled_at',
        'adopted_fingerprint',
        'compared_fingerprint',
        'relationship_fingerprint',
    ];

    protected $casts = [
        'discovered_at' => 'datetime',
        'reconciled_at' => 'datetime',
    ];

    public function adoptedResource()
    {
        return $this->belongsTo(DiscoveredNetworkResource::class, 'adopted_resource_id');
    }

    public function comparedResource()
    {
        return $this->belongsTo(DiscoveredNetworkResource::class, 'compared_resource_id');
    }

    public function discoverySnapshot()
    {
        return $this->belongsTo(NetworkDiscoverySnapshot::class);
    }

    public function networkAccount()
    {
        return $this->belongsTo(NetworkAccount::class);
    }

    public function customerConnection()
    {
        return $this->belongsTo(CustomerConnection::class);
    }

    public function isCurrentlyApplicable(): bool
    {
        if ($this->outcome !== 'MATCHED' || ! $this->discovered_at || ! $this->discovery_snapshot_id) {
            return false;
        }

        if ($this->discovered_at->lt(now()->subSeconds((int) config('network.discovery_freshness_seconds', 86400)))) {
            return false;
        }

        $snapshot = $this->discoverySnapshot;
        $resource = $this->adoptedResource;
        $comparedResource = $this->comparedResource;

        if (! $snapshot || $snapshot->status !== 'success' || ! $resource || ! $comparedResource) {
            return false;
        }

        $latestSnapshotId = NetworkDiscoverySnapshot::query()
            ->where('tenant_id', $this->tenant_id)
            ->where('router_id', $this->router_id)
            ->where('status', 'success')
            ->orderByDesc('discovered_at')
            ->orderByDesc('id')
            ->value('id');

        if ($latestSnapshotId !== $this->discovery_snapshot_id) {
            return false;
        }

        return hash_equals(
            $this->relationship_fingerprint,
            static::relationshipFingerprint($resource, $comparedResource, $this->network_account_id, $this->customer_connection_id)
        );
    }

    public static function relationshipFingerprint(DiscoveredNetworkResource $adoptedResource, ?DiscoveredNetworkResource $comparedResource, ?int $networkAccountId, ?int $customerConnectionId): string
    {
        $networkAccount = $networkAccountId ? NetworkAccount::query()->find($networkAccountId) : null;
        $customerConnection = $customerConnectionId ? CustomerConnection::query()->find($customerConnectionId) : null;

        return hash('sha256', json_encode([
            'adopted_resource_id' => $adoptedResource->id,
            'adopted_fingerprint' => $adoptedResource->fingerprint,
            'compared_resource_id' => $comparedResource?->id,
            'compared_fingerprint' => $comparedResource?->fingerprint,
            'network_account' => [
                'id' => $networkAccount?->id,
                'tenant_id' => $networkAccount?->tenant_id,
                'router_id' => $networkAccount?->router_id,
                'username' => $networkAccount?->username,
                'profile' => $networkAccount?->profile,
                'status' => $networkAccount?->status,
            ],
            'customer_connection' => [
                'id' => $customerConnection?->id,
                'tenant_id' => $customerConnection?->tenant_id,
                'router_id' => $customerConnection?->router_id,
                'network_account_id' => $customerConnection?->network_account_id,
                'status' => $customerConnection?->status,
            ],
        ], JSON_THROW_ON_ERROR));
    }
}
