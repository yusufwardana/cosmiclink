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

	"cosmiclink/network-engine/internal/monitoring"
	"cosmiclink/network-engine/internal/network"
)

const maxRequestBodyBytes = 64 << 10

type Server struct {
	provider    network.Provider
	mutation    network.MutationProvider
	discovery   network.DiscoveryProvider
	token       string
	logger      *slog.Logger
	idempotency *idempotencyStore
	monitoring  monitoring.Provider
}

func New(provider network.Provider, discovery network.DiscoveryProvider, token string, logger *slog.Logger) *Server {
	return NewWithMutationProvider(provider, MutationProviderFrom(provider), discovery, nil, token, logger)
}

// MutationProviderFrom reports whether a registered provider already declares
// the narrow Phase 6I write contract. Providers that do not are used for
// simulated operations only, and write routes then fail closed.
func MutationProviderFrom(provider network.Provider) network.MutationProvider {
	narrow, ok := provider.(network.MutationProvider)
	if !ok {
		return nil
	}
	return narrow
}

func NewWithMonitoring(provider network.Provider, discovery network.DiscoveryProvider, monitor monitoring.Provider, token string, logger *slog.Logger) *Server {
	server := NewWithMutationProvider(provider, MutationProviderFrom(provider), discovery, monitor, token, logger)
	return server
}

// NewWithMutationProvider is the Phase 6I composition entry point. The legacy
// provider serves everything outside the mutation allowlist, while the narrow
// mutation provider owns ENABLE_PPPOE, DISABLE_PPPOE and DISCONNECT_SESSION.
// Either may be nil; a nil mutation provider makes write routes fail closed.
func NewWithMutationProvider(provider network.Provider, mutation network.MutationProvider, discovery network.DiscoveryProvider, monitor monitoring.Provider, token string, logger *slog.Logger) *Server {
	if logger == nil {
		logger = slog.Default()
	}
	return &Server{
		provider:    provider,
		mutation:    mutation,
		discovery:   discovery,
		token:       token,
		logger:      logger,
		idempotency: &idempotencyStore{results: make(map[string]network.Result)},
		monitoring:  monitor,
	}
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
	mux.HandleFunc("POST /api/v1/monitoring/collect", server.monitoringProtected)
	mux.HandleFunc("GET /v1/discovery/safety/mutation-count", server.mutationCount)

	return mux
}

type monitoringRequest struct {
	Router monitoring.RouterTarget `json:"router"`
}

func (server *Server) monitoringProtected(writer http.ResponseWriter, request *http.Request) {
	if !server.authorized(request.Header.Get("Authorization")) {
		writeJSON(writer, http.StatusUnauthorized, map[string]any{"reachable": false, "failure": map[string]string{"code": "UNAUTHORIZED", "message": "Unauthorized"}})
		return
	}
	if server.monitoring == nil {
		writeJSON(writer, http.StatusServiceUnavailable, map[string]any{"reachable": false, "failure": map[string]string{"code": "ENGINE_UNAVAILABLE", "message": "Monitoring provider unavailable"}})
		return
	}
	defer request.Body.Close()
	request.Body = http.MaxBytesReader(writer, request.Body, maxRequestBodyBytes)
	decoder := json.NewDecoder(request.Body)
	decoder.DisallowUnknownFields()
	var command monitoringRequest
	if err := decoder.Decode(&command); err != nil || command.Router.Host == "" {
		writeJSON(writer, http.StatusBadRequest, map[string]any{"reachable": false, "failure": map[string]string{"code": "INVALID_REQUEST", "message": "Invalid monitoring request"}})
		return
	}
	snapshot, err := server.monitoring.Collect(request.Context(), command.Router)
	if err != nil {
		failure := monitoring.Classify(err)
		writeJSON(writer, http.StatusBadGateway, map[string]any{"reachable": false, "failure": map[string]string{"code": string(failure.Code), "message": failure.Message}})
		return
	}
	writeJSON(writer, http.StatusOK, map[string]any{"reachable": true, "collected_at": snapshot.CollectedAt, "identity": snapshot.Router.Identity, "version": snapshot.Router.Version, "architecture": snapshot.Router.Architecture, "board": snapshot.Router.BoardName, "uptime": snapshot.Router.UptimeSeconds, "cpu_load_percent": snapshot.Router.CPULoad, "memory_total_bytes": snapshot.Router.MemoryTotal, "memory_free_bytes": snapshot.Router.MemoryFree, "ppp_active": snapshot.PPPSessions})
}

