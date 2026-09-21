package provider

import (
	"context"
	"errors"
	"reflect"
	"testing"

	"cosmiclink/network-engine/internal/credentials"
	"cosmiclink/network-engine/internal/network"
)

func TestNewMutationProviderSelectsFake(t *testing.T) {
	selected, err := NewMutationProvider(MutationProviderFake)
	if err != nil {
		t.Fatalf("fake mutation provider selection failed: %v", err)
	}
	if selected == nil || selected.Name() != "fake" {
		t.Fatalf("selected provider = %#v", selected)
	}
	if _, ok := selected.(*FakeProvider); !ok {
		t.Fatalf("fake selection must be the existing *FakeProvider, got %T", selected)
	}
	if counter, ok := selected.(network.MutationCounter); !ok || counter.MutationCount() != 0 {
		t.Fatalf("selected provider must keep the fake mutation counter, got %#v", selected)
	}
}

// Selecting the real provider before a safe write provider exists must fail
// loudly. The fake must never answer a routeros request.
func TestNewMutationProviderRejectsRouterOSUntilASafeProviderExists(t *testing.T) {
	selected, err := NewMutationProvider(MutationProviderRouterOS)
	if !errors.Is(err, ErrRealMutationProviderUnavailable) {
		t.Fatalf("err = %v, want ErrRealMutationProviderUnavailable", err)
	}
	if selected != nil {
		t.Fatalf("routeros selection must return no provider, got %#v", selected)
	}
}

func TestNewRouterOSMutationProviderRequiresOperatorResolverAndUsesTypedTransport(t *testing.T) {
	transport := &recordingMutationTransport{}
	resolver := newOperatorResolverForTest()
	readTransport := &sequencedMutationReadTransport{responses: []map[string]string{{".id": "*7", "name": "alice", "disabled": "true"}, {".id": "*7", "name": "alice", "disabled": "false"}}}
	selected, err := NewRouterOSMutationProvider(resolver, resolver, func() RouterOSMutationTransport { return transport }, func() RouterOSTransport { return readTransport })
	if err != nil {
		t.Fatalf("NewRouterOSMutationProvider failed: %v", err)
	}

	result := selected.EnableAccount(context.Background(), network.Request{
		ProtocolVersion: "routeros-mutation.v1",
		OperationID:     "op-enable",
		IdempotencyKey:  "idem-enable",
		RequestDigest:   "digest-enable",
		ExecutionID:     "exec-enable",
		TenantRef:       "tenant-1", RouterRef: "router-1", AgentRef: "agent-1", InstallationID: "install-1", Operation: "ENABLE_PPPOE",
		CredentialRef: "operator-ref", CredentialPurpose: "OPERATOR", CredentialVersion: 1,
		ObserverCredentialRef: "observer-ref", ObserverPurpose: "OBSERVER", ObserverCredentialVersion: 1,
		TargetIdentityRef: "*7", AccountRef: "alice", Host: "router.test", Port: 8728, Transport: "api", ConnectTimeoutSeconds: 1, ReadTimeoutSeconds: 1, Parameters: map[string]any{},
	})
	if !result.Success || result.Code != "ACCOUNT_ENABLED" {
		t.Fatalf("result = %#v", result)
	}
	if len(transport.writes) != 1 || transport.writes[0].Sentence() != "/ppp/secret/set =.id=*7 =disabled=no" {
		t.Fatalf("writes = %#v", transport.writes)
	}
	if transport.connections != 1 || transport.closes != 1 {
		t.Fatalf("transport lifecycle = connects:%d closes:%d", transport.connections, transport.closes)
	}
}

// The Core canonicalises RouterOS `.id` spellings (lowercase hex) while the
// device reports its own spelling, so the write must address the identity the
// read-only preflight actually observed. Otherwise a real write can never match
// the target even though preflight passed.
func TestRouterOSMutationProviderWritesTheIdentityObservedByPreflight(t *testing.T) {
	transport := &recordingMutationTransport{}
	resolver := newOperatorResolverForTest()
	readTransport := &sequencedMutationReadTransport{responses: []map[string]string{
		{".id": "*1D", "name": "cosmiclink-test", "disabled": "true"},
		{".id": "*1D", "name": "cosmiclink-test", "disabled": "false"},
	}}
	selected, err := NewRouterOSMutationProvider(resolver, resolver, func() RouterOSMutationTransport { return transport }, func() RouterOSTransport { return readTransport })
	if err != nil {
		t.Fatalf("NewRouterOSMutationProvider failed: %v", err)
	}

	result := selected.EnableAccount(context.Background(), network.Request{
		ProtocolVersion: "routeros-mutation.v1",
		OperationID:     "op-enable-hex",
		IdempotencyKey:  "idem-enable-hex",
		RequestDigest:   "digest-enable-hex",
		ExecutionID:     "exec-enable-hex",
		TenantRef:       "tenant-1", RouterRef: "router-1", AgentRef: "agent-1", InstallationID: "install-1", Operation: "ENABLE_PPPOE",
		CredentialRef: "operator-ref", CredentialPurpose: "OPERATOR", CredentialVersion: 1,
		ObserverCredentialRef: "observer-ref", ObserverPurpose: "OBSERVER", ObserverCredentialVersion: 1,
		TargetIdentityRef: "*1d", AccountRef: "cosmiclink-test", Host: "router.test", Port: 8728, Transport: "api", ConnectTimeoutSeconds: 1, ReadTimeoutSeconds: 1, Parameters: map[string]any{},
	})
	if !result.Success || result.Code != "ACCOUNT_ENABLED" {
		t.Fatalf("result = %#v", result)
	}
	if len(transport.writes) != 1 || transport.writes[0].Sentence() != "/ppp/secret/set =.id=*1D =disabled=no" {
		t.Fatalf("writes = %#v", transport.writes)
	}
}

