package credentials

import (
	"context"
	"errors"
	"fmt"
	"regexp"
	"strings"
)

type Purpose string

const (
	PurposeObserver Purpose = "OBSERVER"
	PurposeOperator Purpose = "OPERATOR"
)

type InstallationIdentity string

func (identity InstallationIdentity) Valid() bool { return validRef(string(identity)) }

type Reference struct {
	TenantRef      string
	RouterRef      string
	AgentRef       string
	InstallationID InstallationIdentity
	CredentialRef  string
	Purpose        Purpose
	Version        int
}

type CredentialStatus string

const (
	CredentialStatusActive  CredentialStatus = "ACTIVE"
	CredentialStatusRevoked CredentialStatus = "REVOKED"
	CredentialStatusRetired CredentialStatus = "RETIRED"
)

// CredentialRecord is an in-memory deterministic test record. Persistence and
// platform key providers are intentionally out of scope for this slice.
type CredentialRecord struct {
	Status   CredentialStatus
	Username string
	Secret   []byte
}

// ResolvedCredential is the only secret-bearing resolver result. Secret is
// private, is never JSON-serialised, and is returned only as a defensive copy.
// Go GC and provider/library copies mean this is not guaranteed memory erasure.
type ResolvedCredential struct {
	username string
	secret   []byte
}

func NewResolvedCredential(username string, secret []byte) ResolvedCredential {
	return ResolvedCredential{username: username, secret: append([]byte(nil), secret...)}
}

func (credential ResolvedCredential) Username() string { return credential.username }
func (credential ResolvedCredential) SecretBytes() []byte {
	return append([]byte(nil), credential.secret...)
}
func (ResolvedCredential) String() string   { return "[resolved credential redacted]" }
func (ResolvedCredential) GoString() string { return "credentials.ResolvedCredential{redacted}" }

var (
	ErrCredentialReferenceInvalid     = errors.New("credential reference invalid")
	ErrCredentialInstallationMismatch = errors.New("credential installation mismatch")
	ErrCredentialVersionInvalid       = errors.New("credential version invalid")
	ErrCredentialVersionMismatch      = errors.New("credential version mismatch")
	ErrCredentialStatusInvalid        = errors.New("credential status invalid")
	ErrObserverCredentialMissing      = errors.New("observer credential missing")
	ErrObserverCredentialInvalid      = errors.New("observer credential invalid")
	ErrObserverCredentialRevoked      = errors.New("observer credential revoked")
	ErrObserverCredentialRetired      = errors.New("observer credential retired")
	ErrOperatorCredentialMissing      = errors.New("operator credential missing")
	ErrOperatorCredentialInvalid      = errors.New("operator credential invalid")
	ErrOperatorCredentialRevoked      = errors.New("operator credential revoked")
	ErrOperatorCredentialRetired      = errors.New("operator credential retired")
	refPattern                        = regexp.MustCompile(`\A[A-Za-z0-9][A-Za-z0-9._:/-]*\z`)
)

type Resolver interface {
	Resolve(context.Context, Reference) (ResolvedCredential, error)
}

func ParseReference(ref Reference) (Reference, error) {
	if !validRef(ref.TenantRef) || !validRef(ref.RouterRef) || !validRef(ref.AgentRef) || !ref.InstallationID.Valid() || !validRef(ref.CredentialRef) || containsSecretWord(ref.CredentialRef) {
		return Reference{}, ErrCredentialReferenceInvalid
	}
	if ref.Version < 1 {
		return Reference{}, ErrCredentialVersionInvalid
	}
	if ref.Purpose != PurposeObserver && ref.Purpose != PurposeOperator {
		return Reference{}, ErrCredentialReferenceInvalid
	}
	return ref, nil
}

func (r Reference) ValidateScope(tenantRef, routerRef, agentRef string, installationID InstallationIdentity) error {
	if r.TenantRef != tenantRef || r.RouterRef != routerRef || r.AgentRef != agentRef {
		return ErrCredentialReferenceInvalid
	}
	if r.InstallationID != installationID {
		return ErrCredentialInstallationMismatch
	}
	return nil
}

type FakeResolver struct {
	records map[string]CredentialRecord
	states  map[string]State
}

