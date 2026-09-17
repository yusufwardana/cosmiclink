package provider

import "testing"

func TestNewDiscoveryProviderRequiresExplicitSupportedSelection(t *testing.T) {
	for _, name := range []string{"fake", "routeros"} {
		provider, err := NewDiscoveryProvider(name, false)
		if err != nil || provider.Name() != name {
			t.Fatalf("provider %q = %#v, %v", name, provider, err)
		}
	}
	if _, err := NewDiscoveryProvider("unknown", false); err == nil {
		t.Fatal("unknown discovery provider was accepted")
	}
}
