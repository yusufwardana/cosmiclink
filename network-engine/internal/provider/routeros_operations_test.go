package provider

import (
	"context"
	"errors"
	"reflect"
	"sort"
	"strings"
	"testing"

	"cosmiclink/network-engine/internal/network"
)

func TestRealRouterOSAllowlistIsExactlyTheThreeApprovedOperations(t *testing.T) {
	allowed := RealRouterOSOperations()
	sorted := append([]network.MutationOperation(nil), allowed...)
	sort.Slice(sorted, func(i, j int) bool { return sorted[i] < sorted[j] })
	want := []network.MutationOperation{network.MutationDisablePPPoE, network.MutationDisconnectSession, network.MutationEnablePPPoE}
	if len(allowed) != 3 || !reflect.DeepEqual(sorted, want) {
		t.Fatalf("RouterOS real-write allowlist = %#v, want exactly %#v", allowed, want)
	}
	for _, operation := range want {
		if !IsRealRouterOSOperation(string(operation)) {
			t.Fatalf("approved operation %q is missing from the allowlist", operation)
		}
	}
}

func TestDeferredCapabilitiesAreOutsideTheRealRouterOSAllowlist(t *testing.T) {
	deferred := []string{
		"CREATE_PPPOE", "CHANGE_PROFILE", "SET_PASSWORD", "RESET_PASSWORD", "DELETE_PPPOE", "DELETE_SECRET",
		"SET_QUEUE", "SET_FIREWALL", "SET_NAT", "SET_ROUTE", "SET_VLAN", "SET_DHCP", "SET_DNS", "SET_SERVICE",
		"ADD_USER", "SET_PERMISSION", "REBOOT", "RUN_SCRIPT", "SCHEDULE_TASK", "IMPORT_FILE", "RESTORE",
		"TEST_CONNECTION", "READ", "PRINT", "RAW", "ARBITRARY", "/ppp/secret/set", "/ppp/secret/print",
		"/ppp/active/remove", "/system/reboot", "", "   ", "enable_pppoe", "ENABLE_PPPOE ", "DISCONNECT_ALL_SESSIONS",
	}
	for _, operation := range deferred {
		if IsRealRouterOSOperation(operation) {
			t.Fatalf("operation %q must not be allowlisted for real RouterOS writes", operation)
		}
		if _, err := PrepareRouterOSWrite(network.MutationOperation(operation), "*2"); !errors.Is(err, ErrRealRouterOSOperationForbidden) {
			t.Fatalf("PrepareRouterOSWrite(%q) err = %v, want ErrRealRouterOSOperationForbidden", operation, err)
		}
	}
}

// The prepared sentences are exactly the three writes approved in plan Section
// 12: a fixed command path plus fixed arguments, parameterized only by one
// validated RouterOS resource identity.
func TestPrepareRouterOSWriteBuildsOnlyTheApprovedSentences(t *testing.T) {
	cases := []struct {
		operation network.MutationOperation
		identity  string
		path      string
		words     []string
	}{
		{operation: network.MutationEnablePPPoE, identity: "*7", path: "/ppp/secret/set", words: []string{"=.id=*7", "=disabled=no"}},
		{operation: network.MutationDisablePPPoE, identity: "*7", path: "/ppp/secret/set", words: []string{"=.id=*7", "=disabled=yes"}},
		{operation: network.MutationDisconnectSession, identity: "*3", path: "/ppp/active/remove", words: []string{"=.id=*3"}},
	}
	for _, testCase := range cases {
		prepared, err := PrepareRouterOSWrite(testCase.operation, testCase.identity)
		if err != nil {
			t.Fatalf("PrepareRouterOSWrite(%q) failed: %v", testCase.operation, err)
		}
		if prepared.Operation() != testCase.operation || prepared.Path() != testCase.path {
			t.Fatalf("prepared write = %q %q, want %q %q", prepared.Operation(), prepared.Path(), testCase.operation, testCase.path)
		}
		if !reflect.DeepEqual(prepared.Words(), testCase.words) {
			t.Fatalf("prepared words = %#v, want %#v", prepared.Words(), testCase.words)
		}
		if !RouterOSWritePathAllowed(prepared.Path()) {
			t.Fatalf("prepared path %q must be allowlisted", prepared.Path())
		}
	}
}

