package monitoring

import (
	"context"
	"crypto/tls"
	"fmt"
	"net"
	"regexp"
	"strconv"
	"strings"
	"time"

	"cosmiclink/network-engine/internal/network"
	"cosmiclink/network-engine/internal/provider"
	"github.com/go-routeros/routeros/v3"
)

var monitoringCommands = []string{"/system/resource/print", "/system/identity/print", "/ppp/active/print"}
var identityCommands = []string{"/system/identity/print", "/system/resource/print"}
var hotspotSurveyCommands = []string{"/ip/arp/print", "/ip/hotspot/user/print", "/ip/hotspot/active/print"}
var hotspotAccountCommand = "/ip/hotspot/user/print"
var dhcpSurveyCommand = "/ip/dhcp-server/lease/print"

type RouterOSMonitoringProvider struct {
	newTransport     func() provider.RouterOSTransport
	allowInsecureTLS bool
}

func NewRouterOSMonitoringProvider(allowInsecureTLS bool) *RouterOSMonitoringProvider {
	return &RouterOSMonitoringProvider{newTransport: func() provider.RouterOSTransport { return &monitoringTransport{} }, allowInsecureTLS: allowInsecureTLS}
}

func NewRouterOSMonitoringProviderWithTransport(factory func() provider.RouterOSTransport) *RouterOSMonitoringProvider {
	return &RouterOSMonitoringProvider{newTransport: factory}
}

func (p *RouterOSMonitoringProvider) Collect(ctx context.Context, target RouterTarget) (Snapshot, error) {
	if target.Host == "" || target.Port <= 0 || target.Username == "" || target.Transport == "" {
		return Snapshot{}, &Error{Code: FailureRouterProtocol, Message: "invalid RouterOS monitoring target"}
	}
	if target.InsecureTLS && !p.allowInsecureTLS {
		return Snapshot{}, &Error{Code: FailureRouterProtocol, Message: "insecure RouterOS TLS is disabled"}
	}
	transport := p.newTransport()
	connection := network.DiscoveryConnection{Host: target.Host, Port: target.Port, Username: target.Username, Password: target.Password, Transport: target.Transport, ConnectTimeoutSeconds: target.ConnectTimeoutSeconds, ReadTimeoutSeconds: target.ReadTimeoutSeconds, InsecureTLS: target.InsecureTLS}
	if connection.ConnectTimeoutSeconds <= 0 {
		connection.ConnectTimeoutSeconds = 3
	}
	if connection.ReadTimeoutSeconds <= 0 {
		connection.ReadTimeoutSeconds = 5
	}
	if err := transport.Connect(ctx, connection); err != nil {
		return Snapshot{}, classifyRouterError(err)
	}
	defer transport.Close()

	reads := make(map[string][]map[string]string, len(monitoringCommands))
	for _, command := range monitoringCommands {
		readCtx, cancel := context.WithTimeout(ctx, time.Duration(connection.ReadTimeoutSeconds)*time.Second)
		rows, err := transport.Read(readCtx, command)
		cancel()
		if err != nil {
			return Snapshot{}, classifyRouterError(err)
		}
		reads[command] = rows
	}
	resource := first(reads["/system/resource/print"])
	identity := first(reads["/system/identity/print"])
	sessions := make([]PPPSession, 0, len(reads["/ppp/active/print"]))
	for _, row := range reads["/ppp/active/print"] {
		service := row["service"]
		if service != "" && !strings.EqualFold(service, "pppoe") {
			continue
		}
		sessions = append(sessions, PPPSession{Name: row["name"], Service: service, Address: row["address"], Uptime: row["uptime"]})
	}
	return Snapshot{CollectedAt: time.Now().UTC(), Router: RouterResource{Identity: identity["name"], Version: resource["version"], Architecture: resource["architecture-name"], BoardName: resource["board-name"], UptimeSeconds: durationSeconds(resource["uptime"]), CPULoad: intValue(resource["cpu-load"]), MemoryTotal: intValue64(resource["total-memory"]), MemoryFree: intValue64(resource["free-memory"])}, PPPSessions: sessions}, nil
}

