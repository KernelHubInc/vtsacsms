[CmdletBinding()]
param(
    [switch] $Seed
)

$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path -Parent $PSScriptRoot
$environmentFile = Join-Path $repoRoot '.env.local-environments'
$databaseCompose = Join-Path $repoRoot 'infra/database/compose.yaml'
$applicationCompose = Join-Path $repoRoot 'infra/local/compose.environments.yaml'
$mobileDirectory = Join-Path $repoRoot 'apps/mobile'
$productionMobileOutput = Join-Path $repoRoot '.cache/mobile-production'
$stagingMobileOutput = Join-Path $repoRoot '.cache/mobile-staging'

function Invoke-Compose([string] $composeFile, [string[]] $Arguments) {
    & docker compose --env-file $environmentFile -f $composeFile @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "Docker Compose failed with exit code $LASTEXITCODE."
    }
}

& (Join-Path $repoRoot 'tools/bootstrap-environments.ps1')

Invoke-Compose $databaseCompose @(
    '--profile', 'local-tools', 'up', '--detach', '--wait', 'postgres', 'adminer'
)
Invoke-Compose $databaseCompose @(
    'exec', '-T', 'postgres', 'bash', '/opt/vtsa/provision-vtsa-databases.sh'
)

$environmentContent = [IO.File]::ReadAllText($environmentFile)
$tenantMatch = [regex]::Match($environmentContent, '(?m)^DEFAULT_TENANT_ID=(.*)$')
$tenantId = if ($tenantMatch.Success) { $tenantMatch.Groups[1].Value.Trim() } else { '' }

Push-Location $mobileDirectory
try {
    & flutter build web --release --no-web-resources-cdn `
        --output=$productionMobileOutput `
        --dart-define=APP_ENVIRONMENT=production `
        --dart-define=API_BASE_URL=http://localhost:8000 `
        --dart-define=DEFAULT_TENANT_ID=$tenantId `
        --dart-define=MAP_PROVIDER=openstreetmap
    if ($LASTEXITCODE -ne 0) {
        throw 'Production Flutter Web build failed.'
    }

    & flutter build web --release --no-web-resources-cdn `
        --output=$stagingMobileOutput `
        --dart-define=APP_ENVIRONMENT=staging `
        --dart-define=API_BASE_URL=http://localhost:8001 `
        --dart-define=DEFAULT_TENANT_ID=$tenantId `
        --dart-define=MAP_PROVIDER=openstreetmap
    if ($LASTEXITCODE -ne 0) {
        throw 'Staging Flutter Web build failed.'
    }
} finally {
    Pop-Location
}

Invoke-Compose $applicationCompose @('up', '--detach', '--build', '--wait')

foreach ($service in @('platform-production', 'platform-staging')) {
    Invoke-Compose $applicationCompose @('exec', '-T', $service, 'php', 'artisan', 'migrate', '--force')
    Invoke-Compose $applicationCompose @('exec', '-T', $service, 'php', 'artisan', 'security:sync-permissions')
    Invoke-Compose $applicationCompose @('exec', '-T', $service, 'php', 'artisan', 'optimize:clear')

    if ($Seed) {
        Invoke-Compose $applicationCompose @(
            'exec', '-T', '-e', 'APP_ENV=demo', '-e', 'FEATURE_DEMO_MODE=true',
            $service, 'php', 'artisan', 'db:seed',
            '--class=Database\Seeders\MilestoneOneDemoSeeder', '--force'
        )
    }
}

Write-Host ''
Write-Host 'Local environments are ready:'
Write-Host '  Production web:    http://localhost:8000'
Write-Host '  Staging web:       http://localhost:8001'
Write-Host '  Production mobile: http://localhost:3000'
Write-Host '  Staging mobile:    http://localhost:3001'
Write-Host '  Local email:       http://localhost:8025'
Write-Host '  Database Adminer:  http://localhost:8081'
