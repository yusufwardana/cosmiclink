package credentials

import (
	"context"
	"crypto/aes"
	"crypto/cipher"
	"crypto/rand"
	"database/sql"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"time"

	_ "modernc.org/sqlite"
)

type MasterKeyProvider interface {
	MasterKey(context.Context) ([]byte, error)
}

type CredentialMetadata struct {
	CredentialRef   string               `json:"credential_ref"`
	TenantRef       string               `json:"tenant_ref"`
	RouterRef       string               `json:"router_ref"`
	AgentRef        string               `json:"agent_ref"`
	InstallationID  InstallationIdentity `json:"installation_id"`
	Purpose         Purpose              `json:"purpose"`
	Version         int                  `json:"version"`
	Status          CredentialStatus     `json:"status"`
	CreatedAt       time.Time            `json:"created_at"`
	RotatedAt       *time.Time           `json:"rotated_at,omitempty"`
	RevokedAt       *time.Time           `json:"revoked_at,omitempty"`
	RetiredAt       *time.Time           `json:"retired_at,omitempty"`
	LastValidatedAt *time.Time           `json:"last_validated_at,omitempty"`
}

type Store struct {
	db  *sql.DB
	key []byte
}

const credentialStoreSchemaVersion = 1

var (
	ErrMasterKeyUnavailable              = errors.New("credential master key unavailable")
	ErrMasterKeyInvalid                  = errors.New("credential master key invalid")
	ErrCredentialDuplicate               = errors.New("credential already exists")
	ErrCredentialPayloadInvalid          = errors.New("credential payload invalid")
	ErrCredentialPersistedInvalid        = errors.New("persisted credential record invalid")
	ErrCredentialScopeMismatch           = errors.New("credential scope mismatch")
	ErrCredentialNotFound                = errors.New("credential not found")
	ErrCredentialStoreNotInitialized     = errors.New("credential store not initialized")
	ErrCredentialStoreAlreadyInitialized = errors.New("credential store already initialized")
	ErrCredentialStoreInvalid            = errors.New("credential store invalid")
	ErrCredentialStoreVersionUnsupported = errors.New("credential store version unsupported")
)

func OpenStore(path string, provider MasterKeyProvider) (*Store, error) {
	if err := ensureStorePath(path); err != nil {
		return nil, err
	}
	if _, err := os.Stat(path); errors.Is(err, os.ErrNotExist) {
		return initializeStoreWithProvider(path, provider)
	}
	store, err := openExistingStoreWithProvider(path, provider)
	if errors.Is(err, ErrCredentialStoreInvalid) || errors.Is(err, ErrCredentialStoreVersionUnsupported) {
		return nil, errors.New("credential store schema invalid")
	}
	return store, err
}

func InitializeStore(path string, provider MasterKeyProvider) (*Store, error) {
	if err := ensureStorePath(path); err != nil {
		return nil, err
	}
	if _, err := os.Stat(path); err == nil {
		return nil, ErrCredentialStoreAlreadyInitialized
	} else if !errors.Is(err, os.ErrNotExist) {
		return nil, ErrCredentialStoreInvalid
	}
	return initializeStoreWithProvider(path, provider)
}

func OpenExistingStore(path string, provider MasterKeyProvider) (*Store, error) {
	if err := ensureStorePath(path); err != nil {
		return nil, err
	}
	if _, err := os.Stat(path); errors.Is(err, os.ErrNotExist) {
		return nil, ErrCredentialStoreNotInitialized
	}
	return openExistingStoreWithProvider(path, provider)
}

func initializeStoreWithProvider(path string, provider MasterKeyProvider) (*Store, error) {
	store, err := openDatabase(path, provider)
	if err != nil {
		return nil, err
	}
	if err := initializeSchema(store.db); err != nil {
		_ = store.Close()
		_ = os.Remove(path)
		return nil, ErrCredentialStoreInvalid
	}
	if err := validateSchema(store.db); err != nil {
		_ = store.Close()
		_ = os.Remove(path)
		return nil, ErrCredentialStoreInvalid
	}
	_ = os.Chmod(path, 0600)
	return store, nil
}

