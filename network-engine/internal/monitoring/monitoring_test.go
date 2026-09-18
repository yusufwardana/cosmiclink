package monitoring

import (
	"context"
	"testing"
	"time"
)

func TestFakeMonitoringProviderReturnsStableSecretFreeSnapshot(t *testing.T) {
	provider := NewFakeMonitoringProvider(FakeScenario{
		Router:      RouterResource{Identity: "edge-01", Version: "6.49.13", CPULoad: 14},
		PPPSessions: []PPPSession{{Name: "customer-01", Service: "pppoe", Address: "10.0.0.2"}},
	})

	first, err := provider.Collect(context.Background(), RouterTarget{Password: "must-not-escape"})
	if err != nil {
		t.Fatal(err)
	}
	second, err := provider.Collect(context.Background(), RouterTarget{})
	if err != nil {
		t.Fatal(err)
	}
	if first.Router.Identity != "edge-01" || len(first.PPPSessions) != 1 || first.PPPSessions[0].Name != "customer-01" {
		t.Fatalf("unexpected snapshot: %#v", first)
	}
	if first.Router != second.Router || first.PPPSessions[0] != second.PPPSessions[0] {
		t.Fatalf("fake provider was not stable: %#v %#v", first, second)
	}
	if first.CollectedAt.Before(time.Now().Add(-time.Minute)) {
		t.Fatalf("fake observation is stale: %s", first.CollectedAt)
	}
}
