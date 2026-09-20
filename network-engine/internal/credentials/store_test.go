package credentials

import (
	"context"
	"database/sql"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"sync"
	"testing"
)

const storeSentinel = "STORE_DO_NOT_LEAK_SENTINEL"

type testMasterKeyProvider struct {
	key []byte
	err error
}

func (p testMasterKeyProvider) MasterKey(context.Context) ([]byte, error) {
	return append([]byte(nil), p.key...), p.err
}

func TestCredentialStorePersistsEncryptedExactCredentialsAndMetadata(t *testing.T) {
	store, path := newTestStore(t, testMasterKey())
	defer store.Close()

	observer := mustStoreReference(t, PurposeObserver, 1)
	operator := mustStoreReference(t, PurposeOperator, 1)
	if err := store.Insert(context.Background(), observer, "observer-user", []byte(storeSentinel)); err != nil {
		t.Fatal(err)
	}
	if err := store.Insert(context.Background(), operator, "operator-user", []byte("operator-secret")); err != nil {
		t.Fatal(err)
	}

	resolved, err := store.Resolve(context.Background(), observer)
	if err != nil || string(resolved.SecretBytes()) != storeSentinel {
		t.Fatalf("observer resolve = %q, %v", resolved.SecretBytes(), err)
	}
	metadata, err := store.ListMetadata(context.Background())
	if err != nil || len(metadata) != 2 {
		t.Fatalf("metadata = %#v, %v", metadata, err)
	}
	encoded, _ := json.Marshal(metadata)
	if strings.Contains(string(encoded), storeSentinel) || strings.Contains(string(encoded), "encrypted_secret") || strings.Contains(string(encoded), "nonce") {
		t.Fatalf("metadata exposed secret-bearing fields: %s", encoded)
	}

	dbBytes, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	if strings.Contains(string(dbBytes), storeSentinel) {
		t.Fatal("plaintext sentinel found in SQLite file")
	}
	if strings.Contains(string(dbBytes), "operator-secret") {
		t.Fatal("plaintext operator secret found in SQLite file")
	}
}

func TestCredentialStoreUsesFreshNonceAndExactScopeWithoutFallback(t *testing.T) {
	store, _ := newTestStore(t, testMasterKey())
	defer store.Close()

	refV1 := mustStoreReference(t, PurposeObserver, 1)
	refV2 := refV1
	refV2.Version = 2
	if err := store.Insert(context.Background(), refV1, "user", []byte("secret-v1")); err != nil {
		t.Fatal(err)
	}
	if err := store.Insert(context.Background(), refV2, "user", []byte("secret-v2")); err != nil {
		t.Fatal(err)
	}
	row1 := readStoreRow(t, store, refV1)
	row2 := readStoreRow(t, store, refV2)
	if string(row1.nonce) == string(row2.nonce) {
		t.Fatal("encryption reused a nonce")
	}

	wrongTenant := refV1
	wrongTenant.TenantRef = "tenant-2"
	wrongRouter := refV1
	wrongRouter.RouterRef = "router-2"
	wrongAgent := refV1
	wrongAgent.AgentRef = "agent-2"
	wrongInstallation := refV1
	wrongInstallation.InstallationID = InstallationIdentity("installation-2")
	wrongPurpose := refV1
	wrongPurpose.Purpose = PurposeOperator
	wrongVersion := refV1
	wrongVersion.Version = 3
	for _, wrong := range []Reference{wrongTenant, wrongRouter, wrongAgent, wrongInstallation, wrongPurpose, wrongVersion} {
		if _, err := store.Resolve(context.Background(), wrong); err == nil {
			t.Fatalf("wrong scope resolved: %#v", wrong)
		}
	}
}

