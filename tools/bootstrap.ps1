[CmdletBinding()]
param(
    [switch] $Force
)

$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path -Parent $PSScriptRoot
$rootEnvironment = Join-Path $repoRoot '.env'
$rootTemplate = Join-Path $repoRoot '.env.example'
$platformEnvironment = Join-Path $repoRoot 'apps/platform/.env'
$platformTemplate = Join-Path $repoRoot 'apps/platform/.env.example'
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

function Get-EnvironmentValue([string] $path, [string] $name) {
    $content = [IO.File]::ReadAllText($path)
    $match = [regex]::Match($content, "(?m)^$([regex]::Escape($name))=(.*)$")

    if (-not $match.Success) {
        throw "Missing $name in $path"
    }

    return $match.Groups[1].Value.Trim('"')
}

function Set-EnvironmentValue([string] $path, [string] $name, [string] $value) {
    $content = [IO.File]::ReadAllText($path)
    $pattern = "(?m)^$([regex]::Escape($name))=.*$"

    if (-not [regex]::IsMatch($content, $pattern)) {
        $updated = "$($content.TrimEnd())$([Environment]::NewLine)$name=$value$([Environment]::NewLine)"
        [IO.File]::WriteAllText($path, $updated, $utf8WithoutBom)
        return
    }

    $updated = [regex]::Replace($content, $pattern, "$name=$value")
    [IO.File]::WriteAllText($path, $updated, $utf8WithoutBom)
}

if ($Force -or -not (Test-Path $rootEnvironment)) {
    Copy-Item $rootTemplate $rootEnvironment -Force
}

if ($Force -or -not (Test-Path $platformEnvironment)) {
    Copy-Item $platformTemplate $platformEnvironment -Force
}

foreach ($optionalName in @('GOOGLE_MAPS_BROWSER_API_KEY', 'GOOGLE_MAPS_MAP_ID', 'GOOGLE_MAPS_MOBILE_READY')) {
    $rootContent = [IO.File]::ReadAllText($rootEnvironment)
    if (-not [regex]::IsMatch($rootContent, "(?m)^$([regex]::Escape($optionalName))=")) {
        Set-EnvironmentValue $rootEnvironment $optionalName ''
    }
}

$generated = [ordered]@{
    APP_KEY = "base64:$(New-RandomValue)"
    POSTGRES_PASSWORD = New-RandomValue
    MINIO_ROOT_PASSWORD = New-RandomValue
}

foreach ($entry in $generated.GetEnumerator()) {
    $existing = Get-EnvironmentValue $rootEnvironment $entry.Key
    if ($Force -or [string]::IsNullOrWhiteSpace($existing)) {
        Set-EnvironmentValue $rootEnvironment $entry.Key $entry.Value
    }
}

$rootAppKey = Get-EnvironmentValue $rootEnvironment 'APP_KEY'
$postgresDatabase = Get-EnvironmentValue $rootEnvironment 'POSTGRES_DB'
$postgresUser = Get-EnvironmentValue $rootEnvironment 'POSTGRES_USER'
$postgresPassword = Get-EnvironmentValue $rootEnvironment 'POSTGRES_PASSWORD'
$minioUser = Get-EnvironmentValue $rootEnvironment 'MINIO_ROOT_USER'
$minioPassword = Get-EnvironmentValue $rootEnvironment 'MINIO_ROOT_PASSWORD'
$minioBucket = Get-EnvironmentValue $rootEnvironment 'MINIO_BUCKET'
$googleMapsBrowserKey = Get-EnvironmentValue $rootEnvironment 'GOOGLE_MAPS_BROWSER_API_KEY'
$googleMapsMapId = Get-EnvironmentValue $rootEnvironment 'GOOGLE_MAPS_MAP_ID'
$googleMapsMobileReady = Get-EnvironmentValue $rootEnvironment 'GOOGLE_MAPS_MOBILE_READY'

Set-EnvironmentValue $platformEnvironment 'APP_KEY' $rootAppKey
Set-EnvironmentValue $platformEnvironment 'DB_CONNECTION' 'pgsql'
Set-EnvironmentValue $platformEnvironment 'DB_HOST' '127.0.0.1'
Set-EnvironmentValue $platformEnvironment 'DB_DATABASE' $postgresDatabase
Set-EnvironmentValue $platformEnvironment 'DB_USERNAME' $postgresUser
Set-EnvironmentValue $platformEnvironment 'DB_PASSWORD' $postgresPassword
Set-EnvironmentValue $platformEnvironment 'REDIS_HOST' '127.0.0.1'
Set-EnvironmentValue $platformEnvironment 'MAIL_HOST' '127.0.0.1'
Set-EnvironmentValue $platformEnvironment 'AWS_ACCESS_KEY_ID' $minioUser
Set-EnvironmentValue $platformEnvironment 'AWS_SECRET_ACCESS_KEY' $minioPassword
Set-EnvironmentValue $platformEnvironment 'AWS_BUCKET' $minioBucket
Set-EnvironmentValue $platformEnvironment 'AWS_ENDPOINT' 'http://127.0.0.1:9000'
Set-EnvironmentValue $platformEnvironment 'GOOGLE_MAPS_BROWSER_API_KEY' $googleMapsBrowserKey
Set-EnvironmentValue $platformEnvironment 'GOOGLE_MAPS_MAP_ID' $googleMapsMapId
Set-EnvironmentValue $platformEnvironment 'GOOGLE_MAPS_MOBILE_READY' $googleMapsMobileReady

git -C $repoRoot config core.hooksPath githooks

Write-Host 'Local environment files are ready. No credential values were printed.'
Write-Host 'Git now uses the repository githooks directory.'
