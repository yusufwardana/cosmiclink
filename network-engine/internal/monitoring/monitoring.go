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

// IdentityProvider performs the intentionally narrower first-contact check.
// It must issue only /system/identity/print and /system/resource/print.
type IdentityProvider interface {
	VerifyIdentity(ctx context.Context, target RouterTarget) (Snapshot, error)
}

// HotspotSurveyProvider performs the narrowly scoped read-only ARP/Hotspot
// survey. It must issue only the three survey commands and never persist data.
type HotspotSurveyProvider interface {
	SurveyHotspot(ctx context.Context, target RouterTarget) (HotspotSurvey, error)
}

// HotspotAccountProvider performs the narrow account-only validation used by
// Discovery preview. Implementations must issue only /ip/hotspot/user/print.
type HotspotAccountProvider interface {
	SurveyHotspotAccounts(ctx context.Context, target RouterTarget) ([]HotspotUserSurveyEntry, error)
}

type DHCPLeaseSurveyProvider interface {
	SurveyDHCPLeases(ctx context.Context, target RouterTarget) ([]DHCPLeaseSurveyEntry, error)
}

type ARPSurveyEntry struct {
	Address    string `json:"address,omitempty"`
	MACAddress string `json:"mac_address,omitempty"`
	Interface  string `json:"interface,omitempty"`
	Complete   bool   `json:"complete"`
	Dynamic    bool   `json:"dynamic"`
}

type HotspotUserSurveyEntry struct {
	Username string `json:"username"`
	Profile  string `json:"profile,omitempty"`
	Disabled bool   `json:"disabled"`
	Comment  string `json:"comment,omitempty"`
}

type HotspotSessionSurveyEntry struct {
	Username   string `json:"username"`
	Address    string `json:"address,omitempty"`
	MACAddress string `json:"mac_address,omitempty"`
	Server     string `json:"server,omitempty"`
	LoginBy    string `json:"login_by,omitempty"`
	Uptime     string `json:"uptime,omitempty"`
}

type HotspotSurvey struct {
	SurveyedAt     time.Time                   `json:"surveyed_at"`
	ARPEntries     []ARPSurveyEntry            `json:"arp_entries"`
	HotspotUsers   []HotspotUserSurveyEntry    `json:"hotspot_users"`
	ActiveSessions []HotspotSessionSurveyEntry `json:"active_sessions"`
}

type DHCPLeaseSurveyEntry struct {
	Address      string `json:"address,omitempty"`
	MACAddress   string `json:"mac_address,omitempty"`
	HostName     string `json:"host_name,omitempty"`
	ClientID     string `json:"client_id,omitempty"`
	Server       string `json:"server,omitempty"`
	Status       string `json:"status,omitempty"`
	Dynamic      bool   `json:"dynamic"`
	LastSeen     string `json:"last_seen,omitempty"`
	ExpiresAfter string `json:"expires_after,omitempty"`
}
