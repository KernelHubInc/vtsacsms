. (Join-Path $PSScriptRoot 'demo-common.ps1')
$resolvedRoot = [IO.Path]::GetFullPath($script:RepoRoot)
if ($resolvedRoot -ne [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))) {
    throw 'Refusing to reset outside the repository workspace.'
}
Write-Host 'Removing VTSA local demo containers and named volumes...'
Invoke-DemoCompose down --volumes --remove-orphans
& (Join-Path $PSScriptRoot 'demo-up.ps1')
