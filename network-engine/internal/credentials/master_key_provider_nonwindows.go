//go:build !windows

package credentials

func newPlatformMasterKeyProvider(string, InstallationIdentity) (MasterKeyProvider, error) {
	return nil, ErrMasterKeyProviderUnsupported
}

func initializePlatformMasterKey(string, InstallationIdentity) error {
	return ErrMasterKeyProviderUnsupported
}
