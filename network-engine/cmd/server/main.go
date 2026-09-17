package main

import (
	"log/slog"
	"net/http"
	"os"
	"time"

	"cosmiclink/network-engine/internal/api"
	"cosmiclink/network-engine/internal/config"
	"cosmiclink/network-engine/internal/provider"
)

func main() {
	config, err := config.FromEnvironment()
	if err != nil {
		slog.Error("invalid network engine configuration", "error", err.Error())
		os.Exit(1)
	}
	logger := slog.New(slog.NewJSONHandler(os.Stdout, nil))
	server := &http.Server{
		Addr:              config.Address,
		Handler:           api.New(provider.NewFakeProvider(), provider.NewFakeDiscoveryProvider(), config.Token, logger).Handler(),
		ReadHeaderTimeout: 5 * time.Second,
		ReadTimeout:       10 * time.Second,
		WriteTimeout:      15 * time.Second,
		IdleTimeout:       60 * time.Second,
	}
	logger.Info("go network engine listening", "address", config.Address, "provider", "fake")
	if err := server.ListenAndServe(); err != nil && err != http.ErrServerClosed {
		logger.Error("go network engine stopped", "error", err.Error())
		os.Exit(1)
	}
}
