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
