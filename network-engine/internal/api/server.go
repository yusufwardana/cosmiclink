package api

import (
	"context"
	"crypto/subtle"
	"encoding/json"
	"io"
	"log/slog"
	"net/http"
	"strings"
	"sync"
	"time"

	"cosmiclink/network-engine/internal/network"
)

const maxRequestBodyBytes = 64 << 10

type Server struct {
	provider    network.Provider
	discovery   network.DiscoveryProvider
	token       string
	logger      *slog.Logger
	idempotency *idempotencyStore
}

func New(provider network.Provider, discovery network.DiscoveryProvider, token string, logger *slog.Logger) *Server {
	return &Server{provider: provider, discovery: discovery, token: token, logger: logger, idempotency: &idempotencyStore{results: make(map[string]network.Result)}}
}

func (server *Server) Handler() http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("GET /health", server.health)
	mux.HandleFunc("POST /v1/network/routers/test", server.protected("TEST_CONNECTION", server.execute))
	mux.HandleFunc("POST /v1/network/accounts", server.protected("CREATE_PPPOE", server.execute))
	mux.HandleFunc("POST /v1/network/accounts/{reference}/enable", server.protected("ENABLE_PPPOE", server.execute))
	mux.HandleFunc("POST /v1/network/accounts/{reference}/disable", server.protected("DISABLE_PPPOE", server.execute))
	mux.HandleFunc("POST /v1/network/accounts/{reference}/profile", server.protected("CHANGE_PROFILE", server.execute))
	mux.HandleFunc("POST /v1/network/accounts/{reference}/disconnect", server.protected("DISCONNECT_SESSION", server.execute))
	mux.HandleFunc("POST /v1/discovery/routers/{router_ref}", server.discoveryProtected)
	mux.HandleFunc("GET /v1/discovery/safety/mutation-count", server.mutationCount)

	return mux
}

func (server *Server) mutationCount(writer http.ResponseWriter, request *http.Request) {
	if !server.authorized(request.Header.Get("Authorization")) {
		writeJSON(writer, http.StatusUnauthorized, map[string]any{"code": "UNAUTHORIZED", "message": "Unauthorized"})
		return
	}
	counter, supported := server.provider.(network.MutationCounter)
	if !supported {
		writeJSON(writer, http.StatusNotFound, map[string]any{"code": "NOT_AVAILABLE", "message": "Mutation counter unavailable"})
		return
	}
	writeJSON(writer, http.StatusOK, map[string]int{"mutation_count": counter.MutationCount()})
}

func (server *Server) discoveryProtected(writer http.ResponseWriter, request *http.Request) {
	if !server.authorized(request.Header.Get("Authorization")) {
		writeJSON(writer, http.StatusUnauthorized, network.DiscoveryResult{Success: false, Provider: server.discovery.Name(), Code: "UNAUTHORIZED", Message: "Unauthorized"})
		return
	}
	defer request.Body.Close()
	request.Body = http.MaxBytesReader(writer, request.Body, maxRequestBodyBytes)
	var command network.DiscoveryRequest
	decoder := json.NewDecoder(request.Body)
	decoder.DisallowUnknownFields()
	if err := decoder.Decode(&command); err != nil || decoder.Decode(&struct{}{}) != io.EOF || command.TenantRef == "" || command.RouterRef == "" || command.RouterRef != request.PathValue("router_ref") {
		writeJSON(writer, http.StatusBadRequest, network.DiscoveryResult{Success: false, Provider: server.discovery.Name(), RouterRef: command.RouterRef, Code: "INVALID_REQUEST", Message: "Invalid discovery request"})
		return
	}
	result := server.discovery.Discover(request.Context(), command)
	if result.Provider == "" {
		result.Provider = server.discovery.Name()
	}
	if result.RouterRef == "" {
		result.RouterRef = command.RouterRef
	}
	server.logger.Info("network discovery", "tenant_ref", command.TenantRef, "router_ref", command.RouterRef, "provider", result.Provider, "result", result.Code)
	status := http.StatusOK
	if !result.Success {
		if result.Code == "ROUTER_NOT_FOUND" {
			status = http.StatusNotFound
		} else if result.Code == "ROUTER_UNAVAILABLE" {
			status = http.StatusServiceUnavailable
		} else {
			status = http.StatusBadGateway
		}
	}
	writeJSON(writer, status, result)
}

func (server *Server) health(writer http.ResponseWriter, _ *http.Request) {
	writeJSON(writer, http.StatusOK, map[string]string{"status": "ok"})
}

func (server *Server) protected(operation string, next func(http.ResponseWriter, *http.Request, string)) http.HandlerFunc {
	return func(writer http.ResponseWriter, request *http.Request) {
		if !server.authorized(request.Header.Get("Authorization")) {
			writeResult(writer, http.StatusUnauthorized, network.Result{Success: false, Provider: server.provider.Name(), Code: "UNAUTHORIZED", Message: "Unauthorized"})
			return
		}
		next(writer, request, operation)
	}
}

