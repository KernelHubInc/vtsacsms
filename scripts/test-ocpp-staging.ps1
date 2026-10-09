param(
    [ValidateRange(1, 60)]
    [int]$Heartbeats = 6,
    [ValidatePattern('^[A-Za-z0-9._-]{1,120}$')]
    [string]$Identity = 'DEMO-CP-023',
    [switch]$UseBasicAuthentication
)

$ErrorActionPreference = 'Stop'
$repo = Split-Path -Parent $PSScriptRoot
$python = Join-Path $repo 'services/ocpp-gateway/.venv/Scripts/python.exe'
$credential = Join-Path $repo '.cache/ocpp-staging/simulator-client/password.dpapi'
if (-not (Test-Path -LiteralPath $python)) {
    throw 'Install the gateway simulator virtual environment first; see the staging OCPP runbook.'
}
if ($UseBasicAuthentication -and -not (Test-Path -LiteralPath $credential)) {
    throw 'The encrypted simulator credential is missing. It belongs to the Windows account used for enrollment.'
}

$previousPassword = $env:SIMULATOR_BASIC_PASSWORD
try {
    $env:SIMULATOR_BASIC_PASSWORD = $null
    if ($UseBasicAuthentication) {
        $secret = (Get-Content -LiteralPath $credential -Raw).Trim() | ConvertTo-SecureString
        $env:SIMULATOR_BASIC_PASSWORD = [System.Net.NetworkCredential]::new('', $secret).Password
    }
    & $python -m vtsa_ocpp_gateway.simulator `
        --url 'wss://staging.evcspowersolutions.com/ocpp' `
        --identity $Identity --protocol ocpp1.6 `
        --scenario connectivity --heartbeats $Heartbeats
    $result = $LASTEXITCODE
} finally {
    $env:SIMULATOR_BASIC_PASSWORD = $previousPassword
    if ($null -ne $secret) { $secret.Dispose() }
}
exit $result
