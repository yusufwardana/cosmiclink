package api

import (
	"bytes"
	"context"
	"encoding/json"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"cosmiclink/network-engine/internal/network"
	"cosmiclink/network-engine/internal/provider"
)

const testToken = "test-shared-token"

func TestHealthDoesNotRequireAuthentication(t *testing.T) {
	server := testServer()
	request := httptest.NewRequest(http.MethodGet, "/health", nil)
	response := httptest.NewRecorder()

	server.Handler().ServeHTTP(response, request)

	if response.Code != http.StatusOK || !strings.Contains(response.Body.String(), `"status":"ok"`) {
		t.Fatalf("health response = %d %s", response.Code, response.Body.String())
	}
}

func TestProtectedEndpointsRequireBearerAuthentication(t *testing.T) {
	server := testServer()
	response := execute(server, http.MethodPost, "/v1/network/routers/test", command("TEST_CONNECTION", "", nil), "")

	if response.Code != http.StatusUnauthorized || !strings.Contains(response.Body.String(), `"code":"UNAUTHORIZED"`) {
		t.Fatalf("unauthenticated response = %d %s", response.Code, response.Body.String())
	}
}

func TestConnectionReturnsStructuredSuccess(t *testing.T) {
	server := testServer()
	assertCode(t, execute(server, http.MethodPost, "/v1/network/routers/test", command("TEST_CONNECTION", "", nil), testToken), http.StatusOK, "CONNECTION_OK")
}

func TestNetworkExecutionLifecycleAndIdempotency(t *testing.T) {
	server := testServer()
	create := command("CREATE_PPPOE", "cust001", map[string]any{"username": "cust001", "profile": "HOME-10M", "password": "pppoe-secret"})
	assertCode(t, execute(server, http.MethodPost, "/v1/network/accounts", create, testToken), http.StatusOK, "ACCOUNT_CREATED")
	assertCode(t, execute(server, http.MethodPost, "/v1/network/accounts", create, testToken), http.StatusOK, "ACCOUNT_CREATED")
	assertCode(t, execute(server, http.MethodPost, "/v1/network/accounts/cust001/disable", command("DISABLE_PPPOE", "cust001", nil), testToken), http.StatusOK, "ACCOUNT_DISABLED")
	repeatedDisable := command("DISABLE_PPPOE", "cust001", nil)
	repeatedDisable.IdempotencyKey = "second-disable-cust001"
	assertCode(t, execute(server, http.MethodPost, "/v1/network/accounts/cust001/disable", repeatedDisable, testToken), http.StatusOK, "ACCOUNT_ALREADY_DISABLED")
	assertCode(t, execute(server, http.MethodPost, "/v1/network/accounts/cust001/enable", command("ENABLE_PPPOE", "cust001", nil), testToken), http.StatusOK, "ACCOUNT_ENABLED")
	assertCode(t, execute(server, http.MethodPost, "/v1/network/accounts/cust001/profile", command("CHANGE_PROFILE", "cust001", map[string]any{"profile": "HOME-20M"}), testToken), http.StatusOK, "PROFILE_CHANGED")
	assertCode(t, execute(server, http.MethodPost, "/v1/network/accounts/cust001/disconnect", command("DISCONNECT_SESSION", "cust001", nil), testToken), http.StatusOK, "SESSION_DISCONNECTED")
}

func TestSameIdempotencyKeyWithDifferentDigestIsRejected(t *testing.T) {
	server := testServer()
	first := command("DISABLE_PPPOE", "cust001", nil)
	first.RequestDigest = "digest-a"
	second := first
	second.RequestDigest = "digest-b"

	assertCode(t, execute(server, http.MethodPost, "/v1/network/accounts", command("CREATE_PPPOE", "cust001", map[string]any{"username": "cust001", "profile": "HOME-10M"}), testToken), http.StatusOK, "ACCOUNT_CREATED")
	assertCode(t, execute(server, http.MethodPost, "/v1/network/accounts/cust001/disable", first, testToken), http.StatusOK, "ACCOUNT_DISABLED")
	assertCode(t, execute(server, http.MethodPost, "/v1/network/accounts/cust001/disable", second, testToken), http.StatusConflict, "IDEMPOTENCY_CONFLICT")
}

