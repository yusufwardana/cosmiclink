package main

import (
	"fmt"
	"log/slog"
	"net/http"
	"os"
	"time"

	"cosmiclink/network-engine/internal/api"
	"cosmiclink/network-engine/internal/config"
	"cosmiclink/network-engine/internal/monitoring"
	"cosmiclink/network-engine/internal/network"
	"cosmiclink/network-engine/internal/provider"
)

func main() {
	loaded, err := config.FromEnvironment()
	if err != nil {
		slog.Error("invalid network engine configuration", "error", err.Error())
		os.Exit(1)
	}
	logger := slog.New(slog.NewJSONHandler(os.Stdout, nil))
	handler, cleanup, err := buildHandler(loaded, logger)
	if err != nil {
		logger.Error("network engine startup refused", "error", err.Error())
		os.Exit(1)
	}
	defer cleanup()
	server := &http.Server{
		Addr:              loaded.Address,
		Handler:           handler,
		ReadHeaderTimeout: 5 * time.Second,
		ReadTimeout:       10 * time.Second,
		WriteTimeout:      15 * time.Second,
		IdleTimeout:       60 * time.Second,
	}
	logger.Info("go network engine listening", "address", loaded.Address, "provider", "fake", "discovery_provider", loaded.DiscoveryProvider, "monitoring_provider", loaded.MonitoringProvider, "mutation_provider", loaded.MutationProvider)
	if err := server.ListenAndServe(); err != nil && err != http.ErrServerClosed {
		logger.Error("go network engine stopped", "error", err.Error())
		os.Exit(1)
	}
}

// buildHandler composes the HTTP handler from configuration. It is separated
// from main so provider selection, including the Phase 6I refusal of real
// RouterOS mutations, is testable. Startup fails hard when a selection cannot be
// satisfied: the engine never starts a degraded provider silently.
func buildHandler(loaded config.Config, logger *slog.Logger) (http.Handler, func(), error) {
	discovery, err := provider.NewDiscoveryProvider(loaded.DiscoveryProvider, loaded.AllowInsecureRouterOSTLS)
	if err != nil {
		return nil, nil, fmt.Errorf("invalid discovery provider configuration: %w", err)
	}
	monitor, err := monitoringProviderFor(loaded.MonitoringProvider, loaded.AllowInsecureRouterOSTLS)
	if err != nil {
		return nil, nil, err
	}
	simulation := provider.GlobalFakeProvider()
	mutation, err := selectMutationProvider(loaded)
	if err != nil {
		return nil, nil, err
	}
	server := api.NewWithMutationProvider(simulation, mutation, discovery, monitor, loaded.Token, logger)
	return server.Handler(), func() {}, nil
}

// selectMutationProvider resolves the Phase 6I write provider. Only the shared
// simulation is registrable today; "routeros" is refused until a real provider
// with an allowlisted transport and audit trail exists.
func selectMutationProvider(loaded config.Config) (network.MutationProvider, error) {
	if loaded.MutationProvider == provider.MutationProviderRouterOS {
		return nil, fmt.Errorf("NETWORK_MUTATION_PROVIDER=%q: %w", loaded.MutationProvider, provider.ErrRealMutationProviderUnavailable)
	}
	return provider.MutationProviderFor(loaded.MutationProvider, provider.GlobalFakeProvider())
}

func monitoringProviderFor(selection string, insecureTLS bool) (monitoring.Provider, error) {
	switch selection {
	case "fake":
		return monitoring.NewFakeMonitoringProvider(monitoring.FakeScenario{
			Router:      monitoring.RouterResource{Identity: "CORE-01", Version: "simulated"},
			PPPSessions: []monitoring.PPPSession{{Name: "existing-user-001", Service: "pppoe"}},
		}), nil
	case "routeros":
		return monitoring.NewRouterOSMonitoringProvider(insecureTLS), nil
	default:
		return nil, fmt.Errorf("invalid monitoring provider configuration: %q", selection)
	}
}
