// Package monitoring defines the normalized, strictly read-only monitoring
// contract for the CosmicLink Network Engine.
//
// SECURITY / SAFETY INVARIANTS
//   - Only RouterOS READ commands may be issued by providers in this package.
//   - No mutation, provisioning, or arbitrary command execution is possible.
//   - Router/PPPoE credentials are accepted only as transient inputs and are
//     never returned, logged, or persisted by this package.
//   - This package must never be given a write-capable RouterOS command.
package monitoring

import (
	"context"
	"errors"
	"time"
)

// FailureCode is a bounded, operator-safe monitoring failure classification.
// Raw transport/RouterOS errors are never surfaced to Laravel.
type FailureCode string

const (
	FailureNone           FailureCode = ""
	FailureRouterUnreach  FailureCode = "ROUTER_UNREACHABLE"
	FailureRouterAuth     FailureCode = "ROUTER_AUTH_FAILED"
	FailureRouterTimeout  FailureCode = "ROUTER_TIMEOUT"
	FailureRouterProtocol FailureCode = "ROUTER_PROTOCOL_ERROR"
	FailureMonitoring     FailureCode = "MONITORING_FAILED"
)

// Error is a bounded monitoring failure. Message must never contain secrets.
type Error struct {
	Code    FailureCode
	Message string
}

func (e *Error) Error() string {
	if e == nil {
		return ""
	}
	if e.Message == "" {
		return string(e.Code)
	}
	return string(e.Code) + ": " + e.Message
}

// Classify maps any error to a bounded, secret-free monitoring error.
func Classify(err error) *Error {
	if err == nil {
		return nil
	}
	var me *Error
	if errors.As(err, &me) && me != nil {
		return me
	}
	return &Error{Code: FailureMonitoring, Message: "monitoring failed"}
}

// RouterTarget is a transient read-only connection target.
// Credentials are never persisted or logged.
type RouterTarget struct {
	Host                  string `json:"host"`
	Port                  int    `json:"port"`
	Username              string `json:"username"`
	Password              string `json:"password"`
	Transport             string `json:"transport"`
	ConnectTimeoutSeconds int    `json:"connect_timeout_seconds"`
	ReadTimeoutSeconds    int    `json:"read_timeout_seconds"`
	InsecureTLS           bool   `json:"insecure_tls"`
}

// RouterResource is normalized read-only router telemetry.
type RouterResource struct {
	Identity      string `json:"identity"`
	Version       string `json:"version"`
	Architecture  string `json:"architecture,omitempty"`
	BoardName     string `json:"board_name,omitempty"`
	UptimeSeconds int64  `json:"uptime_seconds"`
	CPULoad       int    `json:"cpu_load_percent"`
	MemoryTotal   int64  `json:"memory_total_bytes"`
	MemoryFree    int64  `json:"memory_free_bytes"`
}

// PPPSession is a normalized active PPPoE session.
// It deliberately excludes passwords and other secrets.
type PPPSession struct {
	Name    string `json:"name"`
	Service string `json:"service,omitempty"`
	Address string `json:"address,omitempty"`
	Uptime  string `json:"uptime,omitempty"`
}

// Snapshot is the normalized result of one read-only collection.
type Snapshot struct {
	CollectedAt time.Time      `json:"collected_at"`
	Router      RouterResource `json:"router"`
	PPPSessions []PPPSession   `json:"ppp_sessions"`
}

// Provider performs a single read-only monitoring collection.
type Provider interface {
	Collect(ctx context.Context, target RouterTarget) (Snapshot, error)
}
