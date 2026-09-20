package admin

import (
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"sync"
	"time"

	"cosmiclink/network-engine/internal/adminprotocol"
	"cosmiclink/network-engine/internal/agent"
	"cosmiclink/network-engine/internal/credentials"
)

const PipeName = `\\.\pipe\cosmiclink-agent-admin`

var (
	ErrStoreNotInitialized = errors.New("CREDENTIAL_STORE_NOT_INITIALIZED")
	ErrAlreadyInitialized  = errors.New("CREDENTIAL_STORE_ALREADY_INITIALIZED")
	ErrNotFound            = errors.New("CREDENTIAL_NOT_FOUND")
	ErrInvalidReference    = errors.New("CREDENTIAL_REFERENCE_INVALID")
	ErrVersionInvalid      = errors.New("CREDENTIAL_VERSION_INVALID")
	ErrOperatorForbidden   = errors.New("OPERATOR_ENROLLMENT_FORBIDDEN")
	ErrLocalValidation     = errors.New("LOCAL_VALIDATION_FAILED")
	ErrConfirmation        = errors.New("CREDENTIAL_CONFIRMATION_INVALID")
)

type AuditEvent struct {
	EventID       string    `json:"event_id"`
	Timestamp     time.Time `json:"timestamp"`
	Actor         string    `json:"actor"`
	Operation     string    `json:"operation"`
	CredentialRef string    `json:"credential_ref,omitempty"`
	Purpose       string    `json:"purpose,omitempty"`
	Version       int       `json:"version,omitempty"`
	TenantRef     string    `json:"tenant_ref,omitempty"`
	RouterRef     string    `json:"router_ref,omitempty"`
	Result        string    `json:"result"`
	Reason        string    `json:"reason,omitempty"`
	CorrelationID string    `json:"correlation_id"`
}

type Service struct {
	dataDir string
	actor   string
	mu      sync.Mutex
}

type CredentialResult struct {
	Metadata           *Metadata                    `json:"metadata,omitempty"`
	LocalValidation    string                       `json:"local_validation,omitempty"`
	HardwareAccess     string                       `json:"hardware_access,omitempty"`
	LocalRotation      string                       `json:"local_rotation,omitempty"`
	HardwareValidation string                       `json:"hardware_validation,omitempty"`
	Status             credentials.CredentialStatus `json:"status,omitempty"`
}

type Metadata struct {
	CredentialRef   string                       `json:"credential_ref"`
	Purpose         credentials.Purpose          `json:"purpose"`
	TenantRef       string                       `json:"tenant_ref"`
	RouterRef       string                       `json:"router_ref"`
	AgentRef        string                       `json:"agent_ref,omitempty"`
	Version         int                          `json:"version"`
	Status          credentials.CredentialStatus `json:"status"`
	CreatedAt       time.Time                    `json:"created_at"`
	RotatedAt       *time.Time                   `json:"rotated_at,omitempty"`
	RevokedAt       *time.Time                   `json:"revoked_at,omitempty"`
	RetiredAt       *time.Time                   `json:"retired_at,omitempty"`
	LastValidatedAt *time.Time                   `json:"last_validated_at,omitempty"`
}

func publicMetadata(item credentials.CredentialMetadata) Metadata {
	return Metadata{CredentialRef: item.CredentialRef, Purpose: item.Purpose, TenantRef: item.TenantRef, RouterRef: item.RouterRef, AgentRef: item.AgentRef, Version: item.Version, Status: item.Status, CreatedAt: item.CreatedAt, RotatedAt: item.RotatedAt, RevokedAt: item.RevokedAt, RetiredAt: item.RetiredAt, LastValidatedAt: item.LastValidatedAt}
}

func NewService(dataDir, actor string) *Service { return &Service{dataDir: dataDir, actor: actor} }

