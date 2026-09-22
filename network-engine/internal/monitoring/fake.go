package monitoring

import (
	"context"
	"time"
)

// FakeScenario configures a deterministic FakeMonitoringProvider outcome.
type FakeScenario struct {
	Router      RouterResource
	PPPSessions []PPPSession
	Traffic     TrafficSnapshot
	Err         error
	Delay       time.Duration
}

// FakeMonitoringProvider is a deterministic, network-free provider used for CI,
// demos, development and regression testing. It is read-only by construction.
type FakeMonitoringProvider struct {
	Scenario FakeScenario
}

func (f *FakeMonitoringProvider) CollectTraffic(ctx context.Context, _ RouterTarget, options TrafficOptions) (TrafficSnapshot, error) {
	if f.Scenario.Delay > 0 {
		select {
		case <-time.After(f.Scenario.Delay):
		case <-ctx.Done():
			return TrafficSnapshot{}, &Error{Code: FailureMonitoring, Message: "monitoring cancelled"}
		}
	}
	if f.Scenario.Err != nil {
		return TrafficSnapshot{}, f.Scenario.Err
	}
	snapshot := f.Scenario.Traffic
	if snapshot.CollectedAt.IsZero() {
		snapshot.CollectedAt = time.Now().UTC()
	}
	if !options.IncludeEnrichment {
		snapshot.DHCPLeases = nil
		snapshot.ARPEntries = nil
		snapshot.Evidence.EnrichmentCollected = false
	}
	return snapshot, nil
}

// NewFakeMonitoringProvider builds a fake provider for the given scenario.
func NewFakeMonitoringProvider(scenario FakeScenario) *FakeMonitoringProvider {
	return &FakeMonitoringProvider{Scenario: scenario}
}

// Collect returns the configured scenario. It never performs network access.
func (f *FakeMonitoringProvider) Collect(ctx context.Context, _ RouterTarget) (Snapshot, error) {
	if f.Scenario.Delay > 0 {
		select {
		case <-time.After(f.Scenario.Delay):
		case <-ctx.Done():
			return Snapshot{}, &Error{Code: FailureMonitoring, Message: "monitoring cancelled"}
		}
	}
	if f.Scenario.Err != nil {
		return Snapshot{}, f.Scenario.Err
	}
	sessions := make([]PPPSession, len(f.Scenario.PPPSessions))
	copy(sessions, f.Scenario.PPPSessions)
	return Snapshot{
		CollectedAt: time.Now().UTC(),
		Router:      f.Scenario.Router,
		PPPSessions: sessions,
	}, nil
}
