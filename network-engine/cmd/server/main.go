package main

import (
	"fmt"
	"log/slog"
	"net/http"
	"os"
	"time"

	"cosmiclink/network-engine/internal/agent"
	"cosmiclink/network-engine/internal/api"
	"cosmiclink/network-engine/internal/config"
	"cosmiclink/network-engine/internal/credentials"
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

// buildHandler composes the HTTP handler from configuration. Provider selection
// is explicit and fail-closed; selecting routeros constructs the typed provider
// without dialing hardware.
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
	var resolver *agent.LazyProductionResolver
	if loaded.AgentDataDir != "" {
		resolver = agent.NewLazyProductionResolver(loaded.AgentDataDir)
	}
	mutation, err := selectMutationProvider(loaded, resolver)
	if err != nil {
		return nil, nil, err
	}
	server := api.NewWithCredentialResolver(simulation, discovery, monitor, resolver, loaded.Token, logger)
	server.SetMutationProvider(mutation)
	return server.Handler(), func() {}, nil
}

func selectMutationProvider(loaded config.Config, resolver credentials.Resolver) (network.MutationProvider, error) {
	if loaded.MutationProvider == provider.MutationProviderRouterOS {
		if resolver == nil {
			resolver = agent.NewLazyProductionResolver("")
		}
		return provider.NewMutationProviderWithResolver(loaded.MutationProvider, resolver)
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
