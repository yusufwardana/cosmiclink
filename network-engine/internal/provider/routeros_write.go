package provider

import (
	"context"
	"errors"
	"fmt"
	"regexp"
	"strings"

	"cosmiclink/network-engine/internal/network"
)

// Phase 6I, Task 1 defines the only RouterOS writes this engine may ever send.
// Every entry is a fixed sentence shape built from the operation plus a
// validated resource identity; nothing a caller sends can extend it.
const (
	writePPPSecretSetCommand = "/ppp/secret/set"
	removePPPActiveCommand   = "/ppp/active/remove"
)

var (
	// ErrRealRouterOSOperationForbidden is returned when an operation, identity,
	// or path is outside the narrow Phase 6I mutation allowlist. The wrapped
	// text never contains caller-supplied values.
	ErrRealRouterOSOperationForbidden = errors.New("routeros mutation is outside the approved allowlist")
	// ErrInvalidRouterOSResourceID is returned when a resource identity is not a
	// bare MikroTik .id. Complete sentences and injection attempts are rejected.
	ErrInvalidRouterOSResourceID = errors.New("invalid RouterOS resource identity")

	// realRouterOSID is a bare MikroTik identity: ".id=*7" or a short name made of
	// a conservative character class. Semicolons, quotes, equals signs, control
	// characters, whitespace and traversal sequences cannot match, and names are
	// capped at 32 characters so an over-long value is rejected outright.
	realRouterOSID = regexp.MustCompile(`\A(?:\*[0-9]{1,9}|[A-Za-z0-9][A-Za-z0-9._-]{0,31})\z`)
)

// allowedRouterOSWrite describes one approved sentence: a fixed path plus the
// attribute words that may accompany a validated identity.
type allowedRouterOSWrite struct {
	// Path is the RouterOS menu the write is sent to.
	Path string
	// Disabled, when set, is the only value written to the disabled attribute.
	Disabled string
	// Remove marks the sentence as a remove-by-.id command.
	Remove bool
}

// Words builds the argument list for the approved sentence. The identity has
// already been validated, and every other token is a compile-time constant.
func (w allowedRouterOSWrite) Words(operation network.MutationOperation, identity string) []string {
	words := []string{"=.id=" + identity}
	if w.Disabled != "" {
		words = append(words, "=disabled="+w.Disabled)
	}
	return words
}

// realRouterOSWrites is the mutation allowlist. Keys are the transport-neutral
// API operation codes, so no RouterOS sentence can be used to select an entry.
var realRouterOSWrites = map[network.MutationOperation]allowedRouterOSWrite{
	network.MutationEnablePPPoE:       {Path: writePPPSecretSetCommand, Disabled: "no"},
	network.MutationDisablePPPoE:      {Path: writePPPSecretSetCommand, Disabled: "yes"},
	network.MutationDisconnectSession: {Path: removePPPActiveCommand, Remove: true},
}

// RealRouterOSOperations returns the three approved write operations.
func RealRouterOSOperations() []network.MutationOperation {
	return network.MutationOperations()
}

// IsRealRouterOSOperation reports whether operation is one of the approved
// writes. Matching is exact: empty, whitespace, lowercase, and RouterOS
// sentence text are all rejected.
func IsRealRouterOSOperation(operation string) bool {
	_, ok := realRouterOSWrites[network.MutationOperation(operation)]
	return ok
}

// RouterOSPreparedWrite is an immutable, allowlisted RouterOS sentence. It can
// only be produced by PrepareRouterOSWrite, so a transport that accepts nothing
// else cannot be handed an arbitrary command.
type RouterOSPreparedWrite struct {
	operation network.MutationOperation
	path      string
	words     []string
}

// Operation returns the API operation code the write was prepared for.
func (p RouterOSPreparedWrite) Operation() network.MutationOperation { return p.operation }

// Path returns the allowlisted RouterOS menu for the sentence.
func (p RouterOSPreparedWrite) Path() string { return p.path }

// Words returns a copy of the argument tokens.
func (p RouterOSPreparedWrite) Words() []string {
	words := make([]string, len(p.words))
	copy(words, p.words)
	return words
}

// Sentence renders the complete RouterOS command. It is derived from the
// allowlisted path and tokens, never from caller text.
func (p RouterOSPreparedWrite) Sentence() string {
	if len(p.words) == 0 {
		return p.path
	}
	return p.path + " " + strings.Join(p.words, " ")
}

// PrepareRouterOSWrite turns an approved operation plus a validated resource
// identity into the only sentence shape that operation may ever take. The
// operation is checked before the identity so a rejection can never echo the
// more sensitive value, and neither value is included in any error message.
func PrepareRouterOSWrite(operation network.MutationOperation, identity string) (RouterOSPreparedWrite, error) {
	entry, ok := realRouterOSWrites[operation]
	if !ok {
		return RouterOSPreparedWrite{}, fmt.Errorf("%w: %s", ErrRealRouterOSOperationForbidden, "operation is not one of the approved RouterOS writes")
	}
	if !realRouterOSID.MatchString(identity) {
		return RouterOSPreparedWrite{}, fmt.Errorf("%w: %s", ErrInvalidRouterOSResourceID, "resource identity is not a bare RouterOS .id or name")
	}
	return RouterOSPreparedWrite{
		operation: operation,
		path:      entry.Path,
		words:     entry.Words(operation, identity),
	}, nil
}

// RouterOSWritePathAllowed reports whether path is on the write allowlist. It is
// a total function: any path that is not an approved write menu returns false.
func RouterOSWritePathAllowed(path string) bool {
	switch path {
	case writePPPSecretSetCommand, removePPPActiveCommand:
		return true
	default:
		return false
	}
}

// RouterOSMutationTransport is the separate, write-capable transport contract.
// The read-only RouterOSTransport used by discovery and monitoring never gains a
// Write method, and a transport here can only accept a prepared, allowlisted
// sentence. Task 1 provides no production implementation.
type RouterOSMutationTransport interface {
	Connect(context.Context, network.DiscoveryConnection) error
	Write(context.Context, RouterOSPreparedWrite) error
	Close() error
}