func TestPrepareRouterOSWriteRejectsUntrustedIdentitiesAndZeroValues(t *testing.T) {
	for _, identity := range []string{"", " ", "*7 =disabled=yes", "*7;reboot", "=.id=*7", "*7'", "name=alice", strings.Repeat("a", 33)} {
		prepared, err := PrepareRouterOSWrite(network.MutationDisablePPPoE, identity)
		if !errors.Is(err, ErrInvalidRouterOSResourceID) {
			t.Fatalf("identity %q err = %v, want ErrInvalidRouterOSResourceID", identity, err)
		}
		if prepared.Path() != "" || len(prepared.Words()) != 0 {
			t.Fatalf("rejected write must be empty, got %#v", prepared)
		}
	}
	if RouterOSWritePathAllowed("") {
		t.Fatal("the empty path must never be treated as an allowlisted write")
	}
	// A forged zero value carries no approved operation, so it cannot be sent.
	if _, allowed := realRouterOSWrites[RouterOSPreparedWrite{}.Operation()]; allowed {
		t.Fatal("zero-value RouterOSPreparedWrite must not resolve to an approved write")
	}
}

func TestRouterOSRejectionMessagesNeverEchoCallerInput(t *testing.T) {
	raw := "/ppp/secret/set =name=alice =password=super-secret-value"
	if IsRealRouterOSOperation(raw) {
		t.Fatal("raw RouterOS sentence was reported as an allowlisted operation")
	}
	_, err := PrepareRouterOSWrite(network.MutationOperation(raw), "*7")
	if !errors.Is(err, ErrRealRouterOSOperationForbidden) {
		t.Fatalf("err = %v, want ErrRealRouterOSOperationForbidden", err)
	}
	if strings.Contains(err.Error(), "super-secret-value") || strings.Contains(err.Error(), "alice") {
		t.Fatalf("rejection echoed caller input: %v", err)
	}
}

// The read-only transport must not gain write capability: its contract stays
// exactly Connect/Read/Close, and no production transport satisfies the
// separate mutation transport introduced by Phase 6I.
func TestReadOnlyRouterOSTransportContractStaysReadOnly(t *testing.T) {
	readOnly := reflect.TypeOf((*RouterOSTransport)(nil)).Elem()
	if readOnly.Kind() != reflect.Interface {
		t.Fatalf("RouterOSTransport must stay an interface, got %s", readOnly.Kind())
	}
	names := make([]string, 0, readOnly.NumMethod())
	for index := 0; index < readOnly.NumMethod(); index++ {
		name := readOnly.Method(index).Name
		names = append(names, name)
		if strings.Contains(name, "Write") || strings.Contains(name, "Set") || strings.Contains(name, "Remove") || strings.Contains(name, "Run") || strings.Contains(name, "Execute") {
			t.Fatalf("read-only RouterOSTransport gained the write-capable method %s", name)
		}
	}
	sort.Strings(names)
	if !reflect.DeepEqual(names, []string{"Close", "Connect", "Read"}) {
		t.Fatalf("RouterOSTransport methods = %#v, want exactly Close, Connect, Read", names)
	}
	var production any = &realRouterOSTransport{}
	if _, ok := production.(RouterOSMutationTransport); ok {
		t.Fatal("the production read-only transport must not satisfy RouterOSMutationTransport")
	}
	if _, ok := production.(RouterOSTransport); !ok {
		t.Fatal("the production read-only transport must keep satisfying RouterOSTransport")
	}
}

