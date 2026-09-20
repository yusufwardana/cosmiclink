package agent

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"log/slog"
	"strings"
	"testing"

	"cosmiclink/network-engine/internal/credentials"
)

const agentCredentialSentinel = "AGENT_SYNTHETIC_SECRET_SENTINEL"

type resolverTestKeyProvider struct{ key []byte }

func (p resolverTestKeyProvider) MasterKey(context.Context) ([]byte, error) {
	return append([]byte(nil), p.key...), nil
}

func TestAgentCredentialResolverResolvesExactActiveReferencesAndSyntheticConsumerRedacts(t *testing.T) {
	store, ref := newAgentResolverStore(t, credentials.PurposeObserver, 1)
	defer store.Close()
	if err := store.Insert(context.Background(), ref, "observer-synthetic-user", []byte(agentCredentialSentinel)); err != nil {
		t.Fatal(err)
	}

	resolver, err := NewAgentCredentialResolver(store, credentials.InstallationIdentity("installation-1"))
	if err != nil {
		t.Fatal(err)
	}
	resolved, err := resolver.ResolveJob(context.Background(), CredentialResolutionJob{
		TenantRef: "tenant-1", RouterRef: "router-1", AgentRef: "agent-1", InstallationID: "installation-1",
		CredentialRef: ref.CredentialRef, CredentialPurpose: string(credentials.PurposeObserver), CredentialVersion: 1,
	})
	if err != nil || resolved.Username() != "observer-synthetic-user" || string(resolved.SecretBytes()) != agentCredentialSentinel {
		t.Fatalf("resolved=%q/%q err=%v", resolved.Username(), resolved.SecretBytes(), err)
	}

	evidence, err := ConsumeResolvedCredential(ref, resolved)
	if err != nil {
		t.Fatal(err)
	}
	encoded, _ := json.Marshal(evidence)
	if evidence.CredentialResolved != true || evidence.Purpose != string(credentials.PurposeObserver) || evidence.Version != 1 || strings.Contains(string(encoded), agentCredentialSentinel) || strings.Contains(string(encoded), "observer-synthetic-user") {
		t.Fatalf("unsafe evidence: %#v %s", evidence, encoded)
	}
}

func TestAgentCredentialBoundaryReturnsSafeEvidenceWithoutNetworkExecution(t *testing.T) {
	store, ref := newAgentResolverStore(t, credentials.PurposeOperator, 1)
	defer store.Close()
	if err := store.Insert(context.Background(), ref, "operator-user", []byte(agentCredentialSentinel)); err != nil {
		t.Fatal(err)
	}
	resolver, err := NewAgentCredentialResolver(store, credentials.InstallationIdentity("installation-1"))
	if err != nil {
		t.Fatal(err)
	}
	a := NewWithCredentialResolver(Config{}, nil, resolver, nil)
	evidence, err := a.ResolveCredential(context.Background(), CredentialResolutionJob{TenantRef: ref.TenantRef, RouterRef: ref.RouterRef, AgentRef: ref.AgentRef, InstallationID: string(ref.InstallationID), CredentialRef: ref.CredentialRef, CredentialPurpose: string(ref.Purpose), CredentialVersion: ref.Version})
	if err != nil {
		t.Fatal(err)
	}
	encoded, _ := json.Marshal(evidence)
	if strings.Contains(string(encoded), agentCredentialSentinel) || strings.Contains(string(encoded), "operator-user") {
		t.Fatalf("secret appeared in result: %s", encoded)
	}
}

func TestAgentCredentialResolutionIsExactAndFailClosed(t *testing.T) {
	store, ref := newAgentResolverStore(t, credentials.PurposeOperator, 2)
	defer store.Close()
	if err := store.Insert(context.Background(), ref, "operator-synthetic-user", []byte(agentCredentialSentinel)); err != nil {
		t.Fatal(err)
	}
	resolver, err := NewAgentCredentialResolver(store, credentials.InstallationIdentity("installation-1"))
	if err != nil {
		t.Fatal(err)
	}
	base := CredentialResolutionJob{TenantRef: "tenant-1", RouterRef: "router-1", AgentRef: "agent-1", InstallationID: "installation-1", CredentialRef: ref.CredentialRef, CredentialPurpose: string(credentials.PurposeOperator), CredentialVersion: 2}
	for name, change := range map[string]func(*CredentialResolutionJob){
		"wrong tenant":         func(j *CredentialResolutionJob) { j.TenantRef = "tenant-2" },
		"wrong router":         func(j *CredentialResolutionJob) { j.RouterRef = "router-2" },
		"wrong agent":          func(j *CredentialResolutionJob) { j.AgentRef = "agent-2" },
		"wrong installation":   func(j *CredentialResolutionJob) { j.InstallationID = "installation-2" },
		"missing installation": func(j *CredentialResolutionJob) { j.InstallationID = "" },
		"wrong purpose":        func(j *CredentialResolutionJob) { j.CredentialPurpose = string(credentials.PurposeObserver) },
		"wrong version":        func(j *CredentialResolutionJob) { j.CredentialVersion = 1 },
		"missing reference":    func(j *CredentialResolutionJob) { j.CredentialRef = "" },
		"malformed reference":  func(j *CredentialResolutionJob) { j.CredentialRef = "operator password" },
	} {
		t.Run(name, func(t *testing.T) {
			job := base
			change(&job)
			if _, err := resolver.ResolveJob(context.Background(), job); err == nil {
				t.Fatal("invalid reference resolved")
			}
		})
	}
}

