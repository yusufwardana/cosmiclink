package agent

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"math/rand"
	"net/http"
	"os"
	"runtime"
	"strings"
	"time"

	"cosmiclink/network-engine/internal/credentials"
	"cosmiclink/network-engine/internal/network"
)

const discoverRouter = "DISCOVER_ROUTER"

type Config struct {
	CoreURL, Token, Name                        string
	DataDir                                     string
	Timeout                                     time.Duration
	PollInterval, HeartbeatInterval, MaxBackoff time.Duration
}
type Job struct {
	ID                    int64                        `json:"id"`
	Type                  string                       `json:"type"`
	RouterRef             string                       `json:"router_ref"`
	Connection            *network.DiscoveryConnection `json:"connection,omitempty"`
	TenantRef             string                       `json:"tenant_ref"`
	AgentRef              string                       `json:"agent_ref"`
	InstallationID        string                       `json:"installation_id,omitempty"`
	CredentialRef         string                       `json:"credential_ref,omitempty"`
	CredentialPurpose     string                       `json:"credential_purpose,omitempty"`
	CredentialVersion     int                          `json:"credential_version,omitempty"`
	Host                  string                       `json:"host,omitempty"`
	Port                  int                          `json:"port,omitempty"`
	Transport             string                       `json:"transport,omitempty"`
	ConnectTimeoutSeconds int                          `json:"connect_timeout_seconds,omitempty"`
	ReadTimeoutSeconds    int                          `json:"read_timeout_seconds,omitempty"`
	InsecureTLS           bool                         `json:"insecure_tls,omitempty"`
	Attempt               int                          `json:"attempt"`
	Fence                 string                       `json:"fence"`
	RenewalSeconds        int                          `json:"renewal_seconds"`
}
type claimResponse struct {
	Job *Job `json:"job"`
}

type Agent struct {
	config             Config
	bootstrap          *BootstrapState
	bootstrapErr       error
	provider           network.DiscoveryProvider
	credentialResolver JobResolver
	client             *http.Client
	logger             *slog.Logger
	started            time.Time
	lastHeartbeat      time.Time
}

var ErrAuthentication = errors.New("agent authentication rejected")

func New(config Config, provider network.DiscoveryProvider, logger *slog.Logger) *Agent {
	return newAgent(config, provider, nil, logger)
}

// NewWithCredentialResolver enables the local reference-only credential seam.
// It does not alter legacy discovery jobs or connect the resolved credential to
// any network/provider operation.
func NewWithCredentialResolver(config Config, provider network.DiscoveryProvider, resolver JobResolver, logger *slog.Logger) *Agent {
	return newAgent(config, provider, resolver, logger)
}

func newAgent(config Config, provider network.DiscoveryProvider, resolver JobResolver, logger *slog.Logger) *Agent {
	if config.Timeout <= 0 {
		config.Timeout = 10 * time.Second
	}
	if logger == nil {
		logger = slog.Default()
	}
	if config.PollInterval <= 0 {
		config.PollInterval = 5 * time.Second
	}
	if config.HeartbeatInterval <= 0 {
		config.HeartbeatInterval = 30 * time.Second
	}
	if config.MaxBackoff <= 0 {
		config.MaxBackoff = time.Minute
	}
	var bootstrap *BootstrapState
	var bootstrapErr error
	if config.DataDir != "" {
		bootstrap, bootstrapErr = NewBootstrapState(strings.TrimRight(config.DataDir, `/\\`) + string(os.PathSeparator) + "agent-bootstrap.json")
	} else {
		bootstrap, bootstrapErr = NewBootstrapState("")
	}
	return &Agent{config: config, bootstrap: bootstrap, bootstrapErr: bootstrapErr, provider: provider, credentialResolver: resolver, client: &http.Client{Timeout: config.Timeout, CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}, logger: logger, started: time.Now()}
}

// ResolveCredential consumes a reference-only credential job through the
// local encrypted store and returns only synthetic safe evidence.
func (a *Agent) ResolveCredential(ctx context.Context, job CredentialResolutionJob) (CredentialResolutionEvidence, error) {
	if a == nil || a.credentialResolver == nil {
		return CredentialResolutionEvidence{}, credentials.ErrCredentialReferenceInvalid
	}
	resolved, err := a.credentialResolver.ResolveJob(ctx, job)
	if err != nil {
		return CredentialResolutionEvidence{}, err
	}
	ref := credentials.Reference{TenantRef: job.TenantRef, RouterRef: job.RouterRef, AgentRef: job.AgentRef, InstallationID: credentials.InstallationIdentity(job.InstallationID), CredentialRef: job.CredentialRef, Purpose: credentials.Purpose(job.CredentialPurpose), Version: job.CredentialVersion}
	return ConsumeResolvedCredential(ref, resolved)
}