func NewFakeResolver() *FakeResolver {
	return &FakeResolver{records: make(map[string]CredentialRecord), states: make(map[string]State)}
}
func (r *FakeResolver) SetState(ref Reference, state State) { r.states[key(ref)] = state }
func (r *FakeResolver) SetRecord(ref Reference, record CredentialRecord) {
	r.records[key(ref)] = CredentialRecord{Status: record.Status, Username: record.Username, Secret: append([]byte(nil), record.Secret...)}
}

func (r *FakeResolver) Resolve(_ context.Context, ref Reference) (ResolvedCredential, error) {
	parsed, err := ParseReference(ref)
	if err != nil {
		return ResolvedCredential{}, purposeError(ref.Purpose, err, err)
	}
	record, found := r.records[key(parsed)]
	if !found {
		if state, ok := r.states[key(parsed)]; ok {
			if state == CredentialStateMissing {
				return ResolvedCredential{}, purposeError(parsed.Purpose, ErrObserverCredentialMissing, ErrOperatorCredentialMissing)
			}
			record = CredentialRecord{Status: statusForState(state)}
		} else if r.hasOtherVersion(parsed) {
			return ResolvedCredential{}, ErrCredentialVersionMismatch
		} else if r.hasOtherInstallation(parsed) {
			return ResolvedCredential{}, ErrCredentialInstallationMismatch
		} else {
			return ResolvedCredential{}, purposeError(parsed.Purpose, ErrObserverCredentialMissing, ErrOperatorCredentialMissing)
		}
	}
	switch record.Status {
	case CredentialStatusActive:
		if record.Username == "" || len(record.Secret) == 0 {
			return ResolvedCredential{}, purposeError(parsed.Purpose, ErrObserverCredentialInvalid, ErrOperatorCredentialInvalid)
		}
		return NewResolvedCredential(record.Username, record.Secret), nil
	case CredentialStatusRevoked:
		return ResolvedCredential{}, purposeError(parsed.Purpose, ErrObserverCredentialRevoked, ErrOperatorCredentialRevoked)
	case CredentialStatusRetired:
		return ResolvedCredential{}, purposeError(parsed.Purpose, ErrObserverCredentialRetired, ErrOperatorCredentialRetired)
	case "":
		return ResolvedCredential{}, purposeError(parsed.Purpose, ErrObserverCredentialInvalid, ErrOperatorCredentialInvalid)
	default:
		return ResolvedCredential{}, ErrCredentialStatusInvalid
	}
}

func (r *FakeResolver) hasOtherVersion(ref Reference) bool {
	for candidate := range r.records {
		if strings.HasPrefix(candidate, scopeKey(ref)+"|") {
			return true
		}
	}
	return false
}

func (r *FakeResolver) hasOtherInstallation(ref Reference) bool {
	for candidate := range r.records {
		parts := strings.Split(candidate, "|")
		if len(parts) == 7 && parts[0] == ref.TenantRef && parts[1] == ref.RouterRef && parts[2] == ref.AgentRef && parts[4] == ref.CredentialRef && parts[5] == string(ref.Purpose) && parts[6] == fmt.Sprint(ref.Version) {
			return true
		}
	}
	return false
}
func validRef(value string) bool {
	return value != "" && len(value) <= 191 && refPattern.MatchString(value)
}
func containsSecretWord(value string) bool {
	return regexp.MustCompile(`(?i)(password|secret|token|credential)`).MatchString(value)
}
func key(ref Reference) string {
	return fmt.Sprintf("%s|%s|%s|%s|%s|%s|%d", ref.TenantRef, ref.RouterRef, ref.AgentRef, ref.InstallationID, ref.CredentialRef, ref.Purpose, ref.Version)
}
func scopeKey(ref Reference) string {
	return fmt.Sprintf("%s|%s|%s|%s|%s|%s", ref.TenantRef, ref.RouterRef, ref.AgentRef, ref.InstallationID, ref.CredentialRef, ref.Purpose)
}
func purposeError(purpose Purpose, observer, operator error) error {
	if purpose == PurposeObserver {
		return observer
	}
	return operator
}

type State string

const (
	CredentialStateMissing State = "missing"
	CredentialStateInvalid State = "invalid"
	CredentialStateRevoked State = "revoked"
	CredentialStateReady   State = "ready"
)

func statusForState(state State) CredentialStatus {
	switch state {
	case CredentialStateReady:
		return CredentialStatusActive
	case CredentialStateRevoked:
		return CredentialStatusRevoked
	case CredentialStateMissing:
		return ""
	default:
		return "UNKNOWN"
	}
}
