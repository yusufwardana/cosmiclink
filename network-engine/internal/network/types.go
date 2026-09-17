package network

import "context"

type Request struct {
	OperationID    string         `json:"operation_id"`
	IdempotencyKey string         `json:"idempotency_key"`
	Operation      string         `json:"operation"`
	TenantRef      string         `json:"tenant_ref"`
	RouterRef      string         `json:"router_ref"`
	AccountRef     string         `json:"account_ref,omitempty"`
	Parameters     map[string]any `json:"parameters"`
}

type Result struct {
	Success     bool           `json:"success"`
	OperationID string         `json:"operation_id"`
	Provider    string         `json:"provider"`
	Code        string         `json:"code"`
	Message     string         `json:"message"`
	Data        map[string]any `json:"data,omitempty"`
}

type Provider interface {
	Name() string
	TestConnection(context.Context, Request) Result
	CreateAccount(context.Context, Request) Result
	EnableAccount(context.Context, Request) Result
	DisableAccount(context.Context, Request) Result
	ChangeProfile(context.Context, Request) Result
	DisconnectSession(context.Context, Request) Result
}

type DiscoveryRequest struct {
	TenantRef string `json:"tenant_ref"`
	RouterRef string `json:"router_ref"`
}

type DiscoveryResult struct {
	Success      bool              `json:"success"`
	Provider     string            `json:"provider"`
	RouterRef    string            `json:"router_ref"`
	DiscoveredAt string            `json:"discovered_at,omitempty"`
	Snapshot     DiscoverySnapshot `json:"snapshot,omitempty"`
	Code         string            `json:"code"`
	Message      string            `json:"message"`
}

type DiscoverySnapshot struct {
	Device       map[string]any   `json:"device"`
	Profiles     []map[string]any `json:"profiles"`
	Accounts     []map[string]any `json:"accounts"`
	AddressPools []map[string]any `json:"address_pools"`
	Queues       []map[string]any `json:"queues"`
}

type DiscoveryProvider interface {
	Name() string
	Discover(context.Context, DiscoveryRequest) DiscoveryResult
}

type MutationCounter interface {
	MutationCount() int
}