func TestCredentialStoreRejectsInvalidKeysTamperingAndMalformedLifecycle(t *testing.T) {
	path := filepath.Join(t.TempDir(), "credentials.db")
	if _, err := OpenStore(path, nil); !errors.Is(err, ErrMasterKeyUnavailable) {
		t.Fatalf("missing key error = %v", err)
	}
	if _, err := OpenStore(path, testMasterKeyProvider{key: []byte("short")}); !errors.Is(err, ErrMasterKeyInvalid) {
		t.Fatalf("short key error = %v", err)
	}
	store, _ := newTestStoreAt(t, path, testMasterKey())
	defer store.Close()
	ref := mustStoreReference(t, PurposeObserver, 1)
	if err := store.Insert(context.Background(), ref, "user", []byte(storeSentinel)); err != nil {
		t.Fatal(err)
	}
	if err := store.Insert(context.Background(), ref, "user", []byte(storeSentinel)); !errors.Is(err, ErrCredentialDuplicate) {
		t.Fatalf("duplicate error = %v", err)
	}
	row := readStoreRow(t, store, ref)
	if _, err := store.Resolve(context.Background(), ref); err != nil {
		t.Fatal(err)
	}
	if _, err := store.db.Exec(`UPDATE credentials SET encrypted_secret = ? WHERE credential_ref = ?`, []byte("tampered"), ref.CredentialRef); err != nil {
		t.Fatal(err)
	}
	if _, err := store.Resolve(context.Background(), ref); !errors.Is(err, ErrCredentialPayloadInvalid) {
		t.Fatalf("ciphertext tamper error = %v", err)
	}
	if _, err := store.db.Exec(`UPDATE credentials SET encrypted_secret = ?, nonce = ? WHERE credential_ref = ?`, row.ciphertext, []byte("badnonce"), ref.CredentialRef); err != nil {
		t.Fatal(err)
	}
	if _, err := store.Resolve(context.Background(), ref); !errors.Is(err, ErrCredentialPayloadInvalid) {
		t.Fatalf("nonce tamper error = %v", err)
	}
	if _, err := store.db.Exec(`PRAGMA ignore_check_constraints = ON`); err != nil {
		t.Fatal(err)
	}
	if _, err := store.db.Exec(`UPDATE credentials SET purpose = ? WHERE credential_ref = ?`, "UNKNOWN", ref.CredentialRef); err != nil {
		t.Fatal(err)
	}
	if _, err := store.Resolve(context.Background(), ref); !errors.Is(err, ErrCredentialPersistedInvalid) {
		t.Fatalf("metadata tamper error = %v", err)
	}
	if _, err := store.db.Exec(`PRAGMA ignore_check_constraints = ON`); err != nil {
		t.Fatal(err)
	}
	if _, err := store.db.Exec(`UPDATE credentials SET status = ?, version = ? WHERE credential_ref = ?`, "UNKNOWN", 0, ref.CredentialRef); err != nil {
		t.Fatal(err)
	}
	if _, err := store.Resolve(context.Background(), ref); !errors.Is(err, ErrCredentialVersionInvalid) {
		t.Fatalf("malformed persisted version error = %v", err)
	}
}

func TestCredentialStoreRejectsMalformedExistingSchema(t *testing.T) {
	path := filepath.Join(t.TempDir(), "malformed.db")
	db, err := sql.Open("sqlite", "file:"+filepath.ToSlash(path))
	if err != nil {
		t.Fatal(err)
	}
	if _, err := db.Exec(`CREATE TABLE credentials (credential_ref TEXT NOT NULL)`); err != nil {
		t.Fatal(err)
	}
	if err := db.Close(); err != nil {
		t.Fatal(err)
	}
	if _, err := OpenStore(path, testMasterKeyProvider{key: testMasterKey()}); err == nil || !strings.Contains(err.Error(), "schema invalid") {
		t.Fatalf("malformed schema error = %v", err)
	}
}

