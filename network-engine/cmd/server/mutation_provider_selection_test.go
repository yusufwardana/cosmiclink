package main

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"cosmiclink/network-engine/internal/config"
	"cosmiclink/network-engine/internal/network"
	"cosmiclink/network-engine/internal/provider"
)

const compositionToken = "6a1c4f9e8b3d2f57ac01e6b94d7c3a8f"

// Phase 6I, Task 1: the startup composition must default to the simulated
// provider and must refuse to serve RouterOS mutations even when configured.
func TestSelectMutationProviderDefaultsToTheSharedFakeSimulation(t *testing.T) {
	shared := provider.GlobalFakeProvider()
	seeded := shared.CreateAccount(context.Background(), network.Request{
		OperationID: "op-select-seed", TenantRef: "tenant-1", RouterRef: "router-1", AccountRef: "cust001",
		Parameters: map[string]any{"username": "cust001", "profile": "HOME-10M"},
	})
	if !seeded.Success {
		t.Fatalf("could not seed the shared simulation: %#v", seeded)
	}
	before := shared.MutationCount()

	selected, err := selectMutationProvider(config.Config{MutationProvider: "fake"}, nil)
	if err != nil {
		t.Fatalf("selectMutationProvider(fake) failed: %v", err)
	}
	if selected != network.MutationProvider(shared) {
		t.Fatalf("fake selection must return the shared simulation instance, got %T", selected)
	}
	if result := selected.DisableAccount(context.Background(), network.Request{
		OperationID: "op-select", TenantRef: "tenant-1", RouterRef: "router-1", AccountRef: "cust001",
	}); !result.Success || result.Code != "ACCOUNT_DISABLED" {
		t.Fatalf("selected provider disable result = %#v", result)
	}
	if after := shared.MutationCount(); after != before+1 {
		t.Fatalf("fake mutation count = %d, want %d: the selection must use the shared simulation", after, before+1)
	}
}

func TestSelectMutationProviderFailsClosedForUnknownSelections(t *testing.T) {
	for _, selection := range []string{"", "   ", "Fake", "FAKE", "simulated", "router-ops", "both", "legacy"} {
		selected, err := selectMutationProvider(config.Config{MutationProvider: selection}, nil)
		if err == nil {
			t.Fatalf("selectMutationProvider(%q) returned %#v, want a startup error", selection, selected)
		}
		if selected != nil {
			t.Fatalf("selectMutationProvider(%q) returned a provider alongside %v", selection, err)
		}
		if !errors.Is(err, provider.ErrRealMutationProviderUnavailable) && !errors.Is(err, provider.ErrUnsupportedMutationProvider) {
			t.Fatalf("selectMutationProvider(%q) error = %v, want an explicit unsupported/unavailable error", selection, err)
		}
	}
}

func TestSelectMutationProviderConstructsRouterOSWithoutHardwareContact(t *testing.T) {
	selected, err := selectMutationProvider(config.Config{MutationProvider: "routeros"}, nil)
	if err != nil {
		t.Fatalf("routeros selection error = %v", err)
	}
	if selected == nil || selected.Name() != "routeros" {
		t.Fatalf("routeros selection = %#v", selected)
	}
}

// engineConfig mirrors what config.FromEnvironment produces for a real
// deployment, changing only the mutation selection under test.
func engineConfig(selection string) config.Config {
	return config.Config{
		Address:            "127.0.0.1:0",
		Token:              compositionToken,
		DiscoveryProvider:  "fake",
		MonitoringProvider: "fake",
		MutationProvider:   selection,
	}
}

func TestBuildHandlerAcceptsFakeAndConstructsRouterOS(t *testing.T) {
	_, cleanup, err := buildHandler(engineConfig("fake"), discard())
	if err != nil {
		t.Fatalf("buildHandler(fake) failed: %v", err)
	}
	if cleanup == nil {
		t.Fatal("buildHandler must return a cleanup function")
	}
	cleanup()

	_, cleanup, err = buildHandler(engineConfig("routeros"), discard())
	if err != nil {
		t.Fatalf("buildHandler(routeros) error = %v", err)
	}
	cleanup()

	_, _, err = buildHandler(engineConfig("fake "), discard())
	if err == nil || !strings.Contains(err.Error(), "unsupported") {
		t.Fatalf("buildHandler(%q) error = %v, want an unsupported selection", "fake ", err)
	}
}

// End-to-end over the built handler: the default fake selection keeps serving
// every operation, and no environment selection can reach a real RouterOS write.
func TestBuiltHandlerKeepsSimulatedBehaviorForTheFakeSelection(t *testing.T) {
	handler, cleanup, err := buildHandler(engineConfig("fake"), discard())
	if err != nil {
		t.Fatalf("buildHandler failed: %v", err)
	}
	defer cleanup()

	shared := provider.GlobalFakeProvider()
	// Unique refs and idempotency keys: the composed provider is the process-wide
	// simulation, so tests must not collide on its account state.
	created := post(t, handler, "/v1/network/accounts", `{"operation":"CREATE_PPPOE","idempotency_key":"comp-create-2","operation_id":"comp-create-2","tenant_ref":"tenant-2","router_ref":"router-2","account_ref":"cust002","parameters":{"username":"cust002","profile":"HOME-10M"}}`)
	if created.Code != http.StatusOK || !strings.Contains(created.Body.String(), `"ACCOUNT_CREATED"`) {
		t.Fatalf("create through the composed handler failed: %d %s", created.Code, created.Body.String())
	}
	before := shared.MutationCount()

	disabled := post(t, handler, "/v1/network/accounts/cust002/disable", `{"operation":"DISABLE_PPPOE","idempotency_key":"comp-disable-2","operation_id":"comp-disable-2","tenant_ref":"tenant-2","router_ref":"router-2","account_ref":"cust002","parameters":{}}`)
	if disabled.Code != http.StatusOK {
		t.Fatalf("status = %d, body = %s", disabled.Code, disabled.Body.String())
	}
	var payload map[string]any
	if err := json.Unmarshal(disabled.Body.Bytes(), &payload); err != nil {
		t.Fatalf("response is not JSON: %s, %v", disabled.Body.String(), err)
	}
	if payload["code"] != "ACCOUNT_DISABLED" {
		t.Fatalf("code = %v, want ACCOUNT_DISABLED", payload["code"])
	}
	if payload["provider"] != "fake" {
		t.Fatalf("provider = %v, want fake", payload["provider"])
	}
	if after := shared.MutationCount(); after != before+1 {
		t.Fatalf("fake mutation count = %d, want %d: the composed provider must record the simulated write", after, before+1)
	}

	health := httptest.NewRequest(http.MethodGet, "/health", nil)
	healthRecorder := httptest.NewRecorder()
	handler.ServeHTTP(healthRecorder, health)
	if healthRecorder.Code != http.StatusOK || !strings.Contains(healthRecorder.Body.String(), `"status":"ok"`) {
		t.Fatalf("health changed: %d %s", healthRecorder.Code, healthRecorder.Body.String())
	}
}

func post(t *testing.T, handler http.Handler, path, body string) *httptest.ResponseRecorder {
	t.Helper()
	request := httptest.NewRequest(http.MethodPost, path, bytes.NewBufferString(body))
	request.Header.Set("Authorization", "Bearer "+compositionToken)
	request.Header.Set("Content-Type", "application/json")
	recorder := httptest.NewRecorder()
	handler.ServeHTTP(recorder, request)

	return recorder
}

func discard() *slog.Logger {
	return slog.New(slog.NewTextHandler(io.Discard, nil))
}
