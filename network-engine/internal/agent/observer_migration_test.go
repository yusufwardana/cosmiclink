package agent

import (
	"context"
	"strings"
	"testing"

	"cosmiclink/network-engine/internal/credentials"
	"cosmiclink/network-engine/internal/network"
)

func TestDecodeCredentialResolutionJobRejectsSecretFields(t *testing.T) {
	var job CredentialResolutionJob
	if err := DecodeCredentialResolutionJob(strings.NewReader(`{"tenant_ref":"t","router_ref":"r","agent_ref":"a","installation_id":"i","credential_ref":"c","credential_purpose":"OBSERVER","credential_version":1,"password":"secret"}`), &job); err == nil {
		t.Fatal("secret-bearing resolution job accepted")
	}
}

func TestAgentResolvesExactObserverVersionForDiscovery(t *testing.T) {
	resolver := &recordingResolver{credential: credentials.NewResolvedCredential("readonly", []byte("synthetic-secret"))}
	a := NewWithCredentialResolver(Config{}, recordingDiscovery{}, resolver, nil)
	result, err := a.executeResult(context.Background(), Job{Type: discoverRouter, TenantRef: "t", RouterRef: "r", AgentRef: "a", InstallationID: "i", CredentialRef: "c", CredentialPurpose: "OBSERVER", CredentialVersion: 2, Host: "router.test", Port: 8729, Transport: "api_ssl"})
	if err != nil {
		t.Fatal(err)
	}
	if resolver.job.CredentialVersion != 2 || resolver.job.CredentialPurpose != "OBSERVER" {
		t.Fatalf("resolver job = %#v", resolver.job)
	}
	if !result["success"].(bool) {
		t.Fatalf("result = %#v", result)
	}
}

type recordingResolver struct {
	job        CredentialResolutionJob
	credential credentials.ResolvedCredential
}

func (r *recordingResolver) ResolveJob(_ context.Context, job CredentialResolutionJob) (credentials.ResolvedCredential, error) {
	r.job = job
	return r.credential, nil
}

type recordingDiscovery struct{}

func (recordingDiscovery) Name() string { return "fake" }
func (recordingDiscovery) Discover(_ context.Context, request network.DiscoveryRequest) network.DiscoveryResult {
	if request.Connection == nil || request.Connection.Username != "readonly" || request.Connection.Password != "synthetic-secret" {
		return network.DiscoveryResult{Success: false, Code: "MISSING_LOCAL_CREDENTIAL", Message: "missing local credential"}
	}
	return network.DiscoveryResult{Success: true, Provider: "fake", RouterRef: request.RouterRef, Message: "ok"}
}
