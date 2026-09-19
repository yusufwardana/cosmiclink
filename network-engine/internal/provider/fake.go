package provider

import (
	"context"
	"sync"

	"cosmiclink/network-engine/internal/network"
)

type FakeProvider struct {
	mu        sync.Mutex
	accounts  map[string]account
	mutations int
}

type account struct {
	Profile string
	Enabled bool
}

func NewFakeProvider() *FakeProvider {
	return &FakeProvider{accounts: make(map[string]account)}
}

func (p *FakeProvider) Name() string {
	return "fake"
}

func (p *FakeProvider) TestConnection(_ context.Context, request network.Request) network.Result {
	if request.RouterRef == "unavailable" {
		return p.failure(request, "ROUTER_UNAVAILABLE", "Network router unavailable")
	}

	return p.success(request, "CONNECTION_OK", "Network connection test successful", nil)
}

func (p *FakeProvider) CreateAccount(_ context.Context, request network.Request) network.Result {
	p.mu.Lock()
	defer p.mu.Unlock()
	p.mutations++
	key := accountKey(request)
	if _, exists := p.accounts[key]; exists {
		return p.failure(request, "ACCOUNT_ALREADY_EXISTS", "Network account already exists")
	}
	profile, _ := request.Parameters["profile"].(string)
	p.accounts[key] = account{Profile: profile, Enabled: true}

	return p.success(request, "ACCOUNT_CREATED", "Network account created", map[string]any{"username": request.AccountRef, "profile": profile, "enabled": true})
}

func (p *FakeProvider) EnableAccount(_ context.Context, request network.Request) network.Result {
	p.mu.Lock()
	defer p.mu.Unlock()
	p.mutations++
	account, found := p.accounts[accountKey(request)]
	if !found {
		return p.failure(request, "ACCOUNT_NOT_FOUND", "Network account not found")
	}
	if account.Enabled {
		return p.success(request, "ACCOUNT_ALREADY_ENABLED", "Network account already enabled", map[string]any{"username": request.AccountRef})
	}
	account.Enabled = true
	p.accounts[accountKey(request)] = account

	return p.success(request, "ACCOUNT_ENABLED", "Network account enabled", map[string]any{"username": request.AccountRef})
}

func (p *FakeProvider) DisableAccount(_ context.Context, request network.Request) network.Result {
	p.mu.Lock()
	defer p.mu.Unlock()
	p.mutations++
	account, found := p.accounts[accountKey(request)]
	if !found {
		return p.failure(request, "ACCOUNT_NOT_FOUND", "Network account not found")
	}
	if !account.Enabled {
		return p.success(request, "ACCOUNT_ALREADY_DISABLED", "Network account already disabled", map[string]any{"username": request.AccountRef})
	}
	account.Enabled = false
	p.accounts[accountKey(request)] = account

	return p.success(request, "ACCOUNT_DISABLED", "Network account disabled", map[string]any{"username": request.AccountRef})
}

func (p *FakeProvider) ChangeProfile(_ context.Context, request network.Request) network.Result {
	p.mu.Lock()
	defer p.mu.Unlock()
	p.mutations++
	account, found := p.accounts[accountKey(request)]
	if !found {
		return p.failure(request, "ACCOUNT_NOT_FOUND", "Network account not found")
	}
	profile, _ := request.Parameters["profile"].(string)
	account.Profile = profile
	p.accounts[accountKey(request)] = account

	return p.success(request, "PROFILE_CHANGED", "Network account profile changed", map[string]any{"username": request.AccountRef, "profile": profile})
}

func (p *FakeProvider) DisconnectSession(_ context.Context, request network.Request) network.Result {
	p.mu.Lock()
	defer p.mu.Unlock()
	p.mutations++
	if _, found := p.accounts[accountKey(request)]; !found {
		return p.failure(request, "ACCOUNT_NOT_FOUND", "Network account not found")
	}

	return p.success(request, "SESSION_DISCONNECTED", "Network account session disconnected", map[string]any{"username": request.AccountRef})
}

func (p *FakeProvider) MutationCount() int {
	p.mu.Lock()
	defer p.mu.Unlock()

	return p.mutations
}

// SupportedOperations declares that the simulation serves every approved Phase
// 6I write. Those writes still mutate only this in-memory simulation: the fake
// never contacts a router, and it remains the only provider that can be
// registered as a mutation provider in this build.
func (p *FakeProvider) SupportedOperations() []network.MutationOperation {
	return network.MutationOperations()
}

var (
	_ network.Provider         = (*FakeProvider)(nil)
	_ network.MutationProvider = (*FakeProvider)(nil)
	_ network.MutationCounter  = (*FakeProvider)(nil)
)

func (p *FakeProvider) success(request network.Request, code, message string, data map[string]any) network.Result {
	return network.Result{Success: true, OperationID: request.OperationID, Provider: p.Name(), Code: code, Message: message, Data: data}
}

func (p *FakeProvider) failure(request network.Request, code, message string) network.Result {
	return network.Result{Success: false, OperationID: request.OperationID, Provider: p.Name(), Code: code, Message: message}
}

func accountKey(request network.Request) string {
	return request.TenantRef + ":" + request.RouterRef + ":" + request.AccountRef
}
