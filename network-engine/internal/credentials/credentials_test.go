package credentials

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"strings"
	"testing"
)

func TestReferenceAcceptsOnlyExplicitPurposeAndStrictScope(t *testing.T) {
	ref, err := ParseReference(Reference{TenantRef: "tenant-1", RouterRef: "router-1", AgentRef: "agent-1", InstallationID: InstallationIdentity("installation-1"), CredentialRef: "router-1/operator/v1", Purpose: PurposeOperator, Version: 1})
	if err != nil {
		t.Fatal(err)
	}
	if ref.Purpose != PurposeOperator {
		t.Fatalf("purpose=%q", ref.Purpose)
	}
	if err := ref.ValidateScope("tenant-2", "router-1", "agent-1", InstallationIdentity("installation-1")); !errors.Is(err, ErrCredentialReferenceInvalid) {
		t.Fatalf("scope error=%v", err)
	}
}

func TestReferenceRejectsUnknownEmptyAndSecretLikeValues(t *testing.T) {
	cases := []Reference{
		{TenantRef: "tenant-1", RouterRef: "router-1", AgentRef: "agent-1", InstallationID: InstallationIdentity("installation-1"), CredentialRef: "router-1/observer/v1", Purpose: "", Version: 1},
		{TenantRef: "tenant-1", RouterRef: "router-1", AgentRef: "agent-1", InstallationID: InstallationIdentity("installation-1"), CredentialRef: "router-1/observer/v1", Purpose: "UNKNOWN", Version: 1},
		{TenantRef: "tenant-1", RouterRef: "router-1", AgentRef: "agent-1", InstallationID: InstallationIdentity("installation-1"), CredentialRef: "DO_NOT_LEAK_OPERATOR_SECRET_123", Purpose: PurposeOperator, Version: 1},
		{TenantRef: "tenant-1", RouterRef: "router-1", AgentRef: "agent-1", InstallationID: "", CredentialRef: "router-1/observer/v1", Purpose: PurposeObserver, Version: 1},
	}
	for _, ref := range cases {
		if _, err := ParseReference(ref); !errors.Is(err, ErrCredentialReferenceInvalid) {
			t.Fatalf("reference %#v error=%v", ref, err)
		}
	}
}

func TestResolverNormalizesPurposeSpecificFailuresWithoutFallback(t *testing.T) {
	resolver := NewFakeResolver()
	observer := mustReference(t, PurposeObserver)
	operator := mustReference(t, PurposeOperator)
	resolver.SetState(observer, CredentialStateMissing)
	resolver.SetState(operator, CredentialStateRevoked)

	if _, err := resolver.Resolve(context.Background(), observer); !errors.Is(err, ErrObserverCredentialMissing) {
		t.Fatalf("observer=%v", err)
	}
	if _, err := resolver.Resolve(context.Background(), operator); !errors.Is(err, ErrOperatorCredentialRevoked) {
		t.Fatalf("operator=%v", err)
	}
}

func TestActiveCredentialsResolveOnlyAtExactScopeAndVersion(t *testing.T) {
	resolver := NewFakeResolver()
	observer := mustReference(t, PurposeObserver)
	resolver.SetRecord(observer, CredentialRecord{Status: CredentialStatusActive, Username: "observer-user", Secret: []byte("DO_NOT_LEAK_SENTINEL")})

	resolved, err := resolver.Resolve(context.Background(), observer)
	if err != nil {
		t.Fatal(err)
	}
	if resolved.Username() != "observer-user" || string(resolved.SecretBytes()) != "DO_NOT_LEAK_SENTINEL" {
		t.Fatal("active credential did not resolve")
	}

	for _, wrong := range []Reference{
		observerWith(func(r *Reference) { r.TenantRef = "tenant-2" }),
		observerWith(func(r *Reference) { r.RouterRef = "router-2" }),
		observerWith(func(r *Reference) { r.AgentRef = "agent-2" }),
		observerWith(func(r *Reference) { r.Purpose = PurposeOperator }),
	} {
		if _, err := resolver.Resolve(context.Background(), wrong); !errors.Is(err, ErrObserverCredentialMissing) && !errors.Is(err, ErrOperatorCredentialMissing) {
			t.Fatalf("wrong scope resolved or returned unexpected error: %v", err)
		}
	}
	wrongVersion := observer
	wrongVersion.Version = 2
	if _, err := resolver.Resolve(context.Background(), wrongVersion); !errors.Is(err, ErrCredentialVersionMismatch) {
		t.Fatalf("wrong version error = %v", err)
	}
	wrongInstallation := observer
	wrongInstallation.InstallationID = InstallationIdentity("installation-2")
	if _, err := resolver.Resolve(context.Background(), wrongInstallation); !errors.Is(err, ErrCredentialInstallationMismatch) {
		t.Fatalf("wrong installation error = %v", err)
	}
}

