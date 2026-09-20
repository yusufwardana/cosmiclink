package agent

import (
	"bytes"
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"time"

	"cosmiclink/network-engine/internal/credentials"
)

const installationFileVersion = 1

var (
	ErrAgentNotBootstrapped                = errors.New("agent not bootstrapped")
	ErrAgentNotInitialized                 = errors.New("agent not initialized")
	ErrAgentAlreadyInitialized             = errors.New("agent already initialized")
	ErrAgentInstallationInvalid            = errors.New("agent installation invalid")
	ErrAgentInstallationCorrupt            = errors.New("agent installation corrupt")
	ErrAgentInstallationVersionUnsupported = errors.New("agent installation version unsupported")
	ErrAgentInstallationIdentityMismatch   = errors.New("agent installation identity mismatch")
	installationIDPattern                  = regexp.MustCompile(`\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z`)
)

type installationFile struct {
	FormatVersion  int                              `json:"format_version"`
	InstallationID credentials.InstallationIdentity `json:"installation_id"`
	AgentRef       string                           `json:"agent_ref"`
	CreatedAt      time.Time                        `json:"created_at"`
}

type Installation struct {
	InstallationID credentials.InstallationIdentity
	AgentRef       string
	CreatedAt      time.Time
	dataDir        string
}

// InitializeCredentialStore explicitly creates the service-owned store only
// after the installation and installation-scoped master key have been opened.
func InitializeCredentialStore(ctx context.Context, dataDir string) (*credentials.Store, error) {
	installation, err := OpenInstallation(ctx, dataDir)
	if err != nil {
		return nil, err
	}
	_, _, keyPath, storePath := installationPaths(dataDir)
	provider, err := credentials.NewProductionMasterKeyProvider(keyPath, installation.InstallationID)
	if err != nil {
		return nil, credentials.ErrMasterKeyUnavailable
	}
	return credentials.InitializeStore(storePath, provider)
}

// OpenCredentialStore opens only an already initialized service-owned store.
// It never creates a database or schema and validates the installation first.
func OpenCredentialStore(ctx context.Context, dataDir string) (*credentials.Store, error) {
	installation, err := OpenInstallation(ctx, dataDir)
	if err != nil {
		return nil, err
	}
	_, _, keyPath, storePath := installationPaths(dataDir)
	provider, err := credentials.NewProductionMasterKeyProvider(keyPath, installation.InstallationID)
	if err != nil {
		return nil, credentials.ErrMasterKeyUnavailable
	}
	return credentials.OpenExistingStore(storePath, provider)
}

func validInstallationID(value string) bool { return installationIDPattern.MatchString(value) }

func installationPaths(dataDir string) (bootstrap, installation, key, store string) {
	return filepath.Join(dataDir, "agent-bootstrap.json"), filepath.Join(dataDir, "installation.json"), filepath.Join(dataDir, "master-key.protected"), filepath.Join(dataDir, "credentials.db")
}

// provisionLocalInstallation completes the local production provisioning
// boundary only after authenticated bootstrap has persisted Core's authoritative
// Agent reference. It initializes an entirely fresh state or validates an
// entirely existing state; partial and corrupt state always fail closed.
func provisionLocalInstallation(ctx context.Context, dataDir string) error {
	_, installationPath, keyPath, storePath := installationPaths(dataDir)
	installationExists, err := regularFileExists(installationPath)
	if err != nil {
		return provisioningError(err)
	}
	keyExists, err := regularFileExists(keyPath)
	if err != nil {
		return provisioningError(err)
	}
	storeExists, err := regularFileExists(storePath)
	if err != nil {
		return provisioningError(err)
	}

	if !installationExists && !keyExists && !storeExists {
		if _, err := InitializeInstallation(ctx, dataDir); err != nil {
			return provisioningError(err)
		}
		store, err := InitializeCredentialStore(ctx, dataDir)
		if err != nil {
			return provisioningError(err)
		}
		if err := store.Close(); err != nil {
			return provisioningError(err)
		}
	} else if !installationExists || !keyExists || !storeExists {
		return provisioningError(ErrAgentProvisioningIncomplete)
	}

	if _, err := OpenInstallation(ctx, dataDir); err != nil {
		return provisioningError(err)
	}
	store, err := OpenCredentialStore(ctx, dataDir)
	if err != nil {
		return provisioningError(err)
	}
	if err := store.Close(); err != nil {
		return provisioningError(err)
	}
	return nil
}

func regularFileExists(path string) (bool, error) {
	info, err := os.Stat(path)
	if errors.Is(err, os.ErrNotExist) {
		return false, nil
	}
	if err != nil {
		return false, err
	}
	if !info.Mode().IsRegular() {
		return false, ErrAgentProvisioningIncomplete
	}
	return true, nil
}

func provisioningError(err error) error {
	if err == nil || errors.Is(err, ErrAgentProvisioningFailed) {
		return err
	}
	return fmt.Errorf("%w: %w", ErrAgentProvisioningFailed, err)
}

