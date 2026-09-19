package provider

import (
	"context"
	"encoding/json"
	"reflect"
	"strings"
	"testing"

	"cosmiclink/network-engine/internal/network"
)

// TestFakeDiscoveryReportsOnlySimulatedSessionEvidence pins the Task 2.8 rule
// that a simulation may report session context but never a reference that looks
// like a real RouterOS `.id`, because that reference is what a later lifecycle
// would address the router with.
func TestFakeDiscoveryReportsOnlySimulatedSessionEvidence(t *testing.T) {
	result := NewFakeDiscoveryProvider().Discover(context.Background(), network.DiscoveryRequest{RouterRef: "router-1"})
	if !result.Success {
		t.Fatalf("result=%#v", result)
	}
	if len(result.Snapshot.ActiveSessions) == 0 {
		t.Fatal("fake discovery must report session context so the managed lifecycle has evidence to project")
	}

	accountNames := map[string]bool{}
	for _, account := range result.Snapshot.Accounts {
		accountNames[account["username"].(string)] = true
	}

	for _, session := range result.Snapshot.ActiveSessions {
		reference, _ := session["external_ref"].(string)
		if !strings.HasPrefix(reference, "sim:") {
			t.Fatalf("simulated session reference %q is not marked as a simulation artifact", reference)
		}
		if !accountNames[session["name"].(string)] {
			t.Fatalf("session %q does not belong to a discovered account", session["name"])
		}
	}

	encoded, err := json.Marshal(result.Snapshot)
	if err != nil {
		t.Fatalf("marshal: %v", err)
	}
	for _, forbidden := range []string{"password", "secret", "credential", "token"} {
		if strings.Contains(strings.ToLower(string(encoded)), forbidden) {
			t.Fatalf("snapshot leaked %q: %s", forbidden, encoded)
		}
	}
}

// TestRouterOSDiscoveryDoesNotInventSessionEvidence keeps the read-only allowlist
// honest: Task 2.8 adds no new router read, so the real provider reports no
// sessions until a later task adds an explicit, reviewed enumeration.
func TestRouterOSDiscoveryDoesNotInventSessionEvidence(t *testing.T) {
	transport := &fakeRouterOSTransport{responses: successfulResponses()}
	result := NewRouterOSDiscoveryProviderWithTransport(func() RouterOSTransport { return transport }).
		Discover(context.Background(), validRouterOSRequest())

	if len(result.Snapshot.ActiveSessions) != 0 {
		t.Fatalf("real discovery invented session evidence: %#v", result.Snapshot.ActiveSessions)
	}
	want := []string{readSystemResource, readSystemIdentity, readPPPProfile, readPPPSecret, readIPPool, readSimpleQueue}
	if !reflect.DeepEqual(transport.commands, want) {
		t.Fatalf("discovery read allowlist changed: %#v", transport.commands)
	}
}

// TestDiscoverySnapshotDistinguishesAbsentFromEmptySessionSection proves the one
// distinction the session lifecycle depends on: "not enumerated" must never be
// read as "enumerated with none", because only the latter may supersede recorded
// sessions.
func TestDiscoverySnapshotDistinguishesAbsentFromEmptySessionSection(t *testing.T) {
	absent := mustMarshalSnapshot(t, network.DiscoverySnapshot{
		Device:   map[string]any{"name": "edge-01"},
		Accounts: []map[string]any{{"external_ref": "*2", "username": "alice"}},
	})
	if !strings.Contains(absent, `"active_sessions":null`) {
		t.Fatalf("a provider that did not enumerate sessions must serialise null, got %s", absent)
	}

	enumeratedNone := mustMarshalSnapshot(t, network.DiscoverySnapshot{ActiveSessions: []map[string]any{}})
	if !strings.Contains(enumeratedNone, `"active_sessions":[]`) {
		t.Fatalf("an empty enumeration must serialise as [], got %s", enumeratedNone)
	}

	if absent == enumeratedNone {
		t.Fatal("absent and empty session enumerations are indistinguishable")
	}
}

func mustMarshalSnapshot(t *testing.T, snapshot network.DiscoverySnapshot) string {
	t.Helper()

	encoded, err := json.Marshal(snapshot)
	if err != nil {
		t.Fatalf("marshal: %v", err)
	}

	return string(encoded)
}
