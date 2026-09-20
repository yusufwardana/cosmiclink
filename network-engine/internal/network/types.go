package network

import "context"

type Request struct {
	OperationID       string         `json:"operation_id"`
	IdempotencyKey    string         `json:"idempotency_key"`
	Operation         string         `json:"operation"`
	TenantRef         string         `json:"tenant_ref"`
	RouterRef         string         `json:"router_ref"`
	AgentRef          string         `json:"agent_ref,omitempty"`
	InstallationID    string         `json:"installation_id,omitempty"`
	CredentialRef     string         `json:"credential_ref,omitempty"`
	CredentialPurpose string         `json:"credential_purpose,omitempty"`
	CredentialVersion int            `json:"credential_version,omitempty"`
	TargetIdentityRef string         `json:"target_identity_ref,omitempty"`
	FencingRef        string         `json:"fencing_ref,omitempty"`
	AccountRef        string         `json:"account_ref,omitempty"`
	Parameters        map[string]any `json:"parameters"`
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
	TenantRef             string               `json:"tenant_ref"`
	RouterRef             string               `json:"router_ref"`
	AgentRef              string               `json:"agent_ref,omitempty"`
	InstallationID        string               `json:"installation_id,omitempty"`
	CredentialRef         string               `json:"credential_ref,omitempty"`
	CredentialPurpose     string               `json:"credential_purpose,omitempty"`
	CredentialVersion     int                  `json:"credential_version,omitempty"`
	Host                  string               `json:"host,omitempty"`
	Port                  int                  `json:"port,omitempty"`
	Transport             string               `json:"transport,omitempty"`
	ConnectTimeoutSeconds int                  `json:"connect_timeout_seconds,omitempty"`
	ReadTimeoutSeconds    int                  `json:"read_timeout_seconds,omitempty"`
	InsecureTLS           bool                 `json:"insecure_tls,omitempty"`
	Connection            *DiscoveryConnection `json:"connection,omitempty"`
}

// DiscoveryConnection exists only for the authenticated Laravel-to-Go request.
// It must never be copied into a DiscoveryResult, log event, or persisted model.
type DiscoveryConnection struct {
	Host                  string `json:"host"`
	Port                  int    `json:"port"`
	Username              string `json:"username"`
	Password              string `json:"password"`
	Transport             string `json:"transport"`
	ConnectTimeoutSeconds int    `json:"connect_timeout_seconds"`
	ReadTimeoutSeconds    int    `json:"read_timeout_seconds"`
	InsecureTLS           bool   `json:"insecure_tls,omitempty"`
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
	Device   map[string]any   `json:"device"`
	Profiles []map[string]any `json:"profiles"`
	Accounts []map[string]any `json:"accounts"`
	// ActiveSessions is lifecycle context, not an adoption candidate. It is
	// deliberately always serialised: `null` means the provider did not enumerate
	// sessions (no inference allowed), while `[]` means the router was enumerated
	// and reported none (recorded sessions may be superseded).
	ActiveSessions []map[string]any `json:"active_sessions"`
	AddressPools   []map[string]any `json:"address_pools"`
	Queues         []map[string]any `json:"queues"`
}

type DiscoveryProvider interface {
	Name() string
	Discover(context.Context, DiscoveryRequest) DiscoveryResult
}

type MutationCounter interface {
	MutationCount() int
}