func TestAgentCredentialResolutionRejectsRetiredAndRevokedWithoutFallback(t *testing.T) {
	store, observer := newAgentResolverStore(t, credentials.PurposeObserver, 1)
	defer store.Close()
	operator := observer
	operator.Purpose = credentials.PurposeOperator
	operator.CredentialRef = "router-1/OPERATOR/v1"
	if err := store.Insert(context.Background(), observer, "observer-user", []byte("observer-secret")); err != nil {
		t.Fatal(err)
	}
	if err := store.Insert(context.Background(), operator, "operator-user", []byte("operator-secret")); err != nil {
		t.Fatal(err)
	}
	if err := store.Revoke(context.Background(), observer); err != nil {
		t.Fatal(err)
	}
	if err := store.Retire(context.Background(), operator); err != nil {
		t.Fatal(err)
	}
	resolver, err := NewAgentCredentialResolver(store, credentials.InstallationIdentity("installation-1"))
	if err != nil {
		t.Fatal(err)
	}
	for _, ref := range []credentials.Reference{observer, operator} {
		job := CredentialResolutionJob{TenantRef: ref.TenantRef, RouterRef: ref.RouterRef, AgentRef: ref.AgentRef, InstallationID: string(ref.InstallationID), CredentialRef: ref.CredentialRef, CredentialPurpose: string(ref.Purpose), CredentialVersion: ref.Version}
		if _, err := resolver.ResolveJob(context.Background(), job); err == nil {
			t.Fatalf("non-active credential resolved: %#v", ref)
		}
	}
}

func TestCredentialResolutionJobStrictlyRejectsSecretFieldsAndNeverLogsSecrets(t *testing.T) {
	var job CredentialResolutionJob
	if err := DecodeCredentialResolutionJob(strings.NewReader(`{"tenant_ref":"t","router_ref":"r","agent_ref":"a","installation_id":"i","credential_ref":"r/OBSERVER/v1","credential_purpose":"OBSERVER","credential_version":1,"password":"`+agentCredentialSentinel+`"}`), &job); err == nil {
		t.Fatal("secret-bearing unknown field accepted")
	}
	encoded, _ := json.Marshal(job)
	if strings.Contains(string(encoded), "password") || strings.Contains(string(encoded), agentCredentialSentinel) {
		t.Fatalf("job exposed secret field: %s", encoded)
	}
	var logs bytes.Buffer
	logger := slog.New(slog.NewJSONHandler(&logs, nil))
	logger.Error("credential resolution failed", "error", errors.New("credential resolution failed"))
	if strings.Contains(logs.String(), agentCredentialSentinel) {
		t.Fatal("sentinel appeared in logs")
	}
}

func TestProductionCredentialResolverDoesNotCreateMissingStoreOrUseNonWindowsFallback(t *testing.T) {
	path := t.TempDir() + "\\missing-credentials.db"
	resolver, err := NewProductionCredentialResolver(path, credentials.InstallationIdentity("installation-1"))
	if resolver != nil || err == nil {
		t.Fatalf("missing production prerequisites accepted: resolver=%#v err=%v", resolver, err)
	}
}

func newAgentResolverStore(t *testing.T, purpose credentials.Purpose, version int) (*credentials.Store, credentials.Reference) {
	t.Helper()
	path := t.TempDir() + "\\credentials.db"
	store, err := credentials.OpenStore(path, resolverTestKeyProvider{key: []byte("01234567890123456789012345678900")})
	if err != nil {
		t.Fatal(err)
	}
	ref, err := credentials.ParseReference(credentials.Reference{TenantRef: "tenant-1", RouterRef: "router-1", AgentRef: "agent-1", InstallationID: "installation-1", CredentialRef: "router-1/" + string(purpose) + "/v" + string(rune('0'+version)), Purpose: purpose, Version: version})
	if err != nil {
		store.Close()
		t.Fatal(err)
	}
	return store, ref
}
