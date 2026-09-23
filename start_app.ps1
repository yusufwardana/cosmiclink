# Start Laravel application with real monitoring configuration

$agentDataDir = 'C:\ProgramData\CosmicLink\Phase6I-HardwareAcceptance\agent-data-fresh-2acc02e8d92c4c7a98f0d6b77ab45c48'
$tokenFile = "C:\xampp\htdocs\cosmiclink\.env.local.token"

if (-not (Test-Path $tokenFile)) {
    Write-Host "ERROR: Token file not found at $tokenFile" -ForegroundColor Red
    exit 1
}

$token = (Get-Content $tokenFile | Out-String).Trim()

Write-Host "Starting CosmicLink Real Monitoring..." -ForegroundColor Cyan
Write-Host "Agent data dir: $($agentDataDir)" -ForegroundColor Yellow
Write-Host "Token: [REDACTED]" -ForegroundColor Yellow
Write-Host ""

# Set all necessary environment variables for THIS PowerShell session
$env:COSMICLINK_AGENT_DATA_DIR = $agentDataDir
$env:GO_NETWORK_ENGINE_TOKEN = $token
$env:APP_ENV = 'local'
$env:MONITORING_DRIVER = 'engine'
$env:MONITORING_TRAFFIC_ENABLED = 'true'
$env:MONITORING_PROVIDER = 'routeros'
$env:NETWORK_DISCOVERY_PROVIDER = 'routeros'
$env:NETWORK_MUTATION_PROVIDER = 'fake'
$env:NETWORK_MUTATIONS_ENABLED = 'false'
$env:DB_DATABASE = 'cosmiclink'

Set-Location C:\xampp\htdocs\cosmiclink

Write-Host "Running scheduled tasks..." -ForegroundColor Green
php artisan schedule:work