func TestCredentialStatusAndVersionFailuresAreDeterministic(t *testing.T) {
	resolver := NewFakeResolver()
	ref := mustReference(t, PurposeOperator)
	for _, status := range []CredentialStatus{"", "UNKNOWN", CredentialStatusRevoked, CredentialStatusRetired} {
		resolver.SetRecord(ref, CredentialRecord{Status: status, Username: "operator-user", Secret: []byte("DO_NOT_LEAK_SENTINEL")})
		_, err := resolver.Resolve(context.Background(), ref)
		if status == CredentialStatusRevoked && !errors.Is(err, ErrOperatorCredentialRevoked) || status == CredentialStatusRetired && !errors.Is(err, ErrOperatorCredentialRetired) || status == "" && !errors.Is(err, ErrOperatorCredentialInvalid) || status == "UNKNOWN" && !errors.Is(err, ErrCredentialStatusInvalid) {
			t.Fatalf("status %q returned %v", status, err)
		}
	}
	for _, version := range []int{0, -1} {
		invalid := ref
		invalid.Version = version
		if _, err := resolver.Resolve(context.Background(), invalid); !errors.Is(err, ErrCredentialVersionInvalid) {
			t.Fatalf("version %d returned %v", version, err)
		}
	}
}

func TestResolvedCredentialNeverSerializesOrFormatsItsSecret(t *testing.T) {
	resolved := NewResolvedCredential("observer-user", []byte("DO_NOT_LEAK_SENTINEL"))
	for _, output := range []string{string(mustJSON(t, resolved)), fmt.Sprintf("%v", resolved), fmt.Sprintf("%+v", resolved), fmt.Sprintf("%#v", resolved)} {
		if strings.Contains(output, "DO_NOT_LEAK_SENTINEL") {
			t.Fatalf("resolved credential leaked secret: %s", output)
		}
	}
	if strings.Contains(fmt.Sprint(errors.New("credential resolution failed")), "DO_NOT_LEAK_SENTINEL") {
		t.Fatal("error output contained secret")
	}
}

func TestFutureMutationRequestShapeContainsReferencesButNoCredentialMaterial(t *testing.T) {
	request := struct {
		AgentRef          string `json:"agent_ref,omitempty"`
		CredentialRef     string `json:"credential_ref,omitempty"`
		CredentialPurpose string `json:"credential_purpose,omitempty"`
		CredentialVersion int    `json:"credential_version,omitempty"`
		TargetIdentityRef string `json:"target_identity_ref,omitempty"`
		FencingRef        string `json:"fencing_ref,omitempty"`
	}{
		AgentRef: "agent-1", CredentialRef: "router-1/operator/v1", CredentialPurpose: string(PurposeOperator), CredentialVersion: 1, TargetIdentityRef: "*7", FencingRef: "fence-1",
	}
	if request.CredentialPurpose != string(PurposeOperator) || request.CredentialVersion != 1 || request.CredentialRef == "" {
		t.Fatalf("future request references were not retained: %#v", request)
	}
}

func mustReference(t *testing.T, purpose Purpose) Reference {
	t.Helper()
	ref, err := ParseReference(Reference{TenantRef: "tenant-1", RouterRef: "router-1", AgentRef: "agent-1", InstallationID: InstallationIdentity("installation-1"), CredentialRef: "router-1/" + string(purpose) + "/v1", Purpose: purpose, Version: 1})
	if err != nil {
		t.Fatal(err)
	}
	return ref
}

func observerWith(change func(*Reference)) Reference {
	ref := mustReferenceForTest(PurposeObserver)
	change(&ref)
	return ref
}

func mustReferenceForTest(purpose Purpose) Reference {
	ref, err := ParseReference(Reference{TenantRef: "tenant-1", RouterRef: "router-1", AgentRef: "agent-1", InstallationID: InstallationIdentity("installation-1"), CredentialRef: "router-1/" + string(purpose) + "/v1", Purpose: purpose, Version: 1})
	if err != nil {
		panic(err)
	}
	return ref
}

func mustJSON(t *testing.T, value any) []byte {
	t.Helper()
	encoded, err := json.Marshal(value)
	if err != nil {
		t.Fatal(err)
	}
	return encoded
}
