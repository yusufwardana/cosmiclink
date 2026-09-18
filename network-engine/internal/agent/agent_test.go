package agent

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync/atomic"
	"testing"
	"time"

	"cosmiclink/network-engine/internal/network"
	"cosmiclink/network-engine/internal/provider"
)

func TestAgentPerformsOutboundFakeDiscoveryWithoutLoggingSecrets(t *testing.T) {
	var requests []string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		requests = append(requests, r.URL.Path)
		if r.Header.Get("Authorization") != "Bearer agent-token" {
			t.Fatal("missing bearer token")
		}
		switch r.URL.Path {
		case "/api/v1/agent/heartbeat":
			w.WriteHeader(http.StatusOK)
			_, _ = w.Write([]byte(`{"status":"ok"}`))
		case "/api/v1/agent/jobs/claim":
			_, _ = w.Write([]byte(`{"job":{"id":1,"type":"DISCOVER_ROUTER","router_ref":"router-1","attempt":1,"fence":"test-fence","renewal_seconds":30}}`))
		case "/api/v1/agent/jobs/1/result":
			_, _ = w.Write([]byte(`{"status":"ok"}`))
		default:
			t.Fatalf("unexpected path %s", r.URL.Path)
		}
	}))
	defer server.Close()
	var logs bytes.Buffer
	a := New(Config{CoreURL: server.URL, Token: "agent-token", Name: "LAN", Timeout: time.Second}, provider.NewFakeDiscoveryProvider(), slog.New(slog.NewJSONHandler(&logs, nil)))
	if err := a.RunOnce(context.Background()); err != nil {
		t.Fatal(err)
	}
	if strings.Contains(logs.String(), "agent-token") || strings.Contains(logs.String(), "password") {
		t.Fatalf("secret leaked: %s", logs.String())
	}
	if len(requests) != 3 {
		t.Fatalf("requests = %v", requests)
	}
}

type delayedDiscovery struct{ delay time.Duration }

func (p delayedDiscovery) Name() string { return "fake" }
func (p delayedDiscovery) Discover(ctx context.Context, r network.DiscoveryRequest) network.DiscoveryResult {
	select {
	case <-ctx.Done():
		return network.DiscoveryResult{}
	case <-time.After(p.delay):
		return provider.NewFakeDiscoveryProvider().Discover(ctx, r)
	}
}

func TestLeaseRenewalMetadataAndFencing(t *testing.T) {
	var renewed, submitted atomic.Int32
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		var data map[string]any
		_ = json.NewDecoder(r.Body).Decode(&data)
		switch r.URL.Path {
		case "/api/v1/agent/heartbeat":
			if data["version"] != "0.6.0" || data["go_runtime"] == nil || data["uptime_seconds"] == nil {
				t.Error("missing bounded metadata")
			}
		case "/api/v1/agent/jobs/claim":
			_, _ = w.Write([]byte(`{"job":{"id":1,"type":"DISCOVER_ROUTER","router_ref":"test","attempt":2,"fence":"current","renewal_seconds":1}}`))
		case "/api/v1/agent/jobs/1/renew", "/api/v1/agent/jobs/1/result":
			if data["attempt"] != float64(2) || data["fence"] != "current" {
				t.Error("missing fence")
			}
			if strings.HasSuffix(r.URL.Path, "renew") {
				renewed.Add(1)
			} else {
				submitted.Add(1)
			}
		default:
			t.Error("unexpected endpoint")
		}
	}))
	defer server.Close()
	a := New(Config{CoreURL: server.URL}, delayedDiscovery{1200 * time.Millisecond}, nil)
	if err := a.RunOnce(context.Background()); err != nil {
		t.Fatal(err)
	}
	if renewed.Load() != 1 || submitted.Load() != 1 {
		t.Fatal("lease/result flow incomplete")
	}
}

