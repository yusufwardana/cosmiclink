package monitoring

import (
	"context"
	"errors"
	"reflect"
	"testing"

	"cosmiclink/network-engine/internal/network"
	"cosmiclink/network-engine/internal/provider"
)

func TestRouterOSMonitoringProviderReadsOnlyTheThreeAllowlistedCommands(t *testing.T) {
	transport := &monitoringTransportFake{responses: map[string][]map[string]string{
		"/system/resource/print": {{"version": "6.49.13", "board-name": "hEX", "cpu-load": "14", "total-memory": "100", "free-memory": "58"}},
		"/system/identity/print": {{"name": "edge-01"}},
		"/ppp/active/print":      {{"name": "customer-01", "service": "pppoe"}, {"name": "l2tp-user", "service": "l2tp", "password": "must-not-escape"}},
	}}
	provider := NewRouterOSMonitoringProviderWithTransport(func() provider.RouterOSTransport { return transport })
	snapshot, err := provider.Collect(context.Background(), RouterTarget{Host: "router.test", Port: 8728, Username: "readonly", Transport: "api"})
	if err != nil {
		t.Fatal(err)
	}
	if !reflect.DeepEqual(transport.commands, monitoringCommands) {
		t.Fatalf("commands=%v", transport.commands)
	}
	if len(snapshot.PPPSessions) != 1 || snapshot.PPPSessions[0].Name != "customer-01" {
		t.Fatalf("sessions=%#v", snapshot.PPPSessions)
	}
	if transport.mutations != 0 || !transport.closed {
		t.Fatalf("mutations=%d closed=%t", transport.mutations, transport.closed)
	}
}

func TestRouterOSMonitoringProviderHotspotAccountValidationReadsOnlyUserPrint(t *testing.T) {
	transport := &monitoringTransportFake{responses: map[string][]map[string]string{
		"/ip/hotspot/user/print": {{"name": "alice", "profile": "10M", "password": "must-not-escape", "secret": "must-not-escape"}},
	}}
	provider := NewRouterOSMonitoringProviderWithTransport(func() provider.RouterOSTransport { return transport })
	users, err := provider.SurveyHotspotAccounts(context.Background(), RouterTarget{Host: "router.test", Port: 8728, Username: "readonly", Transport: "api"})
	if err != nil {
		t.Fatal(err)
	}
	if !reflect.DeepEqual(transport.commands, []string{"/ip/hotspot/user/print"}) {
		t.Fatalf("commands=%v", transport.commands)
	}
	if len(users) != 1 || users[0].Username != "alice" || users[0].Profile != "10M" {
		t.Fatalf("users=%#v", users)
	}
	if transport.mutations != 0 || !transport.closed {
		t.Fatalf("mutations=%d closed=%t", transport.mutations, transport.closed)
	}
}

type monitoringTransportFake struct {
	responses map[string][]map[string]string
	commands  []string
	mutations int
	closed    bool
}

func (f *monitoringTransportFake) Connect(context.Context, network.DiscoveryConnection) error {
	return nil
}
func (f *monitoringTransportFake) Read(_ context.Context, command string) ([]map[string]string, error) {
	if command != "/system/resource/print" && command != "/system/identity/print" && command != "/ppp/active/print" && command != "/ip/hotspot/user/print" {
		f.mutations++
		return nil, errors.New("mutation")
	}
	f.commands = append(f.commands, command)
	return f.responses[command], nil
}
func (f *monitoringTransportFake) Close() error { f.closed = true; return nil }
