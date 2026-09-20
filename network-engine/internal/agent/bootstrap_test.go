package agent

import (
	"context"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func TestBootstrapBindsAndPersistsCoreAgentIdentifier(t *testing.T) {
	path := filepath.Join(t.TempDir(), "agent-bootstrap.json")
	state, err := NewBootstrapState(path)
	if err != nil {
		t.Fatal(err)
	}

	if err := state.Bind(context.Background(), []byte(`{"identifier":"550e8400-e29b-41d4-a716-446655440000","status":"ok"}`)); err != nil {
		t.Fatal(err)
	}
	persisted, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	if strings.Contains(string(persisted), "agent-token") {
		t.Fatal("bootstrap state persisted a token")
	}
	var file bootstrapFile
	if err := json.Unmarshal(persisted, &file); err != nil {
		t.Fatal(err)
	}
	if file.AgentRef != "550e8400-e29b-41d4-a716-446655440000" || file.FormatVersion != bootstrapFileVersion || file.BoundAt.IsZero() {
		t.Fatalf("unexpected bootstrap state: %#v", file)
	}
}

func TestBootstrapAcceptsSameIdentityAndRejectsChangesWithoutOverwrite(t *testing.T) {
	path := filepath.Join(t.TempDir(), "agent-bootstrap.json")
	state, err := NewBootstrapState(path)
	if err != nil {
		t.Fatal(err)
	}
	first := `{"identifier":"550e8400-e29b-41d4-a716-446655440000","status":"ok"}`
	if err := state.Bind(context.Background(), []byte(first)); err != nil {
		t.Fatal(err)
	}
	if err := state.Bind(context.Background(), []byte(first)); err != nil {
		t.Fatalf("same identity rejected: %v", err)
	}
	before, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	if err := state.Bind(context.Background(), []byte(`{"identifier":"6ba7b810-9dad-11d1-80b4-00c04fd430c8","status":"ok"}`)); !errors.Is(err, ErrAgentReferenceMismatch) {
		t.Fatalf("identity mismatch = %v", err)
	}
	after, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	if string(before) != string(after) {
		t.Fatal("identity mismatch overwrote bootstrap state")
	}
}

func TestBootstrapRejectsInvalidHeartbeatResponses(t *testing.T) {
	for name, response := range map[string]string{
		"missing identifier":   `{"status":"ok"}`,
		"empty identifier":     `{"identifier":"","status":"ok"}`,
		"malformed identifier": `{"identifier":"not-an-id","status":"ok"}`,
		"wrong status":         `{"identifier":"550e8400-e29b-41d4-a716-446655440000","status":"bad"}`,
		"unknown field":        `{"identifier":"550e8400-e29b-41d4-a716-446655440000","status":"ok","token":"secret"}`,
		"trailing data":        `{"identifier":"550e8400-e29b-41d4-a716-446655440000","status":"ok"}{}`,
	} {
		t.Run(name, func(t *testing.T) {
			state, err := NewBootstrapState(filepath.Join(t.TempDir(), "agent-bootstrap.json"))
			if err != nil {
				t.Fatal(err)
			}
			if err := state.Bind(context.Background(), []byte(response)); !errors.Is(err, ErrAgentBootstrapInvalid) && !errors.Is(err, ErrAgentReferenceInvalid) {
				t.Fatalf("invalid response error = %v", err)
			}
		})
	}
}

func TestBootstrapRejectsMalformedLocalStateAndUnknownFields(t *testing.T) {
	path := filepath.Join(t.TempDir(), "agent-bootstrap.json")
	if err := os.WriteFile(path, []byte(`{"format_version":1,"agent_ref":"550e8400-e29b-41d4-a716-446655440000"`), 0600); err != nil {
		t.Fatal(err)
	}
	if _, err := NewBootstrapState(path); !errors.Is(err, ErrAgentBootstrapInvalid) {
		t.Fatalf("truncated state error = %v", err)
	}
	if err := os.WriteFile(path, []byte(`{"format_version":1,"agent_ref":"550e8400-e29b-41d4-a716-446655440000","bound_at":"`+time.Now().UTC().Format(time.RFC3339Nano)+`","extra":"nope"}`), 0600); err != nil {
		t.Fatal(err)
	}
	if _, err := NewBootstrapState(path); !errors.Is(err, ErrAgentBootstrapInvalid) {
		t.Fatalf("unknown field error = %v", err)
	}
	if err := os.WriteFile(path, []byte(`{"format_version":99,"agent_ref":"550e8400-e29b-41d4-a716-446655440000","bound_at":"`+time.Now().UTC().Format(time.RFC3339Nano)+`"}`), 0600); err != nil {
		t.Fatal(err)
	}
	if _, err := NewBootstrapState(path); !errors.Is(err, ErrAgentBootstrapVersionUnsupported) {
		t.Fatalf("unsupported version error = %v", err)
	}
}
