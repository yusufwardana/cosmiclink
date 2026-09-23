$env:GO_NETWORK_ENGINE_TOKEN = 'bad68625ecbd25ebcf7571164e617e75fecf494697224181e9d95ce9aa53a126'
$env:APP_ENV = 'local'
$env:MONITORING_PROVIDER = 'routeros'
$env:NETWORK_DISCOVERY_PROVIDER = 'routeros'
$env:NETWORK_MUTATION_PROVIDER = 'fake'
$env:COSMICLINK_AGENT_DATA_DIR = 'C:\ProgramData\CosmicLink\Phase6I-HardwareAcceptance\agent-data-fresh-2acc02e8d92c4c7a98f0d6b77ab45c48'

Start-Process -FilePath "C:
mpp\htdocs\cosmiclink
etwork-engine\cosmiclink-engine.exe" -WorkingDirectory "C:
mpp\htdocs\cosmiclink
etwork-engine" -WindowStyle Hidden