func openExistingStoreWithProvider(path string, provider MasterKeyProvider) (*Store, error) {
	store, err := openDatabase(path, provider)
	if err != nil {
		return nil, err
	}
	if err := validateSchema(store.db); err != nil {
		_ = store.Close()
		if errors.Is(err, ErrCredentialStoreVersionUnsupported) {
			return nil, err
		}
		return nil, ErrCredentialStoreInvalid
	}
	return store, nil
}

func openDatabase(path string, provider MasterKeyProvider) (*Store, error) {
	if provider == nil {
		return nil, ErrMasterKeyUnavailable
	}
	key, err := provider.MasterKey(context.Background())
	if err != nil || len(key) == 0 {
		return nil, ErrMasterKeyUnavailable
	}
	if len(key) != 32 {
		return nil, ErrMasterKeyInvalid
	}
	if err := ensureStorePath(path); err != nil {
		return nil, errors.New("credential store path invalid")
	}
	if err := os.MkdirAll(filepath.Dir(path), 0700); err != nil {
		return nil, errors.New("credential store unavailable")
	}
	db, err := sql.Open("sqlite", "file:"+filepath.ToSlash(path)+"?_pragma=busy_timeout(5000)&_pragma=foreign_keys(ON)")
	if err != nil {
		return nil, errors.New("credential store unavailable")
	}
	if err := db.Ping(); err != nil {
		db.Close()
		return nil, errors.New("credential store unavailable")
	}
	return &Store{db: db, key: append([]byte(nil), key...)}, nil
}

func ensureStorePath(path string) error {
	if path == "" || filepath.Ext(path) == "" {
		return errors.New("credential store path invalid")
	}
	return nil
}

func (s *Store) Close() error {
	if s == nil || s.db == nil {
		return nil
	}
	for i := range s.key {
		s.key[i] = 0
	}
	return s.db.Close()
}

// NextVersion returns one greater than the maximum historical version for the
// exact credential scope. Retired and revoked rows remain part of history.
func (s *Store) NextVersion(ctx context.Context, ref Reference) (int, error) {
	parsed, err := ParseReference(ref)
	if err != nil {
		return 0, err
	}
	tx, err := s.db.BeginTx(ctx, nil)
	if err != nil {
		return 0, ErrCredentialStoreInvalid
	}
	defer tx.Rollback()
	var maximum sql.NullInt64
	if err := tx.QueryRowContext(ctx, `SELECT MAX(version) FROM credentials WHERE tenant_ref = ? AND router_ref = ? AND agent_ref = ? AND installation_id = ? AND credential_ref = ? AND purpose = ?`, parsed.TenantRef, parsed.RouterRef, parsed.AgentRef, parsed.InstallationID, parsed.CredentialRef, parsed.Purpose).Scan(&maximum); err != nil {
		return 0, ErrCredentialStoreInvalid
	}
	next := 1
	if maximum.Valid {
		next = int(maximum.Int64) + 1
	}
	if err := tx.Commit(); err != nil {
		return 0, ErrCredentialStoreInvalid
	}
	return next, nil
}

func (s *Store) Insert(ctx context.Context, ref Reference, username string, secret []byte) error {
	parsed, err := ParseReference(ref)
	if err != nil {
		return err
	}
	if username == "" || len(secret) == 0 {
		return purposeError(parsed.Purpose, ErrObserverCredentialInvalid, ErrOperatorCredentialInvalid)
	}
	ciphertext, nonce, err := s.encrypt(parsed, secret)
	if err != nil {
		return err
	}
	now := time.Now().UTC()
	_, err = s.db.ExecContext(ctx, `INSERT INTO credentials (
		credential_ref, tenant_ref, router_ref, agent_ref, installation_id, purpose,
		username, encrypted_secret, nonce, version, status, created_at
	) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`, parsed.CredentialRef, parsed.TenantRef, parsed.RouterRef, parsed.AgentRef, parsed.InstallationID, parsed.Purpose, username, ciphertext, nonce, parsed.Version, CredentialStatusActive, now.Format(time.RFC3339Nano))
	if err != nil {
		if isConstraintError(err) {
			return ErrCredentialDuplicate
		}
		return errors.New("credential store write failed")
	}
	return nil
}