func (server *Server) authorized(header string) bool {
	prefix := "Bearer "
	if !strings.HasPrefix(header, prefix) {
		return false
	}
	candidate := strings.TrimPrefix(header, prefix)
	if len(candidate) != len(server.token) {
		return false
	}

	return subtle.ConstantTimeCompare([]byte(candidate), []byte(server.token)) == 1
}

func (server *Server) execute(writer http.ResponseWriter, request *http.Request, expectedOperation string) {
	started := time.Now()
	defer request.Body.Close()
	request.Body = http.MaxBytesReader(writer, request.Body, maxRequestBodyBytes)
	decoder := json.NewDecoder(request.Body)
	decoder.DisallowUnknownFields()
	var command network.Request
	if err := decoder.Decode(&command); err != nil || decoder.Decode(&struct{}{}) != io.EOF {
		server.invalid(writer, command.OperationID, "INVALID_REQUEST", "Invalid network request")
		return
	}
	if reference := request.PathValue("reference"); reference != "" {
		if command.AccountRef != reference {
			server.invalid(writer, command.OperationID, "INVALID_REQUEST", "Network account reference does not match route")
			return
		}
	}
	if command.Operation != expectedOperation || !valid(command, expectedOperation) {
		server.invalid(writer, command.OperationID, "INVALID_REQUEST", "Invalid network request")
		return
	}
	result, replayed := server.idempotency.execute(command.IdempotencyKey, func() network.Result {
		result := server.invoke(request.Context(), command)
		if result.OperationID == "" {
			result.OperationID = command.OperationID
		}
		if result.Provider == "" {
			result.Provider = server.provider.Name()
		}
		return result
	})
	server.logger.Info("network execution", "operation_id", command.OperationID, "operation", command.Operation, "tenant_ref", command.TenantRef, "router_ref", command.RouterRef, "account_ref", command.AccountRef, "provider", result.Provider, "result", result.Code, "duration_ms", time.Since(started).Milliseconds())
	if replayed {
		writeResult(writer, statusFor(result), result)
		return
	}
	writeResult(writer, statusFor(result), result)
}

func valid(command network.Request, operation string) bool {
	if command.OperationID == "" || command.IdempotencyKey == "" || command.TenantRef == "" || command.RouterRef == "" || command.Parameters == nil {
		return false
	}
	needsAccount := operation != "TEST_CONNECTION"
	if needsAccount && command.AccountRef == "" {
		return false
	}
	if operation == "CREATE_PPPOE" {
		username, usernameOK := command.Parameters["username"].(string)
		profile, profileOK := command.Parameters["profile"].(string)
		return usernameOK && username != "" && username == command.AccountRef && profileOK && profile != ""
	}
	if operation == "CHANGE_PROFILE" {
		profile, profileOK := command.Parameters["profile"].(string)
		return profileOK && profile != ""
	}

	return true
}

func (server *Server) invoke(ctx context.Context, command network.Request) network.Result {
	switch command.Operation {
	case "TEST_CONNECTION":
		return server.provider.TestConnection(ctx, command)
	case "CREATE_PPPOE":
		return server.provider.CreateAccount(ctx, command)
	case "ENABLE_PPPOE":
		return server.provider.EnableAccount(ctx, command)
	case "DISABLE_PPPOE":
		return server.provider.DisableAccount(ctx, command)
	case "CHANGE_PROFILE":
		return server.provider.ChangeProfile(ctx, command)
	default:
		return server.provider.DisconnectSession(ctx, command)
	}
}

func (server *Server) invalid(writer http.ResponseWriter, operationID, code, message string) {
	writeResult(writer, http.StatusBadRequest, network.Result{Success: false, OperationID: operationID, Provider: server.provider.Name(), Code: code, Message: message})
}

func statusFor(result network.Result) int {
	if result.Success {
		return http.StatusOK
	}
	if result.Code == "ACCOUNT_NOT_FOUND" {
		return http.StatusNotFound
	}
	if result.Code == "ROUTER_UNAVAILABLE" {
		return http.StatusServiceUnavailable
	}
	return http.StatusBadGateway
}

func writeResult(writer http.ResponseWriter, status int, result network.Result) {
	writeJSON(writer, status, result)
}

func writeJSON(writer http.ResponseWriter, status int, payload any) {
	writer.Header().Set("Content-Type", "application/json")
	writer.WriteHeader(status)
	_ = json.NewEncoder(writer).Encode(payload)
}

type idempotencyStore struct {
	mu      sync.Mutex
	results map[string]network.Result
}

func (store *idempotencyStore) execute(key string, operation func() network.Result) (network.Result, bool) {
	store.mu.Lock()
	defer store.mu.Unlock()
	if result, found := store.results[key]; found {
		return result, true
	}
	result := operation()
	store.results[key] = result
	return result, false
}
