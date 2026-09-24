package main

import (
	"context"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"os"

	"cosmiclink/network-engine/internal/agent"
	"cosmiclink/network-engine/internal/credentials"
)

type options struct {
	dataDir      string
	oldTenant    string
	oldRouter    string
	newTenant    string
	newRouter    string
	agent        string
	installation string
	credential   string
	version      int
}

func main() {
	if err := run(os.Args[1:]); err != nil {
		fmt.Fprintln(os.Stderr, err.Error())
		os.Exit(1)
	}
}

func run(args []string) error {
	if len(args) == 0 {
		return errors.New("operation is required")
	}
	command := args[0]
	options, err := parseOptions(args[1:])
	if err != nil {
		return err
	}
	ctx := context.Background()
	installation, err := agent.OpenInstallation(ctx, options.dataDir)
	if err != nil {
		return errors.New("AGENT_INSTALLATION_INVALID")
	}
	if string(installation.InstallationID) != options.installation || (options.agent != "" && installation.AgentRef != options.agent) {
		return errors.New("AGENT_INSTALLATION_SCOPE_MISMATCH")
	}
	store, err := agent.OpenCredentialStore(ctx, options.dataDir)
	if err != nil {
		return errors.New("CREDENTIAL_STORE_UNAVAILABLE")
	}
	defer store.Close()

	metadata, err := metadataFor(store, options.credential, options.version)
	if err != nil {
		return err
	}
	if metadata.Purpose != credentials.PurposeObserver || metadata.Status != credentials.CredentialStatusActive || string(metadata.InstallationID) != options.installation || metadata.AgentRef != installation.AgentRef {
		return errors.New("OBSERVER_CREDENTIAL_INVALID")
	}
	options.agent = metadata.AgentRef

	switch command {
	case "inspect":
		oldRef := reference(metadata.TenantRef, metadata.RouterRef, options)
		resolved, err := store.Resolve(ctx, oldRef)
		if err != nil || resolved.Username() == "" || len(resolved.SecretBytes()) == 0 {
			return errors.New("OBSERVER_SECRET_VALIDATION_FAILED")
		}
		return writeJSON(map[string]any{
			"credential_ref":  metadata.CredentialRef,
			"tenant_ref":      metadata.TenantRef,
			"router_ref":      metadata.RouterRef,
			"agent_ref":       metadata.AgentRef,
			"installation_id": string(metadata.InstallationID),
			"purpose":         string(metadata.Purpose),
			"version":         metadata.Version,
			"status":          string(metadata.Status),
		})
	case "validate":
		ref := reference(options.newTenant, options.newRouter, options)
		resolver := agent.NewLazyProductionResolver(options.dataDir)
		resolved, err := resolver.Resolve(ctx, ref)
		if err != nil {
			return errors.New("RESOLVER_VALIDATION_FAILED")
		}
		if resolved.Username() == "" || len(resolved.SecretBytes()) == 0 {
			return errors.New("RESOLVER_VALIDATION_FAILED")
		}
		return writeJSON(map[string]any{"resolver_validation": "PASS", "purpose": string(ref.Purpose), "version": ref.Version})
	case "rebind":
		oldRef := reference(options.oldTenant, options.oldRouter, options)
		newRef := reference(options.newTenant, options.newRouter, options)
		if metadata.TenantRef == newRef.TenantRef && metadata.RouterRef == newRef.RouterRef {
			if _, err := store.Resolve(ctx, newRef); err != nil {
				return errors.New("REBIND_METADATA_INVALID")
			}
			return writeJSON(map[string]any{"status": "ALREADY_BOUND"})
		}
		if metadata.TenantRef != oldRef.TenantRef || metadata.RouterRef != oldRef.RouterRef {
			return errors.New("OLD_SCOPE_MISMATCH")
		}
		if err := store.RebindObserverScope(ctx, oldRef, newRef); err != nil {
			return errors.New("VAULT_REBIND_FAILED")
		}
		return writeJSON(map[string]any{"status": "REBIND_COMPLETE"})
	default:
		return errors.New("unknown operation")
	}
}

func parseOptions(args []string) (options, error) {
	set := flag.NewFlagSet("observer-recovery", flag.ContinueOnError)
	set.SetOutput(os.Stderr)
	var value options
	set.StringVar(&value.dataDir, "data-dir", "", "existing Agent data directory")
	set.StringVar(&value.oldTenant, "old-tenant", "", "preserved tenant reference")
	set.StringVar(&value.oldRouter, "old-router", "", "preserved router reference")
	set.StringVar(&value.newTenant, "new-tenant", "", "new Core tenant reference")
	set.StringVar(&value.newRouter, "new-router", "", "new Core router reference")
	set.StringVar(&value.agent, "agent", "", "preserved Agent reference")
	set.StringVar(&value.installation, "installation", "", "preserved installation identity")
	set.StringVar(&value.credential, "credential", "", "preserved credential reference")
	set.IntVar(&value.version, "version", 0, "preserved credential version")
	if err := set.Parse(args); err != nil {
		return options{}, err
	}
	if value.dataDir == "" || value.installation == "" || value.credential == "" || value.version < 1 {
		return options{}, errors.New("incomplete recovery metadata")
	}
	return value, nil
}

func metadataFor(store *credentials.Store, ref string, version int) (credentials.CredentialMetadata, error) {
	items, err := store.ListMetadata(context.Background())
	if err != nil {
		return credentials.CredentialMetadata{}, errors.New("CREDENTIAL_METADATA_UNAVAILABLE")
	}
	found := make([]credentials.CredentialMetadata, 0, 1)
	for _, item := range items {
		if item.CredentialRef == ref && item.Version == version {
			found = append(found, item)
		}
	}
	if len(found) != 1 {
		return credentials.CredentialMetadata{}, errors.New("CREDENTIAL_REFERENCE_AMBIGUOUS")
	}
	return found[0], nil
}

func reference(tenant, router string, options options) credentials.Reference {
	return credentials.Reference{
		TenantRef: tenant, RouterRef: router, AgentRef: options.agent,
		InstallationID: credentials.InstallationIdentity(options.installation),
		CredentialRef:  options.credential, Purpose: credentials.PurposeObserver,
		Version: options.version,
	}
}

func writeJSON(value any) error {
	return json.NewEncoder(os.Stdout).Encode(value)
}
