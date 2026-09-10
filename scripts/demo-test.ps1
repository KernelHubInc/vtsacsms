. (Join-Path $PSScriptRoot 'demo-common.ps1')
Invoke-DemoCompose exec -T platform vendor/bin/pint --test
Invoke-DemoCompose exec -T platform vendor/bin/phpstan analyse --memory-limit=1G
Invoke-DemoCompose exec -T -e APP_ENV=testing -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: -e DB_URL= platform vendor/bin/phpunit --do-not-cache-result
Invoke-DemoCompose build flutter-web
Invoke-DemoCompose --profile quality run --rm --no-deps flutter-check
Write-Host 'Platform and Flutter formatting, static analysis, tests, and builds passed.'