// VerifyIdentity performs the first-contact read-only check. It deliberately
// does not call Collect because that method also reads /ppp/active/print.
func (p *RouterOSMonitoringProvider) VerifyIdentity(ctx context.Context, target RouterTarget) (Snapshot, error) {
	if target.Host == "" || target.Port <= 0 || target.Username == "" || target.Transport == "" {
		return Snapshot{}, &Error{Code: FailureRouterProtocol, Message: "invalid RouterOS identity target"}
	}
	if target.InsecureTLS && !p.allowInsecureTLS {
		return Snapshot{}, &Error{Code: FailureRouterProtocol, Message: "insecure RouterOS TLS is disabled"}
	}
	transport := p.newTransport()
	connection := network.DiscoveryConnection{Host: target.Host, Port: target.Port, Username: target.Username, Password: target.Password, Transport: target.Transport, ConnectTimeoutSeconds: target.ConnectTimeoutSeconds, ReadTimeoutSeconds: target.ReadTimeoutSeconds, InsecureTLS: target.InsecureTLS}
	if connection.ConnectTimeoutSeconds <= 0 {
		connection.ConnectTimeoutSeconds = 3
	}
	if connection.ReadTimeoutSeconds <= 0 {
		connection.ReadTimeoutSeconds = 5
	}
	if err := transport.Connect(ctx, connection); err != nil {
		return Snapshot{}, classifyRouterError(err)
	}
	defer transport.Close()

	reads := make(map[string][]map[string]string, len(identityCommands))
	for _, command := range identityCommands {
		readCtx, cancel := context.WithTimeout(ctx, time.Duration(connection.ReadTimeoutSeconds)*time.Second)
		rows, err := transport.Read(readCtx, command)
		cancel()
		if err != nil {
			return Snapshot{}, classifyRouterError(err)
		}
		reads[command] = rows
	}
	resource := first(reads["/system/resource/print"])
	identity := first(reads["/system/identity/print"])
	return Snapshot{CollectedAt: time.Now().UTC(), Router: RouterResource{
		Identity: identity["name"], Version: resource["version"], Architecture: resource["architecture-name"],
		BoardName: resource["board-name"], UptimeSeconds: durationSeconds(resource["uptime"]),
		CPULoad: intValue(resource["cpu-load"]), MemoryTotal: intValue64(resource["total-memory"]), MemoryFree: intValue64(resource["free-memory"]),
	}}, nil
}

// SurveyHotspot performs only the explicitly authorized ARP and Hotspot reads.
// Password/secret-shaped fields are discarded before the result is returned.
func (p *RouterOSMonitoringProvider) SurveyHotspot(ctx context.Context, target RouterTarget) (HotspotSurvey, error) {
	if target.Host == "" || target.Port <= 0 || target.Username == "" || target.Transport == "" {
		return HotspotSurvey{}, &Error{Code: FailureRouterProtocol, Message: "invalid RouterOS survey target"}
	}
	if target.InsecureTLS && !p.allowInsecureTLS {
		return HotspotSurvey{}, &Error{Code: FailureRouterProtocol, Message: "insecure RouterOS TLS is disabled"}
	}
	transport := p.newTransport()
	connection := network.DiscoveryConnection{Host: target.Host, Port: target.Port, Username: target.Username, Password: target.Password, Transport: target.Transport, ConnectTimeoutSeconds: target.ConnectTimeoutSeconds, ReadTimeoutSeconds: target.ReadTimeoutSeconds, InsecureTLS: target.InsecureTLS}
	if connection.ConnectTimeoutSeconds <= 0 {
		connection.ConnectTimeoutSeconds = 3
	}
	if connection.ReadTimeoutSeconds <= 0 {
		connection.ReadTimeoutSeconds = 5
	}
	if err := transport.Connect(ctx, connection); err != nil {
		return HotspotSurvey{}, classifyRouterError(err)
	}
	defer transport.Close()

	reads := make(map[string][]map[string]string, len(hotspotSurveyCommands))
	for _, command := range hotspotSurveyCommands {
		readCtx, cancel := context.WithTimeout(ctx, time.Duration(connection.ReadTimeoutSeconds)*time.Second)
		rows, err := transport.Read(readCtx, command)
		cancel()
		if err != nil {
			return HotspotSurvey{}, classifyRouterError(err)
		}
		reads[command] = rows
	}

	return HotspotSurvey{
		SurveyedAt:     time.Now().UTC(),
		ARPEntries:     normalizeSurveyARP(reads["/ip/arp/print"]),
		HotspotUsers:   normalizeHotspotUsers(reads["/ip/hotspot/user/print"]),
		ActiveSessions: normalizeHotspotSessions(reads["/ip/hotspot/active/print"]),
	}, nil
}

