package agent

import (
	"context"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"cosmiclink/network-engine/internal/credentials"
)

func TestSyncObserverMetadataSendsTypedSecretFreePayload(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/v1/agent/observer-references/sync" || r.Header.Get("Authorization") != "Bearer token" {
			t.Fatalf("unexpected sync request: %s %s", r.Method, r.URL.Path)
		}
		body := make([]byte, 4096)
		n, _ := r.Body.Read(body)
		if strings.Contains(strings.ToLower(string(body[:n])), "secret") || strings.Contains(strings.ToLower(string(body[:n])), "password") {
			t.Fatal("secret-bearing sync payload")
		}
		w.WriteHeader(http.StatusOK)
	}))
	defer server.Close()

	a := New(Config{CoreURL: server.URL, Token: "token", Timeout: time.Second}, nil, nil)
	state, err := NewBootstrapState("")
	if err != nil {
		t.Fatal(err)
	}
	if err := state.Bind(context.Background(), []byte(`{"identifier":"550e8400-e29b-41d4-a716-446655440000","status":"ok"}`)); err != nil {
		t.Fatal(err)
	}
	a.bootstrap = state
	if err := a.SyncObserverMetadata(context.Background(), credentials.CredentialMetadata{TenantRef: "tenant-1", RouterRef: "router-1", InstallationID: "550e8400-e29b-41d4-a716-446655440001", CredentialRef: "cred-observer", Purpose: credentials.PurposeObserver, Version: 2, Status: credentials.CredentialStatusActive}); err != nil {
		t.Fatal(err)
	}
}
