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
	"runtime"
	"strings"
	"time"

	"cosmiclink/network-engine/internal/network"
)

const discoverRouter = "DISCOVER_ROUTER"

type Config struct {
	CoreURL, Token, Name                        string
	Timeout                                     time.Duration
	PollInterval, HeartbeatInterval, MaxBackoff time.Duration
}
type Job struct {
	ID             int64                        `json:"id"`
	Type           string                       `json:"type"`
	RouterRef      string                       `json:"router_ref"`
	Connection     *network.DiscoveryConnection `json:"connection,omitempty"`
	Attempt        int                          `json:"attempt"`
	Fence          string                       `json:"fence"`
	RenewalSeconds int                          `json:"renewal_seconds"`
}
type claimResponse struct {
	Job *Job `json:"job"`
}

type Agent struct {
	config        Config
	provider      network.DiscoveryProvider
	client        *http.Client
	logger        *slog.Logger
	started       time.Time
	lastHeartbeat time.Time
}

var ErrAuthentication = errors.New("agent authentication rejected")

func New(config Config, provider network.DiscoveryProvider, logger *slog.Logger) *Agent {
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
	return &Agent{config: config, provider: provider, client: &http.Client{Timeout: config.Timeout, CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}, logger: logger, started: time.Now()}
}

func (a *Agent) heartbeat(ctx context.Context) error {
	err := a.request(ctx, "/api/v1/agent/heartbeat", map[string]any{"version": "0.6.0", "capabilities": []string{"discovery.routeros.readonly", "jobs.lease.v1"}, "go_runtime": runtime.Version(), "os": runtime.GOOS, "architecture": runtime.GOARCH, "uptime_seconds": int64(time.Since(a.started).Seconds())}, nil)
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
	result := a.provider.Discover(ctx, network.DiscoveryRequest{RouterRef: job.RouterRef, Connection: job.Connection})
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
func (a *Agent) requestStatus(ctx context.Context, path string, input, output any) (int, error) {
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
		if err := json.NewDecoder(io.LimitReader(resp.Body, 1048576)).Decode(output); err != nil {
			return resp.StatusCode, err
		}
	}
	return resp.StatusCode, nil
}