// SurveyHotspotAccounts validates local Hotspot usernames without collecting
// ARP, active sessions, or any other observation data.
func (p *RouterOSMonitoringProvider) SurveyHotspotAccounts(ctx context.Context, target RouterTarget) ([]HotspotUserSurveyEntry, error) {
	if target.Host == "" || target.Port <= 0 || target.Username == "" || target.Transport == "" {
		return nil, &Error{Code: FailureRouterProtocol, Message: "invalid RouterOS Hotspot account target"}
	}
	if target.InsecureTLS && !p.allowInsecureTLS {
		return nil, &Error{Code: FailureRouterProtocol, Message: "insecure RouterOS TLS is disabled"}
	}
	transport := p.newTransport()
	connection := network.DiscoveryConnection{Host: target.Host, Port: target.Port, Username: target.Username, Password: target.Password, Transport: target.Transport, ConnectTimeoutSeconds: target.ConnectTimeoutSeconds, ReadTimeoutSeconds: target.ReadTimeoutSeconds, InsecureTLS: target.InsecureTLS}
	if connection.ConnectTimeoutSeconds <= 0 {
		connection.ConnectTimeoutSeconds = 3
	}
	if connection.ReadTimeoutSeconds <= 0 {
		connection.ReadTimeoutSeconds = 5
	}
	if err := transport.Connect(ctx, connection); err != nil {
		return nil, classifyRouterError(err)
	}
	defer transport.Close()
	readCtx, cancel := context.WithTimeout(ctx, time.Duration(connection.ReadTimeoutSeconds)*time.Second)
	rows, err := transport.Read(readCtx, hotspotAccountCommand)
	cancel()
	if err != nil {
		return nil, classifyRouterError(err)
	}
	return normalizeHotspotUsers(rows), nil
}

func (p *RouterOSMonitoringProvider) SurveyDHCPLeases(ctx context.Context, target RouterTarget) ([]DHCPLeaseSurveyEntry, error) {
	if target.Host == "" || target.Port <= 0 || target.Username == "" || target.Transport == "" {
		return nil, &Error{Code: FailureRouterProtocol, Message: "invalid RouterOS DHCP survey target"}
	}
	if target.InsecureTLS && !p.allowInsecureTLS {
		return nil, &Error{Code: FailureRouterProtocol, Message: "insecure RouterOS TLS is disabled"}
	}
	transport := p.newTransport()
	connection := network.DiscoveryConnection{Host: target.Host, Port: target.Port, Username: target.Username, Password: target.Password, Transport: target.Transport, ConnectTimeoutSeconds: target.ConnectTimeoutSeconds, ReadTimeoutSeconds: target.ReadTimeoutSeconds, InsecureTLS: target.InsecureTLS}
	if connection.ConnectTimeoutSeconds <= 0 {
		connection.ConnectTimeoutSeconds = 3
	}
	if connection.ReadTimeoutSeconds <= 0 {
		connection.ReadTimeoutSeconds = 5
	}
	if err := transport.Connect(ctx, connection); err != nil {
		return nil, classifyRouterError(err)
	}
	defer transport.Close()
	readCtx, cancel := context.WithTimeout(ctx, time.Duration(connection.ReadTimeoutSeconds)*time.Second)
	rows, err := transport.Read(readCtx, dhcpSurveyCommand)
	cancel()
	if err != nil {
		return nil, classifyRouterError(err)
	}
	result := make([]DHCPLeaseSurveyEntry, 0, len(rows))
	for _, row := range rows {
		result = append(result, DHCPLeaseSurveyEntry{
			Address: row["address"], MACAddress: normalizeSurveyMAC(row["mac-address"]), HostName: row["host-name"], ClientID: row["client-id"],
			Server: row["server"], Status: row["status"], Dynamic: surveyBool(row["dynamic"]), LastSeen: row["last-seen"], ExpiresAfter: row["expires-after"],
		})
	}
	return result, nil
}