func (s *Store) Resolve(ctx context.Context, ref Reference) (ResolvedCredential, error) {
	parsed, err := ParseReference(ref)
	if err != nil {
		return ResolvedCredential{}, purposeError(ref.Purpose, err, err)
	}
	row := s.db.QueryRowContext(ctx, `SELECT credential_ref, tenant_ref, router_ref, agent_ref, installation_id, purpose,
		username, encrypted_secret, nonce, version, status FROM credentials WHERE credential_ref = ? AND version = ?`, parsed.CredentialRef, parsed.Version)
	var persisted persistedCredential
	if err := row.Scan(&persisted.credentialRef, &persisted.tenantRef, &persisted.routerRef, &persisted.agentRef, &persisted.installationID, &persisted.purpose, &persisted.username, &persisted.ciphertext, &persisted.nonce, &persisted.version, &persisted.status); err != nil {
		if errors.Is(err, sql.ErrNoRows) {
			var persistedVersion int
			if versionErr := s.db.QueryRowContext(ctx, `SELECT version FROM credentials WHERE credential_ref = ? ORDER BY version LIMIT 1`, parsed.CredentialRef).Scan(&persistedVersion); versionErr == nil && persistedVersion < 1 {
				return ResolvedCredential{}, ErrCredentialVersionInvalid
			}
			return ResolvedCredential{}, purposeError(parsed.Purpose, ErrObserverCredentialMissing, ErrOperatorCredentialMissing)
		}
		return ResolvedCredential{}, ErrCredentialPersistedInvalid
	}
	stored, err := persisted.reference()
	if err != nil {
		return ResolvedCredential{}, ErrCredentialPersistedInvalid
	}
	if stored != parsed {
		return ResolvedCredential{}, ErrCredentialScopeMismatch
	}
	if persisted.status != CredentialStatusActive {
		return ResolvedCredential{}, statusError(parsed.Purpose, persisted.status)
	}
	secret, err := s.decrypt(stored, persisted.ciphertext, persisted.nonce)
	if err != nil {
		return ResolvedCredential{}, ErrCredentialPayloadInvalid
	}
	return NewResolvedCredential(persisted.username, secret), nil
}

func (s *Store) ListMetadata(ctx context.Context) ([]CredentialMetadata, error) {
	rows, err := s.db.QueryContext(ctx, `SELECT credential_ref, tenant_ref, router_ref, agent_ref, installation_id, purpose, version, status, CAST(created_at AS TEXT), CAST(rotated_at AS TEXT), CAST(revoked_at AS TEXT), CAST(retired_at AS TEXT), CAST(last_validated_at AS TEXT) FROM credentials ORDER BY credential_ref, version`)
	if err != nil {
		return nil, errors.New("credential metadata unavailable")
	}
	defer rows.Close()
	metadata := make([]CredentialMetadata, 0)
	for rows.Next() {
		var item CredentialMetadata
		var createdAt, rotatedAt, revokedAt, retiredAt, lastValidatedAt any
		if err := rows.Scan(&item.CredentialRef, &item.TenantRef, &item.RouterRef, &item.AgentRef, &item.InstallationID, &item.Purpose, &item.Version, &item.Status, &createdAt, &rotatedAt, &revokedAt, &retiredAt, &lastValidatedAt); err != nil {
			return nil, errors.New("credential metadata invalid")
		}
		var parseErr error
		item.CreatedAt, parseErr = parseStoreTimeValue(createdAt)
		if parseErr != nil {
			return nil, errors.New("credential metadata invalid")
		}
		item.RotatedAt, parseErr = parseOptionalStoreTimeValue(rotatedAt)
		if parseErr != nil {
			return nil, errors.New("credential metadata invalid")
		}
		item.RevokedAt, parseErr = parseOptionalStoreTimeValue(revokedAt)
		if parseErr != nil {
			return nil, errors.New("credential metadata invalid")
		}
		item.RetiredAt, parseErr = parseOptionalStoreTimeValue(retiredAt)
		if parseErr != nil {
			return nil, errors.New("credential metadata invalid")
		}
		item.LastValidatedAt, parseErr = parseOptionalStoreTimeValue(lastValidatedAt)
		if parseErr != nil {
			return nil, errors.New("credential metadata invalid")
		}
		metadata = append(metadata, item)
	}
	if err := rows.Err(); err != nil {
		return nil, errors.New("credential metadata unavailable")
	}
	return metadata, nil
}

