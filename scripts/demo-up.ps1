[CmdletBinding()]
param()

. (Join-Path $PSScriptRoot 'demo-common.ps1')

foreach ($command in @('docker')) {
    if (-not (Get-Command $command -ErrorAction SilentlyContinue)) {
        throw "$command is required. See docs/local/LOCAL-DEMO.md."
    }
}

& docker info *> $null
if ($LASTEXITCODE -ne 0) {
    throw 'Docker Desktop is not running.'
}

& (Join-Path $script:RepoRoot 'tools/bootstrap.ps1')
if ($LASTEXITCODE -ne 0) {
    throw 'Local environment bootstrap failed.'
}

Write-Host 'Building and starting the Milestone 1 stack...'
Invoke-DemoCompose up --build --detach --remove-orphans
Wait-DemoUrl 'http://localhost:8000/health/live' 300
Wait-DemoUrl 'http://localhost:3000' 300

Write-Host 'Running migrations and deterministic Milestone 1 seeders...'
Invoke-DemoCompose exec -T platform php artisan migrate --force
Invoke-DemoCompose exec -T platform php artisan db:seed --class=MilestoneOneDemoSeeder --force
Invoke-DemoCompose exec -T platform php artisan optimize:clear

& (Join-Path $PSScriptRoot 'demo-verify.ps1') -SkipBrowser
if ($LASTEXITCODE -ne 0) {
    throw 'Demo verification failed.'
}

Write-DemoUrls
Write-DemoCredentials
Write-DemoDeferred
Write-Host ''
Write-Host 'Milestone 1 is running in detached containers.'
