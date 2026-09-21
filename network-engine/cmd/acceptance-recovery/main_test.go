package main

import (
	"cosmiclink/network-engine/internal/agent"
	"cosmiclink/network-engine/internal/credentials"
	"strings"
	"testing"
)

func TestManifestPreservesExactScope(t *testing.T) {
	inst := agent.Installation{AgentRef: "agent-a", InstallationID: "install-a"}
	item := credentials.CredentialMetadata{CredentialRef: "observer-a", TenantRef: "182", RouterRef: "122", AgentRef: "agent-a", InstallationID: "install-a", Purpose: credentials.PurposeObserver, Version: 1, Status: credentials.CredentialStatusActive}
	token := strings.Repeat("a", 32) + "." + strings.Repeat("b", 64)
	m, err := recoveryManifest(inst, item, token)
	if err != nil || m["tenant_ref"] != "182" || m["router_ref"] != "122" || m["token"] != token {
		t.Fatal("scope/token not preserved")
	}
	item.AgentRef = "other"
	if _, err := recoveryManifest(inst, item, token); err == nil {
		t.Fatal("foreign agent accepted")
	}
	item.AgentRef = inst.AgentRef
	item.Purpose = credentials.PurposeOperator
	if _, err := recoveryManifest(inst, item, token); err == nil {
		t.Fatal("operator accepted")
	}
}
