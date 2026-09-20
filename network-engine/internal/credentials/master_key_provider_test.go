package credentials

import (
	"context"
	"errors"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"testing"
)

const providerSentinel = "PROVIDER_DO_NOT_LEAK_SENTINEL"

func TestProductionMasterKeyProviderRequiresExplicitInitializationAndInstallationIdentity(t *testing.T) {
	path := filepath.Join(t.TempDir(), "master-key.protected")
	identity := InstallationIdentity("installation-1")
	provider, err := NewProductionMasterKeyProvider(path, identity)
	if runtime.GOOS != "windows" {
		if !errors.Is(err, ErrMasterKeyProviderUnsupported) || provider != nil {
			t.Fatalf("unsupported provider = %#v, %v", provider, err)
		}
		if err := InitializeProductionMasterKey(path, identity); !errors.Is(err, ErrMasterKeyProviderUnsupported) {
			t.Fatalf("unsupported initialization = %v", err)
		}
		return
	}
	if err != nil {
		t.Fatal(err)
	}
	if _, err := provider.MasterKey(context.Background()); !errors.Is(err, ErrMasterKeyUnavailable) {
		t.Fatalf("pre-initialization get = %v", err)
	}
	if err := InitializeProductionMasterKey(path, identity); err != nil {
		t.Fatal(err)
	}
	if err := InitializeProductionMasterKey(path, identity); !errors.Is(err, ErrMasterKeyAlreadyInitialized) {
		t.Fatalf("second initialization = %v", err)
	}
	key, err := provider.MasterKey(context.Background())
	if err != nil || len(key) != 32 {
		t.Fatalf("master key length = %d, %v", len(key), err)
	}
	if strings.Contains(string(mustReadFile(t, path)), providerSentinel) || strings.Contains(string(mustReadFile(t, path)), string(key)) {
		t.Fatal("plaintext key material found in protected file")
	}
	wrong, err := NewProductionMasterKeyProvider(path, InstallationIdentity("installation-2"))
	if err != nil {
		t.Fatal(err)
	}
	if _, err := wrong.MasterKey(context.Background()); !errors.Is(err, ErrMasterKeyInstallationMismatch) {
		t.Fatalf("wrong installation = %v", err)
	}
}

func TestProductionMasterKeyProviderNeverUsesEnvironmentFallbackOrTestProvider(t *testing.T) {
	t.Setenv("COSMICLINK_MASTER_KEY", providerSentinel)
	path := filepath.Join(t.TempDir(), "missing.protected")
	provider, err := NewProductionMasterKeyProvider(path, InstallationIdentity("installation-1"))
	if runtime.GOOS != "windows" {
		if !errors.Is(err, ErrMasterKeyProviderUnsupported) || provider != nil {
			t.Fatalf("provider fallback = %#v, %v", provider, err)
		}
		return
	}
	if err != nil {
		t.Fatal(err)
	}
	if _, err := provider.MasterKey(context.Background()); !errors.Is(err, ErrMasterKeyUnavailable) {
		t.Fatalf("missing key fallback = %v", err)
	}
	if _, err := os.Stat(path); !errors.Is(err, os.ErrNotExist) {
		t.Fatalf("missing key was recreated: %v", err)
	}
}

func mustReadFile(t *testing.T, path string) []byte {
	t.Helper()
	data, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	return data
}
