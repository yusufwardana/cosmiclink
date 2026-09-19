package network

import (
	"reflect"
	"sort"
	"testing"
)

// Phase 6I, Task 1: the mutation vocabulary is bounded to exactly the three
// real-operation capabilities approved in plan Section 12.
func TestMutationOperationsAreBoundedToPhase6IAllowlist(t *testing.T) {
	want := []MutationOperation{MutationEnablePPPoE, MutationDisablePPPoE, MutationDisconnectSession}
	if got := MutationOperations(); !reflect.DeepEqual(got, want) {
		t.Fatalf("MutationOperations() = %#v, want %#v", got, want)
	}
	if MutationEnablePPPoE != "ENABLE_PPPOE" || MutationDisablePPPoE != "DISABLE_PPPOE" || MutationDisconnectSession != "DISCONNECT_SESSION" {
		t.Fatalf("mutation operation names drifted from the approved allowlist: %q %q %q", MutationEnablePPPoE, MutationDisablePPPoE, MutationDisconnectSession)
	}
}

func TestMutationOperationForRejectsDeferredRawAndMalformedOperations(t *testing.T) {
	for _, operation := range []string{"ENABLE_PPPOE", "DISABLE_PPPOE", "DISCONNECT_SESSION"} {
		parsed, ok := MutationOperationFor(operation)
		if !ok || string(parsed) != operation {
			t.Fatalf("MutationOperationFor(%q) = %q, %t; want it accepted unchanged", operation, parsed, ok)
		}
	}
	// CREATE_PPPOE and CHANGE_PROFILE are broader simulated capabilities and are
	// explicitly deferred for real RouterOS writes (plan Section 13). Anything
	// else is a raw sentence, an unknown verb, or malformed input.
	for _, operation := range []string{"", "   ", "CREATE_PPPOE", "CHANGE_PROFILE", "SET_PASSWORD", "DELETE_PPPOE", "REBOOT", "SCHEDULE_SCRIPT", "/ppp/secret/set", "/ppp/active/remove =.id=*2", "enable_pppoe", "ENABLE_PPPOE ", "DISABLE_PPPOE;reboot"} {
		if parsed, ok := MutationOperationFor(operation); ok {
			t.Fatalf("MutationOperationFor(%q) = %q, accepted; want rejection", operation, parsed)
		}
	}
}

// The narrow mutation contract must never grow a method for a deferred
// capability, and must stay smaller than the broad simulated Provider contract.
func TestMutationProviderContractCarriesOnlyAllowlistedCapabilities(t *testing.T) {
	methods := map[string]bool{}
	mutation := reflect.TypeOf((*MutationProvider)(nil)).Elem()
	for i := 0; i < mutation.NumMethod(); i++ {
		methods[mutation.Method(i).Name] = true
	}
	want := []string{"Name", "SupportedOperations", "EnableAccount", "DisableAccount", "DisconnectSession"}
	if len(methods) != len(want) {
		t.Fatalf("MutationProvider methods = %v, want exactly %v", sortedKeys(methods), want)
	}
	for _, name := range want {
		if !methods[name] {
			t.Fatalf("MutationProvider is missing %s; methods = %v", name, sortedKeys(methods))
		}
	}
	for _, forbidden := range []string{"CreateAccount", "ChangeProfile", "TestConnection", "SetPassword", "DeleteAccount", "Run", "Execute"} {
		if methods[forbidden] {
			t.Fatalf("MutationProvider must not expose %s: that capability is not allowlisted for real RouterOS writes", forbidden)
		}
	}
	broad := reflect.TypeOf((*Provider)(nil)).Elem()
	if mutation.NumMethod() >= broad.NumMethod() {
		t.Fatalf("narrow mutation contract (%d methods) must stay narrower than the simulated provider contract (%d methods)", mutation.NumMethod(), broad.NumMethod())
	}
}

// Task 1 must not invent a second mutation payload: the narrow request aliases
// the existing strict-decoded wire request, and the credential-shape change
// stays with the Laravel/Go contract task (plan Section 41).
func TestMutationRequestAliasesTheWireRequest(t *testing.T) {
	var request Request = MutationRequest{OperationID: "operation-1", Operation: "ENABLE_PPPOE", TenantRef: "tenant-1", RouterRef: "router-1", AccountRef: "cust001"}
	if request.Operation != "ENABLE_PPPOE" || request.AccountRef != "cust001" {
		t.Fatalf("aliased request = %#v", request)
	}
	if reflect.TypeOf(MutationRequest{}).Name() != "Request" {
		t.Fatalf("MutationRequest must alias network.Request, not duplicate it")
	}
}

func sortedKeys(values map[string]bool) []string {
	keys := make([]string, 0, len(values))
	for key := range values {
		keys = append(keys, key)
	}
	sort.Strings(keys)
	return keys
}
