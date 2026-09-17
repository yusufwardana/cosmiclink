package main

import (
	"context"
	"log/slog"
	"os"
	"os/signal"
	"syscall"
	"time"

	"cosmiclink/network-engine/internal/agent"
	"cosmiclink/network-engine/internal/provider"
)

func main() {
	coreURL, token := os.Getenv("COSMICLINK_CORE_URL"), os.Getenv("COSMICLINK_AGENT_TOKEN")
	if coreURL == "" || token == "" {
		slog.Error("COSMICLINK_CORE_URL and COSMICLINK_AGENT_TOKEN are required")
		os.Exit(1)
	}
	p, err := provider.NewDiscoveryProvider(value("NETWORK_DISCOVERY_PROVIDER", "fake"), false)
	if err != nil {
		slog.Error("agent provider configuration failed", "error", err)
		os.Exit(1)
	}
	a := agent.New(agent.Config{CoreURL: coreURL, Token: token, Name: value("COSMICLINK_AGENT_NAME", "network-agent"), Timeout: 10 * time.Second}, p, slog.Default())
	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()
	interval := time.Duration(5) * time.Second
	for {
		if err := a.RunOnce(ctx); err != nil && ctx.Err() == nil {
			slog.Warn("agent poll failed", "error", err)
		}
		select {
		case <-ctx.Done():
			return
		case <-time.After(interval):
		}
	}
}
func value(name, fallback string) string {
	if v := os.Getenv(name); v != "" {
		return v
	}
	return fallback
}
