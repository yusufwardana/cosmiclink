package agent

import (
	"context"
	"encoding/json"
	"errors"
	"io"
	"os"
	"strings"

	"cosmiclink/network-engine/internal/credentials"
)

// CredentialResolutionJob is a reference-only Agent contract. It deliberately
// has no credential material, connection object, or provider-specific fields.
type CredentialResolutionJob struct {
	TenantRef         string `json:"tenant_ref"`
	RouterRef         string `json:"router_ref"`
	AgentRef          string `json:"agent_ref"`
	InstallationID    string `json:"installation_id"`
	CredentialRef     string `json:"credential_ref"`
	CredentialPurpose string `json:"credential_purpose"`
	CredentialVersion int    `json:"credential_version"`
}

// DecodeCredentialResolutionJob requires a complete, reference-only JSON
// object and rejects unknown fields, including secret-bearing fields.
func DecodeCredentialResolutionJob(input io.Reader, job *CredentialResolutionJob) error {
	if input == nil || job == nil {
		return credentials.ErrCredentialReferenceInvalid
	}
	decoder := json.NewDecoder(input)
	decoder.DisallowUnknownFields()
	if err := decoder.Decode(job); err != nil {
		return credentials.ErrCredentialReferenceInvalid
	}
	var extra any
	if err := decoder.Decode(&extra); !errors.Is(err, io.EOF) {
		return credentials.ErrCredentialReferenceInvalid
	}
	return nil
}

// AgentCredentialResolver binds a store to one authoritative installation.
// Callers cannot replace that identity through a resolution request.
type AgentCredentialResolver struct {
	store          *credentials.Store
	installationID credentials.InstallationIdentity
}

func NewAgentCredentialResolver(store *credentials.Store, installationID credentials.InstallationIdentity) (*AgentCredentialResolver, error) {
	if store == nil || !installationID.Valid() {
		return nil, credentials.ErrCredentialReferenceInvalid
	}
	return &AgentCredentialResolver{store: store, installationID: installationID}, nil
}

// Close releases the local encrypted store and its in-process key copy.
func (r *AgentCredentialResolver) Close() error {
	if r == nil || r.store == nil {
		return nil
	}
	return r.store.Close()
}

// Resolve implements the hardened credentials.Resolver contract.
func (r *AgentCredentialResolver) Resolve(ctx context.Context, ref credentials.Reference) (credentials.ResolvedCredential, error) {
	if r == nil || r.store == nil {
		return credentials.ResolvedCredential{}, credentials.ErrCredentialReferenceInvalid
	}
	if ref.InstallationID == "" {
		return credentials.ResolvedCredential{}, credentials.ErrCredentialInstallationMismatch
	}
	if ref.InstallationID != r.installationID {
		return credentials.ResolvedCredential{}, credentials.ErrCredentialInstallationMismatch
	}
	parsed, err := credentials.ParseReference(ref)
	if err != nil {
		return credentials.ResolvedCredential{}, err
	}
	return r.store.Resolve(ctx, parsed)
}

// ResolveJob adapts the strict Agent transport contract to the resolver
// contract without adding the job itself to the secret-bearing scope.
func (r *AgentCredentialResolver) ResolveJob(ctx context.Context, job CredentialResolutionJob) (credentials.ResolvedCredential, error) {
	if job.InstallationID == "" {
		return credentials.ResolvedCredential{}, credentials.ErrCredentialInstallationMismatch
	}
	return r.Resolve(ctx, credentials.Reference{
		TenantRef: job.TenantRef, RouterRef: job.RouterRef, AgentRef: job.AgentRef,
		InstallationID: credentials.InstallationIdentity(job.InstallationID), CredentialRef: job.CredentialRef,
		Purpose: credentials.Purpose(job.CredentialPurpose), Version: job.CredentialVersion,
	})
}

// CredentialResolutionEvidence is safe to return to a synthetic consumer.
// The credential itself is not retained or represented here.
type CredentialResolutionEvidence struct {
	CredentialResolved bool   `json:"credential_resolved"`
	Purpose            string `json:"purpose"`
	Version            int    `json:"version"`
	CredentialRef      string `json:"credential_ref"`
}

func ConsumeResolvedCredential(ref credentials.Reference, resolved credentials.ResolvedCredential) (CredentialResolutionEvidence, error) {
	if _, err := credentials.ParseReference(ref); err != nil || resolved.Username() == "" || len(resolved.SecretBytes()) == 0 {
		return CredentialResolutionEvidence{}, credentials.ErrCredentialReferenceInvalid
	}
	return CredentialResolutionEvidence{CredentialResolved: true, Purpose: string(ref.Purpose), Version: ref.Version, CredentialRef: safeReferenceFingerprint(ref.CredentialRef)}, nil
}

func safeReferenceFingerprint(ref string) string {
	if len(ref) <= 12 {
		return ref
	}
	return ref[:12]
}

// NewProductionCredentialResolver composes only already-present production
// prerequisites. It never initializes a key or creates a credential store.
func NewProductionCredentialResolver(storePath string, installationID credentials.InstallationIdentity) (*AgentCredentialResolver, error) {
	storePath = strings.TrimSpace(storePath)
	if storePath == "" {
		return nil, credentials.ErrCredentialReferenceInvalid
	}
	if _, err := os.Stat(storePath); err != nil {
		return nil, credentials.ErrCredentialNotFound
	}
	provider, err := credentials.NewProductionMasterKeyProvider(storePath+".master-key", installationID)
	if err != nil {
		return nil, err
	}
	store, err := credentials.OpenStore(storePath, provider)
	if err != nil {
		return nil, err
	}
	resolver, err := NewAgentCredentialResolver(store, installationID)
	if err != nil {
		_ = store.Close()
		return nil, err
	}
	return resolver, nil
}