func (s *Service) Handle(ctx context.Context, request adminprotocol.Request) (any, error) {
	s.mu.Lock()
	defer s.mu.Unlock()
	switch request.Operation {
	case adminprotocol.StoreInit:
		var p adminprotocol.StoreInitPayload
		if err := adminprotocol.DecodePayload(request.Payload, &p); err != nil {
			return nil, err
		}
		return s.init(ctx)
	case adminprotocol.List:
		var p adminprotocol.ListPayload
		if err := adminprotocol.DecodePayload(request.Payload, &p); err != nil {
			return nil, err
		}
		return s.list(ctx)
	case adminprotocol.Inspect, adminprotocol.TestLocal, adminprotocol.Revoke, adminprotocol.Retire:
		var p adminprotocol.CredentialPayload
		if err := adminprotocol.DecodePayload(request.Payload, &p); err != nil {
			return nil, err
		}
		return s.single(ctx, request.Operation, p)
	case adminprotocol.AddObserver:
		var p adminprotocol.AddObserverPayload
		if err := adminprotocol.DecodePayload(request.Payload, &p); err != nil {
			return nil, err
		}
		return s.add(ctx, p)
	case adminprotocol.RotateObserver:
		var p adminprotocol.RotateObserverPayload
		if err := adminprotocol.DecodePayload(request.Payload, &p); err != nil {
			return nil, err
		}
		return s.rotate(ctx, p)
	default:
		return nil, adminprotocol.ErrUnknownOperation
	}
}

func (s *Service) init(ctx context.Context) (any, error) {
	if _, err := agent.OpenInstallation(ctx, s.dataDir); err != nil {
		return nil, ErrStoreNotInitialized
	}
	store, err := agent.InitializeCredentialStore(ctx, s.dataDir)
	if errors.Is(err, credentials.ErrCredentialStoreAlreadyInitialized) {
		return nil, ErrAlreadyInitialized
	}
	if err != nil {
		return nil, ErrStoreNotInitialized
	}
	_ = store.Close()
	s.audit("CREDENTIAL_STORE_INITIALIZED", "", "", "", "", 0, "success", "")
	return map[string]any{"initialized": true}, nil
}
func (s *Service) open(ctx context.Context) (*credentials.Store, agent.Installation, error) {
	inst, err := agent.OpenInstallation(ctx, s.dataDir)
	if err != nil {
		return nil, agent.Installation{}, ErrStoreNotInitialized
	}
	store, err := agent.OpenCredentialStore(ctx, s.dataDir)
	if err != nil {
		return nil, inst, ErrStoreNotInitialized
	}
	return store, inst, nil
}
func (s *Service) list(ctx context.Context) (any, error) {
	store, _, err := s.open(ctx)
	if err != nil {
		return nil, err
	}
	defer store.Close()
	items, err := store.ListMetadata(ctx)
	if err != nil {
		return nil, ErrLocalValidation
	}
	result := make([]Metadata, 0, len(items))
	for _, item := range items {
		result = append(result, publicMetadata(item))
	}
	return result, nil
}
func (s *Service) ref(ctx context.Context, store *credentials.Store, inst agent.Installation, p adminprotocol.CredentialPayload) (credentials.Reference, error) {
	if p.Version < 1 {
		return credentials.Reference{}, ErrVersionInvalid
	}
	if p.CredentialRef == "" {
		return credentials.Reference{}, ErrInvalidReference
	}
	for _, m := range mustMetadata(ctx, store) {
		if m.CredentialRef == p.CredentialRef && m.Version == p.Version {
			return credentials.Reference{TenantRef: m.TenantRef, RouterRef: m.RouterRef, AgentRef: m.AgentRef, InstallationID: inst.InstallationID, CredentialRef: m.CredentialRef, Purpose: m.Purpose, Version: m.Version}, nil
		}
	}
	return credentials.Reference{}, ErrNotFound
}