// SyncObserverMetadata sends metadata-only evidence to Core over the existing
// authenticated Agent relationship. It deliberately accepts only OBSERVER
// metadata and has no secret-bearing fields.
func (a *Agent) SyncObserverMetadata(ctx context.Context, metadata credentials.CredentialMetadata) error {
	if a == nil || metadata.Purpose != credentials.PurposeObserver || metadata.Version < 1 || metadata.CredentialRef == "" {
		return credentials.ErrCredentialReferenceInvalid
	}
	if a.bootstrap == nil || a.bootstrap.AgentRef() == "" {
		return ErrAgentBootstrapRequired
	}
	payload := map[string]any{
		"tenant_ref":      string(metadata.TenantRef),
		"router_ref":      string(metadata.RouterRef),
		"agent_ref":       a.bootstrap.AgentRef(),
		"installation_id": string(metadata.InstallationID),
		"credential_ref":  metadata.CredentialRef,
		"purpose":         string(metadata.Purpose),
		"version":         metadata.Version,
		"status":          string(metadata.Status),
	}
	return a.requestStrict(ctx, "/api/v1/agent/observer-references/sync", payload, nil)
}

func (a *Agent) heartbeat(ctx context.Context) error {
	if a.bootstrapErr != nil {
		return a.bootstrapErr
	}
	var response heartbeatResponse
	err := a.requestStrict(ctx, "/api/v1/agent/heartbeat", map[string]any{"version": "0.6.0", "capabilities": []string{"discovery.routeros.readonly", "jobs.lease.v1"}, "go_runtime": runtime.Version(), "os": runtime.GOOS, "architecture": runtime.GOARCH, "uptime_seconds": int64(time.Since(a.started).Seconds())}, &response)
	if err == nil {
		encoded, marshalErr := json.Marshal(response)
		if marshalErr != nil {
			return ErrAgentBootstrapInvalid
		}
		err = a.bootstrap.Bind(ctx, encoded)
	}
	if err == nil {
		a.lastHeartbeat = time.Now()
	}
	return err
}

func backoff(failures int, base, maximum time.Duration) time.Duration {
	delay := base
	for i := 1; i < failures && delay < maximum/2; i++ {
		delay *= 2
	}
	if failures > 1 && delay >= maximum/2 {
		delay = maximum
	}
	if delay > maximum {
		delay = maximum
	}
	return delay/2 + time.Duration(rand.Int63n(int64(delay/2)+1))
}

func (a *Agent) Run(ctx context.Context) error {
	failures := 0
	for {
		err := a.RunOnce(ctx)
		if ctx.Err() != nil {
			return ctx.Err()
		}
		if errors.Is(err, ErrAuthentication) {
			return err
		}
		var delay time.Duration
		failures, delay = a.retryDelay(failures, err)
		if err != nil {
			a.logger.Warn("agent communication unavailable", "code", "CORE_UNAVAILABLE")
		}
		timer := time.NewTimer(delay)
		select {
		case <-ctx.Done():
			timer.Stop()
			return ctx.Err()
		case <-timer.C:
		}
	}
}

func (a *Agent) retryDelay(failures int, err error) (int, time.Duration) {
	if err == nil {
		return 0, a.config.PollInterval
	}
	if failures < 32 {
		failures++
	}
	return failures, backoff(failures, a.config.PollInterval, a.config.MaxBackoff)
}

func (a *Agent) RunOnce(ctx context.Context) error {
	if err := ctx.Err(); err != nil {
		return err
	}
	if a.lastHeartbeat.IsZero() || time.Since(a.lastHeartbeat) >= a.config.HeartbeatInterval {
		if err := a.heartbeat(ctx); err != nil {
			return err
		}
	}
	var claimed claimResponse
	status, err := a.requestStatus(ctx, "/api/v1/agent/jobs/claim", map[string]any{}, &claimed)
	if err != nil {
		return err
	}
	if status == http.StatusNoContent || claimed.Job == nil {
		return nil
	}
	job := *claimed.Job
	if job.Attempt < 1 || job.Fence == "" || job.RenewalSeconds < 1 {
		return errors.New("invalid job lease")
	}
	workCtx, cancel := context.WithCancel(ctx)
	defer cancel()
	done := make(chan error, 1)
	go func() {
		renew := time.NewTicker(time.Duration(job.RenewalSeconds) * time.Second)
		pulse := time.NewTicker(a.config.HeartbeatInterval)
		defer renew.Stop()
		defer pulse.Stop()
		for {
			select {
			case <-workCtx.Done():
				done <- nil
				return
			case <-renew.C:
				if err := a.request(workCtx, fmt.Sprintf("/api/v1/agent/jobs/%d/renew", job.ID), map[string]any{"attempt": job.Attempt, "fence": job.Fence}, nil); err != nil {
					cancel()
					done <- err
					return
				}
			case <-pulse.C:
				if err := a.heartbeat(workCtx); err != nil {
					cancel()
					done <- err
					return
				}
			}
		}
	}()
	result, err := a.executeResult(workCtx, job)
	wasCancelled := workCtx.Err()
	cancel()
	leaseErr := <-done
	if leaseErr != nil {
		return leaseErr
	}
	if wasCancelled != nil {
		return wasCancelled
	}
	if err != nil {
		return err
	}
	result["attempt"] = job.Attempt
	result["fence"] = job.Fence
	return a.request(ctx, fmt.Sprintf("/api/v1/agent/jobs/%d/result", claimed.Job.ID), result, nil)
}