func normalizeSurveyARP(rows []map[string]string) []ARPSurveyEntry {
	result := make([]ARPSurveyEntry, 0, len(rows))
	for _, row := range rows {
		result = append(result, ARPSurveyEntry{Address: row["address"], MACAddress: normalizeSurveyMAC(row["mac-address"]), Interface: row["interface"], Complete: surveyBool(row["complete"]), Dynamic: surveyBool(row["dynamic"])})
	}
	return result
}

func normalizeHotspotUsers(rows []map[string]string) []HotspotUserSurveyEntry {
	result := make([]HotspotUserSurveyEntry, 0, len(rows))
	for _, row := range rows {
		result = append(result, HotspotUserSurveyEntry{Username: strings.TrimSpace(row["name"]), Profile: row["profile"], Disabled: surveyBool(row["disabled"]), Comment: row["comment"]})
	}
	return result
}

func normalizeHotspotSessions(rows []map[string]string) []HotspotSessionSurveyEntry {
	result := make([]HotspotSessionSurveyEntry, 0, len(rows))
	for _, row := range rows {
		result = append(result, HotspotSessionSurveyEntry{Username: strings.TrimSpace(row["user"]), Address: row["address"], MACAddress: normalizeSurveyMAC(row["mac-address"]), Server: row["server"], LoginBy: row["login-by"], Uptime: row["uptime"]})
	}
	return result
}

func normalizeSurveyMAC(value string) string { return strings.ToUpper(strings.TrimSpace(value)) }
func surveyBool(value string) bool {
	parsed, _ := strconv.ParseBool(strings.TrimSpace(value))
	return parsed
}

func first(rows []map[string]string) map[string]string {
	if len(rows) == 0 {
		return map[string]string{}
	}
	return rows[0]
}
func intValue(value string) int          { n, _ := strconv.Atoi(value); return n }
func intValue64(value string) int64      { n, _ := strconv.ParseInt(value, 10, 64); return n }
func durationSeconds(value string) int64 { return int64(parseRouterOSDuration(value).Seconds()) }
func parseRouterOSDuration(value string) time.Duration {
	var total time.Duration
	pattern := regexp.MustCompile(`(?i)(\d+)([dhms])`)
	for _, match := range pattern.FindAllStringSubmatch(value, -1) {
		n, _ := strconv.Atoi(match[1])
		switch strings.ToLower(match[2]) {
		case "d":
			total += time.Duration(n) * 24 * time.Hour
		case "h":
			total += time.Duration(n) * time.Hour
		case "m":
			total += time.Duration(n) * time.Minute
		case "s":
			total += time.Duration(n) * time.Second
		}
	}
	return total
}
func classifyRouterError(err error) error {
	if err == context.DeadlineExceeded {
		return &Error{Code: FailureRouterTimeout, Message: "RouterOS monitoring timed out"}
	}
	message := strings.ToLower(err.Error())
	if strings.Contains(message, "auth") || strings.Contains(message, "login") {
		return &Error{Code: FailureRouterAuth, Message: "RouterOS authentication failed"}
	}
	return &Error{Code: FailureRouterUnreach, Message: "RouterOS monitoring connection failed"}
}

type monitoringTransport struct{ client *routeros.Client }

func (t *monitoringTransport) Connect(ctx context.Context, c network.DiscoveryConnection) error {
	address := net.JoinHostPort(c.Host, fmt.Sprintf("%d", c.Port))
	var err error
	if c.Transport == "api_ssl" {
		t.client, err = routeros.DialTLSContext(ctx, address, c.Username, c.Password, &tls.Config{ServerName: c.Host, MinVersion: tls.VersionTLS12, InsecureSkipVerify: c.InsecureTLS})
	} else {
		t.client, err = routeros.DialContext(ctx, address, c.Username, c.Password)
	}
	if err == nil {
		t.client.SetLogHandler(provider.SilentRouterOSLogHandler())
	}
	return err
}
func (t *monitoringTransport) Read(ctx context.Context, command string) ([]map[string]string, error) {
	reply, err := t.client.RunContext(ctx, command)
	if err != nil {
		return nil, err
	}
	rows := make([]map[string]string, 0, len(reply.Re))
	for _, sentence := range reply.Re {
		row := map[string]string{}
		for key, value := range sentence.Map {
			if key != "password" && key != "secret" {
				row[key] = value
			}
		}
		rows = append(rows, row)
	}
	return rows, nil
}
func (t *monitoringTransport) Close() error {
	if t.client == nil {
		return nil
	}
	return t.client.Close()
}
