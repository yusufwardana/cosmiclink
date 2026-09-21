//go:build windows

package agent

import (
	"bytes"
	"context"
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

const agentTokenFileVersion = 1

type protectedAgentTokenFile struct {
	FormatVersion  int    `json:"format_version"`
	InstallationID string `json:"installation_id"`
	Protector      string `json:"protector"`
	ProtectedToken string `json:"protected_token"`
	CreatedAt      string `json:"created_at"`
}

func StoreProtectedAgentToken(ctx context.Context, dataDir, token string) error {
	if err := ctx.Err(); err != nil {
		return err
	}
	if token == "" || len(token) > 512 {
		return ErrAgentTokenUnavailable
	}
	installation, err := OpenInstallation(ctx, dataDir)
	if err != nil {
		return ErrAgentTokenUnavailable
	}
	protected, err := protectAgentToken([]byte(token), string(installation.InstallationID))
	if err != nil {
		return ErrAgentTokenUnavailable
	}
	file := protectedAgentTokenFile{FormatVersion: agentTokenFileVersion, InstallationID: string(installation.InstallationID), Protector: "windows-dpapi-current-user", ProtectedToken: base64.StdEncoding.EncodeToString(protected), CreatedAt: time.Now().UTC().Format(time.RFC3339Nano)}
	encoded, err := json.Marshal(file)
	if err != nil {
		return ErrAgentTokenUnavailable
	}
	return atomicWriteTokenFile(tokenFilePath(dataDir), encoded)
}

func loadProtectedAgentToken(dataDir string) (string, error) {
	installation, err := OpenInstallation(context.Background(), dataDir)
	if err != nil {
		return "", ErrAgentTokenUnavailable
	}
	encoded, err := os.ReadFile(tokenFilePath(dataDir))
	if err != nil {
		return "", ErrAgentTokenUnavailable
	}
	var file protectedAgentTokenFile
	decoder := json.NewDecoder(bytes.NewReader(encoded))
	decoder.DisallowUnknownFields()
	if decoder.Decode(&file) != nil || decoder.Decode(&struct{}{}) != io.EOF || file.FormatVersion != agentTokenFileVersion || file.Protector != "windows-dpapi-current-user" || file.InstallationID != string(installation.InstallationID) || file.ProtectedToken == "" || file.CreatedAt == "" {
		return "", ErrAgentTokenUnavailable
	}
	protected, err := base64.StdEncoding.DecodeString(file.ProtectedToken)
	if err != nil {
		return "", ErrAgentTokenUnavailable
	}
	plain, err := unprotectAgentToken(protected, file.InstallationID)
	if err != nil || len(plain) == 0 || len(plain) > 512 {
		return "", ErrAgentTokenUnavailable
	}
	return string(plain), nil
}

func protectAgentToken(value []byte, installationID string) ([]byte, error) {
	return protectAgentTokenDPAPI(value, installationID)
}
func unprotectAgentToken(value []byte, installationID string) ([]byte, error) {
	return unprotectAgentTokenDPAPI(value, installationID)
}

func protectAgentTokenDPAPI(value []byte, installationID string) ([]byte, error) {
	in := windows.DataBlob{Size: uint32(len(value)), Data: &value[0]}
	entropyBytes := []byte(installationID)
	entropy := windows.DataBlob{Size: uint32(len(entropyBytes)), Data: &entropyBytes[0]}
	var out windows.DataBlob
	if err := windows.CryptProtectData(&in, nil, &entropy, 0, nil, 0, &out); err != nil {
		return nil, err
	}
	defer windows.LocalFree(windows.Handle(unsafe.Pointer(out.Data)))
	return append([]byte(nil), unsafe.Slice(out.Data, out.Size)...), nil
}

func unprotectAgentTokenDPAPI(value []byte, installationID string) ([]byte, error) {
	if len(value) == 0 {
		return nil, errors.New("empty protected token")
	}
	in := windows.DataBlob{Size: uint32(len(value)), Data: &value[0]}
	entropyBytes := []byte(installationID)
	entropy := windows.DataBlob{Size: uint32(len(entropyBytes)), Data: &entropyBytes[0]}
	var out windows.DataBlob
	if err := windows.CryptUnprotectData(&in, nil, &entropy, 0, nil, 0, &out); err != nil {
		return nil, err
	}
	defer windows.LocalFree(windows.Handle(unsafe.Pointer(out.Data)))
	return append([]byte(nil), unsafe.Slice(out.Data, out.Size)...), nil
}

func atomicWriteTokenFile(path string, data []byte) error {
	if err := os.MkdirAll(filepath.Dir(path), 0700); err != nil {
		return err
	}
	tmp, err := os.CreateTemp(filepath.Dir(path), ".agent-token-*.tmp")
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
