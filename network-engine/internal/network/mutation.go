package network

import "context"

// MutationOperation is the narrow set of writes that Phase 6I permits against a
// live MikroTik router. The values are transport-independent API codes; the
// RouterOS sentence for each one lives in the provider allowlist, never here.
//
// CREATE_PPPOE, CHANGE_PROFILE and TEST_CONNECTION are deliberately absent:
// they remain ordinary Provider operations and are never treated as live
// RouterOS mutations.
type MutationOperation string

const (
	MutationEnablePPPoE       MutationOperation = "ENABLE_PPPOE"
	MutationDisablePPPoE      MutationOperation = "DISABLE_PPPOE"
	MutationDisconnectSession MutationOperation = "DISCONNECT_SESSION"
)

// MutationRequest is the request payload for a Phase 6I write. It is an alias,
// not a second wire type: the strict-decoded Request above stays the single
// source of truth for the Laravel-to-Go contract.
type MutationRequest = Request

// MutationProvider is the contract a provider must satisfy to be considered for
// Phase 6I RouterOS mutations. It exposes only the three allowlisted writes and
// the operations a particular implementation actually supports.
type MutationProvider interface {
	Name() string
	SupportedOperations() []MutationOperation
	EnableAccount(context.Context, Request) Result
	DisableAccount(context.Context, Request) Result
	DisconnectSession(context.Context, Request) Result
}

// MutationOperations returns the approved operations in stable order. Callers
// must not mutate the result.
func MutationOperations() []MutationOperation {
	return []MutationOperation{MutationEnablePPPoE, MutationDisablePPPoE, MutationDisconnectSession}
}

// MutationOperationFor maps an incoming API operation to a RouterOS mutation
// operation. The match is exact and case-sensitive: empty, whitespace,
// lowercased, deferred-capability, and raw-sentence values all report false so
// a caller can never fall through to a permitted operation.
func MutationOperationFor(operation string) (MutationOperation, bool) {
	switch MutationOperation(operation) {
	case MutationEnablePPPoE, MutationDisablePPPoE, MutationDisconnectSession:
		return MutationOperation(operation), true
	default:
		return "", false
	}
}
