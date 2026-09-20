package admin

import (
	"context"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"testing"

	"cosmiclink/network-engine/internal/adminprotocol"
	"cosmiclink/network-engine/internal/agent"
)

func testService(t *testing.T) (*Service, string) {
	t.Helper()
	dir := t.TempDir()
	bootstrap, err := agent.NewBootstrapState(filepath.Join(dir, "agent-bootstrap.json"))
	if err != nil {
		t.Fatal(err)
	}
	if err := bootstrap.Bind(context.Background(), []byte(`{"identifier":"550e8400-e29b-41d4-a716-446655440000","status":"ok"}`)); err != nil {
		t.Fatal(err)
	}
	if _, err := agent.InitializeInstallation(context.Background(), dir); err != nil {
		t.Fatal(err)
	}
	return NewService(dir, "test-admin"), dir
}

func handle(t *testing.T, service *Service, operation adminprotocol.Operation, payload any) any {
	t.Helper()
	encoded, err := adminprotocol.EncodeRequest(operation, payload)
	if err != nil {
		t.Fatal(err)
	}
	request, err := adminprotocol.DecodeRequest(encoded)
	if err != nil {
		t.Fatal(err)
	}
	result, err := service.Handle(context.Background(), request)
	if err != nil {
		t.Fatal(err)
	}
	return result
}

func TestServiceObserverLifecycleAndSecretRedaction(t *testing.T) {
	if runtime.GOOS != "windows" {
		t.Skip("production DPAPI provider is Windows-only")
	}
	service, dir := testService(t)
	if _, err := service.Handle(context.Background(), adminprotocol.Request{Version: adminprotocol.Version, Operation: adminprotocol.StoreInit, Payload: json.RawMessage(`{}`)}); err != nil {
		t.Fatal(err)
	}
	created := handle(t, service, adminprotocol.AddObserver, adminprotocol.AddObserverPayload{TenantRef: "tenant-1", RouterRef: "router-1", Username: "observer-user", Secret: "ADMIN_SENTINEL_SECRET"})
	encoded, _ := json.Marshal(created)
	if strings.Contains(string(encoded), "ADMIN_SENTINEL_SECRET") || strings.Contains(string(encoded), "observer-user") {
		t.Fatalf("secret-bearing result: %s", encoded)
	}
	metadata := created.(interface{})
	refBytes, _ := json.Marshal(metadata)
	var ref struct {
		CredentialRef string `json:"credential_ref"`
		Version       int    `json:"version"`
	}
	if err := json.Unmarshal(refBytes, &ref); err != nil {
		t.Fatal(err)
	}
	if ref.CredentialRef == "" || ref.Version != 1 {
		t.Fatalf("bad metadata: %s", refBytes)
	}
	if _, err := service.Handle(context.Background(), adminprotocol.Request{Version: adminprotocol.Version, Operation: adminprotocol.TestLocal, Payload: json.RawMessage(`{"credential_ref":"` + ref.CredentialRef + `","version":1}`)}); err != nil {
		t.Fatal(err)
	}
	if _, err := service.Handle(context.Background(), adminprotocol.Request{Version: adminprotocol.Version, Operation: adminprotocol.Revoke, Payload: json.RawMessage(`{"credential_ref":"` + ref.CredentialRef + `","version":1}`)}); !errors.Is(err, ErrConfirmation) {
		t.Fatalf("missing confirmation error=%v", err)
	}
	logBytes, err := os.ReadFile(filepath.Join(dir, "audit", "credential-admin.log"))
	if err != nil {
		t.Fatal(err)
	}
	if strings.Contains(string(logBytes), "ADMIN_SENTINEL_SECRET") {
		t.Fatal("secret leaked into audit")
	}
}

func TestServiceRejectsOperatorPayload(t *testing.T) {
	service, _ := testService(t)
	_, err := service.Handle(context.Background(), adminprotocol.Request{Version: adminprotocol.Version, Operation: adminprotocol.AddObserver, Payload: json.RawMessage(`{"tenant_ref":"t","router_ref":"r","username":"operator","secret":"sentinel","purpose":"OPERATOR"}`)})
	if !errors.Is(err, adminprotocol.ErrMalformed) {
		t.Fatalf("crafted purpose error=%v", err)
	}
}