func metadataFor(ctx context.Context, store *credentials.Store, ref string, version int) (credentials.CredentialMetadata, error) {
	metadata, err := store.ListMetadata(ctx)
	if err != nil {
		return credentials.CredentialMetadata{}, ErrNotFound
	}
	for _, item := range metadata {
		if item.CredentialRef == ref && (version == 0 || item.Version == version) {
			return item, nil
		}
	}
	return credentials.CredentialMetadata{}, ErrNotFound
}
func mustMetadata(ctx context.Context, s *credentials.Store) []credentials.CredentialMetadata {
	m, _ := s.ListMetadata(ctx)
	return m
}
func (s *Service) single(ctx context.Context, op adminprotocol.Operation, p adminprotocol.CredentialPayload) (any, error) {
	store, inst, err := s.open(ctx)
	if err != nil {
		return nil, err
	}
	defer store.Close()
	ref, err := s.ref(ctx, store, inst, p)
	if err != nil {
		return nil, err
	}
	if op == adminprotocol.Inspect {
		item, err := metadataFor(ctx, store, ref.CredentialRef, ref.Version)
		if err != nil {
			return nil, err
		}
		result := publicMetadata(item)
		return result, nil
	}
	if op == adminprotocol.TestLocal {
		if ref.Purpose != credentials.PurposeObserver {
			return nil, ErrLocalValidation
		}
		if _, err := store.Resolve(ctx, ref); err != nil {
			s.audit("CREDENTIAL_VALIDATED_LOCAL", ref.CredentialRef, string(ref.Purpose), ref.TenantRef, ref.RouterRef, ref.Version, "denied", "invalid")
			return nil, ErrLocalValidation
		}
		if err := store.MarkValidated(ctx, ref); err != nil {
			return nil, ErrLocalValidation
		}
		s.audit("CREDENTIAL_VALIDATED_LOCAL", ref.CredentialRef, string(ref.Purpose), ref.TenantRef, ref.RouterRef, ref.Version, "success", "")
		return CredentialResult{LocalValidation: "completed", HardwareAccess: "not_performed"}, nil
	}
	if op == adminprotocol.Revoke || op == adminprotocol.Retire {
		expected := "REVOKE " + ref.CredentialRef
		if op == adminprotocol.Retire {
			expected = "RETIRE " + ref.CredentialRef
		}
		if p.Confirmation != expected {
			s.audit("CREDENTIAL_ADMIN_DENIED", ref.CredentialRef, string(ref.Purpose), ref.TenantRef, ref.RouterRef, ref.Version, "denied", "confirmation")
			return nil, ErrConfirmation
		}
		status := credentials.CredentialStatusRevoked
		if op == adminprotocol.Retire {
			status = credentials.CredentialStatusRetired
		}
		if err := storeTransition(ctx, store, ref, status); err != nil {
			return nil, lifecycleError(err)
		}
		s.audit(string(op), ref.CredentialRef, string(ref.Purpose), ref.TenantRef, ref.RouterRef, ref.Version, "success", "")
		return map[string]any{"status": status}, nil
	}
	return nil, ErrNotFound
}