// mutationCount reports the write counter of the provider that actually owns
// mutations: the registered Phase 6I mutation provider first, then the legacy
// provider. That ordering keeps the safety signal meaningful no matter which
// selection is registered.
func (server *Server) mutationCount(writer http.ResponseWriter, request *http.Request) {
	if !server.authorized(request.Header.Get("Authorization")) {
		writeJSON(writer, http.StatusUnauthorized, map[string]any{"code": "UNAUTHORIZED", "message": "Unauthorized"})
		return
	}
	counter, supported := server.writeCounter()
	if !supported {
		writeJSON(writer, http.StatusNotFound, map[string]any{"code": "NOT_AVAILABLE", "message": "Mutation counter unavailable"})
		return
	}
	writeJSON(writer, http.StatusOK, map[string]int{"mutation_count": counter.MutationCount()})
}

func (server *Server) writeCounter() (network.MutationCounter, bool) {
	if counter, ok := server.mutation.(network.MutationCounter); ok {
		return counter, true
	}
	counter, ok := server.provider.(network.MutationCounter)
	return counter, ok
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
			result.Provider = server.providerName()
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

// invoke routes an already validated command. Only the three allowlisted Phase
// 6I write codes reach the narrow mutation provider, and every other value is
// rejected here instead of falling through to DisconnectSession.
func (server *Server) invoke(ctx context.Context, command network.Request) network.Result {
	switch command.Operation {
	case "TEST_CONNECTION":
		return server.provider.TestConnection(ctx, command)
	case "CREATE_PPPOE":
		return server.provider.CreateAccount(ctx, command)
	case "CHANGE_PROFILE":
		return server.provider.ChangeProfile(ctx, command)
	case string(network.MutationEnablePPPoE):
		return server.mutate(ctx, command, network.MutationEnablePPPoE)
	case string(network.MutationDisablePPPoE):
		return server.mutate(ctx, command, network.MutationDisablePPPoE)
	case string(network.MutationDisconnectSession):
		return server.mutate(ctx, command, network.MutationDisconnectSession)
	default:
		return server.operationNotAllowed(command)
	}
}

// mutate performs one approved RouterOS write through the registered mutation
// provider. A missing provider or an operation outside its declared capability
// fails closed, so simulated breadth can never be mistaken for real support.
func (server *Server) mutate(ctx context.Context, command network.Request, operation network.MutationOperation) network.Result {
	// Re-derive the operation from the command text rather than trusting the route
	// label, so a body that disagrees with its endpoint can never execute under a
	// permitted code.
	parsed, allowed := network.MutationOperationFor(command.Operation)
	if !allowed || parsed != operation {
		return server.operationNotAllowed(command)
	}
	provider := server.mutation
	if provider == nil || !supportsMutationOperation(provider, operation) {
		return server.operationNotAllowed(command)
	}
	switch operation {
	case network.MutationEnablePPPoE:
		return provider.EnableAccount(ctx, command)
	case network.MutationDisablePPPoE:
		return provider.DisableAccount(ctx, command)
	case network.MutationDisconnectSession:
		return provider.DisconnectSession(ctx, command)
	default:
		return server.operationNotAllowed(command)
	}
}

func supportsMutationOperation(provider network.MutationProvider, operation network.MutationOperation) bool {
	if provider == nil {
		return false
	}
	for _, supported := range provider.SupportedOperations() {
		if supported == operation {
			return true
		}
	}

	return false
}

func (server *Server) operationNotAllowed(command network.Request) network.Result {
	return network.Result{
		Success:     false,
		OperationID: command.OperationID,
		Provider:    server.providerName(),
		Code:        "OPERATION_NOT_ALLOWED",
		Message:     "Operation is not available from the registered mutation provider",
	}
}

// providerName is nil-safe so a partially composed server can still report a
// rejection instead of panicking inside error handling.
func (server *Server) providerName() string {
	if server.provider == nil {
		return ""
	}

	return server.provider.Name()
}

func (server *Server) invalid(writer http.ResponseWriter, operationID, code, message string) {
	writeResult(writer, http.StatusBadRequest, network.Result{Success: false, OperationID: operationID, Provider: server.providerName(), Code: code, Message: message})
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
	if result.Code == "OPERATION_NOT_ALLOWED" {
		return http.StatusNotImplemented
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
