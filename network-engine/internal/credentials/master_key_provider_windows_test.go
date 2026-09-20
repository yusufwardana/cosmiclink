//go:build windows

package credentials

import (
	"context"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestWindowsMasterKeyProviderRejectsMalformedUnsupportedAndTamperedFiles(t *testing.T) {
	path := filepath.Join(t.TempDir(), "master-key.protected")
	identity := InstallationIdentity("installation-1")
	if err := InitializeProductionMasterKey(path, identity); err != nil {
		t.Fatal(err)
	}
	provider, err := NewProductionMasterKeyProvider(path, identity)
	if err != nil {
		t.Fatal(err)
	}

	original, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	for _, contents := range [][]byte{nil, []byte("{"), append(append([]byte(nil), original...), '\n', '{')} {
		if err := os.WriteFile(path, contents, 0600); err != nil {
			t.Fatal(err)
		}
		if _, err := provider.MasterKey(context.Background()); !errors.Is(err, ErrMasterKeyFileInvalid) {
			t.Fatalf("malformed contents error = %v", err)
		}
	}
	var file protectedMasterKeyFile
	if err := json.Unmarshal(original, &file); err != nil {
		t.Fatal(err)
	}
	file.FormatVersion++
	unsupported, _ := json.Marshal(file)
	if err := os.WriteFile(path, unsupported, 0600); err != nil {
		t.Fatal(err)
	}
	if _, err := provider.MasterKey(context.Background()); !errors.Is(err, ErrMasterKeyFileInvalid) {
		t.Fatalf("unsupported version error = %v", err)
	}
	file = protectedMasterKeyFile{}
	if err := json.Unmarshal(original, &file); err != nil {
		t.Fatal(err)
	}
	file.ProtectedKey = file.ProtectedKey[:len(file.ProtectedKey)-1] + "A"
	tampered, _ := json.Marshal(file)
	if err := os.WriteFile(path, tampered, 0600); err != nil {
		t.Fatal(err)
	}
	if _, err := provider.MasterKey(context.Background()); !errors.Is(err, ErrMasterKeyUnavailable) && !errors.Is(err, ErrMasterKeyFileInvalid) {
		t.Fatalf("tampered blob error = %v", err)
	}
	if strings.Contains(string(tampered), providerSentinel) {
		t.Fatal("sentinel leaked into protected file")
	}
}

func TestWindowsMasterKeyProviderUsesDistinctRandomKeysAndSupportsStoreReopen(t *testing.T) {
	firstPath := filepath.Join(t.TempDir(), "first.protected")
	secondPath := filepath.Join(t.TempDir(), "second.protected")
	identity := InstallationIdentity("installation-1")
	if err := InitializeProductionMasterKey(firstPath, identity); err != nil {
		t.Fatal(err)
	}
	if err := InitializeProductionMasterKey(secondPath, identity); err != nil {
		t.Fatal(err)
	}
	first, err := NewProductionMasterKeyProvider(firstPath, identity)
	if err != nil {
		t.Fatal(err)
	}
	second, err := NewProductionMasterKeyProvider(secondPath, identity)
	if err != nil {
		t.Fatal(err)
	}
	firstKey, err := first.MasterKey(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	secondKey, err := second.MasterKey(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	if string(firstKey) == string(secondKey) {
		t.Fatal("separate installations reused a master key")
	}

	dbPath := filepath.Join(t.TempDir(), "credentials.db")
	store, err := OpenStore(dbPath, first)
	if err != nil {
		t.Fatal(err)
	}
	ref := mustStoreReference(t, PurposeObserver, 1)
	if err := store.Insert(context.Background(), ref, "user", []byte("STORE_PROVIDER_SENTINEL")); err != nil {
		t.Fatal(err)
	}
	if err := store.Close(); err != nil {
		t.Fatal(err)
	}
	reopened, err := OpenStore(dbPath, first)
	if err != nil {
		t.Fatal(err)
	}
	defer reopened.Close()
	resolved, err := reopened.Resolve(context.Background(), ref)
	if err != nil || string(resolved.SecretBytes()) != "STORE_PROVIDER_SENTINEL" {
		t.Fatalf("reopened resolve = %q, %v", resolved.SecretBytes(), err)
	}
	wrong, err := OpenStore(dbPath, second)
	if err != nil {
		t.Fatal(err)
	}
	defer wrong.Close()
	if _, err := wrong.Resolve(context.Background(), ref); !errors.Is(err, ErrCredentialPayloadInvalid) {
		t.Fatalf("wrong installation key resolve = %v", err)
	}
}
