[CmdletBinding()]
param([switch] $SkipBrowser)

. (Join-Path $PSScriptRoot 'demo-common.ps1')

$results = [System.Collections.Generic.List[object]]::new()
function Add-Check([string] $Name, [scriptblock] $Check, [string] $Success = 'PASS') {
    try {
        & $Check
        if ($LASTEXITCODE -ne 0) { throw "exit $LASTEXITCODE" }
        $results.Add([pscustomobject]@{ Service = $Name; Status = $Success })
    } catch {
        $results.Add([pscustomobject]@{ Service = $Name; Status = "FAIL - $($_.Exception.Message)" })
    }
}
function Assert-Http([string] $Url) {
    $response = Invoke-WebRequest -UseBasicParsing -Uri $Url -TimeoutSec 15
    if ($response.StatusCode -ne 200) { throw "HTTP $($response.StatusCode)" }
    if ($response.Content -match 'Fatal error|Failed opening required') { throw 'PHP fatal response' }
    return $response
}

Add-Check 'PostgreSQL' { Invoke-DemoCompose exec -T postgres pg_isready -U (Get-EnvironmentValue $script:EnvironmentFile 'POSTGRES_USER') -d (Get-EnvironmentValue $script:EnvironmentFile 'POSTGRES_DB') }
Add-Check 'PostGIS' { Invoke-DemoCompose exec -T postgres psql -U (Get-EnvironmentValue $script:EnvironmentFile 'POSTGRES_USER') -d (Get-EnvironmentValue $script:EnvironmentFile 'POSTGRES_DB') -tAc "SELECT postgis_version()" }
Add-Check 'Redis' { Invoke-DemoCompose exec -T redis redis-cli ping }
Add-Check 'Laravel Platform' {
    $response = Assert-Http 'http://localhost:8000/health/ready'
    $payload = $response.Content | ConvertFrom-Json
    if ($payload.status -notin @('ok', 'ready')) { throw "unexpected health status $($payload.status)" }
}
Add-Check 'Queue Worker' { if ((Invoke-DemoCompose ps --status running worker | Out-String) -notmatch 'worker') { throw 'not running' } }
Add-Check 'Scheduler' { if ((Invoke-DemoCompose ps --status running scheduler | Out-String) -notmatch 'scheduler') { throw 'not running' } }
Add-Check 'Object Storage' { $null = Assert-Http 'http://localhost:9000/minio/health/live' }
Add-Check 'Mailpit' { $null = Assert-Http 'http://localhost:8025/api/v1/info' }
Add-Check 'Public Landing Page' { $null = Assert-Http 'http://localhost:8000/' }
Add-Check 'Store Locator' { $null = Assert-Http 'http://localhost:8000/charging-map' }
Add-Check 'Seeded Station API' {
    $payload = Invoke-RestMethod -Uri 'http://localhost:8000/api/v1/public/stations?limit=250'
    if ($payload.data.Count -lt 30) { throw "only $($payload.data.Count) stations" }
}
Add-Check 'Admin Portal' { $null = Assert-Http 'http://localhost:8000/admin/login' }
Add-Check 'Operator Portal' { $null = Assert-Http 'http://localhost:8000/operator/login' }
Add-Check 'Flutter Web' { $null = Assert-Http 'http://localhost:3000/' }
Add-Check 'Demo Authentication' {
    $body = @{ email = 'driver@demo.vsta.local'; password = 'VstaDemo!2026'; tenant_id = '01J0000000VTSADEMA00000000'; device_name = 'demo-verify' } | ConvertTo-Json
    $response = Invoke-RestMethod -Method Post -Uri 'http://localhost:8000/api/v1/auth/login' -ContentType 'application/json' -Body $body
    if ([string]::IsNullOrWhiteSpace([string] $response.data.token)) {
        throw "token was not issued; response: $($response | ConvertTo-Json -Compress -Depth 5)"
    }
}
Add-Check 'Tenant Isolation Tests' {
    $databaseUser = Get-EnvironmentValue $script:EnvironmentFile 'POSTGRES_USER'
    Invoke-DemoCompose exec -T postgres dropdb --if-exists --force -U $databaseUser vtsa_test
    Invoke-DemoCompose exec -T postgres createdb -U $databaseUser vtsa_test
    Invoke-DemoCompose exec -T -e DB_DATABASE=vtsa_test platform php artisan test --filter=TenantIsolation
}
if (-not $SkipBrowser) {
    Add-Check 'Browser Smoke Tests' { & (Join-Path $script:RepoRoot 'tests/browser/run.ps1'); if ($LASTEXITCODE -ne 0) { throw "browser exit $LASTEXITCODE" } }
}
$results.Add([pscustomobject]@{ Service = 'OCPP Gateway'; Status = 'DEFERRED - MILESTONE 2' })
$results.Add([pscustomobject]@{ Service = 'Real Payment Gateway'; Status = 'DEFERRED - MILESTONE 2' })
$results | Format-Table -AutoSize

if ($results.Status.Where({ $_ -like 'FAIL*' }).Count -gt 0) {
    exit 1
}