func TestLostLeaseCancelsWithoutResult(t *testing.T) {
	var submitted atomic.Int32
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch {
		case strings.HasSuffix(r.URL.Path, "claim"):
			_, _ = w.Write([]byte(`{"job":{"id":1,"type":"DISCOVER_ROUTER","attempt":1,"fence":"old","renewal_seconds":1}}`))
		case strings.HasSuffix(r.URL.Path, "renew"):
			w.WriteHeader(http.StatusConflict)
		case strings.HasSuffix(r.URL.Path, "result"):
			submitted.Add(1)
		}
	}))
	defer server.Close()
	a := New(Config{CoreURL: server.URL}, delayedDiscovery{3 * time.Second}, nil)
	if err := a.RunOnce(context.Background()); err == nil {
		t.Fatal("lost lease accepted")
	}
	if submitted.Load() != 0 {
		t.Fatal("stale result submitted")
	}
}

func TestAuthenticationStopsLoopAndTimeoutCancels(t *testing.T) {
	var calls atomic.Int32
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { calls.Add(1); w.WriteHeader(401) }))
	defer server.Close()
	a := New(Config{CoreURL: server.URL}, provider.NewFakeDiscoveryProvider(), nil)
	if !errors.Is(a.Run(context.Background()), ErrAuthentication) || calls.Load() != 1 {
		t.Fatal("authentication failure retried")
	}
	slow := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		select {
		case <-r.Context().Done():
		case <-time.After(100 * time.Millisecond):
		}
	}))
	defer slow.Close()
	a = New(Config{CoreURL: slow.URL, Timeout: 20 * time.Millisecond}, provider.NewFakeDiscoveryProvider(), nil)
	if a.RunOnce(context.Background()) == nil {
		t.Fatal("timeout ignored")
	}
	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	if !errors.Is(a.Run(ctx), context.Canceled) {
		t.Fatal("shutdown ignored")
	}
}

func TestBackoffIsBounded(t *testing.T) {
	for failures := 1; failures < 100; failures++ {
		d := backoff(failures, time.Second, 30*time.Second)
		if d < 500*time.Millisecond || d > 30*time.Second {
			t.Fatal("backoff outside bounds")
		}
	}
}

func TestTransientFailuresResetAfterSuccessfulCommunication(t *testing.T) {
	a := New(Config{PollInterval: time.Millisecond, MaxBackoff: 20 * time.Millisecond}, provider.NewFakeDiscoveryProvider(), nil)
	failures, _ := a.retryDelay(8, errors.New("unavailable"))
	if failures != 9 {
		t.Fatal("failure not tracked")
	}
	failures, delay := a.retryDelay(failures, nil)
	if failures != 0 || delay != time.Millisecond {
		t.Fatal("successful communication did not reset backoff")
	}
	var calls atomic.Int32
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		n := calls.Add(1)
		if n <= 2 {
			w.WriteHeader(503)
		} else if n == 3 {
			w.WriteHeader(200)
		} else if n == 4 {
			w.WriteHeader(204)
		} else {
			w.WriteHeader(401)
		}
	}))
	defer server.Close()
	a.config.CoreURL = server.URL
	ctx, cancel := context.WithTimeout(context.Background(), time.Second)
	defer cancel()
	if !errors.Is(a.Run(ctx), ErrAuthentication) || calls.Load() != 5 {
		t.Fatal("outage loop failed to recover then stop on authentication")
	}
}

func TestAgentRejectsUnsupportedJobAndHonorsCancellation(t *testing.T) {
	a := New(Config{CoreURL: "http://127.0.0.1:1", Token: "token", Timeout: time.Millisecond}, provider.NewFakeDiscoveryProvider(), slog.Default())
	if err := a.Execute(context.Background(), Job{ID: 1, Type: "EXECUTE"}); err == nil {
		t.Fatal("unsupported job accepted")
	}
	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	if err := a.RunOnce(ctx); err == nil {
		t.Fatal("cancelled context accepted")
	}
}
