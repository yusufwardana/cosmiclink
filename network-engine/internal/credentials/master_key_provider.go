package credentials

import (
	"context"
	"errors"
)

var (
	ErrMasterKeyProviderUnsupported  = errors.New("production master key provider unsupported on this platform")
	ErrMasterKeyFileInvalid          = errors.New("production master key file invalid")
	ErrMasterKeyInstallationMismatch = errors.New("production master key installation mismatch")
	ErrMasterKeyAlreadyInitialized   = errors.New("production master key already initialized")
)

// ProductionMasterKeyProvider owns the installation-scoped production key.
// It is intentionally separate from the deterministic test providers used by
// the credential-store tests.
type ProductionMasterKeyProvider struct {
	path           string
	installationID InstallationIdentity
}

// NewProductionMasterKeyProvider creates a platform provider. It does not
// create or modify key material; initialization is explicit.
func NewProductionMasterKeyProvider(path string, installationID InstallationIdentity) (MasterKeyProvider, error) {
	if path == "" || !installationID.Valid() {
		return nil, ErrMasterKeyFileInvalid
	}
	return newPlatformMasterKeyProvider(path, installationID)
}

// InitializeProductionMasterKey explicitly creates the installation key. It
// never overwrites an existing file and never regenerates a missing key during
// normal MasterKey calls.
func InitializeProductionMasterKey(path string, installationID InstallationIdentity) error {
	if path == "" || !installationID.Valid() {
		return ErrMasterKeyFileInvalid
	}
	return initializePlatformMasterKey(path, installationID)
}

func (p *ProductionMasterKeyProvider) MasterKey(context.Context) ([]byte, error) {
	return nil, ErrMasterKeyProviderUnsupported
}
