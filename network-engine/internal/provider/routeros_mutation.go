package provider

import (
	"context"
	"errors"
	"fmt"
	"net"
	"strings"
	"time"

	"cosmiclink/network-engine/internal/credentials"
	"cosmiclink/network-engine/internal/network"
	"github.com/go-routeros/routeros/v3"
)

type RouterOSMutationTransportFactory func() RouterOSMutationTransport

type RouterOSMutationProvider struct {
	operatorResolver credentials.Resolver
	observerResolver credentials.Resolver
	newTransport     RouterOSMutationTransportFactory
	newReadTransport RouterOSTransportFactory
}

func NewRouterOSMutationProvider(operatorResolver credentials.Resolver, observerResolver credentials.Resolver, factory RouterOSMutationTransportFactory, readFactory RouterOSTransportFactory) (*RouterOSMutationProvider, error) {
	if operatorResolver == nil || observerResolver == nil || factory == nil || readFactory == nil {
		return nil, errors.New("RouterOS mutation provider requires an OPERATOR resolver and transport factory")
	}
	return &RouterOSMutationProvider{operatorResolver: operatorResolver, observerResolver: observerResolver, newTransport: factory, newReadTransport: readFactory}, nil
}

func (p *RouterOSMutationProvider) Name() string { return "routeros" }
func (p *RouterOSMutationProvider) SupportedOperations() []network.MutationOperation {
	return network.MutationOperations()
}

func (p *RouterOSMutationProvider) EnableAccount(ctx context.Context, request network.Request) network.Result {
	return p.execute(ctx, request, network.MutationEnablePPPoE, "ACCOUNT_ENABLED")
}
func (p *RouterOSMutationProvider) DisableAccount(ctx context.Context, request network.Request) network.Result {
	return p.execute(ctx, request, network.MutationDisablePPPoE, "ACCOUNT_DISABLED")
}
func (p *RouterOSMutationProvider) DisconnectSession(ctx context.Context, request network.Request) network.Result {
	return p.execute(ctx, request, network.MutationDisconnectSession, "SESSION_DISCONNECTED")
}

func (p *RouterOSMutationProvider) execute(ctx context.Context, request network.Request, operation network.MutationOperation, successCode string) network.Result {
	if request.ProtocolVersion != "routeros-mutation.v1" || request.ExecutionID == "" || request.IdempotencyKey == "" || request.RequestDigest == "" {
		return mutationFailure(request, "MUTATION_CONTRACT_INVALID", "Mutation execution contract is invalid")
	}
	if request.CredentialPurpose != string(credentials.PurposeOperator) || request.CredentialVersion < 1 || request.CredentialRef == "" || request.AgentRef == "" || request.InstallationID == "" {
		return mutationFailure(request, "CREDENTIAL_REFERENCE_INVALID", "Operator credential reference is invalid")
	}
	if request.TargetIdentityRef == "" || request.Host == "" || request.Port < 1 || request.TenantRef == "" || request.RouterRef == "" {
		return mutationFailure(request, "MUTATION_REFERENCE_INVALID", "Mutation execution reference is invalid")
	}
	if request.ObserverCredentialRef == "" || request.ObserverPurpose != string(credentials.PurposeObserver) || request.ObserverCredentialVersion < 1 {
		return mutationFailure(request, "OBSERVER_CREDENTIAL_REFERENCE_INVALID", "Observer credential reference is invalid")
	}
	observerRef := credentials.Reference{TenantRef: request.TenantRef, RouterRef: request.RouterRef, AgentRef: request.AgentRef, InstallationID: credentials.InstallationIdentity(request.InstallationID), CredentialRef: request.ObserverCredentialRef, Purpose: credentials.PurposeObserver, Version: request.ObserverCredentialVersion}
	observer, err := p.observerResolver.Resolve(ctx, observerRef)
	if err != nil {
		return mutationFailure(request, "OBSERVER_CREDENTIAL_RESOLUTION_FAILED", "Observer credential resolution failed")
	}
	preflight, satisfied, err := p.preflight(ctx, request, observer, operation)
	if err != nil {
		return mutationFailure(request, "PREFLIGHT_FAILED", "RouterOS read-only preflight failed")
	}
	if satisfied {
		return resultWithEvidence(request, true, "IDEMPOTENT_ALREADY_SATISFIED", "RouterOS state already satisfied", preflight, false, nil)
	}
	operatorRef := credentials.Reference{TenantRef: request.TenantRef, RouterRef: request.RouterRef, AgentRef: request.AgentRef, InstallationID: credentials.InstallationIdentity(request.InstallationID), CredentialRef: request.CredentialRef, Purpose: credentials.PurposeOperator, Version: request.CredentialVersion}
	resolved, err := p.operatorResolver.Resolve(ctx, operatorRef)
	if err != nil {
		return mutationFailure(request, "CREDENTIAL_RESOLUTION_FAILED", "Operator credential resolution failed")
	}
	transport := p.newTransport()
	if transport == nil {
		return mutationFailure(request, "MUTATION_TRANSPORT_UNAVAILABLE", "RouterOS mutation transport unavailable")
	}
	connection := network.DiscoveryConnection{Host: request.Host, Port: request.Port, Username: resolved.Username(), Password: string(resolved.SecretBytes()), Transport: request.Transport, ConnectTimeoutSeconds: request.ConnectTimeoutSeconds, ReadTimeoutSeconds: request.ReadTimeoutSeconds, InsecureTLS: request.InsecureTLS}
	if err := transport.Connect(ctx, connection); err != nil {
		return mutationFailure(request, "ROUTER_UNAVAILABLE", "RouterOS mutation connection failed")
	}
	defer transport.Close()
	prepared, err := PrepareRouterOSWrite(operation, request.TargetIdentityRef)
	if err != nil {
		return mutationFailure(request, "MUTATION_NOT_ALLOWED", "RouterOS mutation is not allowlisted")
	}
	if err := transport.Write(ctx, prepared); err != nil {
		return resultWithEvidence(request, false, "UNKNOWN_OUTCOME", "RouterOS mutation outcome is unknown", preflight, true, nil)
	}
	postflight, satisfied, err := p.preflight(ctx, request, observer, operation)
	if err != nil {
		return resultWithEvidence(request, false, "UNKNOWN_OUTCOME", "RouterOS mutation postflight is unavailable", preflight, true, nil)
	}
	if !satisfied {
		return resultWithEvidence(request, false, "POSTFLIGHT_MISMATCH", "RouterOS mutation postflight did not match", preflight, true, postflight)
	}
	return resultWithEvidence(request, true, successCode, "RouterOS mutation verified", preflight, true, postflight)
}

