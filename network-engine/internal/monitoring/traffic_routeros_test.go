package monitoring

import (
	"context"
	"encoding/json"
	"errors"
	"reflect"
	"testing"

	"cosmiclink/network-engine/internal/network"
	providerpkg "cosmiclink/network-engine/internal/provider"
)

func TestRouterOSTrafficProviderReadsRequiredDatasetsOnceAndNormalizesCounters(t *testing.T) {
	transport := &trafficTransportFake{responses: trafficResponses()}
	provider := NewRouterOSMonitoringProviderWithTransport(func() providerpkg.RouterOSTransport { return transport })

	snapshot, err := provider.CollectTraffic(context.Background(), RouterTarget{Host: "router.test", Port: 8728, Username: "readonly", Transport: "api"}, TrafficOptions{})
	if err != nil {
		t.Fatal(err)
	}

	if !reflect.DeepEqual(transport.commands, trafficRequiredCommands) {
		t.Fatalf("commands=%v", transport.commands)
	}
	if transport.connects != 1 || transport.closes != 1 || transport.mutations != 0 {
		t.Fatalf("connects=%d closes=%d mutations=%d", transport.connects, transport.closes, transport.mutations)
	}
	if !reflect.DeepEqual(snapshot.Evidence.Datasets, []string{"system_resource", "interfaces", "simple_queues", "hotspot_sessions"}) || snapshot.Evidence.EnrichmentCollected {
		t.Fatalf("evidence=%#v", snapshot.Evidence)
	}
	if len(snapshot.Interfaces) != 1 || snapshot.Interfaces[0].SourceKey != "*1" || value(snapshot.Interfaces[0].DownloadBytes) != 120 || value(snapshot.Interfaces[0].UploadBytes) != 80 || !snapshot.Interfaces[0].Running {
		t.Fatalf("interfaces=%#v", snapshot.Interfaces)
	}
	if len(snapshot.SimpleQueues) != 2 || value(snapshot.SimpleQueues[0].UploadBytes) != 100 || value(snapshot.SimpleQueues[0].DownloadBytes) != 200 || snapshot.SimpleQueues[1].UploadBytes != nil || snapshot.SimpleQueues[1].DownloadBytes != nil {
		t.Fatalf("queues=%#v", snapshot.SimpleQueues)
	}
	if len(snapshot.HotspotSessions) != 1 || value(snapshot.HotspotSessions[0].DownloadBytes) != 300 || value(snapshot.HotspotSessions[0].UploadBytes) != 150 || snapshot.HotspotSessions[0].SubjectKey != "alice" || snapshot.HotspotSessions[0].SourceKey == "*A" {
		t.Fatalf("hotspot=%#v", snapshot.HotspotSessions)
	}
	encoded, _ := json.Marshal(snapshot)
	if string(encoded) == "" || containsSecret(string(encoded)) {
		t.Fatalf("secret leaked: %s", encoded)
	}
}

func TestRouterOSTrafficProviderRunsOptionalEnrichmentAndBoundsOptionalFailure(t *testing.T) {
	responses := trafficResponses()
	responses["/ip/dhcp-server/lease/print"] = []map[string]string{{".id": "*D", "address": "10.0.0.2", "mac-address": "AA:BB", "host-name": "phone", "status": "bound", "password": "nope"}}
	responses["/ip/arp/print"] = []map[string]string{{".id": "*R", "address": "10.0.0.2", "mac-address": "AA:BB", "interface": "bridge", "complete": "true"}}
	transport := &trafficTransportFake{responses: responses, failures: map[string]error{"/ip/arp/print": errors.New("raw secret failure")}}
	provider := NewRouterOSMonitoringProviderWithTransport(func() providerpkg.RouterOSTransport { return transport })

	snapshot, err := provider.CollectTraffic(context.Background(), RouterTarget{Host: "router.test", Port: 8728, Username: "readonly", Transport: "api"}, TrafficOptions{IncludeEnrichment: true})
	if err != nil {
		t.Fatal(err)
	}

	if !reflect.DeepEqual(transport.commands, append(append([]string{}, trafficRequiredCommands...), trafficEnrichmentCommands...)) {
		t.Fatalf("commands=%v", transport.commands)
	}
	if snapshot.Evidence.EnrichmentCollected || len(snapshot.DHCPLeases) != 1 || len(snapshot.ARPEntries) != 0 {
		t.Fatalf("snapshot=%#v", snapshot)
	}
	if !reflect.DeepEqual(snapshot.Evidence.Datasets, []string{"system_resource", "interfaces", "simple_queues", "hotspot_sessions", "dhcp_leases"}) {
		t.Fatalf("datasets=%v", snapshot.Evidence.Datasets)
	}
}

