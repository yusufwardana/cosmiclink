package provider

import (
	"context"
	"testing"

	"cosmiclink/network-engine/internal/credentials"
	"cosmiclink/network-engine/internal/network"
)

type countingResolver struct{ calls int }

func (r *countingResolver) Resolve(context.Context, credentials.Reference) (credentials.ResolvedCredential, error) {
	r.calls++
	return credentials.ResolvedCredential{}, nil
}

func TestFakeMutationProviderDoesNotResolveCredentials(t *testing.T) {
	resolver := &countingResolver{}
	selected, err := NewMutationProviderWithResolver(MutationProviderFake, resolver)
	if err != nil {
		t.Fatal(err)
	}
	selected.DisconnectSession(context.Background(), network.Request{TenantRef: "t", RouterRef: "r", AccountRef: "a"})
	if resolver.calls != 0 {
		t.Fatalf("resolver calls=%d", resolver.calls)
	}
}

func TestRouterOSSelectionStillFailsClosedWithResolver(t *testing.T) {
	selected, err := NewMutationProviderWithResolver(MutationProviderRouterOS, &countingResolver{})
	if err == nil || selected != nil {
		t.Fatalf("selected=%#v err=%v", selected, err)
	}
}
