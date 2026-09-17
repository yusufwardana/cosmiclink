package agent

import (
	"bytes"
	"context"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

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
			_, _ = w.Write([]byte(`{"job":{"id":1,"type":"DISCOVER_ROUTER","router_ref":"router-1"}}`))
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
