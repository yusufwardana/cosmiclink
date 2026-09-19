package api

import (
	"context"
	"encoding/json"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"cosmiclink/network-engine/internal/network"
	"cosmiclink/network-engine/internal/provider"
)

const mutationTestToken = "phase6i-test-token"

func TestTypedMutationsRouteThroughTheRegisteredMutationProvider(t *testing.T) {
	recorder := newRecordingMutationProvider()
	broad := provider.NewFakeProvider()
	server := NewWithMutationProvider(broad, recorder, provider.NewFakeDiscoveryProvider(), nil, mutationTestToken, discardLogger())

	enabled := execute(server, http.MethodPost, "/v1/network/accounts/cust001/enable", command("ENABLE_PPPOE", "cust001", nil), mutationTestToken)
	assertCode(t, enabled, http.StatusOK, "ACCOUNT_ENABLED")
	assertCode(t, execute(server, http.MethodPost, "/v1/network/accounts/cust001/disable", command("DISABLE_PPPOE", "cust001", nil), mutationTestToken), http.StatusOK, "ACCOUNT_DISABLED")
	assertCode(t, execute(server, http.MethodPost, "/v1/network/accounts/cust001/disconnect", command("DISCONNECT_SESSION", "cust001", nil), mutationTestToken), http.StatusOK, "SESSION_DISCONNECTED")

	want := []string{"ENABLE_PPPOE", "DISABLE_PPPOE", "DISCONNECT_SESSION"}
	if strings.Join(recorder.invoked, ",") != strings.Join(want, ",") {
		t.Fatalf("mutation provider invocations = %#v, want %#v", recorder.invoked, want)
	}
	if !strings.Contains(enabled.Body.String(), `"provider":"recording-mutation"`) {
		t.Fatalf("allowlisted writes must be attributed to the registered mutation provider: %s", enabled.Body.String())
	}
	if broad.MutationCount() != 0 {
		t.Fatalf("broad simulation handled %d writes; the narrow provider must own them", broad.MutationCount())
	}
}

func TestNonAllowlistedOperationsStayOnTheBroadSimulation(t *testing.T) {
	recorder := newRecordingMutationProvider()
	broad := provider.NewFakeProvider()
	server := NewWithMutationProvider(broad, recorder, provider.NewFakeDiscoveryProvider(), nil, mutationTestToken, discardLogger())

	assertCode(t, execute(server, http.MethodPost, "/v1/network/routers/test", command("TEST_CONNECTION", "", nil), mutationTestToken), http.StatusOK, "CONNECTION_OK")
	assertCode(t, execute(server, http.MethodPost, "/v1/network/accounts", command("CREATE_PPPOE", "cust001", map[string]any{"username": "cust001", "profile": "HOME-10M"}), mutationTestToken), http.StatusOK, "ACCOUNT_CREATED")
	assertCode(t, execute(server, http.MethodPost, "/v1/network/accounts/cust001/profile", command("CHANGE_PROFILE", "cust001", map[string]any{"profile": "HOME-20M"}), mutationTestToken), http.StatusOK, "PROFILE_CHANGED")

	if len(recorder.invoked) != 0 {
		t.Fatalf("the narrow mutation provider must not serve create/profile/test, got %#v", recorder.invoked)
	}
	if broad.MutationCount() != 2 {
		t.Fatalf("broad simulation mutation count = %d, want 2 (create + profile)", broad.MutationCount())
	}
}

// A provider may only execute what it declares. This is the boundary that stops
// the broader simulated capability set from implying real RouterOS support.
func TestOperationsOutsideTheDeclaredCapabilityFailClosed(t *testing.T) {
	recorder := newRecordingMutationProvider()
	recorder.supported = []network.MutationOperation{network.MutationEnablePPPoE}
	server := NewWithMutationProvider(provider.NewFakeProvider(), recorder, provider.NewFakeDiscoveryProvider(), nil, mutationTestToken, discardLogger())

	response := execute(server, http.MethodPost, "/v1/network/accounts/cust001/disconnect", command("DISCONNECT_SESSION", "cust001", nil), mutationTestToken)
	assertCode(t, response, http.StatusNotImplemented, "OPERATION_NOT_ALLOWED")
	if len(recorder.invoked) != 0 {
		t.Fatalf("undeclared operation still reached the provider: %#v", recorder.invoked)
	}
}

func TestUnknownOperationIsNeverMappedToDisconnect(t *testing.T) {
	recorder := newRecordingMutationProvider()
	server := NewWithMutationProvider(provider.NewFakeProvider(), recorder, provider.NewFakeDiscoveryProvider(), nil, mutationTestToken, discardLogger())

	for _, operation := range []string{"DELETE_PPPOE", "REBOOT", "/ppp/active/remove", "", "disconnect_session", "SET_PASSWORD"} {
		result := server.invoke(context.Background(), network.Request{OperationID: "op-1", IdempotencyKey: "key-" + operation, Operation: operation, TenantRef: "tenant-1", RouterRef: "router-1", AccountRef: "cust001", Parameters: map[string]any{}})
		if result.Success || result.Code != "OPERATION_NOT_ALLOWED" {
			t.Fatalf("invoke(%q) = %#v, want a failed OPERATION_NOT_ALLOWED result", operation, result)
		}
	}
	// The HTTP surface rejects an operation that does not match its route.
	mismatched := execute(server, http.MethodPost, "/v1/network/accounts/cust001/enable", command("DELETE_PPPOE", "cust001", nil), mutationTestToken)
	assertCode(t, mismatched, http.StatusBadRequest, "INVALID_REQUEST")
	if len(recorder.invoked) != 0 {
		t.Fatalf("rejected operations reached the mutation provider: %#v", recorder.invoked)
	}
}

