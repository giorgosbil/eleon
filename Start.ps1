$ErrorActionPreference = 'Stop'
Set-Location $PSScriptRoot
docker info | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Start Docker Desktop with Linux containers, then run this script again.' }
docker run --rm -v "${PSScriptRoot}:/work" -w /work php:8.4-cli php docker/configure.php
if ($LASTEXITCODE -ne 0) { throw 'Local configuration could not be generated.' }
& "$PSScriptRoot\scripts\lan-ip.ps1"
docker compose up --build -d --wait --wait-timeout 240
if ($LASTEXITCODE -ne 0) { throw 'Startup failed. Run docker compose logs init web sync to inspect the error.' }
$localPort = 8080; $code = ''
foreach ($line in Get-Content .env) {
    if ($line -match '^ECW_HTTP_PORT=(\d+)$') { $localPort = $Matches[1] }
    if ($line -match '^ECW_SETUP_TOKEN=(.*)$') { $code = $Matches[1].Trim().Trim('"', "'") }
}
# The code was printed before the long build too; repeat it here, where people look.
Write-Host ''
Write-Host 'Ready.' -ForegroundColor Green
Write-Host "First-admin setup code: $code" -ForegroundColor Green
Write-Host "The setup page opens by itself: http://localhost:${localPort}/other_things/web/setup.php"
Start-Process "http://localhost:${localPort}/other_things/web/setup.php"
