# Restart Case Workspace (the email system) after a reboot, a Docker crash or a move to
# another network. Run it from Restart.cmd (double-click), or:
#   powershell -ExecutionPolicy Bypass -File scripts\restart.ps1 [-IP 192.168.2.50] [-Build]
#
#   1. Docker: if it does not answer, Docker Desktop is closed, WSL (its Linux part) restarted
#      and Docker Desktop started again, then this waits until it is ready.
#   2. The network address: this PC's address now (scripts\lan-ip.ps1), or the one given with
#      -IP. An old IP in ECW_BASE_URL / ECW_HTTPS_HOSTS (.env) becomes the new one; a name
#      there (cases.office.lan, a Tailscale name) is left as it is.
#   3. The app starts (with HTTPS when it is set up), -Build also rebuilds it first.
#   4. It is asked whether it answers, and the addresses to open are shown.
# Data, mailboxes and passwords are never touched; only the address lines of .env change.
param(
    [string]$IP = '',
    [switch]$Build
)
$ErrorActionPreference = 'Stop'
$root = Split-Path $PSScriptRoot -Parent
Set-Location $root
$envPath = Join-Path $root '.env'
if (-not (Test-Path $envPath)) { throw 'No .env here: this is a new installation. Run Start.ps1 first.' }

# Steps with their percentage, a bar while one runs (scripts/progress.ps1).
. (Join-Path $PSScriptRoot 'progress.ps1')
Start-Steps 4
function Test-Docker {
    $prev = $ErrorActionPreference; $ErrorActionPreference = 'SilentlyContinue'
    docker info --format '{{.ServerVersion}}' 2>$null | Out-Null
    $ok = ($LASTEXITCODE -eq 0)
    $ErrorActionPreference = $prev
    return $ok
}
function Get-EnvValue([string]$text, [string]$key) {
    if ($text -match "(?m)^$key=(.*)$") { return $Matches[1].Trim().Trim('"', "'") }
    return ''
}
function Set-EnvValue([string]$text, [string]$key, [string]$value) {
    if ($text -match "(?m)^$key=") { return [regex]::Replace($text, "(?m)^$key=.*$", "$key=$value") }
    if ($text -ne '' -and -not $text.EndsWith("`n")) { $text += "`n" }
    return $text + "$key=$value`n"
}

# ---- 1. Docker ---------------------------------------------------------------------------
Step 'Docker'
if (Test-Docker) {
    Write-Host 'Docker is running.'
} else {
    Write-Host 'Docker does not answer: restarting Docker Desktop and WSL (about 1-3 minutes)...'
    Get-Process 'Docker Desktop', 'com.docker.backend', 'com.docker.build' -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue
    wsl --shutdown
    Start-Sleep -Seconds 5
    $exe = @("$env:LOCALAPPDATA\Programs\DockerDesktop\Docker Desktop.exe", "$env:ProgramFiles\Docker\Docker\Docker Desktop.exe") | Where-Object { Test-Path $_ } | Select-Object -First 1
    if (-not $exe) { throw 'Docker Desktop is not installed where expected. Start it by hand, then run this again.' }
    Start-Process $exe
    $deadline = (Get-Date).AddMinutes(5)
    while (-not (Test-Docker)) {
        if ((Get-Date) -gt $deadline) { throw 'Docker Desktop did not start within 5 minutes. Restart the PC, then run this again.' }
        Start-Sleep -Seconds 5
        $waited = [int](300 - ($deadline - (Get-Date)).TotalSeconds)
        Write-Progress -Id 2 -ParentId 1 -Activity 'Waiting for Docker Desktop' -Status "$waited s (it gives up after 5 minutes)" -PercentComplete ([math]::Min(100, [int]($waited / 3)))
        if ($waited % 30 -lt 5) { Write-Host "   still starting... $waited s" }
    }
    Write-Progress -Id 2 -Activity 'Waiting for Docker Desktop' -Completed
    Write-Host 'Docker is running again.'
}

