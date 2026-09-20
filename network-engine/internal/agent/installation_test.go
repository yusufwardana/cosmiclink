package agent

import (
	"context"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"cosmiclink/network-engine/internal/credentials"
)

func TestInitializeInstallationRequiresBootstrapAndPersistsImmutableIdentity(t *testing.T) {
	dir := t.TempDir()
	if _, err := InitializeInstallation(context.Background(), dir); !errors.Is(err, ErrAgentNotBootstrapped) {
		t.Fatalf("missing bootstrap error = %v", err)
	}
	bootstrap := filepath.Join(dir, "agent-bootstrap.json")
	state, err := NewBootstrapState(bootstrap)
	if err != nil {
		t.Fatal(err)
	}
	if err := state.Bind(context.Background(), []byte(`{"identifier":"550e8400-e29b-41d4-a716-446655440000","status":"ok"}`)); err != nil {
		t.Fatal(err)
	}
	result, err := InitializeInstallation(context.Background(), dir)
	if err != nil {
		t.Fatal(err)
	}
	if result.AgentRef != state.AgentRef() || result.InstallationID == credentials.InstallationIdentity(result.AgentRef) || !validInstallationID(string(result.InstallationID)) {
		t.Fatalf("invalid installation metadata: %#v", result)
	}
	first, err := os.ReadFile(filepath.Join(dir, "installation.json"))
	if err != nil {
		t.Fatal(err)
	}
	if strings.Contains(string(first), "token") || strings.Contains(string(first), "protected") || strings.Contains(string(first), "secret") {
		t.Fatalf("installation metadata contains secret material: %s", first)
	}
	if _, err := InitializeInstallation(context.Background(), dir); !errors.Is(err, ErrAgentAlreadyInitialized) {
		t.Fatalf("repeated initialization error = %v", err)
	}
	second, _ := os.ReadFile(filepath.Join(dir, "installation.json"))
	if string(first) != string(second) {
		t.Fatal("repeated initialization changed installation metadata")
	}
}

func TestOpenInstallationFailsClosedAndNeverInitializes(t *testing.T) {
	dir := t.TempDir()
	if _, err := OpenInstallation(context.Background(), dir); !errors.Is(err, ErrAgentNotBootstrapped) {
		t.Fatalf("missing bootstrap runtime error = %v", err)
	}
	if _, err := os.Stat(filepath.Join(dir, "installation.json")); !errors.Is(err, os.ErrNotExist) {
		t.Fatalf("runtime created installation: %v", err)
	}
}

func TestInstallationRejectsMismatchAndStrictCorruption(t *testing.T) {
	dir := t.TempDir()
	state, err := NewBootstrapState(filepath.Join(dir, "agent-bootstrap.json"))
	if err != nil {
		t.Fatal(err)
	}
	if err := state.Bind(context.Background(), []byte(`{"identifier":"550e8400-e29b-41d4-a716-446655440000","status":"ok"}`)); err != nil {
		t.Fatal(err)
	}
	file := installationFile{FormatVersion: installationFileVersion, InstallationID: "550e8400-e29b-41d4-a716-446655440000", AgentRef: "6ba7b810-9dad-11d1-80b4-00c04fd430c8", CreatedAt: time.Now().UTC()}
	encoded, _ := json.Marshal(file)
	if err := os.WriteFile(filepath.Join(dir, "installation.json"), encoded, 0600); err != nil {
		t.Fatal(err)
	}
	if _, err := OpenInstallation(context.Background(), dir); !errors.Is(err, ErrAgentReferenceMismatch) {
		t.Fatalf("mismatch error = %v", err)
	}
	file.AgentRef = state.AgentRef()
	encoded, _ = json.Marshal(file)
	encoded = append(encoded, []byte(` {}`)...)
	if err := os.WriteFile(filepath.Join(dir, "installation.json"), encoded, 0600); err != nil {
		t.Fatal(err)
	}
	if _, err := OpenInstallation(context.Background(), dir); !errors.Is(err, ErrAgentInstallationCorrupt) {
		t.Fatalf("trailing data error = %v", err)
	}
}