func InitializeInstallation(ctx context.Context, dataDir string) (Installation, error) {
	if err := ctx.Err(); err != nil {
		return Installation{}, err
	}
	if dataDir == "" {
		return Installation{}, ErrAgentInstallationInvalid
	}
	bootstrapPath, installationPath, keyPath, _ := installationPaths(dataDir)
	bootstrap, err := NewBootstrapState(bootstrapPath)
	if err != nil {
		return Installation{}, ErrAgentInstallationCorrupt
	}
	if bootstrap.AgentRef() == "" {
		return Installation{}, ErrAgentNotBootstrapped
	}
	if _, err := os.Stat(installationPath); err == nil {
		if _, decodeErr := loadInstallation(installationPath, bootstrap.AgentRef()); decodeErr != nil {
			return Installation{}, decodeErr
		}
		return Installation{}, ErrAgentAlreadyInitialized
	} else if !errors.Is(err, os.ErrNotExist) {
		return Installation{}, ErrAgentInstallationInvalid
	}

	installationID, err := newInstallationID()
	if err != nil {
		return Installation{}, ErrAgentInstallationInvalid
	}
	if err := credentials.InitializeProductionMasterKey(keyPath, installationID); err != nil {
		return Installation{}, err
	}
	provider, err := credentials.NewProductionMasterKeyProvider(keyPath, installationID)
	if err != nil {
		_ = os.Remove(keyPath)
		return Installation{}, err
	}
	if _, err := provider.MasterKey(ctx); err != nil {
		_ = os.Remove(keyPath)
		return Installation{}, err
	}
	file := installationFile{FormatVersion: installationFileVersion, InstallationID: installationID, AgentRef: bootstrap.AgentRef(), CreatedAt: time.Now().UTC()}
	if err := atomicWriteInstallationFile(installationPath, file); err != nil {
		_ = os.Remove(keyPath)
		return Installation{}, ErrAgentInstallationInvalid
	}
	opened, err := OpenInstallation(ctx, dataDir)
	if err != nil {
		_ = os.Remove(installationPath)
		_ = os.Remove(keyPath)
		return Installation{}, err
	}
	return opened, nil
}

func OpenInstallation(ctx context.Context, dataDir string) (Installation, error) {
	if err := ctx.Err(); err != nil {
		return Installation{}, err
	}
	if dataDir == "" {
		return Installation{}, ErrAgentInstallationInvalid
	}
	bootstrapPath, installationPath, keyPath, _ := installationPaths(dataDir)
	bootstrap, err := NewBootstrapState(bootstrapPath)
	if err != nil {
		return Installation{}, ErrAgentInstallationCorrupt
	}
	if bootstrap.AgentRef() == "" {
		return Installation{}, ErrAgentNotBootstrapped
	}
	file, err := loadInstallation(installationPath, bootstrap.AgentRef())
	if err != nil {
		return Installation{}, err
	}
	provider, err := credentials.NewProductionMasterKeyProvider(keyPath, file.InstallationID)
	if err != nil {
		return Installation{}, credentials.ErrMasterKeyUnavailable
	}
	if _, err := provider.MasterKey(ctx); err != nil {
		if errors.Is(err, credentials.ErrMasterKeyInstallationMismatch) {
			return Installation{}, credentials.ErrMasterKeyInstallationMismatch
		}
		return Installation{}, credentials.ErrMasterKeyUnavailable
	}
	return Installation{InstallationID: file.InstallationID, AgentRef: file.AgentRef, CreatedAt: file.CreatedAt, dataDir: dataDir}, nil
}

func loadInstallation(path, agentRef string) (installationFile, error) {
	encoded, err := os.ReadFile(path)
	if errors.Is(err, os.ErrNotExist) {
		return installationFile{}, ErrAgentNotInitialized
	}
	if err != nil {
		return installationFile{}, ErrAgentInstallationCorrupt
	}
	var file installationFile
	decoder := json.NewDecoder(bytes.NewReader(encoded))
	decoder.DisallowUnknownFields()
	if err := decoder.Decode(&file); err != nil {
		return installationFile{}, ErrAgentInstallationCorrupt
	}
	if err := decoder.Decode(&struct{}{}); !errors.Is(err, io.EOF) {
		return installationFile{}, ErrAgentInstallationCorrupt
	}
	if file.FormatVersion != installationFileVersion {
		return installationFile{}, ErrAgentInstallationVersionUnsupported
	}
	if !validInstallationID(string(file.InstallationID)) || !agentReferencePattern.MatchString(file.AgentRef) || file.CreatedAt.IsZero() {
		return installationFile{}, ErrAgentInstallationInvalid
	}
	if file.AgentRef != agentRef {
		return installationFile{}, ErrAgentReferenceMismatch
	}
	return file, nil
}

func newInstallationID() (credentials.InstallationIdentity, error) {
	var raw [16]byte
	if _, err := rand.Read(raw[:]); err != nil {
		return "", err
	}
	raw[6] = (raw[6] & 0x0f) | 0x40
	raw[8] = (raw[8] & 0x3f) | 0x80
	encoded := hex.EncodeToString(raw[:])
	return credentials.InstallationIdentity(encoded[0:8] + "-" + encoded[8:12] + "-" + encoded[12:16] + "-" + encoded[16:20] + "-" + encoded[20:32]), nil
}

func atomicWriteInstallationFile(path string, file installationFile) error {
	encoded, err := json.Marshal(file)
	if err != nil {
		return err
	}
	if err := os.MkdirAll(filepath.Dir(path), 0700); err != nil {
		return err
	}
	tmp, err := os.CreateTemp(filepath.Dir(path), ".installation-*.tmp")
	if err != nil {
		return err
	}
	tmpName := tmp.Name()
	defer os.Remove(tmpName)
	if err := tmp.Chmod(0600); err != nil {
		_ = tmp.Close()
		return err
	}
	if _, err := tmp.Write(encoded); err != nil {
		_ = tmp.Close()
		return err
	}
	if err := tmp.Sync(); err != nil {
		_ = tmp.Close()
		return err
	}
	if err := tmp.Close(); err != nil {
		return err
	}
	return os.Rename(tmpName, path)
}