func TestInvalidRequestAndUnknownAccountAreStructured(t *testing.T) {
	server := testServer()
	invalid := command("ENABLE_PPPOE", "", nil)
	assertCode(t, execute(server, http.MethodPost, "/v1/network/accounts/missing/enable", invalid, testToken), http.StatusBadRequest, "INVALID_REQUEST")
	assertCode(t, execute(server, http.MethodPost, "/v1/network/accounts/missing/enable", command("ENABLE_PPPOE", "missing", nil), testToken), http.StatusNotFound, "ACCOUNT_NOT_FOUND")
}

func TestProviderFailureAndLogRedaction(t *testing.T) {
	var logs bytes.Buffer
	logger := slog.New(slog.NewJSONHandler(&logs, nil))
	server := New(failingProvider{}, provider.NewFakeDiscoveryProvider(), testToken, logger)
	request := command("CREATE_PPPOE", "cust001", map[string]any{"username": "cust001", "profile": "HOME-10M", "password": "pppoe-secret"})
	response := execute(server, http.MethodPost, "/v1/network/accounts", request, testToken)

	assertCode(t, response, http.StatusBadGateway, "PROVIDER_FAILURE")
	if strings.Contains(logs.String(), "pppoe-secret") || strings.Contains(logs.String(), testToken) {
		t.Fatalf("sensitive data present in logs: %s", logs.String())
	}
}

func testServer() *Server {
	return New(provider.NewFakeProvider(), provider.NewFakeDiscoveryProvider(), testToken, slog.New(slog.NewTextHandler(ioDiscard{}, nil)))
}

func TestDiscoveryIsAuthenticatedReadOnlyAndSecretFree(t *testing.T) {
	server := testServer()
	body := []byte(`{"tenant_ref":"tenant-1","router_ref":"router-1"}`)
	req := httptest.NewRequest(http.MethodPost, "/v1/discovery/routers/router-1", bytes.NewReader(body))
	req.Header.Set("Authorization", "Bearer "+testToken)
	response := httptest.NewRecorder()
	server.Handler().ServeHTTP(response, req)
	if response.Code != http.StatusOK || !strings.Contains(response.Body.String(), `"name":"CORE-01"`) || strings.Contains(strings.ToLower(response.Body.String()), "password") {
		t.Fatalf("discovery response = %d %s", response.Code, response.Body.String())
	}
}

func TestDiscoveryCredentialsAreAcceptedOnlyInRequestAndNeverLoggedOrReturned(t *testing.T) {
	var logs bytes.Buffer
	server := New(provider.NewFakeProvider(), provider.NewFakeDiscoveryProvider(), testToken, slog.New(slog.NewJSONHandler(&logs, nil)))
	body := []byte(`{"tenant_ref":"tenant-1","router_ref":"router-1","connection":{"host":"router.test","port":8728,"username":"readonly","password":"router-test-password","transport":"api","connect_timeout_seconds":1,"read_timeout_seconds":1}}`)
	req := httptest.NewRequest(http.MethodPost, "/v1/discovery/routers/router-1", bytes.NewReader(body))
	req.Header.Set("Authorization", "Bearer "+testToken)
	response := httptest.NewRecorder()
	server.Handler().ServeHTTP(response, req)
	if response.Code != http.StatusOK || strings.Contains(response.Body.String(), "router-test-password") || strings.Contains(logs.String(), "router-test-password") {
		t.Fatalf("credentials leaked: response=%s logs=%s", response.Body.String(), logs.String())
	}
}

func TestDiscoveryRejectsInvalidRouterAndUnauthorizedRequest(t *testing.T) {
	server := testServer()
	body := []byte(`{"tenant_ref":"tenant-1","router_ref":"invalid"}`)
	req := httptest.NewRequest(http.MethodPost, "/v1/discovery/routers/invalid", bytes.NewReader(body))
	req.Header.Set("Authorization", "Bearer "+testToken)
	response := httptest.NewRecorder()
	server.Handler().ServeHTTP(response, req)
	if response.Code != http.StatusNotFound {
		t.Fatalf("got %d", response.Code)
	}
	unauthorized := httptest.NewRecorder()
	server.Handler().ServeHTTP(unauthorized, httptest.NewRequest(http.MethodPost, "/v1/discovery/routers/router-1", bytes.NewReader(body)))
	if unauthorized.Code != http.StatusUnauthorized {
		t.Fatalf("got %d", unauthorized.Code)
	}
}

