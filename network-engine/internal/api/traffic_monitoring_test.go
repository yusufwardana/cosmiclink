package api

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"cosmiclink/network-engine/internal/monitoring"
)

func TestTrafficMonitoringRequiresAuthAndTrafficProvider(t *testing.T) {
	server := NewWithMonitoring(nil, nil, monitoringProviderFunc(func(context.Context, monitoring.RouterTarget) (monitoring.Snapshot, error) {
		return monitoring.Snapshot{}, nil
	}), testToken, nil)

	unauthorized := httptest.NewRecorder()
	server.Handler().ServeHTTP(unauthorized, httptest.NewRequest(http.MethodPost, "/api/v1/monitoring/traffic/collect", strings.NewReader(`{}`)))
	if unauthorized.Code != http.StatusUnauthorized {
		t.Fatalf("unauthorized=%d", unauthorized.Code)
	}

	request := authorizedTrafficRequest(`{"router":{"host":"router.test","port":8728,"username":"readonly","transport":"api"}}`)
	response := httptest.NewRecorder()
	server.Handler().ServeHTTP(response, request)
	if response.Code != http.StatusServiceUnavailable || !strings.Contains(response.Body.String(), "TRAFFIC_PROVIDER_UNAVAILABLE") {
		t.Fatalf("status=%d body=%s", response.Code, response.Body.String())
	}
}

func TestTrafficMonitoringStrictlyDecodesAndReturnsNormalizedSnapshot(t *testing.T) {
	called := false
	provider := trafficProviderStub{collect: func(_ context.Context, target monitoring.RouterTarget, options monitoring.TrafficOptions) (monitoring.TrafficSnapshot, error) {
		called = true
		if target.Password != "request-secret" || !options.IncludeEnrichment {
			t.Fatalf("target/options=%#v %#v", target, options)
		}
		upload, download := uint64(5), uint64(9)
		return monitoring.TrafficSnapshot{CollectedAt: time.Date(2026, 9, 22, 12, 0, 0, 0, time.UTC), Evidence: monitoring.TrafficEvidence{Datasets: []string{"interfaces"}}, Interfaces: []monitoring.InterfaceTraffic{{SourceKey: "*1", UploadBytes: &upload, DownloadBytes: &download}}}, nil
	}}
	server := NewWithMonitoring(nil, nil, provider, testToken, nil)

	bad := httptest.NewRecorder()
	server.Handler().ServeHTTP(bad, authorizedTrafficRequest(`{"router":{"host":"router.test"},"unknown":true}`))
	if bad.Code != http.StatusBadRequest || called {
		t.Fatalf("bad status=%d called=%t", bad.Code, called)
	}

	response := httptest.NewRecorder()
	server.Handler().ServeHTTP(response, authorizedTrafficRequest(`{"router":{"host":"router.test","port":8728,"username":"readonly","password":"request-secret","transport":"api"},"include_enrichment":true}`))
	if response.Code != http.StatusOK {
		t.Fatalf("status=%d body=%s", response.Code, response.Body.String())
	}
	var payload map[string]any
	if err := json.Unmarshal(response.Body.Bytes(), &payload); err != nil {
		t.Fatal(err)
	}
	if payload["reachable"] != true || !called || strings.Contains(response.Body.String(), "request-secret") {
		t.Fatalf("payload=%#v called=%t", payload, called)
	}
	if _, ok := payload["snapshot"].(map[string]any); !ok {
		t.Fatalf("snapshot missing: %#v", payload)
	}
}

type trafficProviderStub struct {
	collect func(context.Context, monitoring.RouterTarget, monitoring.TrafficOptions) (monitoring.TrafficSnapshot, error)
}

func (trafficProviderStub) Collect(context.Context, monitoring.RouterTarget) (monitoring.Snapshot, error) {
	return monitoring.Snapshot{}, nil
}

func (stub trafficProviderStub) CollectTraffic(ctx context.Context, target monitoring.RouterTarget, options monitoring.TrafficOptions) (monitoring.TrafficSnapshot, error) {
	return stub.collect(ctx, target, options)
}

func authorizedTrafficRequest(body string) *http.Request {
	request := httptest.NewRequest(http.MethodPost, "/api/v1/monitoring/traffic/collect", strings.NewReader(body))
	request.Header.Set("Authorization", "Bearer "+testToken)
	return request
}
