package provider

import (
	"context"

	"cosmiclink/network-engine/internal/network"
)

// FakeDiscoveryProvider returns simulated, deterministic, secret-free data only.
type FakeDiscoveryProvider struct{}

func NewFakeDiscoveryProvider() *FakeDiscoveryProvider { return &FakeDiscoveryProvider{} }
func (p *FakeDiscoveryProvider) Name() string          { return "fake" }

func (p *FakeDiscoveryProvider) Discover(_ context.Context, request network.DiscoveryRequest) network.DiscoveryResult {
	if request.RouterRef == "unavailable" {
		return network.DiscoveryResult{Success: false, Provider: p.Name(), RouterRef: request.RouterRef, Code: "ROUTER_UNAVAILABLE", Message: "Network router unavailable"}
	}
	if request.RouterRef == "invalid" {
		return network.DiscoveryResult{Success: false, Provider: p.Name(), RouterRef: request.RouterRef, Code: "ROUTER_NOT_FOUND", Message: "Network router not found"}
	}
	return network.DiscoveryResult{Success: true, Provider: p.Name(), RouterRef: request.RouterRef, DiscoveredAt: "2026-09-17T00:00:00Z", Code: "DISCOVERY_COMPLETE", Message: "Read-only simulated network discovery completed", Snapshot: network.DiscoverySnapshot{
		Device:       map[string]any{"name": "CORE-01", "model": "SIMULATED-ROUTER", "platform": "fake", "management_mode": "read_only"},
		Profiles:     []map[string]any{{"external_ref": "profile-10m", "name": "10M"}, {"external_ref": "profile-20m", "name": "20M"}, {"external_ref": "profile-50m", "name": "50M"}},
		Accounts:     []map[string]any{{"external_ref": "existing-user-001", "username": "existing-user-001", "profile": "10M", "enabled": true}, {"external_ref": "existing-user-002", "username": "existing-user-002", "profile": "20M", "enabled": true}, {"external_ref": "existing-user-003", "username": "existing-user-003", "profile": "50M", "enabled": false}},
		AddressPools: []map[string]any{{"external_ref": "pppoe-pool", "name": "pppoe-pool", "ranges": "10.10.0.2-10.10.0.254"}},
		Queues:       []map[string]any{{"external_ref": "queue-existing-user-001", "name": "existing-user-001", "target": "existing-user-001", "max_limit": "10M/10M"}},
		// Simulated session evidence uses `sim:` references on purpose: a fake
		// session must never be addressable as a real RouterOS `.id` target.
		ActiveSessions: []map[string]any{
			{"external_ref": "sim:*1001", "name": "existing-user-001", "service": "pppoe", "address": "10.10.0.11", "caller_id": "", "uptime": "1h2m3s"},
			{"external_ref": "sim:*1002", "name": "existing-user-003", "service": "pppoe", "address": "10.10.0.13", "caller_id": "", "uptime": "4d-02:03:04"},
		},
	}}
}
