$ErrorActionPreference = 'Stop'
$testRoot = $PSScriptRoot
Push-Location $testRoot
try {
    if (-not (Test-Path (Join-Path $testRoot 'node_modules'))) {
        npm.cmd install
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
        npx.cmd playwright install chromium
        if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
    }
    npm.cmd test
    exit $LASTEXITCODE
} finally {
    Pop-Location
}