func TestRouterOSMutationTransportIsASeparateBoundedContract(t *testing.T) {
	mutation := reflect.TypeOf((*RouterOSMutationTransport)(nil)).Elem()
	names := make([]string, 0, mutation.NumMethod())
	for index := 0; index < mutation.NumMethod(); index++ {
		names = append(names, mutation.Method(index).Name)
	}
	sort.Strings(names)
	if !reflect.DeepEqual(names, []string{"Close", "Connect", "Write"}) {
		t.Fatalf("RouterOSMutationTransport methods = %#v, want exactly Close, Connect, Write", names)
	}
	var stub any = &stubRouterOSMutationTransport{}
	if _, ok := stub.(RouterOSTransport); ok {
		t.Fatal("a mutation transport must not satisfy the read-only RouterOSTransport contract")
	}
	if _, ok := stub.(RouterOSMutationTransport); !ok {
		t.Fatal("stubRouterOSMutationTransport must satisfy RouterOSMutationTransport")
	}
	// The only way to obtain a write the transport will accept is the allowlist.
	prepared, err := PrepareRouterOSWrite(network.MutationDisablePPPoE, "*7")
	if err != nil {
		t.Fatalf("PrepareRouterOSWrite failed: %v", err)
	}
	if got := prepared.Sentence(); got != "/ppp/secret/set =.id=*7 =disabled=yes" {
		t.Fatalf("prepared sentence = %q", got)
	}
}

// Discovery and monitoring keep their read-only surface untouched by Task 1.
func TestDiscoveryAndMonitoringProvidersStayReadOnlyAfterTaskOne(t *testing.T) {
	var discovery any = NewRouterOSDiscoveryProviderWithTLS(false)
	if _, ok := discovery.(network.MutationProvider); ok {
		t.Fatal("the RouterOS discovery provider must never satisfy the mutation contract")
	}
	if _, ok := discovery.(network.DiscoveryProvider); !ok {
		t.Fatal("the RouterOS discovery provider must keep satisfying the discovery contract")
	}
	if _, allowed := allowedRouterOSReadCommands[writePPPSecretSetCommand]; allowed {
		t.Fatal("a write command must never appear in the discovery read allowlist")
	}
	if _, allowed := allowedRouterOSReadCommands[removePPPActiveCommand]; allowed {
		t.Fatal("a write command must never appear in the discovery read allowlist")
	}
	if len(allowedRouterOSReadCommands) != 7 {
		t.Fatalf("discovery read allowlist size = %d, want the seven read-only commands", len(allowedRouterOSReadCommands))
	}
	transport := &fakeRouterOSTransport{responses: successfulResponses()}
	result := NewRouterOSDiscoveryProviderWithTransport(func() RouterOSTransport { return transport }).Discover(context.Background(), validRouterOSRequest())
	if !result.Success || transport.mutations != 0 || len(transport.commands) != 6 {
		t.Fatalf("discovery behavior changed: success=%t mutations=%d commands=%#v", result.Success, transport.mutations, transport.commands)
	}
}

func TestTaskOneShipsNoExecutableRealWritePath(t *testing.T) {
	// Nothing in this build can produce a RouterOS write: the provider that
	// would own the transport is not registrable, and the only production
	// transport is read-only.
	if _, err := NewMutationProvider(MutationProviderRouterOS); err == nil {
		t.Fatal("Task 1 must not register a real RouterOS mutation provider")
	}
	stub := &stubRouterOSMutationTransport{}
	if stub.writes != 0 || stub.connects != 0 {
		t.Fatalf("stub transport must start untouched, got %#v", stub)
	}
}

// stubRouterOSMutationTransport records calls so tests can prove that nothing
// in Task 1 reaches a write-capable transport.
type stubRouterOSMutationTransport struct {
	writes   int
	connects int
	closes   int
	sent     []RouterOSPreparedWrite
}

func (s *stubRouterOSMutationTransport) Connect(context.Context, network.DiscoveryConnection) error {
	s.connects++
	return nil
}

func (s *stubRouterOSMutationTransport) Write(_ context.Context, prepared RouterOSPreparedWrite) error {
	s.writes++
	s.sent = append(s.sent, prepared)
	return nil
}

func (s *stubRouterOSMutationTransport) Close() error {
	s.closes++
	return nil
}
