package agent

import (
	"context"
	"encoding/json"
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

func TestAgentRunOnceSynchronizesActiveObserverMetadataAfterAuthenticatedHeartbeat(t *testing.T) {
	var paths []string
	var syncPayload map[string]any
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		paths = append(paths, r.URL.Path)
		if r.Header.Get("Authorization") != "Bearer token" {
			t.Fatal("missing authenticated Agent token")
		}
		switch r.URL.Path {
		case "/api/v1/agent/heartbeat":
			_, _ = w.Write([]byte(`{"identifier":"550e8400-e29b-41d4-a716-446655440000","status":"ok"}`))
		case "/api/v1/agent/observer-references/sync":
			if err := json.NewDecoder(r.Body).Decode(&syncPayload); err != nil {
				t.Fatal(err)
			}
			w.WriteHeader(http.StatusOK)
		case "/api/v1/agent/jobs/claim":
			w.WriteHeader(http.StatusNoContent)
		default:
			t.Fatalf("unexpected path %s", r.URL.Path)
		}
	}))
	defer server.Close()

	metadata := observerMetadataProvider{items: []credentials.CredentialMetadata{{
		TenantRef: "182", RouterRef: "122", InstallationID: "550e8400-e29b-41d4-a716-446655440001",
		CredentialRef: "2308170a-0dfc-452f-9088-ded13340fc10", Purpose: credentials.PurposeObserver,
		Version: 1, Status: credentials.CredentialStatusActive,
	}}}
	a := NewWithCredentialResolver(Config{CoreURL: server.URL, Token: "token", Timeout: time.Second}, nil, metadata, nil)
	state, err := NewBootstrapState("")
	if err != nil {
		t.Fatal(err)
	}
	a.bootstrap = state

	if err := a.RunOnce(context.Background()); err != nil {
		t.Fatal(err)
	}
	if len(paths) != 3 || paths[1] != "/api/v1/agent/observer-references/sync" {
		t.Fatalf("request paths = %#v", paths)
	}
	if _, ok := syncPayload["password"]; ok {
		t.Fatal("password crossed Agent/Core boundary")
	}
	if _, ok := syncPayload["secret"]; ok {
		t.Fatal("secret crossed Agent/Core boundary")
	}
	if syncPayload["credential_ref"] != "2308170a-0dfc-452f-9088-ded13340fc10" || syncPayload["purpose"] != "OBSERVER" {
		t.Fatalf("unexpected sync payload = %#v", syncPayload)
	}
}

type observerMetadataProvider struct {
	items []credentials.CredentialMetadata
}

func (p observerMetadataProvider) ResolveJob(context.Context, CredentialResolutionJob) (credentials.ResolvedCredential, error) {
	return credentials.ResolvedCredential{}, credentials.ErrCredentialReferenceInvalid
}

func (p observerMetadataProvider) ListObserverMetadata(context.Context) ([]credentials.CredentialMetadata, error) {
	return p.items, nil
}
