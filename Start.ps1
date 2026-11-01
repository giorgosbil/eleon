$ErrorActionPreference = 'Stop'
Set-Location $PSScriptRoot
if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    throw 'Install Docker Desktop first, open it from Start, and wait for Engine running. See INSTALL.md.'
}
# Report setup steps instead of Docker's raw named-pipe error.
$dockerPreference = $ErrorActionPreference
try {
    $ErrorActionPreference = 'Continue'
    $engineType = docker info --format '{{.OSType}}' 2>$null
    $engineExit = $LASTEXITCODE
} finally { $ErrorActionPreference = $dockerPreference }
if ($engineExit -ne 0) {
    throw 'Docker is not ready. Inside this computer or VM, open Docker Desktop and wait for Engine running. Follow any WSL update/restart prompt, then run docker info. In a Hyper-V VM, check nested virtualization on the main PC (INSTALL.md). Run Start.ps1 again after docker info succeeds.'
}
if (($engineType -join '').Trim() -ne 'linux') {
    throw 'Eleon needs Linux containers. In the Docker Desktop tray menu, choose Switch to Linux containers, wait for Engine running, then run Start.ps1 again.'
}
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
