package credentials

import (
	"context"
	"errors"
	"testing"
)

func TestReferenceAcceptsOnlyExplicitPurposeAndStrictScope(t *testing.T) {
	ref, err := ParseReference(Reference{TenantRef: "tenant-1", RouterRef: "router-1", AgentRef: "agent-1", CredentialRef: "router-1/operator/v1", Purpose: PurposeOperator, Version: 1})
	if err != nil {
		t.Fatal(err)
	}
	if ref.Purpose != PurposeOperator {
		t.Fatalf("purpose=%q", ref.Purpose)
	}
	if err := ref.ValidateScope("tenant-2", "router-1", "agent-1"); !errors.Is(err, ErrCredentialReferenceInvalid) {
		t.Fatalf("scope error=%v", err)
	}
}

func TestReferenceRejectsUnknownEmptyAndSecretLikeValues(t *testing.T) {
	cases := []Reference{
		{TenantRef: "tenant-1", RouterRef: "router-1", AgentRef: "agent-1", CredentialRef: "router-1/observer/v1", Purpose: "", Version: 1},
		{TenantRef: "tenant-1", RouterRef: "router-1", AgentRef: "agent-1", CredentialRef: "router-1/observer/v1", Purpose: "UNKNOWN", Version: 1},
		{TenantRef: "tenant-1", RouterRef: "router-1", AgentRef: "agent-1", CredentialRef: "DO_NOT_LEAK_OPERATOR_SECRET_123", Purpose: PurposeOperator, Version: 1},
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
	resolver.Set(observer, CredentialStateMissing)
	resolver.Set(operator, CredentialStateRevoked)

	if _, err := resolver.Resolve(context.Background(), observer); !errors.Is(err, ErrObserverCredentialMissing) {
		t.Fatalf("observer=%v", err)
	}
	if _, err := resolver.Resolve(context.Background(), operator); !errors.Is(err, ErrOperatorCredentialRevoked) {
		t.Fatalf("operator=%v", err)
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
	ref, err := ParseReference(Reference{TenantRef: "tenant-1", RouterRef: "router-1", AgentRef: "agent-1", CredentialRef: "router-1/" + string(purpose) + "/v1", Purpose: purpose, Version: 1})
	if err != nil {
		t.Fatal(err)
	}
	return ref
}