// MarkValidated records local-only validation evidence without returning or
// persisting decrypted credential material.
func (s *Store) MarkValidated(ctx context.Context, ref Reference) error {
	parsed, err := ParseReference(ref)
	if err != nil {
		return err
	}
	result, err := s.db.ExecContext(ctx, `UPDATE credentials SET last_validated_at = ? WHERE credential_ref = ? AND version = ? AND tenant_ref = ? AND router_ref = ? AND agent_ref = ? AND installation_id = ? AND purpose = ? AND status = 'ACTIVE'`, time.Now().UTC().Format(time.RFC3339Nano), parsed.CredentialRef, parsed.Version, parsed.TenantRef, parsed.RouterRef, parsed.AgentRef, parsed.InstallationID, parsed.Purpose)
	if err != nil {
		return errors.New("credential validation update failed")
	}
	count, _ := result.RowsAffected()
	if count != 1 {
		return ErrCredentialNotFound
	}
	return nil
}

func parseStoreTime(value string) (time.Time, error) {
	for _, layout := range []string{time.RFC3339Nano, "2006-01-02 15:04:05.999999999-07:00", "2006-01-02 15:04:05.999999999Z07:00", "2006-01-02 15:04:05.999999999 -0700 MST", "2006-01-02 15:04:05"} {
		if parsed, err := time.Parse(layout, value); err == nil {
			return parsed, nil
		}
	}
	return time.Time{}, errors.New("invalid timestamp")
}

func parseStoreTimeValue(value any) (time.Time, error) {
	switch typed := value.(type) {
	case time.Time:
		return typed, nil
	case string:
		return parseStoreTime(typed)
	case []byte:
		return parseStoreTime(string(typed))
	default:
		return time.Time{}, fmt.Errorf("invalid timestamp type %T", value)
	}
}

func parseOptionalStoreTimeValue(value any) (*time.Time, error) {
	if value == nil {
		return nil, nil
	}
	switch typed := value.(type) {
	case string:
		if typed == "" {
			return nil, nil
		}
	case []byte:
		if len(typed) == 0 {
			return nil, nil
		}
	}
	parsed, err := parseStoreTimeValue(value)
	if err != nil {
		return nil, err
	}
	return &parsed, nil
}

func (s *Store) Revoke(ctx context.Context, ref Reference) error {
	return s.transition(ctx, ref, CredentialStatusRevoked)
}
func (s *Store) Retire(ctx context.Context, ref Reference) error {
	return s.transition(ctx, ref, CredentialStatusRetired)
}