# ---- 2. Network address ------------------------------------------------------------------
Step 'Network address'
$text = [System.IO.File]::ReadAllText($envPath)
$oldIp = Get-EnvValue $text 'ECW_LAN_IP'
if ($IP -ne '') {
    $parsed = $null
    if (-not [System.Net.IPAddress]::TryParse($IP, [ref]$parsed) -or $parsed.AddressFamily -ne 'InterNetwork') { throw "Not an IPv4 address: $IP" }
    $text = Set-EnvValue $text 'ECW_LAN_IP' $IP
    [System.IO.File]::WriteAllText($envPath, $text, (New-Object System.Text.UTF8Encoding $false))
} else {
    & (Join-Path $PSScriptRoot 'lan-ip.ps1')
    $text = [System.IO.File]::ReadAllText($envPath)
}
$newIp = Get-EnvValue $text 'ECW_LAN_IP'
if ($oldIp -ne '' -and $newIp -ne '' -and $oldIp -ne $newIp) {
    # The old address in the links people open (and in the HTTPS certificate names) follows.
    foreach ($key in 'ECW_BASE_URL', 'ECW_HTTPS_HOSTS') {
        $value = Get-EnvValue $text $key
        if ($value -match [regex]::Escape($oldIp)) {
            $quoted = $text -match "(?m)^$key=`""
            $value = $value -replace [regex]::Escape($oldIp), $newIp
            $text = Set-EnvValue $text $key $(if ($quoted) { "`"$value`"" } else { $value })
            Write-Host "$key now uses $newIp"
        }
    }
    [System.IO.File]::WriteAllText($envPath, $text, (New-Object System.Text.UTF8Encoding $false))
}
if ($newIp -ne '') { Write-Host "This PC's network address: $newIp" } else { Write-Host 'No office network address found (only this PC and its public address).' }

# ---- 3. Start ----------------------------------------------------------------------------
Step 'Starting the email system (a minute or two)'
$composeArgs = @('compose')
# HTTPS is on when it was set up (scripts\https-setup.ps1 fills ECW_PROXY_SECRET).
if ((Get-EnvValue $text 'ECW_PROXY_SECRET') -ne '') { $composeArgs += @('--profile', 'https') }
$composeArgs += @('up', '-d', '--wait', '--wait-timeout', '400')
if ($Build) { $composeArgs += '--build' }
& docker @composeArgs
if ($LASTEXITCODE -ne 0) {
    Write-Host 'The first start failed; trying once more (the database sometimes needs longer after a crash)...' -ForegroundColor Yellow
    Start-Sleep -Seconds 10
    & docker @composeArgs
    if ($LASTEXITCODE -ne 0) { throw 'The email system did not start. Run: docker compose logs web sync db' }
}

# ---- 4. Check ----------------------------------------------------------------------------
Step 'Checking that it answers and reaches Microsoft'
$port = Get-EnvValue $text 'ECW_HTTP_PORT'; if ($port -eq '') { $port = '8080' }
$local = "http://localhost:$port/other_things/web/"
$ok = $false
for ($i = 0; $i -lt 20 -and -not $ok; $i++) {
    try { $ok = ((Invoke-WebRequest -Uri ($local + 'login.php') -UseBasicParsing -TimeoutSec 10).StatusCode -eq 200) } catch { Start-Sleep -Seconds 3 }
}
if (-not $ok) { throw "It started but does not answer at $local. Run: docker compose logs web" }
# Can it reach Microsoft? An antivirus that scans HTTPS (Avast / AVG Web Shield, Kaspersky...)
# puts its own certificate in between: Windows trusts it, the app does not, and every mailbox
# call fails with "SSL certificate problem: unable to get local issuer certificate".
$prev = $ErrorActionPreference; $ErrorActionPreference = 'SilentlyContinue'
docker compose exec -T web curl -sS -o /dev/null --max-time 15 https://graph.microsoft.com/v1.0/ 2>$null
$tlsOk = ($LASTEXITCODE -eq 0)
$issuer = ''
if (-not $tlsOk) { $issuer = (docker compose exec -T web sh -c 'echo | openssl s_client -connect graph.microsoft.com:443 -servername graph.microsoft.com 2>/dev/null | grep -m1 "i:"') -join ' ' }
$ErrorActionPreference = $prev
if (-not $tlsOk) {
    Write-Host ''
    Write-Host 'WARNING: the email system cannot reach Microsoft securely: mail sync will not work.' -ForegroundColor Yellow
    if ($issuer -match 'Avast|AVG') {
        Write-Host '  Avast / AVG is scanning the secure connections. In Avast: Menu > Settings > Protection >' -ForegroundColor Yellow
        Write-Host '  Core Shields > Web Shield: untick "Enable HTTPS scanning", or under Exceptions add' -ForegroundColor Yellow
        Write-Host '  https://graph.microsoft.com/*  https://login.microsoftonline.com/*  https://*.googleapis.com/*' -ForegroundColor Yellow
    } elseif ($issuer -ne '') {
        Write-Host "  Something in between signs the connection: $issuer" -ForegroundColor Yellow
        Write-Host '  Usually an antivirus that scans HTTPS: turn that off for this PC, or exclude graph.microsoft.com.' -ForegroundColor Yellow
    } else {
        Write-Host '  No connection to the internet from Docker. Check the network, then run this again.' -ForegroundColor Yellow
    }
    Write-Host '  Then run Restart.cmd again.' -ForegroundColor Yellow
}
Complete-Steps 'The email system is running.'
Write-Host "  On this PC:          $local"
$bind = Get-EnvValue $text 'ECW_BIND_ADDRESS'
if ($newIp -ne '' -and $bind -eq '0.0.0.0') { Write-Host "  On the office network: http://${newIp}:$port/other_things/web/" }
$base = Get-EnvValue $text 'ECW_BASE_URL'
if ($base -ne '') { Write-Host "  Address in emailed links: $base" }
