package monitoring

import (
	"context"
	"time"
)

// TrafficProvider performs one normalized, read-only traffic collection.
type TrafficProvider interface {
	CollectTraffic(context.Context, RouterTarget, TrafficOptions) (TrafficSnapshot, error)
}

type TrafficOptions struct {
	IncludeEnrichment bool `json:"include_enrichment"`
}

type TrafficEvidence struct {
	Datasets            []string `json:"datasets"`
	EnrichmentCollected bool     `json:"enrichment_collected"`
}

type InterfaceTraffic struct {
	SourceKey     string  `json:"source_key"`
	Name          string  `json:"name,omitempty"`
	Type          string  `json:"type,omitempty"`
	Running       bool    `json:"running"`
	Disabled      bool    `json:"disabled"`
	UploadBytes   *uint64 `json:"upload_bytes"`
	DownloadBytes *uint64 `json:"download_bytes"`
}

type SimpleQueueTraffic struct {
	SourceKey     string  `json:"source_key"`
	SubjectKey    string  `json:"subject_key,omitempty"`
	Name          string  `json:"name,omitempty"`
	Target        string  `json:"target,omitempty"`
	Disabled      bool    `json:"disabled"`
	Dynamic       bool    `json:"dynamic"`
	UploadBytes   *uint64 `json:"upload_bytes"`
	DownloadBytes *uint64 `json:"download_bytes"`
	UploadRate    *uint64 `json:"upload_rate_bps"`
	DownloadRate  *uint64 `json:"download_rate_bps"`
	UploadLimit   *uint64 `json:"upload_limit_bps"`
	DownloadLimit *uint64 `json:"download_limit_bps"`
}

type HotspotSessionTraffic struct {
	SourceKey     string  `json:"source_key"`
	SubjectKey    string  `json:"subject_key"`
	RouterOSID    string  `json:"routeros_id"`
	User          string  `json:"user"`
	Address       string  `json:"address,omitempty"`
	MACAddress    string  `json:"mac_address,omitempty"`
	Server        string  `json:"server,omitempty"`
	LoginBy       string  `json:"login_by,omitempty"`
	Uptime        string  `json:"uptime,omitempty"`
	UploadBytes   *uint64 `json:"upload_bytes"`
	DownloadBytes *uint64 `json:"download_bytes"`
}

type DHCPLease struct {
	SourceKey  string `json:"source_key,omitempty"`
	Address    string `json:"address,omitempty"`
	MACAddress string `json:"mac_address,omitempty"`
	HostName   string `json:"host_name,omitempty"`
	Server     string `json:"server,omitempty"`
	Status     string `json:"status,omitempty"`
}

type ARPEntry struct {
	SourceKey  string `json:"source_key,omitempty"`
	Address    string `json:"address,omitempty"`
	MACAddress string `json:"mac_address,omitempty"`
	Interface  string `json:"interface,omitempty"`
	Complete   bool   `json:"complete"`
}

type TrafficSnapshot struct {
	CollectedAt     time.Time               `json:"collected_at"`
	Router          RouterResource          `json:"router"`
	Evidence        TrafficEvidence         `json:"evidence"`
	Interfaces      []InterfaceTraffic      `json:"interfaces"`
	SimpleQueues    []SimpleQueueTraffic    `json:"simple_queues"`
	HotspotSessions []HotspotSessionTraffic `json:"hotspot_sessions"`
	DHCPLeases      []DHCPLease             `json:"dhcp_leases,omitempty"`
	ARPEntries      []ARPEntry              `json:"arp_entries,omitempty"`
}