func lifecycleError(err error) error {
	if errors.Is(err, credentials.ErrCredentialNotFound) || errors.Is(err, credentials.ErrObserverCredentialMissing) {
		return ErrNotFound
	}
	if errors.Is(err, credentials.ErrObserverCredentialRevoked) || errors.Is(err, credentials.ErrOperatorCredentialRevoked) {
		return errors.New("CREDENTIAL_REVOKED")
	}
	if errors.Is(err, credentials.ErrObserverCredentialRetired) || errors.Is(err, credentials.ErrOperatorCredentialRetired) {
		return errors.New("CREDENTIAL_RETIRED")
	}
	return ErrLocalValidation
}
func storeTransition(ctx context.Context, store *credentials.Store, ref credentials.Reference, status credentials.CredentialStatus) error {
	if status == credentials.CredentialStatusRevoked {
		return store.Revoke(ctx, ref)
	}
	return store.Retire(ctx, ref)
}
func (s *Service) add(ctx context.Context, p adminprotocol.AddObserverPayload) (any, error) {
	if p.Secret == "" || p.Username == "" {
		return nil, ErrOperatorForbidden
	}
	store, inst, err := s.open(ctx)
	if err != nil {
		return nil, err
	}
	defer store.Close()
	ref := credentials.Reference{TenantRef: p.TenantRef, RouterRef: p.RouterRef, AgentRef: inst.AgentRef, InstallationID: inst.InstallationID, CredentialRef: uuid(), Purpose: credentials.PurposeObserver}
	ref.Version = 1 // valid reference shape; NextVersion derives the actual historical version.
	v, err := store.NextVersion(ctx, ref)
	if err != nil {
		return nil, ErrLocalValidation
	}
	ref.Version = v
	if err := store.Insert(ctx, ref, p.Username, []byte(p.Secret)); err != nil {
		return nil, ErrLocalValidation
	}
	s.audit("CREDENTIAL_CREATED", ref.CredentialRef, string(ref.Purpose), ref.TenantRef, ref.RouterRef, ref.Version, "success", "")
	item, err := metadataFor(ctx, store, ref.CredentialRef, ref.Version)
	if err != nil {
		return nil, err
	}
	result := publicMetadata(item)
	return result, nil
}
func (s *Service) rotate(ctx context.Context, p adminprotocol.RotateObserverPayload) (any, error) {
	store, inst, err := s.open(ctx)
	if err != nil {
		return nil, err
	}
	defer store.Close()
	metadata, err := store.ListMetadata(ctx)
	if err != nil {
		return nil, ErrNotFound
	}
	var item credentials.CredentialMetadata
	for _, candidate := range metadata {
		if candidate.CredentialRef == p.CredentialRef && candidate.Status == credentials.CredentialStatusActive && candidate.Version > item.Version {
			item = candidate
		}
	}
	if item.CredentialRef == "" {
		return nil, ErrNotFound
	}
	old := credentials.Reference{TenantRef: item.TenantRef, RouterRef: item.RouterRef, AgentRef: item.AgentRef, InstallationID: inst.InstallationID, CredentialRef: item.CredentialRef, Purpose: item.Purpose, Version: item.Version}
	if old.Purpose != credentials.PurposeObserver {
		return nil, ErrOperatorForbidden
	}
	v, err := store.NextVersion(ctx, old)
	if err != nil {
		return nil, ErrLocalValidation
	}
	next := old
	next.Version = v
	if p.Username == "" || p.Secret == "" {
		return nil, ErrLocalValidation
	}
	if err := store.Rotate(ctx, old, next, p.Username, []byte(p.Secret)); err != nil {
		return nil, ErrLocalValidation
	}
	s.audit("CREDENTIAL_ROTATED", next.CredentialRef, string(next.Purpose), next.TenantRef, next.RouterRef, next.Version, "success", "")
	return CredentialResult{LocalRotation: "completed", HardwareValidation: "not_performed", Metadata: metadataPointer(ctx, store, next.CredentialRef, next.Version)}, nil
}

func metadataPointer(ctx context.Context, store *credentials.Store, ref string, version int) *Metadata {
	item, err := metadataFor(ctx, store, ref, version)
	if err != nil {
		return nil
	}
	result := publicMetadata(item)
	return &result
}
func uuid() string {
	var b [16]byte
	_, _ = rand.Read(b[:])
	b[6] = (b[6] & 15) | 64
	b[8] = (b[8] & 63) | 128
	h := hex.EncodeToString(b[:])
	return fmt.Sprintf("%s-%s-%s-%s-%s", h[:8], h[8:12], h[12:16], h[16:20], h[20:])
}
func (s *Service) audit(op, ref, purpose, tenantRef, routerRef string, version int, result, reason string) {
	dir := filepath.Join(s.dataDir, "audit")
	_ = os.MkdirAll(dir, 0700)
	e := AuditEvent{EventID: uuid(), Timestamp: time.Now().UTC(), Actor: s.actor, Operation: op, CredentialRef: ref, Purpose: purpose, TenantRef: tenantRef, RouterRef: routerRef, Version: version, Result: result, Reason: reason, CorrelationID: uuid()}
	b, _ := json.Marshal(e)
	f, err := os.OpenFile(filepath.Join(dir, "credential-admin.log"), os.O_CREATE|os.O_APPEND|os.O_WRONLY, 0600)
	if err == nil {
		_, _ = f.Write(append(b, '\n'))
		_ = f.Close()
	}
}