func (s *Store) Rotate(ctx context.Context, oldRef, newRef Reference, username string, secret []byte) error {
	oldParsed, err := ParseReference(oldRef)
	if err != nil {
		return err
	}
	newParsed, err := ParseReference(newRef)
	if err != nil {
		return err
	}
	if oldParsed.TenantRef != newParsed.TenantRef || oldParsed.RouterRef != newParsed.RouterRef || oldParsed.AgentRef != newParsed.AgentRef || oldParsed.InstallationID != newParsed.InstallationID || oldParsed.CredentialRef != newParsed.CredentialRef || oldParsed.Purpose != newParsed.Purpose || newParsed.Version <= oldParsed.Version {
		return ErrCredentialScopeMismatch
	}
	if username == "" || len(secret) == 0 {
		return purposeError(newParsed.Purpose, ErrObserverCredentialInvalid, ErrOperatorCredentialInvalid)
	}
	ciphertext, nonce, err := s.encrypt(newParsed, secret)
	if err != nil {
		return err
	}
	tx, err := s.db.BeginTx(ctx, nil)
	if err != nil {
		return errors.New("credential rotation failed")
	}
	defer tx.Rollback()
	var status CredentialStatus
	if err := tx.QueryRowContext(ctx, `SELECT status FROM credentials WHERE credential_ref = ? AND version = ?`, oldParsed.CredentialRef, oldParsed.Version).Scan(&status); err != nil || status != CredentialStatusActive {
		return ErrCredentialScopeMismatch
	}
	now := time.Now().UTC()
	if _, err := tx.ExecContext(ctx, `INSERT INTO credentials (credential_ref, tenant_ref, router_ref, agent_ref, installation_id, purpose, username, encrypted_secret, nonce, version, status, created_at, rotated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`, newParsed.CredentialRef, newParsed.TenantRef, newParsed.RouterRef, newParsed.AgentRef, newParsed.InstallationID, newParsed.Purpose, username, ciphertext, nonce, newParsed.Version, CredentialStatusActive, now.Format(time.RFC3339Nano), now.Format(time.RFC3339Nano)); err != nil {
		return ErrCredentialDuplicate
	}
	if _, err := tx.ExecContext(ctx, `UPDATE credentials SET status = ?, retired_at = ?, rotated_at = ? WHERE credential_ref = ? AND version = ? AND status = ?`, CredentialStatusRetired, now, now, oldParsed.CredentialRef, oldParsed.Version, CredentialStatusActive); err != nil {
		return errors.New("credential rotation failed")
	}
	if err := tx.Commit(); err != nil {
		return errors.New("credential rotation failed")
	}
	return nil
}

type persistedCredential struct {
	credentialRef, tenantRef, routerRef, agentRef, installationID, purpose, username string
	ciphertext, nonce                                                                []byte
	version                                                                          int
	status                                                                           CredentialStatus
}

func (p persistedCredential) reference() (Reference, error) {
	return ParseReference(Reference{TenantRef: p.tenantRef, RouterRef: p.routerRef, AgentRef: p.agentRef, InstallationID: InstallationIdentity(p.installationID), CredentialRef: p.credentialRef, Purpose: Purpose(p.purpose), Version: p.version})
}

func (s *Store) transition(ctx context.Context, ref Reference, status CredentialStatus) error {
	parsed, err := ParseReference(ref)
	if err != nil {
		return err
	}
	now := time.Now().UTC()
	result, err := s.db.ExecContext(ctx, `UPDATE credentials SET status = ?, revoked_at = CASE WHEN ? = 'REVOKED' THEN ? ELSE revoked_at END, retired_at = CASE WHEN ? = 'RETIRED' THEN ? ELSE retired_at END WHERE credential_ref = ? AND version = ? AND tenant_ref = ? AND router_ref = ? AND agent_ref = ? AND installation_id = ? AND purpose = ? AND status = 'ACTIVE'`, status, status, now.Format(time.RFC3339Nano), status, now.Format(time.RFC3339Nano), parsed.CredentialRef, parsed.Version, parsed.TenantRef, parsed.RouterRef, parsed.AgentRef, parsed.InstallationID, parsed.Purpose)
	if err != nil {
		return errors.New("credential state transition failed")
	}
	count, _ := result.RowsAffected()
	if count != 1 {
		return purposeError(parsed.Purpose, ErrObserverCredentialMissing, ErrOperatorCredentialMissing)
	}
	return nil
}

func (s *Store) encrypt(ref Reference, secret []byte) ([]byte, []byte, error) {
	block, err := aes.NewCipher(s.key)
	if err != nil {
		return nil, nil, ErrMasterKeyInvalid
	}
	aead, err := cipher.NewGCM(block)
	if err != nil {
		return nil, nil, ErrMasterKeyInvalid
	}
	nonce := make([]byte, aead.NonceSize())
	if _, err := rand.Read(nonce); err != nil {
		return nil, nil, errors.New("credential encryption failed")
	}
	return aead.Seal(nil, nonce, secret, associatedData(ref)), nonce, nil
}
func (s *Store) decrypt(ref Reference, ciphertext, nonce []byte) ([]byte, error) {
	block, err := aes.NewCipher(s.key)
	if err != nil {
		return nil, err
	}
	aead, err := cipher.NewGCM(block)
	if err != nil || len(nonce) != aead.NonceSize() {
		return nil, errors.New("invalid payload")
	}
	return aead.Open(nil, nonce, ciphertext, associatedData(ref))
}
func associatedData(ref Reference) []byte {
	return []byte(fmt.Sprintf("%s|%s|%s|%s|%s|%s|%d", ref.TenantRef, ref.RouterRef, ref.AgentRef, ref.InstallationID, ref.CredentialRef, ref.Purpose, ref.Version))
}

