$ErrorActionPreference = 'Stop'
$script:RepoRoot = Split-Path -Parent $PSScriptRoot
$script:ComposeFile = Join-Path $script:RepoRoot 'infra/compose.yaml'
$script:EnvironmentFile = Join-Path $script:RepoRoot '.env'

function Get-EnvironmentValue([string] $Path, [string] $Name) {
    $content = [IO.File]::ReadAllText($Path)
    $match = [regex]::Match($content, "(?m)^$([regex]::Escape($Name))=(.*)$")
    if (-not $match.Success) {
        throw "Missing $Name in $Path"
    }
    return $match.Groups[1].Value.Trim('"')
}

function Invoke-DemoCompose {
    & docker compose --env-file $script:EnvironmentFile -f $script:ComposeFile @args
    if ($LASTEXITCODE -ne 0) {
        throw "Docker Compose failed with exit code $LASTEXITCODE."
    }
}

function Wait-DemoUrl([string] $Url, [int] $Seconds = 180) {
    $deadline = (Get-Date).AddSeconds($Seconds)
    do {
        try {
            $response = Invoke-WebRequest -UseBasicParsing -Uri $Url -TimeoutSec 5
            if ($response.StatusCode -ge 200 -and $response.StatusCode -lt 500) {
                return
            }
        } catch {
            Start-Sleep -Seconds 2
        }
    } while ((Get-Date) -lt $deadline)

    throw "Timed out waiting for $Url"
}

function Write-DemoUrls {
    Write-Host ''
    Write-Host 'VTSA CSMS Milestone 1 URLs'
    Write-Host '  Public website:  http://localhost:8000'
    Write-Host '  Store locator:   http://localhost:8000/charging-map'
    Write-Host '  Platform admin:  http://localhost:8000/admin'
    Write-Host '  Operator portal: http://localhost:8000/operator'
    Write-Host '  Technician view: http://localhost:8000/operator/technician-workboard'
    Write-Host '  Flutter Web:     http://localhost:3000'
    Write-Host '  Mailpit:         http://localhost:8025'
    Write-Host '  MinIO console:   http://localhost:9001'
    Write-Host '  API health:      http://localhost:8000/api/health'
}

function Write-DemoCredentials {
    Write-Host ''
    Write-Host 'Demo password for every account: VstaDemo!2026'
    Write-Host '  superadmin@demo.vsta.local          Platform super administrator'
    Write-Host '  admin@demo.vsta.local               Platform administrator'
    Write-Host '  auditor@demo.vsta.local             Security and audit administrator'
    Write-Host '  operator.admin@demo.vsta.local      Operator administrator'
    Write-Host '  operator.ops@demo.vsta.local        Operator operations manager'
    Write-Host '  sitehost@demo.vsta.local            Site host manager'
    Write-Host '  site.manager@demo.vsta.local        Site manager'
    Write-Host '  requester@demo.vsta.local           Procurement requester'
    Write-Host '  approver@demo.vsta.local            Procurement approver'
    Write-Host '  procurement@demo.vsta.local         Procurement officer'
    Write-Host '  inventory.manager@demo.vsta.local   Inventory manager'
    Write-Host '  warehouse@demo.vsta.local           Warehouse staff'
    Write-Host '  maintenance.manager@demo.vsta.local Maintenance manager'
    Write-Host '  technician@demo.vsta.local          Maintenance technician'
    Write-Host '  finance@demo.vsta.local             Finance manager'
    Write-Host '  support@demo.vsta.local             Customer support agent'
    Write-Host '  fleet.manager@demo.vsta.local       Fleet manager'
    Write-Host '  executive@demo.vsta.local           Read-only executive'
    Write-Host '  driver@demo.vsta.local              Consumer / Flutter'
    Write-Host '  Scopes and scenarios: docs/local/DEMO-CREDENTIALS.md'
}

function Write-DemoDeferred {
    Write-Host ''
    Write-Host 'Milestone 2 deferred: OCPP, physical chargers, remote charging, real payments, real settlements, and OCPI.'
}
