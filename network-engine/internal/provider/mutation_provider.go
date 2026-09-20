package provider

import (
	"errors"
	"fmt"
	"sync"

	"cosmiclink/network-engine/internal/credentials"
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
// mutations. The no-argument form remains useful for tests and deliberately
// cannot construct a live provider because it has no local credential resolver.
func NewMutationProvider(selection string) (network.MutationProvider, error) {
	return NewMutationProviderWithResolver(selection, nil)
}

func NewMutationProviderWithResolver(selection string, resolver credentials.Resolver) (network.MutationProvider, error) {
	switch selection {
	case MutationProviderFake:
		if resolver != nil {
			// Resolver-aware construction is intentionally isolated from the
			// process-wide simulation. Fake execution must not resolve or retain
			// credential state, and tests/compositions must not cross-contaminate
			// the shared mutation counter.
			return NewFakeProvider(), nil
		}
		return GlobalFakeProvider(), nil
	case MutationProviderRouterOS:
		if resolver == nil {
			return nil, fmt.Errorf("%w: %s", ErrRealMutationProviderUnavailable, "an exact local OPERATOR credential resolver is required")
		}
		return NewRouterOSMutationProvider(resolver, resolver, NewRealRouterOSMutationTransport, NewRouterOSDiscoveryProviderWithTLS(false).TransportFactory())
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
		return NewMutationProviderWithResolver(selection, nil)
	default:
		return nil, fmt.Errorf("%w: %s", ErrUnsupportedMutationProvider, "selection must be one of the supported provider names")
	}
}
