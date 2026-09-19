package provider

import (
	"errors"
	"fmt"
	"sync"

	"cosmiclink/network-engine/internal/network"
)

// The two Phase 6I mutation provider selections. These names come from
// NETWORK_MUTATION_PROVIDER and are matched exactly.
const (
	MutationProviderFake     = "fake"
	MutationProviderRouterOS = "routeros"
)

var (
	// ErrRealMutationProviderUnavailable means the selection asked for real
	// RouterOS writes, which no provider in this build is allowed to perform.
	ErrRealMutationProviderUnavailable = errors.New("real RouterOS mutation provider unavailable")
	// ErrUnsupportedMutationProvider means the selection is not a known name or
	// the registered provider cannot serve the narrow Phase 6I contract.
	ErrUnsupportedMutationProvider = errors.New("unsupported mutation provider selection")

	sharedFakeOnce    sync.Once
	sharedFakeProvide *FakeProvider
)

// GlobalFakeProvider returns the process-wide simulated provider. The
// composition root uses it so the API, safety counter, and tests observe the
// same simulated write state.
func GlobalFakeProvider() *FakeProvider {
	sharedFakeOnce.Do(func() { sharedFakeProvide = NewFakeProvider() })
	return sharedFakeProvide
}

// SupportedMutationProviders lists the accepted NETWORK_MUTATION_PROVIDER
// values in documentation order.
func SupportedMutationProviders() []string {
	return []string{MutationProviderFake, MutationProviderRouterOS}
}

// NewMutationProvider selects the provider that may serve Phase 6I RouterOS
// mutations. The fake selection returns the shared simulation; the routeros
// selection is refused until Task 4 registers a safe, auditable real provider.
func NewMutationProvider(selection string) (network.MutationProvider, error) {
	switch selection {
	case MutationProviderFake:
		return GlobalFakeProvider(), nil
	case MutationProviderRouterOS:
		return nil, fmt.Errorf("%w: %s", ErrRealMutationProviderUnavailable, "no allowlisted RouterOS write provider is registered in this build")
	default:
		return nil, fmt.Errorf("%w: %s", ErrUnsupportedMutationProvider, "selection must be one of the supported provider names")
	}
}

// MutationProviderFor adapts an already registered provider to the narrow
// Phase 6I mutation contract. Selection and registration must agree: the
// returned provider is the same instance the rest of the engine uses, and a
// provider that never declared the mutation capability is rejected rather than
// silently widened.
func MutationProviderFor(selection string, registered network.Provider) (network.MutationProvider, error) {
	switch selection {
	case MutationProviderFake:
		if registered == nil {
			return nil, fmt.Errorf("%w: %s", ErrUnsupportedMutationProvider, "the fake selection requires a registered simulation instance")
		}
		narrow, ok := registered.(network.MutationProvider)
		if !ok {
			return nil, fmt.Errorf("%w: %s", ErrUnsupportedMutationProvider, "the registered simulation does not declare the Phase 6I mutation capability")
		}
		return narrow, nil
	case MutationProviderRouterOS:
		return nil, fmt.Errorf("%w: %s", ErrRealMutationProviderUnavailable, "the real RouterOS mutation provider is staged for a later task")
	default:
		return nil, fmt.Errorf("%w: %s", ErrUnsupportedMutationProvider, "selection must be one of the supported provider names")
	}
}
