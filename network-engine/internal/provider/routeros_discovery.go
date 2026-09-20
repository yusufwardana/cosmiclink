package provider

import (
	"context"
	"crypto/tls"
	"errors"
	"fmt"
	"log/slog"
	"net"
	"strings"
	"time"

	"cosmiclink/network-engine/internal/network"
	"github.com/go-routeros/routeros/v3"
)

const (
	readSystemResource = "/system/resource/print"
	readSystemIdentity = "/system/identity/print"
	readPPPProfile     = "/ppp/profile/print"
	readPPPSecret      = "/ppp/secret/print"
	readIPPool         = "/ip/pool/print"
	readSimpleQueue    = "/queue/simple/print"
)

var allowedRouterOSReadCommands = map[string]struct{}{
	readSystemResource: {}, readSystemIdentity: {}, readPPPProfile: {}, readPPPSecret: {}, readIPPool: {}, readSimpleQueue: {},
}

var ErrForbiddenRouterOSCommand = errors.New("forbidden RouterOS command")

// RouterOSTransport is intentionally narrow. Application code cannot execute
// arbitrary RouterOS sentences; the provider only asks it to read allowlisted
// print commands.
type RouterOSTransport interface {
	Connect(context.Context, network.DiscoveryConnection) error
	Read(context.Context, string) ([]map[string]string, error)
	Close() error
}

type RouterOSTransportFactory func() RouterOSTransport

type RouterOSDiscoveryProvider struct {
	newTransport     RouterOSTransportFactory
	allowInsecureTLS bool
}

func NewRouterOSDiscoveryProvider() *RouterOSDiscoveryProvider {
	return NewRouterOSDiscoveryProviderWithTLS(false)
}

func NewRouterOSDiscoveryProviderWithTLS(allowInsecureTLS bool) *RouterOSDiscoveryProvider {
	return &RouterOSDiscoveryProvider{newTransport: func() RouterOSTransport { return &realRouterOSTransport{} }, allowInsecureTLS: allowInsecureTLS}
}

func NewRouterOSDiscoveryProviderWithTransport(factory RouterOSTransportFactory) *RouterOSDiscoveryProvider {
	return &RouterOSDiscoveryProvider{newTransport: factory}
}

func (p *RouterOSDiscoveryProvider) Name() string { return "routeros" }

func (p *RouterOSDiscoveryProvider) Discover(ctx context.Context, request network.DiscoveryRequest) network.DiscoveryResult {
	if request.Connection == nil || !validRouterOSConnection(*request.Connection) || (request.Connection.InsecureTLS && !p.allowInsecureTLS) {
		return discoveryFailure(p.Name(), request.RouterRef, "DISCOVERY_FAILED", "RouterOS discovery connection is invalid")
	}
	transport := p.newTransport()
	if err := transport.Connect(ctx, *request.Connection); err != nil {
		return discoveryFailure(p.Name(), request.RouterRef, routerOSErrorCode(err), "RouterOS discovery connection failed")
	}
	defer transport.Close()

	reads := map[string][]map[string]string{}
	for _, command := range []string{readSystemResource, readSystemIdentity, readPPPProfile, readPPPSecret, readIPPool, readSimpleQueue} {
		readCtx, cancel := context.WithTimeout(ctx, time.Duration(request.Connection.ReadTimeoutSeconds)*time.Second)
		rows, err := guardedRead(readCtx, transport, command)
		cancel()
		if err != nil {
			return discoveryFailure(p.Name(), request.RouterRef, routerOSErrorCode(err), "RouterOS discovery read failed")
		}
		reads[command] = rows
	}

	device := normalizeDevice(reads[readSystemIdentity], reads[readSystemResource])
	if warning := versionWarning(stringValue(device, "routeros_version")); warning != "" {
		device["capability_warning"] = warning
	}
	return network.DiscoveryResult{Success: true, Provider: p.Name(), RouterRef: request.RouterRef, DiscoveredAt: time.Now().UTC().Format(time.RFC3339), Code: "DISCOVERY_COMPLETE", Message: "Read-only RouterOS discovery completed", Snapshot: network.DiscoverySnapshot{
		Device: device, Profiles: normalizeProfiles(reads[readPPPProfile]), Accounts: normalizeAccounts(reads[readPPPSecret]), AddressPools: normalizePools(reads[readIPPool]), Queues: normalizeQueues(reads[readSimpleQueue]),
	}}
}

func guardedRead(ctx context.Context, transport RouterOSTransport, command string) ([]map[string]string, error) {
	if _, allowed := allowedRouterOSReadCommands[command]; !allowed {
		return nil, fmt.Errorf("%w: %s", ErrForbiddenRouterOSCommand, command)
	}
	return transport.Read(ctx, command)
}

func validRouterOSConnection(c network.DiscoveryConnection) bool {
	return c.Host != "" && c.Port > 0 && c.Port <= 65535 && c.Username != "" && c.Password != "" && (c.Transport == "api" || c.Transport == "api_ssl") && c.ConnectTimeoutSeconds > 0 && c.ReadTimeoutSeconds > 0
}

func discoveryFailure(provider, routerRef, code, message string) network.DiscoveryResult {
	return network.DiscoveryResult{Success: false, Provider: provider, RouterRef: routerRef, Code: code, Message: message}
}