func (a *Agent) Execute(ctx context.Context, job Job) error {
	_, err := a.executeResult(ctx, job)
	return err
}

func (a *Agent) executeResult(ctx context.Context, job Job) (map[string]any, error) {
	if job.Type != discoverRouter {
		return nil, errors.New("unsupported network agent job type")
	}
	started := time.Now()
	request := network.DiscoveryRequest{TenantRef: job.TenantRef, RouterRef: job.RouterRef, AgentRef: job.AgentRef, InstallationID: job.InstallationID, CredentialRef: job.CredentialRef, CredentialPurpose: job.CredentialPurpose, CredentialVersion: job.CredentialVersion, Host: job.Host, Port: job.Port, Transport: job.Transport, ConnectTimeoutSeconds: job.ConnectTimeoutSeconds, ReadTimeoutSeconds: job.ReadTimeoutSeconds, InsecureTLS: job.InsecureTLS, Connection: job.Connection}
	if job.CredentialRef != "" {
		if a.credentialResolver == nil || job.CredentialPurpose != string(credentials.PurposeObserver) || job.CredentialVersion < 1 {
			return map[string]any{"success": false, "provider": a.provider.Name(), "code": "CREDENTIAL_REFERENCE_INVALID", "message": "Observer credential reference is invalid."}, nil
		}
		resolved, err := a.credentialResolver.ResolveJob(ctx, CredentialResolutionJob{TenantRef: job.TenantRef, RouterRef: job.RouterRef, AgentRef: job.AgentRef, InstallationID: job.InstallationID, CredentialRef: job.CredentialRef, CredentialPurpose: job.CredentialPurpose, CredentialVersion: job.CredentialVersion})
		if err != nil {
			return map[string]any{"success": false, "provider": a.provider.Name(), "code": "CREDENTIAL_RESOLUTION_FAILED", "message": "Observer credential resolution failed."}, nil
		}
		request.Connection = &network.DiscoveryConnection{Host: job.Host, Port: job.Port, Username: resolved.Username(), Password: string(resolved.SecretBytes()), Transport: job.Transport, ConnectTimeoutSeconds: job.ConnectTimeoutSeconds, ReadTimeoutSeconds: job.ReadTimeoutSeconds, InsecureTLS: job.InsecureTLS}
	}
	result := a.provider.Discover(ctx, request)
	if err := ctx.Err(); err != nil {
		return nil, err
	}
	a.logger.Info("network agent job", "job_id", job.ID, "job_type", discoverRouter, "success", result.Success, "duration_ms", time.Since(started).Milliseconds())
	payload := map[string]any{"success": result.Success, "provider": result.Provider, "discovered_at": result.DiscoveredAt, "code": result.Code, "message": result.Message}
	if result.Success {
		payload["snapshot"] = result.Snapshot
	}
	return payload, nil
}

func (a *Agent) request(ctx context.Context, path string, input, output any) error {
	_, err := a.requestStatus(ctx, path, input, output)
	return err
}

func (a *Agent) requestStrict(ctx context.Context, path string, input, output any) error {
	_, err := a.requestStatusStrict(ctx, path, input, output)
	return err
}
func (a *Agent) requestStatus(ctx context.Context, path string, input, output any) (int, error) {
	return a.requestStatusWithDecoder(ctx, path, input, output, false)
}

func (a *Agent) requestStatusStrict(ctx context.Context, path string, input, output any) (int, error) {
	return a.requestStatusWithDecoder(ctx, path, input, output, true)
}

func (a *Agent) requestStatusWithDecoder(ctx context.Context, path string, input, output any, strict bool) (int, error) {
	body, err := json.Marshal(input)
	if err != nil {
		return 0, err
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, strings.TrimRight(a.config.CoreURL, "/")+path, bytes.NewReader(body))
	if err != nil {
		return 0, err
	}
	req.Header.Set("Authorization", "Bearer "+a.config.Token)
	req.Header.Set("Content-Type", "application/json")
	resp, err := a.client.Do(req)
	if err != nil {
		return 0, err
	}
	defer resp.Body.Close()
	if resp.StatusCode == http.StatusNoContent {
		return resp.StatusCode, nil
	}
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		_, _ = io.Copy(io.Discard, io.LimitReader(resp.Body, 4096))
		if resp.StatusCode == http.StatusUnauthorized || resp.StatusCode == http.StatusForbidden {
			return resp.StatusCode, ErrAuthentication
		}
		return resp.StatusCode, fmt.Errorf("core request failed with status %d", resp.StatusCode)
	}
	if output != nil {
		decoder := json.NewDecoder(io.LimitReader(resp.Body, 1048576))
		if strict {
			decoder.DisallowUnknownFields()
		}
		if err := decoder.Decode(output); err != nil {
			if strict {
				return resp.StatusCode, ErrAgentBootstrapInvalid
			}
			return resp.StatusCode, err
		}
		if strict {
			var extra any
			if err := decoder.Decode(&extra); !errors.Is(err, io.EOF) {
				return resp.StatusCode, ErrAgentBootstrapInvalid
			}
		}
	}
	return resp.StatusCode, nil
}