type sequencedMutationReadTransport struct {
	responses []map[string]string
	index     int
}

func (t *sequencedMutationReadTransport) Connect(context.Context, network.DiscoveryConnection) error {
	return nil
}
func (t *sequencedMutationReadTransport) Read(context.Context, string) ([]map[string]string, error) {
	row := t.responses[len(t.responses)-1]
	if t.index < len(t.responses) {
		row = t.responses[t.index]
		t.index++
	}
	return []map[string]string{row}, nil
}
func (t *sequencedMutationReadTransport) Close() error { return nil }

func TestNewMutationProviderFailsClosedForEveryOtherSelection(t *testing.T) {
	for _, name := range []string{"", "   ", "Fake", "FAKE", " fake", "fake ", "RouterOS", "routeros ", "simulated", "none", "go", "mysql", "unknown"} {
		selected, err := NewMutationProvider(name)
		if err == nil {
			t.Fatalf("mutation provider selection %q was accepted", name)
		}
		if selected != nil {
			t.Fatalf("mutation provider selection %q returned %#v despite an error", name, selected)
		}
		if errors.Is(err, ErrRealMutationProviderUnavailable) {
			t.Fatalf("selection %q must not be reported as a staged routeros rejection", name)
		}
	}
}

// The narrow Phase 6I contract must stay expressible without the broad
// simulated capability set, so a future real provider can never be plugged
// into create/change-profile/test-connection routes.
func TestRealRouterOSProviderShapeCannotSatisfyTheBroadContract(t *testing.T) {
	var candidate any = &narrowOnlyMutationProvider{}
	if _, ok := candidate.(network.Provider); ok {
		t.Fatal("a narrow mutation provider must not satisfy the broad simulated provider contract")
	}
	if _, ok := candidate.(network.MutationProvider); !ok {
		t.Fatal("narrowOnlyMutationProvider must satisfy network.MutationProvider for this test to mean anything")
	}
	mutation := reflect.TypeOf((*network.MutationProvider)(nil)).Elem()
	if mutation.NumMethod() != 5 {
		t.Fatalf("MutationProvider method count = %d, want 5", mutation.NumMethod())
	}
	broad := reflect.TypeOf((*network.Provider)(nil)).Elem()
	if broad.NumMethod() <= mutation.NumMethod() {
		t.Fatalf("broad provider contract (%d) must stay wider than the mutation contract (%d)", broad.NumMethod(), mutation.NumMethod())
	}
}

func TestMutationProviderForSharesRegisteredSimulationState(t *testing.T) {
	shared := NewFakeProvider()
	selected, err := MutationProviderFor(MutationProviderFake, shared)
	if err != nil {
		t.Fatalf("shared fake selection failed: %v", err)
	}
	if selected != network.MutationProvider(shared) {
		t.Fatalf("fake selection must reuse the registered simulation instance, got %T", selected)
	}
	result := selected.DisconnectSession(context.Background(), network.Request{TenantRef: "t", RouterRef: "r", AccountRef: "missing"})
	if result.Success || result.Provider != "fake" {
		t.Fatalf("narrow call result = %#v", result)
	}
	if shared.MutationCount() != 1 {
		t.Fatalf("mutation count = %d, want the shared instance to observe the write", shared.MutationCount())
	}
}

func TestMutationProviderForRejectsSelectionsAndUnusableRegistrations(t *testing.T) {
	if _, err := MutationProviderFor(MutationProviderRouterOS, NewFakeProvider()); !errors.Is(err, ErrRealMutationProviderUnavailable) {
		t.Fatalf("routeros err = %v, want ErrRealMutationProviderUnavailable", err)
	}
	if _, err := MutationProviderFor("", NewFakeProvider()); err == nil {
		t.Fatal("empty mutation provider selection was accepted")
	}
	if _, err := MutationProviderFor("fake", nil); err == nil {
		t.Fatal("fake selection without a registered simulation was accepted")
	}
	if _, err := MutationProviderFor("fake", broadOnlySimulation{}); err == nil {
		t.Fatal("a simulation that never declared the Phase 6I mutation capability was accepted")
	}
}

