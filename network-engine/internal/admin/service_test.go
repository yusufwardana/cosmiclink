package admin

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"testing"

	"cosmiclink/network-engine/internal/adminprotocol"
	"cosmiclink/network-engine/internal/agent"
	"cosmiclink/network-engine/internal/credentials"
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

func TestLocalValidationPurposeAndIntegrity(t *testing.T) {
	if runtime.GOOS != "windows" {
		t.Skip("production DPAPI provider is Windows-only")
	}
	for _, purpose := range []credentials.Purpose{credentials.PurposeObserver, credentials.PurposeOperator} {
		for _, scenario := range []string{"valid", "revoked", "retired", "tenant", "router", "agent", "installation", "purpose", "unsupported", "version", "reference", "ciphertext"} {
			t.Run(string(purpose)+"/"+scenario, func(t *testing.T) {
				service, dir := testService(t)
				handle(t, service, adminprotocol.StoreInit, adminprotocol.StoreInitPayload{})
				store, inst, err := service.open(context.Background())
				if err != nil {
					t.Fatal(err)
				}
				ref := credentials.Reference{TenantRef: "tenant-1", RouterRef: "router-1", AgentRef: inst.AgentRef, InstallationID: inst.InstallationID, CredentialRef: uuid(), Purpose: purpose, Version: 1}
				if err := store.Insert(context.Background(), ref, "LOCAL_TEST_USER", []byte("LOCAL_TEST_SECRET")); err != nil {
					t.Fatal(err)
				}
				store.Close()
				db, err := sql.Open("sqlite", filepath.Join(dir, "credentials.db"))
				if err != nil {
					t.Fatal(err)
				}
				defer db.Close()
				// Simulate a corrupted legacy row in this isolated test vault.
				if scenario == "unsupported" {
					db.SetMaxOpenConns(1)
					if _, err := db.Exec("PRAGMA ignore_check_constraints = ON"); err != nil {
						t.Fatal(err)
					}
				}
				updates := map[string]string{
					"revoked": "status = 'REVOKED'", "retired": "status = 'RETIRED'",
					"tenant": "tenant_ref = 'other'", "router": "router_ref = 'other'", "agent": "agent_ref = 'other'",
					"installation": "installation_id = 'other'", "unsupported": "purpose = 'UNSUPPORTED'",
					"ciphertext": "encrypted_secret = X'00'",
				}
				if purpose == credentials.PurposeOperator {
					updates["purpose"] = "purpose = 'OBSERVER'"
				} else {
					updates["purpose"] = "purpose = 'OPERATOR'"
				}
				if update, ok := updates[scenario]; ok {
					if _, err := db.Exec("UPDATE credentials SET "+update+" WHERE credential_ref = ?", ref.CredentialRef); err != nil {
						t.Fatal(err)
					}
				}
				payload := adminprotocol.CredentialPayload{CredentialRef: ref.CredentialRef, Version: 1}
				if scenario == "version" {
					payload.Version = 0
				}
				if scenario == "reference" {
					payload.CredentialRef = "missing"
				}
				encoded, _ := json.Marshal(payload)
				result, err := service.Handle(context.Background(), adminprotocol.Request{Version: adminprotocol.Version, Operation: adminprotocol.TestLocal, Payload: encoded})
				if scenario == "valid" {
					if err != nil {
						t.Fatal(err)
					}
					response := result.(CredentialResult)
					if response.LocalValidation != "completed" || response.HardwareAccess != "not_performed" {
						t.Fatal("incorrect validation semantics")
					}
					metadata := handle(t, service, adminprotocol.Inspect, payload).(Metadata)
					if metadata.Purpose != purpose || metadata.LastValidatedAt == nil {
						t.Fatal("purpose changed or evidence missing")
					}
				} else if err == nil {
					t.Fatal("invalid vault/reference passed")
				}
				responseBytes, _ := json.Marshal(result)
				logBytes, _ := os.ReadFile(filepath.Join(dir, "audit", "credential-admin.log"))
				for _, text := range []string{string(responseBytes), string(logBytes)} {
					if strings.Contains(text, "LOCAL_TEST_SECRET") || strings.Contains(text, "LOCAL_TEST_USER") {
						t.Fatal("secret-bearing validation output")
					}
				}
			})
		}
	}
}

func TestServiceEnrollsOperatorWithServerFixedPurposeAndMetadataOnlyAudit(t *testing.T) {
	if runtime.GOOS != "windows" {
		t.Skip("production DPAPI provider is Windows-only")
	}
	service, dir := testService(t)
	if _, err := service.Handle(context.Background(), adminprotocol.Request{Version: adminprotocol.Version, Operation: adminprotocol.StoreInit, Payload: json.RawMessage(`{}`)}); err != nil {
		t.Fatal(err)
	}

	_, err := service.Handle(context.Background(), adminprotocol.Request{Version: adminprotocol.Version, Operation: adminprotocol.AddOperator, Payload: json.RawMessage(`{"tenant_ref":"tenant-1","router_ref":"router-1","username":"operator-user","secret":"OPERATOR_SENTINEL_SECRET","confirmation":"wrong"}`)})
	if !errors.Is(err, ErrConfirmation) {
		t.Fatalf("missing confirmation error=%v", err)
	}

	created := handle(t, service, adminprotocol.AddOperator, adminprotocol.AddOperatorPayload{TenantRef: "tenant-1", RouterRef: "router-1", Username: "operator-user", Secret: "OPERATOR_SENTINEL_SECRET", Confirmation: "ENROLL_OPERATOR"})
	encoded, _ := json.Marshal(created)
	if strings.Contains(string(encoded), "OPERATOR_SENTINEL_SECRET") || strings.Contains(string(encoded), "operator-user") {
		t.Fatalf("secret-bearing result: %s", encoded)
	}
	var metadata Metadata
	if err := json.Unmarshal(encoded, &metadata); err != nil {
		t.Fatal(err)
	}
	if metadata.Purpose != "OPERATOR" || metadata.Version != 1 || metadata.CredentialRef == "" || metadata.AgentRef == "" || metadata.Status != "ACTIVE" {
		t.Fatalf("operator metadata = %#v", metadata)
	}

	logBytes, err := os.ReadFile(filepath.Join(dir, "audit", "credential-admin.log"))
	if err != nil {
		t.Fatal(err)
	}
	if strings.Contains(string(logBytes), "OPERATOR_SENTINEL_SECRET") || strings.Contains(string(logBytes), "operator-user") {
		t.Fatal("operator secret leaked into audit")
	}
}
