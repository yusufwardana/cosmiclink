package main

import (
	"context"
	"log/slog"
	"net/url"
	"os"
	"os/signal"
	"strconv"
	"syscall"
	"time"

	"cosmiclink/network-engine/internal/agent"
	"cosmiclink/network-engine/internal/provider"
)

func main() {
	coreURL, token := os.Getenv("COSMICLINK_CORE_URL"), os.Getenv("COSMICLINK_AGENT_TOKEN")
	dataDir := os.Getenv("COSMICLINK_AGENT_DATA_DIR")
	if coreURL == "" || token == "" || dataDir == "" {
		slog.Error("COSMICLINK_CORE_URL, COSMICLINK_AGENT_TOKEN, and COSMICLINK_AGENT_DATA_DIR are required")
		os.Exit(1)
	}
	u, parseErr := url.Parse(coreURL)
	developmentHTTP := os.Getenv("COSMICLINK_AGENT_ALLOW_DEV_HTTP") == "1" && (os.Getenv("APP_ENV") == "local" || os.Getenv("APP_ENV") == "testing")
	if parseErr != nil || u.Host == "" || u.User != nil || u.RawQuery != "" || u.Fragment != "" || (u.Scheme != "https" && !(u.Scheme == "http" && developmentHTTP)) {
		slog.Error("HTTPS Core URL required; HTTP is explicit development-only")
		os.Exit(1)
	}
	p, err := provider.NewDiscoveryProvider(value("NETWORK_DISCOVERY_PROVIDER", "fake"), false)
	if err != nil {
		slog.Error("agent provider configuration failed", "error", err)
		os.Exit(1)
	}
	a := agent.New(agent.Config{CoreURL: coreURL, Token: token, Name: value("COSMICLINK_AGENT_NAME", "network-agent"), DataDir: dataDir, Timeout: 10 * time.Second, PollInterval: seconds("COSMICLINK_AGENT_POLL_INTERVAL", 5), HeartbeatInterval: seconds("NETWORK_AGENT_HEARTBEAT_SECONDS", 30), MaxBackoff: seconds("COSMICLINK_AGENT_MAX_BACKOFF_SECONDS", 60)}, p, slog.Default())
	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()
	if err := a.Run(ctx); err != nil && ctx.Err() == nil {
		slog.Error("agent stopped", "code", "AGENT_AUTH_FAILED")
		os.Exit(1)
	}
}
func value(name, fallback string) string {
	if v := os.Getenv(name); v != "" {
		return v
	}
	return fallback
}

func seconds(name string, fallback int) time.Duration {
	text := os.Getenv(name)
	if text == "" {
		return time.Duration(fallback) * time.Second
	}
	n, err := strconv.Atoi(text)
	if err != nil || n < 1 || n > 3600 {
		slog.Error("invalid Agent interval", "setting", name)
		os.Exit(1)
	}
	return time.Duration(n) * time.Second
}
