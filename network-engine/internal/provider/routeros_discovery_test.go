package provider

import (
	"context"
	"errors"
	"reflect"
	"testing"

	"cosmiclink/network-engine/internal/network"
)

func TestRouterOSDiscoveryNormalizesOnlyAllowlistedReadDataAndStripsPasswords(t *testing.T) {
	transport := &fakeRouterOSTransport{responses: map[string][]map[string]string{
		readSystemIdentity: {{"name": "edge-01"}}, readSystemResource: {{"version": "6.49.13 (stable)", "architecture-name": "mipsbe", "board-name": "RB", "uptime": "1d"}},
		readPPPProfile: {{".id": "*1", "name": "20M", "local-address": "10.0.0.1", "remote-address": "pppoe-pool", "rate-limit": "20M/20M"}},
		readPPPSecret:  {{".id": "*2", "name": "alice", "password": "must-not-escape", "profile": "20M", "service": "pppoe", "disabled": "true", "comment": "legacy"}},
		readIPPool:     {{".id": "*3", "name": "pppoe-pool", "ranges": "10.0.0.2-10.0.0.254"}}, readSimpleQueue: {{".id": "*4", "name": "alice", "target": "10.0.0.2/32", "max-limit": "20M/20M"}},
	}}
	result := NewRouterOSDiscoveryProviderWithTransport(func() RouterOSTransport { return transport }).Discover(context.Background(), validRouterOSRequest())
	if !result.Success || result.Provider != "routeros" || result.Snapshot.Device["name"] != "edge-01" || result.Snapshot.Device["routeros_version"] != "6.49.13 (stable)" {
		t.Fatalf("unexpected result: %#v", result)
	}
	account := result.Snapshot.Accounts[0]
	if account["enabled"] != false || account["username"] != "alice" {
		t.Fatalf("account normalization = %#v", account)
	}
	if _, found := account["password"]; found || containsSecret(result.Snapshot) {
		t.Fatalf("password leaked into normalized snapshot: %#v", result.Snapshot)
	}
	want := []string{readSystemResource, readSystemIdentity, readPPPProfile, readPPPSecret, readIPPool, readSimpleQueue}
	if !reflect.DeepEqual(transport.commands, want) || transport.mutations != 0 || !transport.closed {
		t.Fatalf("commands=%#v mutations=%d closed=%t", transport.commands, transport.mutations, transport.closed)
	}
}

func TestRouterOSDiscoveryReportsUnsupportedVersionWarning(t *testing.T) {
	transport := &fakeRouterOSTransport{responses: successfulResponses()}
	transport.responses[readSystemResource][0]["version"] = "7.12"
	result := NewRouterOSDiscoveryProviderWithTransport(func() RouterOSTransport { return transport }).Discover(context.Background(), validRouterOSRequest())
	if result.Snapshot.Device["capability_warning"] == "" {
		t.Fatal("expected capability warning")
	}
}

func TestRouterOSDiscoveryNormalizesConnectionAndReadFailures(t *testing.T) {
	for name, err := range map[string]error{"unreachable": errors.New("could not connect to router os: connection refused"), "auth": errors.New("could not login: authentication failed"), "timeout": context.DeadlineExceeded} {
		t.Run(name, func(t *testing.T) {
			result := NewRouterOSDiscoveryProviderWithTransport(func() RouterOSTransport { return &fakeRouterOSTransport{connectErr: err} }).Discover(context.Background(), validRouterOSRequest())
			if result.Success || result.Code == "DISCOVERY_FAILED" {
				t.Fatalf("result=%#v", result)
			}
		})
	}
	malformed := &fakeRouterOSTransport{responses: successfulResponses(), readErr: map[string]error{readPPPSecret: errors.New("malformed protocol response")}}
	result := NewRouterOSDiscoveryProviderWithTransport(func() RouterOSTransport { return malformed }).Discover(context.Background(), validRouterOSRequest())
	if result.Code != "ROUTER_PROTOCOL_ERROR" {
		t.Fatalf("result=%#v", result)
	}
}

func TestRouterOSCommandGuardRejectsMutationBeforeTransmission(t *testing.T) {
	transport := &fakeRouterOSTransport{}
	_, err := guardedRead(context.Background(), transport, "/ppp/secret/set")
	if !errors.Is(err, ErrForbiddenRouterOSCommand) || len(transport.commands) != 0 {
		t.Fatalf("err=%v commands=%#v", err, transport.commands)
	}
}

func TestRouterOSDiscoveryRejectsInsecureTLSUnlessProcessConfigurationExplicitlyAllowsIt(t *testing.T) {
	request := validRouterOSRequest()
	request.Connection.InsecureTLS = true
	result := NewRouterOSDiscoveryProviderWithTransport(func() RouterOSTransport { return &fakeRouterOSTransport{responses: successfulResponses()} }).Discover(context.Background(), request)
	if result.Success || result.Code != "DISCOVERY_FAILED" {
		t.Fatalf("result=%#v", result)
	}
}

func validRouterOSRequest() network.DiscoveryRequest {
	return network.DiscoveryRequest{TenantRef: "tenant-1", RouterRef: "router-1", Connection: &network.DiscoveryConnection{Host: "router.test", Port: 8728, Username: "readonly", Password: "test-only-password", Transport: "api", ConnectTimeoutSeconds: 1, ReadTimeoutSeconds: 1}}
}
func successfulResponses() map[string][]map[string]string {
	return map[string][]map[string]string{readSystemIdentity: {{"name": "router"}}, readSystemResource: {{"version": "6.49.13"}}, readPPPProfile: {}, readPPPSecret: {}, readIPPool: {}, readSimpleQueue: {}}
}
func containsSecret(snapshot network.DiscoverySnapshot) bool {
	for _, account := range snapshot.Accounts {
		if _, ok := account["password"]; ok {
			return true
		}
	}
	return false
}

type fakeRouterOSTransport struct {
	responses  map[string][]map[string]string
	commands   []string
	connectErr error
	readErr    map[string]error
	closed     bool
	mutations  int
}

func (f *fakeRouterOSTransport) Connect(context.Context, network.DiscoveryConnection) error {
	return f.connectErr
}
func (f *fakeRouterOSTransport) Read(ctx context.Context, command string) ([]map[string]string, error) {
	f.commands = append(f.commands, command)
	if err := f.readErr[command]; err != nil {
		return nil, err
	}
	select {
	case <-ctx.Done():
		return nil, ctx.Err()
	default:
	}
	return f.responses[command], nil
}
func (f *fakeRouterOSTransport) Close() error { f.closed = true; return nil }
