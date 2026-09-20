[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path -Parent $PSScriptRoot
$template = Join-Path $repoRoot '.env.local-environments.example'
$environmentFile = Join-Path $repoRoot '.env.local-environments'
$utf8WithoutBom = [System.Text.UTF8Encoding]::new($false)

function New-RandomValue([int] $length = 32) {
    $bytes = [byte[]]::new($length)
    $generator = [System.Security.Cryptography.RandomNumberGenerator]::Create()

    try {
        $generator.GetBytes($bytes)
    } finally {
        $generator.Dispose()
    }

    return [Convert]::ToBase64String($bytes)
}

function Set-EnvironmentValue([string] $name, [string] $value) {
    $content = [IO.File]::ReadAllText($environmentFile)
    $pattern = "(?m)^$([regex]::Escape($name))=.*$"
    if (-not [regex]::IsMatch($content, $pattern)) {
        throw "Missing $name in $environmentFile"
    }

    $updated = [regex]::Replace($content, $pattern, "$name=$value")
    [IO.File]::WriteAllText($environmentFile, $updated, $utf8WithoutBom)
}

if (-not (Test-Path $environmentFile)) {
    Copy-Item $template $environmentFile
}

$content = [IO.File]::ReadAllText($environmentFile)
$generated = [ordered]@{
    POSTGRES_ADMIN_PASSWORD = New-RandomValue
    POSTGRES_APP_PASSWORD = New-RandomValue
    PRODUCTION_APP_KEY = "base64:$(New-RandomValue)"
    STAGING_APP_KEY = "base64:$(New-RandomValue)"
}

foreach ($entry in $generated.GetEnumerator()) {
    if ($content -match "(?m)^$([regex]::Escape($entry.Key))=CHANGE_ME") {
        Set-EnvironmentValue $entry.Key $entry.Value
    }
}

Write-Host 'Local production and staging environment values are ready.'
Write-Host 'No generated credentials were printed.'
