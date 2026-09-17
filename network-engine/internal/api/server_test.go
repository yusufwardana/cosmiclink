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

func TestInvalidRequestAndUnknownAccountAreStructured(t *testing.T) {
	server := testServer()
	invalid := command("ENABLE_PPPOE", "", nil)
	assertCode(t, execute(server, http.MethodPost, "/v1/network/accounts/missing/enable", invalid, testToken), http.StatusBadRequest, "INVALID_REQUEST")
	assertCode(t, execute(server, http.MethodPost, "/v1/network/accounts/missing/enable", command("ENABLE_PPPOE", "missing", nil), testToken), http.StatusNotFound, "ACCOUNT_NOT_FOUND")
}

func TestProviderFailureAndLogRedaction(t *testing.T) {
	var logs bytes.Buffer
	logger := slog.New(slog.NewJSONHandler(&logs, nil))
	server := New(failingProvider{}, testToken, logger)
	request := command("CREATE_PPPOE", "cust001", map[string]any{"username": "cust001", "profile": "HOME-10M", "password": "pppoe-secret"})
	response := execute(server, http.MethodPost, "/v1/network/accounts", request, testToken)

	assertCode(t, response, http.StatusBadGateway, "PROVIDER_FAILURE")
	if strings.Contains(logs.String(), "pppoe-secret") || strings.Contains(logs.String(), testToken) {
		t.Fatalf("sensitive data present in logs: %s", logs.String())
	}
}

func testServer() *Server {
	return New(provider.NewFakeProvider(), testToken, slog.New(slog.NewTextHandler(ioDiscard{}, nil)))
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
