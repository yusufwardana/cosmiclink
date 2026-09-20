package credentials

import (
	"context"
	"database/sql"
	"errors"
	"os"
	"path/filepath"
	"testing"
)

func TestExplicitStoreInitializationAndExistingOpenSemantics(t *testing.T) {
	path := filepath.Join(t.TempDir(), "credentials.db")
	provider := testMasterKeyProvider{key: testMasterKey()}
	if _, err := OpenExistingStore(path, provider); !errors.Is(err, ErrCredentialStoreNotInitialized) {
		t.Fatalf("missing store open error = %v", err)
	}
	store, err := InitializeStore(path, provider)
	if err != nil {
		t.Fatal(err)
	}
	store.Close()
	if _, err := InitializeStore(path, provider); !errors.Is(err, ErrCredentialStoreAlreadyInitialized) {
		t.Fatalf("repeated store init error = %v", err)
	}
	reopened, err := OpenExistingStore(path, provider)
	if err != nil {
		t.Fatal(err)
	}
	reopened.Close()
	if _, err := os.Stat(path); err != nil {
		t.Fatal(err)
	}
}

func TestExplicitStoreRejectsEmptyUnrelatedPartialAndUnsupportedDatabases(t *testing.T) {
	provider := testMasterKeyProvider{key: testMasterKey()}
	for name, setup := range map[string]func(string) error{
		"empty": func(path string) error {
			db, err := sql.Open("sqlite", path)
			if err != nil {
				return err
			}
			return db.Close()
		},
		"unrelated": func(path string) error {
			db, err := sql.Open("sqlite", path)
			if err != nil {
				return err
			}
			_, err = db.Exec(`CREATE TABLE unrelated (value TEXT)`)
			closeErr := db.Close()
			if err != nil {
				return err
			}
			return closeErr
		},
		"partial": func(path string) error {
			db, err := sql.Open("sqlite", path)
			if err != nil {
				return err
			}
			_, err = db.Exec(`CREATE TABLE store_metadata (name TEXT PRIMARY KEY, version INTEGER NOT NULL)`)
			closeErr := db.Close()
			if err != nil {
				return err
			}
			return closeErr
		},
		"unsupported": func(path string) error {
			db, err := sql.Open("sqlite", path)
			if err != nil {
				return err
			}
			_, err = db.Exec(`CREATE TABLE store_metadata (name TEXT PRIMARY KEY, version INTEGER NOT NULL); INSERT INTO store_metadata VALUES ('cosmiclink.credentials', 99)`)
			closeErr := db.Close()
			if err != nil {
				return err
			}
			return closeErr
		},
	} {
		t.Run(name, func(t *testing.T) {
			path := filepath.Join(t.TempDir(), "credentials.db")
			if err := setup(path); err != nil {
				t.Fatal(err)
			}
			if _, err := OpenExistingStore(path, provider); err == nil {
				t.Fatal("invalid database accepted")
			}
		})
	}
}

func TestNextVersionUsesMaximumHistoryAndIsScopeExact(t *testing.T) {
	store, _ := newTestStore(t, testMasterKey())
	defer store.Close()
	base := mustStoreReference(t, PurposeObserver, 1)
	for _, version := range []int{1, 3} {
		ref := base
		ref.Version = version
		if err := store.Insert(context.Background(), ref, "user", []byte("secret")); err != nil {
			t.Fatal(err)
		}
	}
	next, err := store.NextVersion(context.Background(), base)
	if err != nil || next != 4 {
		t.Fatalf("next version = %d, %v", next, err)
	}
	other := base
	other.CredentialRef = "router-1/OPERATOR/v1"
	other.Purpose = PurposeOperator
	next, err = store.NextVersion(context.Background(), other)
	if err != nil || next != 1 {
		t.Fatalf("isolated next version = %d, %v", next, err)
	}
}
