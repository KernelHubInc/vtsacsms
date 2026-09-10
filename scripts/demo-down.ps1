. (Join-Path $PSScriptRoot 'demo-common.ps1')
Invoke-DemoCompose down --remove-orphans
Write-Host 'Milestone 1 containers stopped. Local volumes were preserved.'
