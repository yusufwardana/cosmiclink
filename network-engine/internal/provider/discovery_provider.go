package provider

import (
	"fmt"

	"cosmiclink/network-engine/internal/network"
)

func NewDiscoveryProvider(name string, allowInsecureRouterOSTLS bool) (network.DiscoveryProvider, error) {
	switch name {
	case "fake":
		return NewFakeDiscoveryProvider(), nil
	case "routeros":
		return NewRouterOSDiscoveryProviderWithTLS(allowInsecureRouterOSTLS), nil
	default:
		return nil, fmt.Errorf("unsupported discovery provider configuration")
	}
}
