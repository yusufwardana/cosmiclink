package monitoring

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"strconv"
	"strings"
	"time"

	"cosmiclink/network-engine/internal/network"
)

var trafficRequiredCommands = []string{
	"/system/resource/print",
	"/interface/print",
	"/queue/simple/print",
	"/ip/hotspot/active/print",
}

var trafficEnrichmentCommands = []string{
	"/ip/dhcp-server/lease/print",
	"/ip/arp/print",
}

var trafficCommandDatasets = map[string]string{
	"/system/resource/print":      "system_resource",
	"/interface/print":            "interfaces",
	"/queue/simple/print":         "simple_queues",
	"/ip/hotspot/active/print":    "hotspot_sessions",
	"/ip/dhcp-server/lease/print": "dhcp_leases",
	"/ip/arp/print":               "arp_entries",
}

func (p *RouterOSMonitoringProvider) CollectTraffic(ctx context.Context, target RouterTarget, options TrafficOptions) (TrafficSnapshot, error) {
	if target.Host == "" || target.Port <= 0 || target.Username == "" || target.Transport == "" {
		return TrafficSnapshot{}, &Error{Code: FailureRouterProtocol, Message: "invalid RouterOS monitoring target"}
	}
	if target.InsecureTLS && !p.allowInsecureTLS {
		return TrafficSnapshot{}, &Error{Code: FailureRouterProtocol, Message: "insecure RouterOS TLS is disabled"}
	}
	connection := network.DiscoveryConnection{Host: target.Host, Port: target.Port, Username: target.Username, Password: target.Password, Transport: target.Transport, ConnectTimeoutSeconds: target.ConnectTimeoutSeconds, ReadTimeoutSeconds: target.ReadTimeoutSeconds, InsecureTLS: target.InsecureTLS}
	if connection.ConnectTimeoutSeconds <= 0 {
		connection.ConnectTimeoutSeconds = 3
	}
	if connection.ReadTimeoutSeconds <= 0 {
		connection.ReadTimeoutSeconds = 5
	}

	transport := p.newTransport()
	if err := transport.Connect(ctx, connection); err != nil {
		return TrafficSnapshot{}, classifyRouterError(err)
	}
	defer transport.Close()

	reads := make(map[string][]map[string]string)
	datasets := make([]string, 0, 6)
	for _, command := range trafficRequiredCommands {
		rows, err := readTrafficCommand(ctx, transport, command, connection.ReadTimeoutSeconds)
		if err != nil {
			return TrafficSnapshot{}, classifyRouterError(err)
		}
		reads[command] = rows
		datasets = append(datasets, trafficCommandDatasets[command])
	}
	enrichmentCollected := false
	if options.IncludeEnrichment {
		enrichmentCollected = true
		for _, command := range trafficEnrichmentCommands {
			rows, err := readTrafficCommand(ctx, transport, command, connection.ReadTimeoutSeconds)
			if err != nil {
				enrichmentCollected = false
				continue
			}
			reads[command] = rows
			datasets = append(datasets, trafficCommandDatasets[command])
		}
	}

	resource := first(reads["/system/resource/print"])
	return TrafficSnapshot{
		CollectedAt: time.Now().UTC(),
		Router: RouterResource{
			Version: resource["version"], Architecture: resource["architecture-name"], BoardName: resource["board-name"],
			UptimeSeconds: durationSeconds(resource["uptime"]), CPULoad: intValue(resource["cpu-load"]),
			MemoryTotal: intValue64(resource["total-memory"]), MemoryFree: intValue64(resource["free-memory"]),
		},
		Evidence:        TrafficEvidence{Datasets: datasets, EnrichmentCollected: enrichmentCollected},
		Interfaces:      normalizeInterfaceTraffic(reads["/interface/print"]),
		SimpleQueues:    normalizeSimpleQueueTraffic(reads["/queue/simple/print"]),
		HotspotSessions: normalizeHotspotTraffic(reads["/ip/hotspot/active/print"]),
		DHCPLeases:      normalizeDHCPLeases(reads["/ip/dhcp-server/lease/print"]),
		ARPEntries:      normalizeARPEntries(reads["/ip/arp/print"]),
	}, nil
}

func readTrafficCommand(ctx context.Context, transport interface {
	Read(context.Context, string) ([]map[string]string, error)
}, command string, timeoutSeconds int) ([]map[string]string, error) {
	if _, ok := trafficCommandDatasets[command]; !ok {
		return nil, &Error{Code: FailureRouterProtocol, Message: "forbidden RouterOS monitoring command"}
	}
	readCtx, cancel := context.WithTimeout(ctx, time.Duration(timeoutSeconds)*time.Second)
	defer cancel()
	return transport.Read(readCtx, command)
}

