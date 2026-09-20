//go:build windows

package credentials

import (
	"bytes"
	"context"
	"crypto/rand"
	"encoding/base64"
	"encoding/json"
	"errors"
	"io"
	"os"
	"path/filepath"
	"time"
	"unsafe"

	"golang.org/x/sys/windows"
)

const (
	masterKeyFileVersion = 1
	masterKeyProtector   = "windows-dpapi-current-user"
)

type protectedMasterKeyFile struct {
	FormatVersion  int                  `json:"format_version"`
	InstallationID InstallationIdentity `json:"installation_id"`
	Protector      string               `json:"protector"`
	ProtectedKey   string               `json:"protected_key"`
	CreatedAt      time.Time            `json:"created_at"`
}

func newPlatformMasterKeyProvider(path string, installationID InstallationIdentity) (MasterKeyProvider, error) {
	return &windowsMasterKeyProvider{path: path, installationID: installationID}, nil
}

func initializePlatformMasterKey(path string, installationID InstallationIdentity) error {
	if _, err := os.Stat(path); err == nil {
		return ErrMasterKeyAlreadyInitialized
	} else if !errors.Is(err, os.ErrNotExist) {
		return ErrMasterKeyFileInvalid
	}
	key := make([]byte, 32)
	if _, err := rand.Read(key); err != nil {
		return ErrMasterKeyUnavailable
	}
	protected, err := protectWindowsMasterKey(key, installationID)
	for i := range key {
		key[i] = 0
	}
	if err != nil {
		return ErrMasterKeyUnavailable
	}
	file := protectedMasterKeyFile{FormatVersion: masterKeyFileVersion, InstallationID: installationID, Protector: masterKeyProtector, ProtectedKey: base64.StdEncoding.EncodeToString(protected), CreatedAt: time.Now().UTC()}
	encoded, err := json.Marshal(file)
	if err != nil {
		return ErrMasterKeyFileInvalid
	}
	if err := atomicWriteMasterKeyFile(path, encoded); err != nil {
		return ErrMasterKeyFileInvalid
	}
	provider := &windowsMasterKeyProvider{path: path, installationID: installationID}
	if _, err := provider.MasterKey(context.Background()); err != nil {
		_ = os.Remove(path)
		return err
	}
	return nil
}

type windowsMasterKeyProvider struct {
	path           string
	installationID InstallationIdentity
}

func (p *windowsMasterKeyProvider) MasterKey(context.Context) ([]byte, error) {
	encoded, err := os.ReadFile(p.path)
	if err != nil {
		return nil, ErrMasterKeyUnavailable
	}
	var file protectedMasterKeyFile
	decoder := json.NewDecoder(bytes.NewReader(encoded))
	decoder.DisallowUnknownFields()
	if decoder.Decode(&file) != nil || decoder.Decode(&struct{}{}) != io.EOF || file.FormatVersion != masterKeyFileVersion || file.Protector != masterKeyProtector || file.InstallationID == "" || !file.InstallationID.Valid() || file.CreatedAt.IsZero() || file.ProtectedKey == "" {
		return nil, ErrMasterKeyFileInvalid
	}
	if file.InstallationID != p.installationID {
		return nil, ErrMasterKeyInstallationMismatch
	}
	protected, err := base64.StdEncoding.DecodeString(file.ProtectedKey)
	if err != nil || len(protected) == 0 {
		return nil, ErrMasterKeyFileInvalid
	}
	key, err := unprotectWindowsMasterKey(protected, p.installationID)
	if err != nil || len(key) != 32 {
		for i := range key {
			key[i] = 0
		}
		return nil, ErrMasterKeyUnavailable
	}
	return key, nil
}

func atomicWriteMasterKeyFile(path string, data []byte) error {
	if err := os.MkdirAll(filepath.Dir(path), 0700); err != nil {
		return err
	}
	tmp, err := os.CreateTemp(filepath.Dir(path), ".master-key-*.tmp")
	if err != nil {
		return err
	}
	tmpName := tmp.Name()
	defer os.Remove(tmpName)
	if err := tmp.Chmod(0600); err != nil {
		_ = tmp.Close()
		return err
	}
	if _, err := tmp.Write(data); err != nil {
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

func protectWindowsMasterKey(key []byte, installationID InstallationIdentity) ([]byte, error) {
	in := windows.DataBlob{Size: uint32(len(key)), Data: &key[0]}
	entropyBytes := []byte(installationID)
	entropy := windows.DataBlob{Size: uint32(len(entropyBytes)), Data: &entropyBytes[0]}
	var out windows.DataBlob
	if err := windows.CryptProtectData(&in, nil, &entropy, 0, nil, 0, &out); err != nil {
		return nil, err
	}
	defer windows.LocalFree(windows.Handle(unsafe.Pointer(out.Data)))
	return append([]byte(nil), unsafe.Slice(out.Data, out.Size)...), nil
}

func unprotectWindowsMasterKey(protected []byte, installationID InstallationIdentity) ([]byte, error) {
	in := windows.DataBlob{Size: uint32(len(protected)), Data: &protected[0]}
	entropyBytes := []byte(installationID)
	entropy := windows.DataBlob{Size: uint32(len(entropyBytes)), Data: &entropyBytes[0]}
	var out windows.DataBlob
	if err := windows.CryptUnprotectData(&in, nil, &entropy, 0, nil, 0, &out); err != nil {
		return nil, err
	}
	defer windows.LocalFree(windows.Handle(unsafe.Pointer(out.Data)))
	return append([]byte(nil), unsafe.Slice(out.Data, out.Size)...), nil
}
