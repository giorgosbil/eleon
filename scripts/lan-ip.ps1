# This PC's address on the network it is on now, into .env as ECW_LAN_IP: invitation and
# password links (Settings -> Users) open it, also after a move to another PC or network.
# Inside Docker the app cannot see it. Run before "docker compose up"; nothing is sent.
$envPath = Join-Path (Split-Path $PSScriptRoot -Parent) '.env'
if (-not (Test-Path $envPath)) { return }
$ip = $null
try {
    $s = New-Object System.Net.Sockets.Socket ([System.Net.Sockets.AddressFamily]::InterNetwork), ([System.Net.Sockets.SocketType]::Dgram), ([System.Net.Sockets.ProtocolType]::Udp)
    $s.Connect('8.8.8.8', 53)
    $ip = $s.LocalEndPoint.Address.ToString()
    $s.Dispose()
} catch { }
if (-not $ip -or $ip -notmatch '^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)') { return }
$text = [System.IO.File]::ReadAllText($envPath)
if ($text -match '(?m)^ECW_LAN_IP=(.*)$' -and $Matches[1].Trim() -eq $ip) { return }
if ($text -match '(?m)^ECW_LAN_IP=') {
    $text = $text -replace '(?m)^ECW_LAN_IP=.*$', "ECW_LAN_IP=$ip"
} else {
    if ($text -ne '' -and -not $text.EndsWith("`n")) { $text += "`n" }
    $text += "ECW_LAN_IP=$ip`n"
}
[System.IO.File]::WriteAllText($envPath, $text, (New-Object System.Text.UTF8Encoding $false))
Write-Host "This PC's network address: $ip (links in invitations open it)"
