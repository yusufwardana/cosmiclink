package main

import (
	"log/slog"
	"net/http"
	"os"
	"time"

	"cosmiclink/network-engine/internal/api"
	"cosmiclink/network-engine/internal/config"
	"cosmiclink/network-engine/internal/monitoring"
	"cosmiclink/network-engine/internal/provider"
)

func main() {
	config, err := config.FromEnvironment()
	if err != nil {
		slog.Error("invalid network engine configuration", "error", err.Error())
		os.Exit(1)
	}
	logger := slog.New(slog.NewJSONHandler(os.Stdout, nil))
	discovery, err := provider.NewDiscoveryProvider(config.DiscoveryProvider, config.AllowInsecureRouterOSTLS)
	if err != nil {
		slog.Error("invalid discovery provider configuration", "error", err.Error())
		os.Exit(1)
	}
	var monitor monitoring.Provider
	switch config.MonitoringProvider {
	case "fake":
		monitor = monitoring.NewFakeMonitoringProvider(monitoring.FakeScenario{Router: monitoring.RouterResource{Identity: "CORE-01", Version: "simulated"}, PPPSessions: []monitoring.PPPSession{{Name: "existing-user-001", Service: "pppoe"}}})
	case "routeros":
		monitor = monitoring.NewRouterOSMonitoringProvider(config.AllowInsecureRouterOSTLS)
	default:
		slog.Error("invalid monitoring provider configuration", "provider", config.MonitoringProvider)
		os.Exit(1)
	}
	server := &http.Server{
		Addr:              config.Address,
		Handler:           api.NewWithMonitoring(provider.NewFakeProvider(), discovery, monitor, config.Token, logger).Handler(),
		ReadHeaderTimeout: 5 * time.Second,
		ReadTimeout:       10 * time.Second,
		WriteTimeout:      15 * time.Second,
		IdleTimeout:       60 * time.Second,
	}
	logger.Info("go network engine listening", "address", config.Address, "provider", "fake", "discovery_provider", discovery.Name(), "monitoring_provider", config.MonitoringProvider)
	if err := server.ListenAndServe(); err != nil && err != http.ErrServerClosed {
		logger.Error("go network engine stopped", "error", err.Error())
		os.Exit(1)
	}
}