func statusError(purpose Purpose, status CredentialStatus) error {
	switch status {
	case CredentialStatusRevoked:
		return purposeError(purpose, ErrObserverCredentialRevoked, ErrOperatorCredentialRevoked)
	case CredentialStatusRetired:
		return purposeError(purpose, ErrObserverCredentialRetired, ErrOperatorCredentialRetired)
	default:
		return ErrCredentialStatusInvalid
	}
}
func isConstraintError(err error) bool {
	return err != nil && (stringsContains(err.Error(), "constraint") || stringsContains(err.Error(), "UNIQUE"))
}
func stringsContains(value, needle string) bool {
	for i := 0; i+len(needle) <= len(value); i++ {
		if value[i:i+len(needle)] == needle {
			return true
		}
	}
	return false
}

func initializeSchema(db *sql.DB) error {
	_, err := db.Exec(`CREATE TABLE IF NOT EXISTS credentials (
		credential_ref TEXT NOT NULL,
		tenant_ref TEXT NOT NULL,
		router_ref TEXT NOT NULL,
		agent_ref TEXT NOT NULL,
		installation_id TEXT NOT NULL,
		purpose TEXT NOT NULL CHECK (purpose IN ('OBSERVER', 'OPERATOR')),
		username TEXT NOT NULL,
		encrypted_secret BLOB NOT NULL,
		nonce BLOB NOT NULL,
		version INTEGER NOT NULL CHECK (version > 0),
		status TEXT NOT NULL CHECK (status IN ('ACTIVE', 'REVOKED', 'RETIRED')),
		created_at TEXT NOT NULL,
		rotated_at TEXT,
		revoked_at TEXT,
		retired_at TEXT,
		last_validated_at TEXT,
		PRIMARY KEY (credential_ref, version)
	);
	CREATE INDEX IF NOT EXISTS credentials_scope_idx ON credentials (tenant_ref, router_ref, agent_ref, installation_id, purpose, version);
	CREATE TABLE IF NOT EXISTS store_metadata (
		name TEXT PRIMARY KEY,
		version INTEGER NOT NULL
	);
	INSERT OR IGNORE INTO store_metadata (name, version) VALUES ('cosmiclink.credentials', 1);
	`)
	return err
}

func validateSchema(db *sql.DB) error {
	var markerVersion int
	if err := db.QueryRow(`SELECT version FROM store_metadata WHERE name = 'cosmiclink.credentials'`).Scan(&markerVersion); err != nil {
		return ErrCredentialStoreInvalid
	}
	if markerVersion != credentialStoreSchemaVersion {
		return ErrCredentialStoreVersionUnsupported
	}
	rows, err := db.Query(`PRAGMA table_info(credentials)`)
	if err != nil {
		return err
	}
	defer rows.Close()
	required := map[string]bool{
		"credential_ref": false, "tenant_ref": false, "router_ref": false,
		"agent_ref": false, "installation_id": false, "purpose": false,
		"username": false, "encrypted_secret": false, "nonce": false,
		"version": false, "status": false, "created_at": false,
		"rotated_at": false, "revoked_at": false, "retired_at": false,
		"last_validated_at": false,
	}
	for rows.Next() {
		var cid int
		var name, columnType string
		var notNull, primaryKey int
		var defaultValue any
		if err := rows.Scan(&cid, &name, &columnType, &notNull, &defaultValue, &primaryKey); err != nil {
			return err
		}
		if _, ok := required[name]; ok {
			required[name] = true
		}
	}
	if err := rows.Err(); err != nil {
		return err
	}
	for _, present := range required {
		if !present {
			return errors.New("required credential schema column missing")
		}
	}
	return nil
}
