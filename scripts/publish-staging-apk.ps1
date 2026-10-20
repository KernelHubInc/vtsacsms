[CmdletBinding()]
param(
    [Parameter(Mandatory)]
    [ValidatePattern('\A[0-9]+\.[0-9]+\.[0-9]+\z')]
    [string]$Version,
    [switch]$Publish
)

$ErrorActionPreference = 'Stop'
$fileName = "Power-Solutions-Staging-Lumina-$Version.apk"
$projectRoot = Split-Path -Parent $PSScriptRoot
$apk = Join-Path $projectRoot ".cache/mobile-staging-lumina/$fileName"
$checksumFile = "$apk.sha256"

if (!(Test-Path -LiteralPath $apk) -or !(Test-Path -LiteralPath $checksumFile)) {
    throw 'The packaged LUMINA staging APK and its SHA256 file are required.'
}

$expected = ((Get-Content -LiteralPath $checksumFile -Raw).Trim() -split '\s+')[0].ToLowerInvariant()
$actual = (Get-FileHash -LiteralPath $apk -Algorithm SHA256).Hash.ToLowerInvariant()
if ($expected -notmatch '\A[0-9a-f]{64}\z' -or $actual -ne $expected) {
    throw 'APK checksum mismatch. Do not publish this file.'
}

Write-Output "Verified $fileName ($((Get-Item -LiteralPath $apk).Length) bytes)"
Write-Output "SHA256: $actual"
if (!$Publish) {
    Write-Output 'Local verification only. Add -Publish to upload to staging.'
    exit 0
}

foreach ($command in @('scp', 'ssh')) {
    if (!(Get-Command $command -ErrorAction SilentlyContinue)) {
        throw "Install the Windows OpenSSH client; $command is unavailable."
    }
}

$server = 'root@187.53.134.122'
$incoming = "/root/$fileName"
$destination = "/var/www/staging-apk/$fileName"
& scp $apk "${server}:$incoming"
if ($LASTEXITCODE -ne 0) { throw 'APK upload failed.' }

# Publish a complete, verified file with an atomic rename inside the served directory.
$publishCommand = "printf '%s  %s\n' '$actual' '$incoming' | sha256sum -c - && if test -e '$destination'; then printf '%s  %s\n' '$actual' '$destination' | sha256sum -c -; else install -m 0644 '$incoming' '$destination.part' && mv '$destination.part' '$destination'; fi"
& ssh $server $publishCommand
if ($LASTEXITCODE -ne 0) { throw 'Remote checksum verification or publication failed.' }

$url = "https://staging.evcspowersolutions.com/demo/$fileName"
$response = Invoke-WebRequest -Uri $url -Method Head -UseBasicParsing
if ($response.StatusCode -ne 200 -or [long]$response.Headers['Content-Length'] -ne (Get-Item -LiteralPath $apk).Length) {
    throw 'Published download did not return HTTP 200 with the expected file size.'
}
Write-Output "Published: $url"

# Advance the stable link only after the versioned download is verified.
$demoName = 'Power-Solutions-Demo.apk'
$demoPath = "/var/www/staging-apk/$demoName"
& ssh $server "ln -sfn '$fileName' '$demoPath.next' && mv -Tf '$demoPath.next' '$demoPath'"
if ($LASTEXITCODE -ne 0) { throw 'Demo download link update failed.' }
$demoUrl = "https://staging.evcspowersolutions.com/demo/$demoName"
$demoResponse = Invoke-WebRequest -Uri $demoUrl -Method Head -UseBasicParsing
if ($demoResponse.StatusCode -ne 200 -or [long]$demoResponse.Headers['Content-Length'] -ne (Get-Item -LiteralPath $apk).Length) {
    throw 'Demo download verification failed.'
}
Write-Output "Demo (latest): $demoUrl"