// The pre-6I constructors must keep working unchanged: a provider that already
// declares the Phase 6I capability serves the typed writes itself.
func TestLegacyConstructorStillServesTheSharedFakeSimulation(t *testing.T) {
	broad := provider.NewFakeProvider()
	server := New(broad, provider.NewFakeDiscoveryProvider(), mutationTestToken, discardLogger())

	assertCode(t, execute(server, http.MethodPost, "/v1/network/accounts", command("CREATE_PPPOE", "cust001", map[string]any{"username": "cust001", "profile": "HOME-10M"}), mutationTestToken), http.StatusOK, "ACCOUNT_CREATED")
	assertCode(t, execute(server, http.MethodPost, "/v1/network/accounts/cust001/disable", command("DISABLE_PPPOE", "cust001", nil), mutationTestToken), http.StatusOK, "ACCOUNT_DISABLED")
	if broad.MutationCount() != 2 {
		t.Fatalf("shared fake mutation count = %d, want 2", broad.MutationCount())
	}
	if body := safetyMutationCount(t, server); !strings.Contains(body, `"mutation_count":2`) {
		t.Fatalf("safety counter = %s, want the registered write provider", body)
	}
}

// Registering a provider that never declared the Phase 6I capability must fail
// closed on write routes instead of silently reaching broader capability.
func TestBroadOnlyProviderCannotServePhase6IMutations(t *testing.T) {
	server := New(failingProvider{}, provider.NewFakeDiscoveryProvider(), mutationTestToken, discardLogger())
	response := execute(server, http.MethodPost, "/v1/network/accounts/cust001/enable", command("ENABLE_PPPOE", "cust001", nil), mutationTestToken)
	assertCode(t, response, http.StatusNotImplemented, "OPERATION_NOT_ALLOWED")
	assertCode(t, execute(server, http.MethodPost, "/v1/network/accounts", command("CREATE_PPPOE", "cust001", map[string]any{"username": "cust001", "profile": "HOME-10M"}), mutationTestToken), http.StatusBadGateway, "PROVIDER_FAILURE")
}

func TestUnregisteredMutationProviderFailsClosedWithoutPanicking(t *testing.T) {
	server := NewWithMutationProvider(nil, nil, provider.NewFakeDiscoveryProvider(), nil, mutationTestToken, discardLogger())
	response := execute(server, http.MethodPost, "/v1/network/accounts/cust001/disconnect", command("DISCONNECT_SESSION", "cust001", nil), mutationTestToken)
	assertCode(t, response, http.StatusNotImplemented, "OPERATION_NOT_ALLOWED")
}

type recordingMutationProvider struct {
	supported []network.MutationOperation
	invoked   []string
}

func newRecordingMutationProvider() *recordingMutationProvider {
	return &recordingMutationProvider{supported: network.MutationOperations()}
}

func (r *recordingMutationProvider) Name() string { return "recording-mutation" }

func (r *recordingMutationProvider) SupportedOperations() []network.MutationOperation {
	return r.supported
}

func (r *recordingMutationProvider) EnableAccount(_ context.Context, request network.Request) network.Result {
	r.invoked = append(r.invoked, string(network.MutationEnablePPPoE))
	return network.Result{Success: true, OperationID: request.OperationID, Provider: r.Name(), Code: "ACCOUNT_ENABLED"}
}

func (r *recordingMutationProvider) DisableAccount(_ context.Context, request network.Request) network.Result {
	r.invoked = append(r.invoked, string(network.MutationDisablePPPoE))
	return network.Result{Success: true, OperationID: request.OperationID, Provider: r.Name(), Code: "ACCOUNT_DISABLED"}
}

func (r *recordingMutationProvider) DisconnectSession(_ context.Context, request network.Request) network.Result {
	r.invoked = append(r.invoked, string(network.MutationDisconnectSession))
	return network.Result{Success: true, OperationID: request.OperationID, Provider: r.Name(), Code: "SESSION_DISCONNECTED"}
}

var _ network.MutationProvider = (*recordingMutationProvider)(nil)

func discardLogger() *slog.Logger {
	return slog.New(slog.NewTextHandler(ioDiscard{}, nil))
}

func safetyMutationCount(t *testing.T, server *Server) string {
	t.Helper()
	recorder := httptest.NewRecorder()
	request := httptest.NewRequest(http.MethodGet, "/v1/discovery/safety/mutation-count", nil)
	request.Header.Set("Authorization", "Bearer "+mutationTestToken)
	server.Handler().ServeHTTP(recorder, request)
	var payload map[string]any
	if err := json.Unmarshal(recorder.Body.Bytes(), &payload); err != nil {
		t.Fatalf("safety counter body = %s, %v", recorder.Body.String(), err)
	}
	return recorder.Body.String()
}
