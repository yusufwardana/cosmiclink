# Start Go Network Engine with real monitoring configuration

$env:COSMICLINK_AGENT_DATA_DIR = 'C:\ProgramData\CosmicLink\Phase6I-HardwareAcceptance\agent-data-fresh-2acc02e8d92c4c7a98f0d6b77ab45c48'
$tokenFile = "C:\xampp\htdocs\cosmiclink\.env.local.token"

if (-not (Test-Path $tokenFile)) {
    Write-Host "ERROR: Token file not found at $tokenFile" -ForegroundColor Red
    exit 1
}

$token = (Get-Content $tokenFile | Out-String).Trim()

$env:GO_NETWORK_ENGINE_TOKEN = $token
$env:APP_ENV = 'local'
$env:MONITORING_PROVIDER = 'routeros'
$env:NETWORK_DISCOVERY_PROVIDER = 'routeros'
$env:NETWORK_MUTATION_PROVIDER = 'fake'

Write-Host "Starting Go Network Engine on 127.0.0.1:8787..." -ForegroundColor Cyan
Write-Host "Environment:"
Write-Host "  TOKEN: [REDACTED]"
Write-Host "  MONITORING_PROVIDER: routeros"
Write-Host "  DISCOVERY_PROVIDER: fake"
Write-Host "  MUTATION_PROVIDER: fake"
Write-Host "  AGENT_DATA_DIR: $($env:COSMICLINK_AGENT_DATA_DIR)"
Write-Host ""

Set-Location C:\xampp\htdocs\cosmiclink\network-engine
go run ./cmd/server
