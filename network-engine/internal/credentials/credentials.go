package credentials

import (
	"context"
	"errors"
	"fmt"
	"regexp"
)

type Purpose string

const (
	PurposeObserver Purpose = "OBSERVER"
	PurposeOperator Purpose = "OPERATOR"
)

type Reference struct {
	TenantRef     string
	RouterRef     string
	AgentRef      string
	CredentialRef string
	Purpose       Purpose
	Version       int
}

type Credential struct {
	Username string
	Secret   string
}

type State string

const (
	CredentialStateMissing State = "missing"
	CredentialStateInvalid State = "invalid"
	CredentialStateRevoked State = "revoked"
	CredentialStateReady   State = "ready"
)

var (
	ErrCredentialReferenceInvalid = errors.New("credential reference invalid")
	ErrObserverCredentialMissing  = errors.New("observer credential missing")
	ErrObserverCredentialInvalid  = errors.New("observer credential invalid")
	ErrObserverCredentialRevoked  = errors.New("observer credential revoked")
	ErrOperatorCredentialMissing  = errors.New("operator credential missing")
	ErrOperatorCredentialInvalid  = errors.New("operator credential invalid")
	ErrOperatorCredentialRevoked  = errors.New("operator credential revoked")
	refPattern                    = regexp.MustCompile(`\A[A-Za-z0-9][A-Za-z0-9._:/-]*\z`)
)

type Resolver interface {
	Resolve(context.Context, Reference) (Credential, error)
}

func ParseReference(ref Reference) (Reference, error) {
	if !validRef(ref.TenantRef) || !validRef(ref.RouterRef) || !validRef(ref.AgentRef) || !validRef(ref.CredentialRef) || ref.Version < 1 || (ref.Purpose != PurposeObserver && ref.Purpose != PurposeOperator) || containsSecretWord(ref.CredentialRef) {
		return Reference{}, ErrCredentialReferenceInvalid
	}
	return ref, nil
}

func (r Reference) ValidateScope(tenantRef, routerRef, agentRef string) error {
	if r.TenantRef != tenantRef || r.RouterRef != routerRef || r.AgentRef != agentRef {
		return ErrCredentialReferenceInvalid
	}
	return nil
}

type FakeResolver struct{ states map[string]State }

func NewFakeResolver() *FakeResolver { return &FakeResolver{states: make(map[string]State)} }

func (r *FakeResolver) Set(ref Reference, state State) { r.states[key(ref)] = state }

func (r *FakeResolver) Resolve(_ context.Context, ref Reference) (Credential, error) {
	ref, err := ParseReference(ref)
	if err != nil {
		return Credential{}, err
	}
	state := r.states[key(ref)]
	switch state {
	case CredentialStateMissing:
		return Credential{}, purposeError(ref.Purpose, ErrObserverCredentialMissing, ErrOperatorCredentialMissing)
	case CredentialStateInvalid:
		return Credential{}, purposeError(ref.Purpose, ErrObserverCredentialInvalid, ErrOperatorCredentialInvalid)
	case CredentialStateRevoked:
		return Credential{}, purposeError(ref.Purpose, ErrObserverCredentialRevoked, ErrOperatorCredentialRevoked)
	default:
		return Credential{}, purposeError(ref.Purpose, ErrObserverCredentialMissing, ErrOperatorCredentialMissing)
	}
}

func validRef(value string) bool {
	return value != "" && len(value) <= 191 && refPattern.MatchString(value)
}
func containsSecretWord(value string) bool {
	return regexp.MustCompile(`(?i)(password|secret|token|credential)`).MatchString(value)
}
func key(ref Reference) string {
	return fmt.Sprintf("%s|%s|%s|%s|%d", ref.TenantRef, ref.RouterRef, ref.AgentRef, ref.CredentialRef, ref.Version)
}
func purposeError(purpose Purpose, observer, operator error) error {
	if purpose == PurposeObserver {
		return observer
	}
	return operator
}
