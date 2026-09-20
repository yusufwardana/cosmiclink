package agent

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"time"
)

const bootstrapFileVersion = 1

var (
	ErrAgentBootstrapRequired           = errors.New("agent bootstrap required")
	ErrAgentBootstrapInvalid            = errors.New("agent bootstrap invalid")
	ErrAgentBootstrapVersionUnsupported = errors.New("agent bootstrap version unsupported")
	ErrAgentReferenceInvalid            = errors.New("agent reference invalid")
	ErrAgentReferenceMismatch           = errors.New("agent reference mismatch")
	agentReferencePattern               = regexp.MustCompile(`\A[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}\z`)
)

type bootstrapFile struct {
	FormatVersion int       `json:"format_version"`
	AgentRef      string    `json:"agent_ref"`
	BoundAt       time.Time `json:"bound_at"`
}

type heartbeatResponse struct {
	Identifier string `json:"identifier"`
	Status     string `json:"status"`
}

type BootstrapState struct {
	path     string
	agentRef string
}

func NewBootstrapState(path string) (*BootstrapState, error) {
	state := &BootstrapState{path: path}
	if path == "" {
		return state, nil
	}
	encoded, err := os.ReadFile(path)
	if errors.Is(err, os.ErrNotExist) {
		return state, nil
	}
	if err != nil {
		return nil, ErrAgentBootstrapInvalid
	}
	file, err := decodeBootstrapFile(encoded)
	if err != nil {
		return nil, err
	}
	state.agentRef = file.AgentRef
	return state, nil
}

func (s *BootstrapState) AgentRef() string {
	if s == nil {
		return ""
	}
	return s.agentRef
}

func (s *BootstrapState) Bind(ctx context.Context, encoded []byte) error {
	if s == nil {
		return ErrAgentBootstrapRequired
	}
	if err := ctx.Err(); err != nil {
		return err
	}
	response, err := decodeHeartbeatResponse(encoded)
	if err != nil {
		return err
	}
	if s.agentRef != "" {
		if s.agentRef != response.Identifier {
			return ErrAgentReferenceMismatch
		}
		return nil
	}
	file := bootstrapFile{FormatVersion: bootstrapFileVersion, AgentRef: response.Identifier, BoundAt: time.Now().UTC()}
	if s.path != "" {
		if err := atomicWriteBootstrapFile(s.path, file); err != nil {
			return ErrAgentBootstrapInvalid
		}
	}
	s.agentRef = response.Identifier
	return nil
}

func decodeHeartbeatResponse(encoded []byte) (heartbeatResponse, error) {
	var response heartbeatResponse
	decoder := json.NewDecoder(bytes.NewReader(encoded))
	decoder.DisallowUnknownFields()
	if err := decoder.Decode(&response); err != nil {
		return heartbeatResponse{}, ErrAgentBootstrapInvalid
	}
	if err := decoder.Decode(&struct{}{}); !errors.Is(err, io.EOF) {
		return heartbeatResponse{}, ErrAgentBootstrapInvalid
	}
	if response.Status != "ok" {
		return heartbeatResponse{}, ErrAgentBootstrapInvalid
	}
	if !agentReferencePattern.MatchString(response.Identifier) {
		return heartbeatResponse{}, ErrAgentReferenceInvalid
	}
	return response, nil
}

func decodeBootstrapFile(encoded []byte) (bootstrapFile, error) {
	var file bootstrapFile
	decoder := json.NewDecoder(bytes.NewReader(encoded))
	decoder.DisallowUnknownFields()
	if err := decoder.Decode(&file); err != nil {
		return bootstrapFile{}, ErrAgentBootstrapInvalid
	}
	if err := decoder.Decode(&struct{}{}); !errors.Is(err, io.EOF) {
		return bootstrapFile{}, ErrAgentBootstrapInvalid
	}
	if file.FormatVersion != bootstrapFileVersion {
		return bootstrapFile{}, ErrAgentBootstrapVersionUnsupported
	}
	if !agentReferencePattern.MatchString(file.AgentRef) || file.BoundAt.IsZero() {
		return bootstrapFile{}, ErrAgentBootstrapInvalid
	}
	return file, nil
}

func atomicWriteBootstrapFile(path string, file bootstrapFile) error {
	encoded, err := json.Marshal(file)
	if err != nil {
		return err
	}
	if err := os.MkdirAll(filepath.Dir(path), 0700); err != nil {
		return err
	}
	tmp, err := os.CreateTemp(filepath.Dir(path), ".agent-bootstrap-*.tmp")
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
