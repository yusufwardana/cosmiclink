//go:build windows

package agent

import (
	"bytes"
	"context"
	"errors"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"cosmiclink/network-engine/internal/provider"
)

const (
	provisioningAgentRef      = "550e8400-e29b-41d4-a716-446655440000"
	otherProvisioningAgentRef = "6ba7b810-9dad-41d1-80b4-00c04fd430c8"
)

func provisioningCore(t *testing.T, agentRef string, status int) *httptest.Server {
	t.Helper()
	return httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Authorization") != "Bearer production-agent-token" {
			t.Fatal("missing authenticated Agent token")
		}
		switch r.URL.Path {
		case "/api/v1/agent/heartbeat":
			if status != http.StatusOK {
				w.WriteHeader(status)
				return
			}
			_, _ = w.Write([]byte(`{"identifier":"` + agentRef + `","status":"ok"}`))
		case "/api/v1/agent/jobs/claim":
			w.WriteHeader(http.StatusNoContent)
		default:
			t.Fatalf("unexpected path %s", r.URL.Path)
		}
	}))
}

func runProvisioningAgent(t *testing.T, coreURL, dataDir string, logs *bytes.Buffer) error {
	t.Helper()
	var logger *slog.Logger
	if logs != nil {
		logger = slog.New(slog.NewJSONHandler(logs, nil))
	}
	a := New(Config{CoreURL: coreURL, Token: "production-agent-token", DataDir: dataDir, Timeout: time.Second}, provider.NewFakeDiscoveryProvider(), logger)
	return a.RunOnce(context.Background())
}

func TestProductionAgentFreshBootstrapInitializesAndReopensLocalState(t *testing.T) {
	dataDir := t.TempDir()
	core := provisioningCore(t, provisioningAgentRef, http.StatusOK)
	defer core.Close()

	if err := runProvisioningAgent(t, core.URL, dataDir, nil); err != nil {
		t.Fatal(err)
	}
	for _, name := range []string{"agent-bootstrap.json", "installation.json", "master-key.protected", "credentials.db"} {
		if _, err := os.Stat(filepath.Join(dataDir, name)); err != nil {
			t.Fatalf("%s was not initialized: %v", name, err)
		}
	}
	installation, err := OpenInstallation(context.Background(), dataDir)
	if err != nil {
		t.Fatal(err)
	}
	if installation.AgentRef != provisioningAgentRef || !validInstallationID(string(installation.InstallationID)) {
		t.Fatalf("unexpected authoritative installation: %#v", installation)
	}
	store, err := OpenCredentialStore(context.Background(), dataDir)
	if err != nil {
		t.Fatal(err)
	}
	if err := store.Close(); err != nil {
		t.Fatal(err)
	}
}

func TestProductionAgentRestartPreservesInstallationAndKey(t *testing.T) {
	dataDir := t.TempDir()
	core := provisioningCore(t, provisioningAgentRef, http.StatusOK)
	defer core.Close()
	if err := runProvisioningAgent(t, core.URL, dataDir, nil); err != nil {
		t.Fatal(err)
	}
	beforeInstallation, _ := os.ReadFile(filepath.Join(dataDir, "installation.json"))
	beforeKey, _ := os.ReadFile(filepath.Join(dataDir, "master-key.protected"))
	beforeStore, err := os.Stat(filepath.Join(dataDir, "credentials.db"))
	if err != nil {
		t.Fatal(err)
	}

	if err := runProvisioningAgent(t, core.URL, dataDir, nil); err != nil {
		t.Fatal(err)
	}
	afterInstallation, _ := os.ReadFile(filepath.Join(dataDir, "installation.json"))
	afterKey, _ := os.ReadFile(filepath.Join(dataDir, "master-key.protected"))
	afterStore, err := os.Stat(filepath.Join(dataDir, "credentials.db"))
	if err != nil {
		t.Fatal(err)
	}
	if !bytes.Equal(beforeInstallation, afterInstallation) || !bytes.Equal(beforeKey, afterKey) {
		t.Fatal("restart regenerated installation identity or protected master key")
	}
	if !beforeStore.ModTime().Equal(afterStore.ModTime()) || beforeStore.Size() != afterStore.Size() {
		t.Fatal("restart reinitialized the credential store")
	}
}

func TestProductionAgentRejectsDifferentAuthoritativeAgentWithoutReset(t *testing.T) {
	dataDir := t.TempDir()
	first := provisioningCore(t, provisioningAgentRef, http.StatusOK)
	if err := runProvisioningAgent(t, first.URL, dataDir, nil); err != nil {
		t.Fatal(err)
	}
	first.Close()
	before, _ := os.ReadFile(filepath.Join(dataDir, "installation.json"))

	second := provisioningCore(t, otherProvisioningAgentRef, http.StatusOK)
	defer second.Close()
	err := runProvisioningAgent(t, second.URL, dataDir, nil)
	if !errors.Is(err, ErrAgentReferenceMismatch) {
		t.Fatalf("agent mismatch error = %v", err)
	}
	after, _ := os.ReadFile(filepath.Join(dataDir, "installation.json"))
	if !bytes.Equal(before, after) {
		t.Fatal("agent mismatch reset installation state")
	}
}