func TestDiscoveryDoesNotIncreaseFakeProviderMutationCount(t *testing.T) {
	fake := provider.NewFakeProvider()
	server := New(fake, provider.NewFakeDiscoveryProvider(), testToken, slog.New(slog.NewTextHandler(ioDiscard{}, nil)))
	request := httptest.NewRequest(http.MethodPost, "/v1/discovery/routers/router-1", strings.NewReader(`{"tenant_ref":"tenant-1","router_ref":"router-1"}`))
	request.Header.Set("Authorization", "Bearer "+testToken)
	server.Handler().ServeHTTP(httptest.NewRecorder(), request)
	if fake.MutationCount() != 0 {
		t.Fatalf("mutation count = %d, want 0", fake.MutationCount())
	}
	count := httptest.NewRecorder()
	check := httptest.NewRequest(http.MethodGet, "/v1/discovery/safety/mutation-count", nil)
	check.Header.Set("Authorization", "Bearer "+testToken)
	server.Handler().ServeHTTP(count, check)
	if count.Code != http.StatusOK || !strings.Contains(count.Body.String(), `"mutation_count":0`) {
		t.Fatalf("counter response = %d %s", count.Code, count.Body.String())
	}
}

func TestDiscoveryProviderFailureIsStructured(t *testing.T) {
	server := New(provider.NewFakeProvider(), failingDiscoveryProvider{}, testToken, slog.New(slog.NewTextHandler(ioDiscard{}, nil)))
	request := httptest.NewRequest(http.MethodPost, "/v1/discovery/routers/router-1", strings.NewReader(`{"tenant_ref":"tenant-1","router_ref":"router-1"}`))
	request.Header.Set("Authorization", "Bearer "+testToken)
	response := httptest.NewRecorder()
	server.Handler().ServeHTTP(response, request)
	if response.Code != http.StatusBadGateway || !strings.Contains(response.Body.String(), `"code":"PROVIDER_FAILURE"`) {
		t.Fatalf("response = %d %s", response.Code, response.Body.String())
	}
}

func command(operation, account string, parameters map[string]any) network.Request {
	if parameters == nil {
		parameters = map[string]any{}
	}
	return network.Request{OperationID: "operation-1", IdempotencyKey: operation + "-" + account, Operation: operation, TenantRef: "tenant-1", RouterRef: "router-1", AccountRef: account, Parameters: parameters}
}

func execute(server *Server, method, path string, command network.Request, token string) *httptest.ResponseRecorder {
	body, _ := json.Marshal(command)
	request := httptest.NewRequest(method, path, bytes.NewReader(body))
	if token != "" {
		request.Header.Set("Authorization", "Bearer "+token)
	}
	response := httptest.NewRecorder()
	server.Handler().ServeHTTP(response, request)
	return response
}

func assertCode(t *testing.T, response *httptest.ResponseRecorder, status int, code string) {
	t.Helper()
	if response.Code != status || !strings.Contains(response.Body.String(), `"code":"`+code+`"`) {
		t.Fatalf("response = %d %s, want %d %s", response.Code, response.Body.String(), status, code)
	}
}

type failingProvider struct{}

type failingDiscoveryProvider struct{}

func (failingDiscoveryProvider) Name() string { return "fake" }
func (failingDiscoveryProvider) Discover(context.Context, network.DiscoveryRequest) network.DiscoveryResult {
	return network.DiscoveryResult{Success: false, Provider: "fake", Code: "PROVIDER_FAILURE", Message: "Provider discovery failed"}
}

func (failingProvider) Name() string { return "fake" }
func (failingProvider) TestConnection(context.Context, network.Request) network.Result {
	return failure()
}
func (failingProvider) CreateAccount(context.Context, network.Request) network.Result {
	return failure()
}
func (failingProvider) EnableAccount(context.Context, network.Request) network.Result {
	return failure()
}
func (failingProvider) DisableAccount(context.Context, network.Request) network.Result {
	return failure()
}
func (failingProvider) ChangeProfile(context.Context, network.Request) network.Result {
	return failure()
}
func (failingProvider) DisconnectSession(context.Context, network.Request) network.Result {
	return failure()
}
func failure() network.Result {
	return network.Result{Success: false, Provider: "fake", Code: "PROVIDER_FAILURE", Message: "Provider execution failed"}
}

type ioDiscard struct{}

func (ioDiscard) Write(bytes []byte) (int, error) { return len(bytes), nil }
