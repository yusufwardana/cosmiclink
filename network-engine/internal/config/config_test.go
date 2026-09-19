package config

import (
	"os"
	"testing"
)

const mutationProviderEnv = "NETWORK_MUTATION_PROVIDER"

// Phase 6I, Task 1: the write-side provider selection must default to the
// simulated provider. A missing or empty environment value is never interpreted
// as a request for real RouterOS mutation.
func TestMutationProviderSafeDefaultIsFake(t *testing.T) {
	t.Setenv("GO_NETWORK_ENGINE_TOKEN", "test-token")
	t.Setenv(mutationProviderEnv, "placeholder")
	if err := os.Unsetenv(mutationProviderEnv); err != nil {
		t.Fatalf("could not clear %s: %v", mutationProviderEnv, err)
	}
	loaded, err := FromEnvironment()
	if err != nil {
		t.Fatalf("FromEnvironment failed: %v", err)
	}
	if loaded.MutationProvider != "fake" {
		t.Fatalf("MutationProvider default = %q, want fake", loaded.MutationProvider)
	}

	t.Setenv(mutationProviderEnv, "   ")
	loaded, err = FromEnvironment()
	if err != nil {
		t.Fatalf("FromEnvironment failed: %v", err)
	}
	if loaded.MutationProvider != "   " {
		t.Fatalf("MutationProvider = %q, want the raw value so the selector can fail closed", loaded.MutationProvider)
	}
}

func TestMutationProviderSelectionIsCaseAndSpaceSensitive(t *testing.T) {
	t.Setenv("GO_NETWORK_ENGINE_TOKEN", "test-token")
	for _, value := range []string{"fake", "routeros", "RouterOS", "FAKE", " fake", "fake ", "unknown"} {
		t.Setenv(mutationProviderEnv, value)
		loaded, err := FromEnvironment()
		if err != nil {
			t.Fatalf("FromEnvironment failed for %q: %v", value, err)
		}
		if loaded.MutationProvider != value {
			t.Fatalf("MutationProvider = %q, want the configured %q unchanged", loaded.MutationProvider, value)
		}
	}
}

// Task 1 must not change discovery or monitoring selection semantics.
func TestExistingProviderSelectionSemanticsAreUnchanged(t *testing.T) {
	t.Setenv("GO_NETWORK_ENGINE_TOKEN", "test-token")
	t.Setenv("NETWORK_DISCOVERY_PROVIDER", "placeholder")
	t.Setenv("MONITORING_PROVIDER", "placeholder")
	if err := os.Unsetenv("NETWORK_DISCOVERY_PROVIDER"); err != nil {
		t.Fatalf("could not clear discovery provider: %v", err)
	}
	if err := os.Unsetenv("MONITORING_PROVIDER"); err != nil {
		t.Fatalf("could not clear monitoring provider: %v", err)
	}
	loaded, err := FromEnvironment()
	if err != nil {
		t.Fatalf("FromEnvironment failed: %v", err)
	}
	if loaded.DiscoveryProvider != "fake" || loaded.MonitoringProvider != "fake" {
		t.Fatalf("discovery/monitoring defaults changed: %q %q", loaded.DiscoveryProvider, loaded.MonitoringProvider)
	}
	if loaded.Address != "127.0.0.1:8787" {
		t.Fatalf("engine address default changed: %q", loaded.Address)
	}
	if loaded.AllowInsecureRouterOSTLS {
		t.Fatal("insecure RouterOS TLS must stay disabled by default")
	}
	t.Setenv("GO_NETWORK_ENGINE_TOKEN", "")
	if _, err := FromEnvironment(); err == nil {
		t.Fatal("a missing engine token must fail startup")
	}
}