func TestHotspotTrafficSourceKeyIsStableAndIdentitySensitive(t *testing.T) {
	a := hotspotSourceKey("*A", " Alice ", "10.0.0.2", "aa:bb")
	b := hotspotSourceKey("*A", "alice", "10.0.0.2", "AA:BB")
	c := hotspotSourceKey("*A", "alice", "10.0.0.3", "AA:BB")
	if a != b || a == c || a == "" {
		t.Fatalf("keys a=%q b=%q c=%q", a, b, c)
	}
}

func trafficResponses() map[string][]map[string]string {
	return map[string][]map[string]string{
		"/system/resource/print":   {{"version": "6.49.13", "architecture-name": "smips", "board-name": "hEX", "uptime": "1d2h", "cpu-load": "14", "total-memory": "1000", "free-memory": "400"}},
		"/interface/print":         {{".id": "*1", "name": "ether1", "type": "ether", "running": "true", "disabled": "false", "rx-byte": "120", "tx-byte": "80", "secret": "nope"}, {"name": "unstable", "rx-byte": "1", "tx-byte": "2"}},
		"/queue/simple/print":      {{".id": "*2", "name": "alice", "target": "10.0.0.2/32", "bytes": "100/200", "rate": "10/20", "max-limit": "1000/2000"}, {".id": "*3", "name": "broken", "bytes": "bad"}},
		"/ip/hotspot/active/print": {{".id": "*A", "user": " Alice ", "address": "10.0.0.2", "mac-address": "aa:bb", "server": "hotspot1", "login-by": "http-chap", "uptime": "1h", "bytes-in": "300", "bytes-out": "150", "password": "nope"}, {"user": "unstable"}},
	}
}

type trafficTransportFake struct {
	responses map[string][]map[string]string
	failures  map[string]error
	commands  []string
	connects  int
	closes    int
	mutations int
}

func (f *trafficTransportFake) Connect(context.Context, network.DiscoveryConnection) error {
	f.connects++
	return nil
}

func (f *trafficTransportFake) Read(_ context.Context, command string) ([]map[string]string, error) {
	allowed := append(append([]string{}, trafficRequiredCommands...), trafficEnrichmentCommands...)
	found := false
	for _, candidate := range allowed {
		if command == candidate {
			found = true
			break
		}
	}
	if !found {
		f.mutations++
		return nil, errors.New("forbidden")
	}
	f.commands = append(f.commands, command)
	if err := f.failures[command]; err != nil {
		return nil, err
	}
	return f.responses[command], nil
}

func (f *trafficTransportFake) Close() error { f.closes++; return nil }

func value(pointer *uint64) uint64 {
	if pointer == nil {
		return 0
	}
	return *pointer
}

func containsSecret(value string) bool {
	return reflect.ValueOf(value).String() == "must-never-match" ||
		contains(value, "nope") || contains(value, "password") || contains(value, "secret")
}

func contains(value, fragment string) bool {
	for index := 0; index+len(fragment) <= len(value); index++ {
		if value[index:index+len(fragment)] == fragment {
			return true
		}
	}
	return false
}