func resultWithEvidence(request network.Request, success bool, code, message string, preflight any, attempted bool, postflight any) network.Result {
	return network.Result{Success: success, OperationID: request.OperationID, Provider: "routeros", Code: code, Message: message, Data: map[string]any{"execution_id": request.ExecutionID, "operation": request.Operation, "target_reference": request.TargetIdentityRef, "mutation_attempted": attempted, "preflight": preflight, "postflight": postflight}}
}

func (p *RouterOSMutationProvider) preflight(ctx context.Context, request network.Request, observer credentials.ResolvedCredential, operation network.MutationOperation) (map[string]any, bool, error) {
	transport := p.newReadTransport()
	if transport == nil {
		return nil, false, errors.New("read transport unavailable")
	}
	connection := network.DiscoveryConnection{Host: request.Host, Port: request.Port, Username: observer.Username(), Password: string(observer.SecretBytes()), Transport: request.Transport, ConnectTimeoutSeconds: request.ConnectTimeoutSeconds, ReadTimeoutSeconds: request.ReadTimeoutSeconds, InsecureTLS: request.InsecureTLS}
	if err := transport.Connect(ctx, connection); err != nil {
		return nil, false, err
	}
	defer transport.Close()
	if operation == network.MutationDisconnectSession {
		rows, err := guardedRead(ctx, transport, readPPPActive)
		if err != nil {
			return nil, false, err
		}
		for _, row := range rows {
			if externalRef(row) == request.TargetIdentityRef || row["name"] == request.AccountRef {
				return map[string]any{"present": true}, false, nil
			}
		}
		return map[string]any{"present": false}, true, nil
	}
	rows, err := guardedRead(ctx, transport, "/ppp/secret/print")
	if err != nil {
		return nil, false, err
	}
	for _, row := range rows {
		if externalRef(row) != request.TargetIdentityRef && row["name"] != request.AccountRef {
			continue
		}
		disabled := routerOSBool(row["disabled"])
		wantDisabled := operation == network.MutationDisablePPPoE
		return map[string]any{"identity": externalRef(row), "disabled": disabled}, disabled == wantDisabled, nil
	}
	return nil, false, errors.New("target identity not found")
}

func mutationFailure(request network.Request, code, message string) network.Result {
	return network.Result{Success: false, OperationID: request.OperationID, Provider: "routeros", Code: code, Message: message}
}

// realRouterOSMutationTransport is intentionally separate from realRouterOSTransport.
// It exposes only Write(prepared), never arbitrary Run or Read methods.
type realRouterOSMutationTransport struct{ client *routeros.Client }

func NewRealRouterOSMutationTransport() RouterOSMutationTransport {
	return &realRouterOSMutationTransport{}
}

func (t *realRouterOSMutationTransport) Connect(ctx context.Context, c network.DiscoveryConnection) error {
	address := net.JoinHostPort(c.Host, fmt.Sprintf("%d", c.Port))
	connectCtx, cancel := context.WithTimeout(ctx, time.Duration(c.ConnectTimeoutSeconds)*time.Second)
	defer cancel()
	var err error
	if strings.EqualFold(c.Transport, "api_ssl") {
		// RouterOS v6 API-SSL uses the dependency's TLS dialer; certificate policy is
		// controlled by the caller's explicit transport configuration.
		t.client, err = routeros.DialTLSContext(connectCtx, address, c.Username, c.Password, nil)
	} else {
		t.client, err = routeros.DialContext(connectCtx, address, c.Username, c.Password)
	}
	if err != nil {
		return err
	}
	t.client.SetLogHandler(silentRouterOSLogHandler{})
	return nil
}

func (t *realRouterOSMutationTransport) Write(ctx context.Context, prepared RouterOSPreparedWrite) error {
	if t.client == nil || !RouterOSWritePathAllowed(prepared.Path()) || !IsRealRouterOSOperation(string(prepared.Operation())) {
		return errors.New("RouterOS mutation transport rejected prepared write")
	}
	args := append([]string{prepared.Path()}, prepared.Words()...)
	_, err := t.client.RunArgsContext(ctx, args)
	return err
}

func (t *realRouterOSMutationTransport) Close() error {
	if t.client == nil {
		return nil
	}
	return t.client.Close()
}