func routerOSErrorCode(err error) string {
	if errors.Is(err, ErrForbiddenRouterOSCommand) {
		return "DISCOVERY_FAILED"
	}
	if errors.Is(err, context.DeadlineExceeded) {
		return "ROUTER_TIMEOUT"
	}
	var netErr net.Error
	if errors.As(err, &netErr) && netErr.Timeout() {
		return "ROUTER_TIMEOUT"
	}
	text := strings.ToLower(err.Error())
	switch {
	case strings.Contains(text, "login"), strings.Contains(text, "authentication"), strings.Contains(text, "not enough permissions"):
		return "ROUTER_AUTH_FAILED"
	case strings.Contains(text, "connection refused"), strings.Contains(text, "no such host"), strings.Contains(text, "network is unreachable"), strings.Contains(text, "could not connect"):
		return "ROUTER_UNREACHABLE"
	case strings.Contains(text, "protocol"), strings.Contains(text, "malformed"):
		return "ROUTER_PROTOCOL_ERROR"
	default:
		return "DISCOVERY_FAILED"
	}
}

func normalizeDevice(identity, resource []map[string]string) map[string]any {
	i, r := first(identity), first(resource)
	return map[string]any{"name": i["name"], "routeros_version": r["version"], "architecture": r["architecture-name"], "board_name": r["board-name"], "platform": r["platform"], "uptime": r["uptime"], "management_mode": "read_only"}
}
func normalizeProfiles(rows []map[string]string) []map[string]any {
	out := make([]map[string]any, 0, len(rows))
	for _, r := range rows {
		out = append(out, map[string]any{"external_ref": externalRef(r), "name": r["name"], "local_address": r["local-address"], "remote_address": r["remote-address"], "rate_limit": r["rate-limit"]})
	}
	return out
}
func normalizeAccounts(rows []map[string]string) []map[string]any {
	out := make([]map[string]any, 0, len(rows))
	for _, r := range rows {
		out = append(out, map[string]any{"external_ref": externalRef(r), "username": r["name"], "profile": r["profile"], "service": r["service"], "enabled": !routerOSBool(r["disabled"]), "comment": r["comment"]})
	}
	return out
}
func normalizePools(rows []map[string]string) []map[string]any {
	out := make([]map[string]any, 0, len(rows))
	for _, r := range rows {
		out = append(out, map[string]any{"external_ref": externalRef(r), "name": r["name"], "ranges": r["ranges"]})
	}
	return out
}
func normalizeQueues(rows []map[string]string) []map[string]any {
	out := make([]map[string]any, 0, len(rows))
	for _, r := range rows {
		out = append(out, map[string]any{"external_ref": externalRef(r), "name": r["name"], "target": r["target"], "max_limit": r["max-limit"]})
	}
	return out
}
func first(rows []map[string]string) map[string]string {
	if len(rows) == 0 {
		return map[string]string{}
	}
	return rows[0]
}
func externalRef(row map[string]string) string {
	if row[".id"] != "" {
		return row[".id"]
	}
	return row["name"]
}
func routerOSBool(value string) bool {
	return strings.EqualFold(value, "true") || strings.EqualFold(value, "yes")
}
func stringValue(data map[string]any, key string) string {
	value, _ := data[key].(string)
	return value
}
func versionWarning(version string) string {
	if version == "" {
		return "RouterOS version was not reported; compatibility is unverified."
	}
	if !strings.HasPrefix(version, "6.49.") {
		return "RouterOS version is outside the tested Phase 6D 6.49.x range; read-only discovery was attempted without compatibility certification."
	}
	return ""
}

type realRouterOSTransport struct{ client *routeros.Client }

func (t *realRouterOSTransport) Connect(ctx context.Context, c network.DiscoveryConnection) error {
	address := net.JoinHostPort(c.Host, fmt.Sprintf("%d", c.Port))
	connectCtx, cancel := context.WithTimeout(ctx, time.Duration(c.ConnectTimeoutSeconds)*time.Second)
	defer cancel()
	var err error
	if c.Transport == "api_ssl" {
		t.client, err = routeros.DialTLSContext(connectCtx, address, c.Username, c.Password, &tls.Config{ServerName: c.Host, MinVersion: tls.VersionTLS12, InsecureSkipVerify: c.InsecureTLS})
	} else {
		t.client, err = routeros.DialContext(connectCtx, address, c.Username, c.Password)
	}
	if err != nil {
		return err
	}
	// The dependency logs API sentences at debug level. Discard them: login sentences contain passwords.
	t.client.SetLogHandler(silentRouterOSLogHandler{})
	return nil
}
func (t *realRouterOSTransport) Read(ctx context.Context, command string) ([]map[string]string, error) {
	if t.client == nil {
		return nil, errors.New("RouterOS client is not connected")
	}
	reply, err := t.client.RunContext(ctx, command)
	if err != nil {
		return nil, err
	}
	rows := make([]map[string]string, 0, len(reply.Re))
	for _, sentence := range reply.Re {
		row := map[string]string{}
		for k, v := range sentence.Map {
			row[k] = v
		}
		rows = append(rows, row)
	}
	return rows, nil
}
func (t *realRouterOSTransport) Close() error {
	if t.client == nil {
		return nil
	}
	return t.client.Close()
}

type silentRouterOSLogHandler struct{}

func SilentRouterOSLogHandler() slog.Handler { return silentRouterOSLogHandler{} }

func (silentRouterOSLogHandler) Enabled(context.Context, slog.Level) bool  { return false }
func (silentRouterOSLogHandler) Handle(context.Context, slog.Record) error { return nil }
func (silentRouterOSLogHandler) WithAttrs([]slog.Attr) slog.Handler {
	return silentRouterOSLogHandler{}
}
func (silentRouterOSLogHandler) WithGroup(string) slog.Handler { return silentRouterOSLogHandler{} }