func normalizeInterfaceTraffic(rows []map[string]string) []InterfaceTraffic {
	result := make([]InterfaceTraffic, 0, len(rows))
	for _, row := range rows {
		if row[".id"] == "" {
			continue
		}
		result = append(result, InterfaceTraffic{SourceKey: row[".id"], Name: row["name"], Type: row["type"], Running: boolValue(row["running"]), Disabled: boolValue(row["disabled"]), DownloadBytes: uint64Value(row["rx-byte"]), UploadBytes: uint64Value(row["tx-byte"])})
	}
	return result
}

func normalizeSimpleQueueTraffic(rows []map[string]string) []SimpleQueueTraffic {
	result := make([]SimpleQueueTraffic, 0, len(rows))
	for _, row := range rows {
		if row[".id"] == "" {
			continue
		}
		upload, download := uint64Pair(row["bytes"])
		uploadRate, downloadRate := uint64Pair(row["rate"])
		uploadLimit, downloadLimit := uint64Pair(row["max-limit"])
		subject := normalizeSubject(row["target"])
		if subject == "" {
			subject = normalizeSubject(row["name"])
		}
		result = append(result, SimpleQueueTraffic{SourceKey: row[".id"], SubjectKey: subject, Name: row["name"], Target: row["target"], Disabled: boolValue(row["disabled"]), Dynamic: boolValue(row["dynamic"]), UploadBytes: upload, DownloadBytes: download, UploadRate: uploadRate, DownloadRate: downloadRate, UploadLimit: uploadLimit, DownloadLimit: downloadLimit})
	}
	return result
}

func normalizeHotspotTraffic(rows []map[string]string) []HotspotSessionTraffic {
	result := make([]HotspotSessionTraffic, 0, len(rows))
	for _, row := range rows {
		if row[".id"] == "" {
			continue
		}
		user := normalizeSubject(row["user"])
		result = append(result, HotspotSessionTraffic{SourceKey: hotspotSourceKey(row[".id"], user, row["address"], row["mac-address"]), SubjectKey: user, RouterOSID: row[".id"], User: strings.TrimSpace(row["user"]), Address: strings.TrimSpace(row["address"]), MACAddress: normalizeMAC(row["mac-address"]), Server: row["server"], LoginBy: row["login-by"], Uptime: row["uptime"], DownloadBytes: uint64Value(row["bytes-in"]), UploadBytes: uint64Value(row["bytes-out"])})
	}
	return result
}

func normalizeDHCPLeases(rows []map[string]string) []DHCPLease {
	result := make([]DHCPLease, 0, len(rows))
	for _, row := range rows {
		result = append(result, DHCPLease{SourceKey: row[".id"], Address: row["address"], MACAddress: normalizeMAC(row["mac-address"]), HostName: row["host-name"], Server: row["server"], Status: row["status"]})
	}
	return result
}

func normalizeARPEntries(rows []map[string]string) []ARPEntry {
	result := make([]ARPEntry, 0, len(rows))
	for _, row := range rows {
		result = append(result, ARPEntry{SourceKey: row[".id"], Address: row["address"], MACAddress: normalizeMAC(row["mac-address"]), Interface: row["interface"], Complete: boolValue(row["complete"])})
	}
	return result
}

func hotspotSourceKey(id, user, address, mac string) string {
	normalized := strings.Join([]string{strings.TrimSpace(id), normalizeSubject(user), strings.TrimSpace(address), normalizeMAC(mac)}, "\x00")
	digest := sha256.Sum256([]byte(normalized))
	return hex.EncodeToString(digest[:])
}

func normalizeSubject(value string) string { return strings.ToLower(strings.TrimSpace(value)) }
func normalizeMAC(value string) string     { return strings.ToUpper(strings.TrimSpace(value)) }
func boolValue(value string) bool {
	parsed, _ := strconv.ParseBool(strings.TrimSpace(value))
	return parsed
}
func uint64Value(value string) *uint64 {
	if strings.TrimSpace(value) == "" {
		return nil
	}
	parsed, err := strconv.ParseUint(strings.TrimSpace(value), 10, 64)
	if err != nil {
		return nil
	}
	return &parsed
}
func uint64Pair(value string) (*uint64, *uint64) {
	parts := strings.Split(value, "/")
	if len(parts) != 2 {
		return nil, nil
	}
	first, second := uint64Value(parts[0]), uint64Value(parts[1])
	if first == nil || second == nil {
		return nil, nil
	}
	return first, second
}
