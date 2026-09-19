package provider

import (
	"context"
	"errors"
	"reflect"
	"testing"

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
