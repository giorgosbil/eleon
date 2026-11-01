# Turn on HTTPS for the office network (optional).
#
# Usage (PowerShell, in the project folder):
#   .\scripts\https-setup.ps1                         -> localhost + this PC's network address(es)
#   .\scripts\https-setup.ps1 "localhost, cases.office.lan, 192.168.2.10"
#
# It writes ECW_HTTPS_HOSTS and ECW_PROXY_SECRET to .env, starts the HTTPS proxy
# (Caddy, port 8443), saves its root certificate as caddy-root.crt and trusts it
# on THIS computer. Install caddy-root.crt on other computers/phones once so
# their browsers trust the address too.
param([string]$Hosts)
$ErrorActionPreference = 'Stop'
Set-Location (Split-Path -Parent $PSScriptRoot)

if (-not $Hosts) {
    $ips = Get-NetIPAddress -AddressFamily IPv4 | Where-Object { $_.PrefixOrigin -in 'Dhcp', 'Manual' -and $_.IPAddress -notlike '169.254.*' -and $_.InterfaceAlias -notlike 'vEthernet*' } | Select-Object -ExpandProperty IPAddress
    $Hosts = (@('localhost') + $ips) -join ', '
}

$envFile = '.env'
$text = [IO.File]::ReadAllText($envFile)
function Set-EnvValue([string]$name, [string]$value) {
    $line = "$name=$value"
    if ($script:text -match "(?m)^$name=.*$") { $script:text = [regex]::Replace($script:text, "(?m)^$name=.*$", [Text.RegularExpressions.MatchEvaluator]{ param($m) $line }) }
    else { $script:text = $script:text.TrimEnd() + "`n$line`n" }
}
Set-EnvValue 'ECW_HTTPS_HOSTS' ('"' + $Hosts + '"')
if ($text -notmatch '(?m)^ECW_PROXY_SECRET=.+$') {
    $bytes = New-Object byte[] 24; [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    Set-EnvValue 'ECW_PROXY_SECRET' ([BitConverter]::ToString($bytes).Replace('-', '').ToLower())
}
[IO.File]::WriteAllText($envFile, $text, (New-Object Text.UTF8Encoding $false))

Write-Host "HTTPS names: $Hosts"
docker compose --profile https up -d
docker compose up -d web sync   # pick up ECW_PROXY_SECRET

# The local certificate authority is created when the first certificate is issued.
$tries = 0
do { Start-Sleep -Seconds 2; docker compose cp https:/data/caddy/pki/authorities/local/root.crt ./caddy-root.crt 2>$null; $tries++ } while (-not (Test-Path ./caddy-root.crt) -and $tries -lt 15)
if (Test-Path ./caddy-root.crt) {
    Import-Certificate -FilePath ./caddy-root.crt -CertStoreLocation Cert:\CurrentUser\Root | Out-Null
    Write-Host 'Trusted the certificate on this computer (caddy-root.crt). Install the same file on other computers and phones.'
} else {
    Write-Host 'Could not fetch the root certificate yet. Open https://localhost:8443 once, then run this script again.'
}
$port = if ($text -match '(?m)^ECW_HTTPS_PORT=(\d+)') { $Matches[1] } else { '8443' }
foreach ($h in $Hosts.Split(',')) { Write-Host ("  https://{0}:{1}/other_things/web/" -f $h.Trim(), $port) }
Write-Host 'For other devices on the network, ECW_BIND_ADDRESS=0.0.0.0 must be set in .env (and port 8443 allowed in the firewall).'
