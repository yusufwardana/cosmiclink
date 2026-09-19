package config

import (
	"fmt"
	"os"
	"strconv"
)

type Config struct {
	Address                  string
	Token                    string
	DiscoveryProvider        string
	MonitoringProvider       string
	MutationProvider         string
	AllowInsecureRouterOSTLS bool
}

// FromEnvironment loads engine settings. Phase 6I adds NETWORK_MUTATION_PROVIDER
// with a safe default of "fake": an unset value can never select real RouterOS
// writes. The raw string is preserved so the composition root rejects unknown or
// oddly spaced selections instead of silently coercing them.
func FromEnvironment() (Config, error) {
	config := Config{
		Address:                  valueOrDefault("GO_NETWORK_ENGINE_ADDRESS", "127.0.0.1:8787"),
		Token:                    os.Getenv("GO_NETWORK_ENGINE_TOKEN"),
		DiscoveryProvider:        valueOrDefault("NETWORK_DISCOVERY_PROVIDER", "fake"),
		MonitoringProvider:       valueOrDefault("MONITORING_PROVIDER", "fake"),
		MutationProvider:         valueOrDefault("NETWORK_MUTATION_PROVIDER", "fake"),
		AllowInsecureRouterOSTLS: os.Getenv("APP_ENV") == "local" && boolValue("GO_NETWORK_ENGINE_ALLOW_INSECURE_ROUTEROS_TLS"),
	}
	if config.Token == "" {
		return Config{}, fmt.Errorf("GO_NETWORK_ENGINE_TOKEN must be configured")
	}

	return config, nil
}

func boolValue(name string) bool {
	value, _ := strconv.ParseBool(os.Getenv(name))
	return value
}

func valueOrDefault(name, fallback string) string {
	if value := os.Getenv(name); value != "" {
		return value
	}

	return fallback
}