func TestProductionAgentRejectsAuthenticationWithoutCreatingState(t *testing.T) {
	for _, status := range []int{http.StatusUnauthorized, http.StatusNotFound} {
		t.Run(http.StatusText(status), func(t *testing.T) {
			dataDir := t.TempDir()
			core := provisioningCore(t, provisioningAgentRef, status)
			defer core.Close()
			err := runProvisioningAgent(t, core.URL, dataDir, nil)
			if status == http.StatusUnauthorized && !errors.Is(err, ErrAuthentication) {
				t.Fatalf("authentication error = %v", err)
			}
			if err == nil {
				t.Fatal("missing Core Agent unexpectedly provisioned")
			}
			entries, readErr := os.ReadDir(dataDir)
			if readErr != nil {
				t.Fatal(readErr)
			}
			if len(entries) != 0 {
				t.Fatalf("failed authentication created local state: %v", entries)
			}
		})
	}
}

func TestProductionAgentRunStopsWhenCoreEnrollmentIsMissing(t *testing.T) {
	dataDir := t.TempDir()
	core := provisioningCore(t, provisioningAgentRef, http.StatusNotFound)
	defer core.Close()
	a := New(Config{CoreURL: core.URL, Token: "production-agent-token", DataDir: dataDir, Timeout: time.Second, PollInterval: time.Millisecond}, provider.NewFakeDiscoveryProvider(), nil)
	done := make(chan error, 1)
	go func() { done <- a.Run(context.Background()) }()
	select {
	case err := <-done:
		if !errors.Is(err, ErrAuthentication) {
			t.Fatalf("missing enrollment error = %v", err)
		}
	case <-time.After(250 * time.Millisecond):
		t.Fatal("missing Core Agent entered retry loop")
	}
}

func TestProductionAgentRejectsCorruptStateWithoutAutomaticReset(t *testing.T) {
	corruptions := map[string]string{
		"bootstrap":    "agent-bootstrap.json",
		"installation": "installation.json",
		"master-key":   "master-key.protected",
		"store":        "credentials.db",
	}
	for name, target := range corruptions {
		t.Run(name, func(t *testing.T) {
			dataDir := t.TempDir()
			core := provisioningCore(t, provisioningAgentRef, http.StatusOK)
			defer core.Close()
			if name != "bootstrap" {
				if err := runProvisioningAgent(t, core.URL, dataDir, nil); err != nil {
					t.Fatal(err)
				}
			}
			path := filepath.Join(dataDir, target)
			corrupt := []byte("CORRUPT_PROVISIONING_STATE")
			if err := os.WriteFile(path, corrupt, 0600); err != nil {
				t.Fatal(err)
			}
			if err := runProvisioningAgent(t, core.URL, dataDir, nil); err == nil {
				t.Fatal("corrupt state was accepted")
			}
			after, err := os.ReadFile(path)
			if err != nil {
				t.Fatal(err)
			}
			if !bytes.Equal(corrupt, after) {
				t.Fatal("corrupt state was automatically reset")
			}
		})
	}
}

func TestProductionAgentRejectsPartialStateWithoutRecreatingMissingArtifacts(t *testing.T) {
	for _, target := range []string{"installation.json", "master-key.protected", "credentials.db"} {
		t.Run(target, func(t *testing.T) {
			dataDir := t.TempDir()
			core := provisioningCore(t, provisioningAgentRef, http.StatusOK)
			defer core.Close()
			if err := runProvisioningAgent(t, core.URL, dataDir, nil); err != nil {
				t.Fatal(err)
			}
			path := filepath.Join(dataDir, target)
			if err := os.Remove(path); err != nil {
				t.Fatal(err)
			}
			if err := runProvisioningAgent(t, core.URL, dataDir, nil); !errors.Is(err, ErrAgentProvisioningIncomplete) {
				t.Fatalf("partial state error = %v", err)
			}
			if _, err := os.Stat(path); !errors.Is(err, os.ErrNotExist) {
				t.Fatalf("missing artifact was recreated: %v", err)
			}
		})
	}
}

func TestProductionAgentProvisioningDoesNotLeakTokenOrSecretMaterial(t *testing.T) {
	dataDir := t.TempDir()
	core := provisioningCore(t, provisioningAgentRef, http.StatusOK)
	defer core.Close()
	var logs bytes.Buffer
	if err := runProvisioningAgent(t, core.URL, dataDir, &logs); err != nil {
		t.Fatal(err)
	}
	if strings.Contains(logs.String(), "production-agent-token") || strings.Contains(strings.ToLower(logs.String()), "protected_key") {
		t.Fatalf("provisioning log leaked secret material: %s", logs.String())
	}
	for _, name := range []string{"agent-bootstrap.json", "installation.json"} {
		encoded, err := os.ReadFile(filepath.Join(dataDir, name))
		if err != nil {
			t.Fatal(err)
		}
		if strings.Contains(string(encoded), "production-agent-token") || strings.Contains(strings.ToLower(string(encoded)), "password") || strings.Contains(strings.ToLower(string(encoded)), "secret") {
			t.Fatalf("%s leaked secret material", name)
		}
	}
}
