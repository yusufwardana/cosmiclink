package agent

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"strings"
	"time"

	"cosmiclink/network-engine/internal/network"
)

const discoverRouter = "DISCOVER_ROUTER"

type Config struct {
	CoreURL, Token, Name string
	Timeout              time.Duration
}
type Job struct {
	ID         int64                        `json:"id"`
	Type       string                       `json:"type"`
	RouterRef  string                       `json:"router_ref"`
	Connection *network.DiscoveryConnection `json:"connection,omitempty"`
}
type claimResponse struct {
	Job *Job `json:"job"`
}

type Agent struct {
	config   Config
	provider network.DiscoveryProvider
	client   *http.Client
	logger   *slog.Logger
}

func New(config Config, provider network.DiscoveryProvider, logger *slog.Logger) *Agent {
	if config.Timeout <= 0 {
		config.Timeout = 10 * time.Second
	}
	if logger == nil {
		logger = slog.Default()
	}
	return &Agent{config: config, provider: provider, client: &http.Client{Timeout: config.Timeout}, logger: logger}
}

func (a *Agent) RunOnce(ctx context.Context) error {
	if err := ctx.Err(); err != nil {
		return err
	}
	if err := a.request(ctx, "/api/v1/agent/heartbeat", map[string]any{"version": "phase-6e", "capabilities": []string{"discovery.routeros.readonly"}}, nil); err != nil {
		return err
	}
	var claimed claimResponse
	status, err := a.requestStatus(ctx, "/api/v1/agent/jobs/claim", map[string]any{}, &claimed)
	if err != nil {
		return err
	}
	if status == http.StatusNoContent || claimed.Job == nil {
		return nil
	}
	result, err := a.executeResult(ctx, *claimed.Job)
	if err != nil {
		return err
	}
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
	a.logger.Info("network agent job", "job_id", job.ID, "router_ref", job.RouterRef, "job_type", job.Type, "status", result.Code, "duration_ms", time.Since(started).Milliseconds())
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
		_, _ = io.Copy(io.Discard, resp.Body)
		return resp.StatusCode, fmt.Errorf("core request failed with status %d", resp.StatusCode)
	}
	if output != nil {
		if err := json.NewDecoder(resp.Body).Decode(output); err != nil {
			return resp.StatusCode, err
		}
	}
	return resp.StatusCode, nil
}
