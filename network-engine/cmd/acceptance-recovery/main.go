// acceptance-recovery is a local, explicit Core disaster-recovery bridge.
// Secrets travel only through an anonymous child stdin pipe, never stdout/files.
package main

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"os/exec"
	"regexp"

	"cosmiclink/network-engine/internal/agent"
	"cosmiclink/network-engine/internal/credentials"
)

var errRecovery = errors.New("ACCEPTANCE_RECOVERY_REFUSED")

func recoveryManifest(inst agent.Installation, item credentials.CredentialMetadata, token string) (map[string]any, error) {
	if item.AgentRef != inst.AgentRef || item.InstallationID != inst.InstallationID || item.Purpose != credentials.PurposeObserver || item.Status != credentials.CredentialStatusActive || item.Version < 1 ||
		!regexp.MustCompile(`^[1-9][0-9]*$`).MatchString(item.TenantRef) || !regexp.MustCompile(`^[1-9][0-9]*$`).MatchString(item.RouterRef) ||
		!regexp.MustCompile(`^[a-f0-9]{32}\.[A-Za-z0-9]{64}$`).MatchString(token) {
		return nil, errRecovery
	}
	return map[string]any{"tenant_ref": item.TenantRef, "router_ref": item.RouterRef, "agent_ref": inst.AgentRef, "installation_id": string(inst.InstallationID), "credential_ref": item.CredentialRef, "version": item.Version, "token": token}, nil
}

func run(args []string) error {
	if len(args) != 5 || args[4] != "RECOVER_PHASE6I_ACCEPTANCE" {
		return errRecovery
	}
	ctx := context.Background()
	inst, err := agent.OpenInstallation(ctx, args[0])
	if err != nil {
		return errRecovery
	}
	store, err := agent.OpenCredentialStore(ctx, args[0])
	if err != nil {
		return errRecovery
	}
	defer store.Close()
	items, err := store.ListMetadata(ctx)
	if err != nil {
		return errRecovery
	}
	var selected *credentials.CredentialMetadata
	for _, item := range items {
		if item.CredentialRef == args[1] && item.Status == credentials.CredentialStatusActive {
			if selected != nil {
				return errRecovery
			}
			copy := item
			selected = &copy
		}
	}
	if selected == nil {
		return errRecovery
	}
	ref := credentials.Reference{TenantRef: selected.TenantRef, RouterRef: selected.RouterRef, AgentRef: selected.AgentRef, InstallationID: selected.InstallationID, CredentialRef: selected.CredentialRef, Purpose: selected.Purpose, Version: selected.Version}
	if _, err = store.Resolve(ctx, ref); err != nil {
		return errRecovery
	}
	token, err := agent.ResolveToken(ctx, args[0], "")
	if err != nil {
		return errRecovery
	}
	manifest, err := recoveryManifest(inst, *selected, token)
	if err != nil {
		return errRecovery
	}
	encoded, err := json.Marshal(manifest)
	if err != nil {
		return errRecovery
	}
	command := exec.CommandContext(ctx, args[2], "artisan", "network-agents:recover-acceptance", "--confirm=RECOVER_PHASE6I_ACCEPTANCE", "--no-interaction")
	command.Dir = args[3]
	command.Stdin = bytes.NewReader(encoded)
	// The PHP command exposes only IDs; suppress child diagnostics on failure.
	output, err := command.Output()
	if err != nil {
		return errRecovery
	}
	var result map[string]any
	if json.Unmarshal(bytes.TrimSpace(output), &result) != nil {
		return errRecovery
	}
	safe := map[string]any{}
	for _, key := range []string{"tenant_id", "router_id", "agent_id", "agent_identifier", "actor_id"} {
		safe[key] = result[key]
	}
	return json.NewEncoder(os.Stdout).Encode(safe)
}

func main() {
	if run(os.Args[1:]) != nil {
		fmt.Fprintln(os.Stderr, "ACCEPTANCE_RECOVERY_REFUSED")
		os.Exit(1)
	}
}
