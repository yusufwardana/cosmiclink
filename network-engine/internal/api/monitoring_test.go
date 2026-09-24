package api

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"cosmiclink/network-engine/internal/monitoring"
)

func TestMonitoringCollectRequiresAuthAndReturnsNormalizedFakeSnapshot(t *testing.T) {
	provider := monitoring.NewFakeMonitoringProvider(monitoring.FakeScenario{
		Router:      monitoring.RouterResource{Identity: "edge-01", Version: "6.49.13", Architecture: "smips", BoardName: "hEX"},
		PPPSessions: []monitoring.PPPSession{{Name: "customer-01", Service: "pppoe"}},
	})
	server := NewWithMonitoring(nil, nil, provider, testToken, nil)

	unauthorized := httptest.NewRecorder()
	server.Handler().ServeHTTP(unauthorized, httptest.NewRequest(http.MethodPost, "/api/v1/monitoring/collect", strings.NewReader(`{}`)))
	if unauthorized.Code != http.StatusUnauthorized {
		t.Fatalf("unauthorized status = %d", unauthorized.Code)
	}

	body := `{"router":{"host":"router.test","port":8728,"username":"readonly","password":"secret","transport":"api_ssl","connect_timeout_seconds":3,"read_timeout_seconds":5,"insecure_tls":false}}`
	request := httptest.NewRequest(http.MethodPost, "/api/v1/monitoring/collect", strings.NewReader(body))
	request.Header.Set("Authorization", "Bearer "+testToken)
	response := httptest.NewRecorder()
	server.Handler().ServeHTTP(response, request)
	if response.Code != http.StatusOK {
		t.Fatalf("monitoring status = %d body=%s", response.Code, response.Body.String())
	}
	var payload map[string]any
	if err := json.Unmarshal(response.Body.Bytes(), &payload); err != nil {
		t.Fatal(err)
	}
	if payload["reachable"] != true || payload["identity"] != "edge-01" || payload["architecture"] != "smips" {
		t.Fatalf("unexpected monitoring response: %#v", payload)
	}
	if strings.Contains(response.Body.String(), "secret") {
		t.Fatalf("secret leaked in response: %s", response.Body.String())
	}
}

func TestHotspotAccountsEndpointReturnsSanitizedAccountsWithoutReachabilityOrSecrets(t *testing.T) {
	provider := monitoring.NewFakeMonitoringProvider(monitoring.FakeScenario{HotspotUsers: []monitoring.HotspotUserSurveyEntry{{Username: "alice", Profile: "10M"}}})
	server := NewWithMonitoring(nil, nil, provider, testToken, nil)
	request := httptest.NewRequest(http.MethodPost, "/api/v1/monitoring/hotspot-accounts", strings.NewReader(`{"router":{"host":"router.test","port":8728,"username":"readonly","password":"secret","transport":"api_ssl"}}`))
	request.Header.Set("Authorization", "Bearer "+testToken)
	response := httptest.NewRecorder()
	server.Handler().ServeHTTP(response, request)
	if response.Code != http.StatusOK {
		t.Fatalf("hotspot account status = %d body=%s", response.Code, response.Body.String())
	}
	if strings.Contains(response.Body.String(), "secret") || strings.Contains(response.Body.String(), "password") {
		t.Fatalf("secret leaked in response: %s", response.Body.String())
	}
	if !strings.Contains(response.Body.String(), "alice") {
		t.Fatalf("account missing from response: %s", response.Body.String())
	}
}

type monitoringProviderFunc func(context.Context, monitoring.RouterTarget) (monitoring.Snapshot, error)

func (f monitoringProviderFunc) Collect(ctx context.Context, target monitoring.RouterTarget) (monitoring.Snapshot, error) {
	return f(ctx, target)
}
