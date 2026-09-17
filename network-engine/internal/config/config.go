package config

import (
	"fmt"
	"os"
)

type Config struct {
	Address string
	Token   string
}

func FromEnvironment() (Config, error) {
	config := Config{
		Address: valueOrDefault("GO_NETWORK_ENGINE_ADDRESS", "127.0.0.1:8787"),
		Token:   os.Getenv("GO_NETWORK_ENGINE_TOKEN"),
	}
	if config.Token == "" {
		return Config{}, fmt.Errorf("GO_NETWORK_ENGINE_TOKEN must be configured")
	}

	return config, nil
}

func valueOrDefault(name, fallback string) string {
	if value := os.Getenv(name); value != "" {
		return value
	}

	return fallback
}