func TestSupportedMutationProvidersAreDocumentedAndBounded(t *testing.T) {
	names := SupportedMutationProviders()
	if len(names) != 2 || names[0] != MutationProviderFake || names[1] != MutationProviderRouterOS {
		t.Fatalf("SupportedMutationProviders() = %#v", names)
	}
}

// narrowOnlyMutationProvider implements just the Phase 6I write contract.
type narrowOnlyMutationProvider struct{}

func (narrowOnlyMutationProvider) Name() string { return "narrow" }

func (narrowOnlyMutationProvider) SupportedOperations() []network.MutationOperation {
	return network.MutationOperations()
}

func (narrowOnlyMutationProvider) EnableAccount(context.Context, network.Request) network.Result {
	return network.Result{Success: true, Provider: "narrow", Code: "ACCOUNT_ENABLED"}
}

func (narrowOnlyMutationProvider) DisableAccount(context.Context, network.Request) network.Result {
	return network.Result{Success: true, Provider: "narrow", Code: "ACCOUNT_DISABLED"}
}

func (narrowOnlyMutationProvider) DisconnectSession(context.Context, network.Request) network.Result {
	return network.Result{Success: true, Provider: "narrow", Code: "SESSION_DISCONNECTED"}
}

var _ network.MutationProvider = (*narrowOnlyMutationProvider)(nil)

// broadOnlySimulation implements the pre-6I simulated contract and nothing
// else: it never declares the Phase 6I mutation capability, so it must not be
// usable as a mutation provider.
type broadOnlySimulation struct{}

func (broadOnlySimulation) Name() string { return "broad-only" }
func (broadOnlySimulation) TestConnection(context.Context, network.Request) network.Result {
	return network.Result{Success: true, Provider: "broad-only", Code: "CONNECTION_OK"}
}
func (broadOnlySimulation) CreateAccount(context.Context, network.Request) network.Result {
	return network.Result{Success: true, Provider: "broad-only", Code: "ACCOUNT_CREATED"}
}
func (broadOnlySimulation) EnableAccount(context.Context, network.Request) network.Result {
	return network.Result{Success: true, Provider: "broad-only", Code: "ACCOUNT_ENABLED"}
}
func (broadOnlySimulation) DisableAccount(context.Context, network.Request) network.Result {
	return network.Result{Success: true, Provider: "broad-only", Code: "ACCOUNT_DISABLED"}
}
func (broadOnlySimulation) ChangeProfile(context.Context, network.Request) network.Result {
	return network.Result{Success: true, Provider: "broad-only", Code: "PROFILE_CHANGED"}
}
func (broadOnlySimulation) DisconnectSession(context.Context, network.Request) network.Result {
	return network.Result{Success: true, Provider: "broad-only", Code: "SESSION_DISCONNECTED"}
}

var _ network.Provider = broadOnlySimulation{}

func newOperatorResolverForTest() credentials.Resolver {
	resolver := credentials.NewFakeResolver()
	resolver.SetRecord(credentials.Reference{
		TenantRef: "tenant-1", RouterRef: "router-1", AgentRef: "agent-1", InstallationID: "install-1",
		CredentialRef: "operator-ref", Purpose: credentials.PurposeOperator, Version: 1,
	}, credentials.CredentialRecord{Status: credentials.CredentialStatusActive, Username: "operator", Secret: []byte("synthetic-only")})
	resolver.SetRecord(credentials.Reference{
		TenantRef: "tenant-1", RouterRef: "router-1", AgentRef: "agent-1", InstallationID: "install-1",
		CredentialRef: "observer-ref", Purpose: credentials.PurposeObserver, Version: 1,
	}, credentials.CredentialRecord{Status: credentials.CredentialStatusActive, Username: "observer", Secret: []byte("synthetic-observer")})
	return resolver
}

type recordingMutationTransport struct {
	writes      []RouterOSPreparedWrite
	connections int
	closes      int
}

func (t *recordingMutationTransport) Connect(context.Context, network.DiscoveryConnection) error {
	t.connections++
	return nil
}

func (t *recordingMutationTransport) Write(_ context.Context, prepared RouterOSPreparedWrite) error {
	t.writes = append(t.writes, prepared)
	return nil
}

func (t *recordingMutationTransport) Close() error {
	t.closes++
	return nil
}

var _ RouterOSMutationTransport = (*recordingMutationTransport)(nil)