func TestCredentialStoreRevocationReopenAndAtomicRotation(t *testing.T) {
	path := filepath.Join(t.TempDir(), "credentials.db")
	store, _ := newTestStoreAt(t, path, testMasterKey())
	observer := mustStoreReference(t, PurposeObserver, 1)
	operator := mustStoreReference(t, PurposeOperator, 1)
	if err := store.Insert(context.Background(), observer, "observer", []byte("observer-secret")); err != nil {
		t.Fatal(err)
	}
	if err := store.Insert(context.Background(), operator, "operator", []byte("operator-secret")); err != nil {
		t.Fatal(err)
	}
	if err := store.Revoke(context.Background(), observer); err != nil {
		t.Fatal(err)
	}
	if _, err := store.Resolve(context.Background(), observer); !errors.Is(err, ErrObserverCredentialRevoked) {
		t.Fatalf("revoked observer error = %v", err)
	}
	if _, err := store.Resolve(context.Background(), operator); err != nil {
		t.Fatalf("operator affected by observer revoke: %v", err)
	}
	if err := store.Close(); err != nil {
		t.Fatal(err)
	}
	store, err := OpenStore(path, testMasterKeyProvider{key: testMasterKey()})
	if err != nil {
		t.Fatal(err)
	}
	defer store.Close()
	if _, err := store.Resolve(context.Background(), observer); !errors.Is(err, ErrObserverCredentialRevoked) {
		t.Fatalf("reopen revoked observer error = %v", err)
	}

	oldRef := mustStoreReference(t, PurposeOperator, 2)
	if err := store.Insert(context.Background(), oldRef, "operator", []byte("operator-v1")); err != nil {
		t.Fatal(err)
	}
	newRef := oldRef
	newRef.Version = 3
	if err := store.Rotate(context.Background(), oldRef, newRef, "operator", []byte("operator-v2")); err != nil {
		t.Fatal(err)
	}
	if _, err := store.Resolve(context.Background(), oldRef); !errors.Is(err, ErrOperatorCredentialRetired) {
		t.Fatalf("old version after rotation = %v", err)
	}
	if _, err := store.Resolve(context.Background(), newRef); err != nil {
		t.Fatalf("new version after rotation = %v", err)
	}

	failedRef := newRef
	failedRef.Version = 4
	if err := store.Rotate(context.Background(), oldRef, failedRef, "operator", []byte("operator-v3")); err == nil {
		t.Fatal("rotation from retired version unexpectedly succeeded")
	}
	if _, err := store.Resolve(context.Background(), newRef); err != nil {
		t.Fatalf("failed rotation affected active replacement: %v", err)
	}
	metadata, err := store.ListMetadata(context.Background())
	if err != nil {
		t.Fatal(err)
	}
	for _, item := range metadata {
		if item.CredentialRef == newRef.CredentialRef && item.Version == newRef.Version && item.Status != CredentialStatusActive {
			t.Fatalf("replacement lifecycle changed after failed rotation: %#v", item)
		}
	}
}

func TestCredentialStoreConcurrentExactReadsAndWrongKey(t *testing.T) {
	path := filepath.Join(t.TempDir(), "credentials.db")
	store, _ := newTestStoreAt(t, path, testMasterKey())
	ref := mustStoreReference(t, PurposeObserver, 1)
	if err := store.Insert(context.Background(), ref, "user", []byte("secret")); err != nil {
		t.Fatal(err)
	}
	var wg sync.WaitGroup
	for i := 0; i < 16; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			if _, err := store.Resolve(context.Background(), ref); err != nil {
				t.Errorf("concurrent resolve: %v", err)
			}
		}()
	}
	wg.Wait()
	if err := store.Close(); err != nil {
		t.Fatal(err)
	}
	wrong, err := OpenStore(path, testMasterKeyProvider{key: testMasterKeyWithByte(1)})
	if err != nil {
		t.Fatal(err)
	}
	defer wrong.Close()
	if _, err := wrong.Resolve(context.Background(), ref); !errors.Is(err, ErrCredentialPayloadInvalid) {
		t.Fatalf("wrong key error = %v", err)
	}
}

type storeRow struct {
	ciphertext []byte
	nonce      []byte
}

func readStoreRow(t *testing.T, store *Store, ref Reference) storeRow {
	t.Helper()
	var row storeRow
	if err := store.db.QueryRow(`SELECT encrypted_secret, nonce FROM credentials WHERE credential_ref = ? AND version = ?`, ref.CredentialRef, ref.Version).Scan(&row.ciphertext, &row.nonce); err != nil {
		t.Fatal(err)
	}
	return row
}

func newTestStore(t *testing.T, key []byte) (*Store, string) {
	t.Helper()
	return newTestStoreAt(t, filepath.Join(t.TempDir(), "credentials.db"), key)
}

func newTestStoreAt(t *testing.T, path string, key []byte) (*Store, string) {
	t.Helper()
	store, err := OpenStore(path, testMasterKeyProvider{key: key})
	if err != nil {
		t.Fatal(err)
	}
	return store, path
}

func testMasterKey() []byte { return testMasterKeyWithByte(0) }
func testMasterKeyWithByte(value byte) []byte {
	return append([]byte("0123456789012345678901234567890"), value)
}

func mustStoreReference(t *testing.T, purpose Purpose, version int) Reference {
	t.Helper()
	ref, err := ParseReference(Reference{
		TenantRef: "tenant-1", RouterRef: "router-1", AgentRef: "agent-1",
		InstallationID: InstallationIdentity("installation-1"), CredentialRef: fmt.Sprintf("router-1/%s/v%d", strings.ToLower(string(purpose)), version), Purpose: purpose, Version: version,
	})
	if err != nil {
		t.Fatal(err)
	}
	return ref
}
