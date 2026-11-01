# Update Email Case Workspace, safely. In the app folder (PowerShell):
#   .\scripts\update.ps1                      the newest version from the update channel
#   .\scripts\update.ps1 -Zip ecw-1.2.0.zip   a release you downloaded (its .json next to it)
#
# 1. checks the release is signed by the vendor and unchanged
# 2. makes a backup
# 3. keeps the current version as a way back (updates\rollback-<version>)
# 4. installs, rebuilds and checks that the app starts and works
# 5. if it does not: puts the previous version back by itself
# Your .env, data, backups and mailbox connections are never touched.
param([string]$Zip = '', [switch]$AllowSame)
$ErrorActionPreference = 'Stop'
Set-Location (Split-Path -Parent $PSScriptRoot)
$root = (Get-Location).Path
$dir = Join-Path $root 'updates'
New-Item -ItemType Directory -Force $dir | Out-Null
$utf8 = New-Object Text.UTF8Encoding $false
$img = (docker compose config --images | Where-Object { $_ -notmatch 'mysql|caddy' } | Select-Object -First 1)
if (-not $img) { $img = 'email-case-workspace:local' }
$rbImg = ($img -replace ':[^:/]+$', '') + ':rollback'
# Steps with their percentage, a bar while one runs (scripts/progress.ps1).
. (Join-Path $PSScriptRoot 'progress.ps1')
Start-Steps $(if ($Zip -eq '') { 6 } else { 5 })

# ---------------------------------------------------------------- 1. the release
if ($Zip -eq '') {
    Step 'Asking the update channel for the newest version'
    $url = (docker compose exec -T web php -r "require 'lib/updates.php'; echo ecw_update_url();").Trim()
    if (-not $url) { throw 'No update channel is set (Settings -> System health, or ECW_UPDATE_URL). Or pass -Zip with a release you downloaded.' }
    [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
    $feedRaw = (Invoke-WebRequest -UseBasicParsing $url).Content
    $feed = $feedRaw | ConvertFrom-Json
    $zipUrl = if ($feed.zip -match '^https://') { $feed.zip } else { ($url -replace '[^/]+$', '') + $feed.zip }
    $Zip = Join-Path $dir (Split-Path $zipUrl -Leaf)
    Write-Host "Downloading $zipUrl"
    Invoke-WebRequest -UseBasicParsing $zipUrl -OutFile $Zip
    [IO.File]::WriteAllText([IO.Path]::ChangeExtension($Zip, '.json'), $feedRaw, $utf8)
}
$Zip = (Resolve-Path $Zip).Path
$json = [IO.Path]::ChangeExtension($Zip, '.json')
if (-not (Test-Path $json)) { throw "The signature file $(Split-Path $json -Leaf) must be next to the zip." }
if ((Split-Path $Zip -Parent) -ne $dir) { Copy-Item $Zip $dir -Force; Copy-Item $json $dir -Force }
$zipName = Split-Path $Zip -Leaf; $jsonName = Split-Path $json -Leaf

Step 'Checking the signature'
$verifyArgs = @('compose', 'run', '--rm', '--no-deps', '-T', '-v', "${dir}:/opt/ecw/updates:ro", 'web', 'php', 'bin/update_verify.php', "/opt/ecw/updates/$zipName", "/opt/ecw/updates/$jsonName")
if ($AllowSame) { $verifyArgs += '--allow-same' }
$new = (& docker @verifyArgs | Select-Object -Last 1)
if ($LASTEXITCODE -ne 0) { throw 'The release did not pass the check (see above). Nothing was changed.' }
$new = "$new".Trim()
if ($new -notmatch '^\d+\.\d+\.\d+$') { throw 'The release did not pass the check (see above). Nothing was changed.' }
$current = ([IO.File]::ReadAllText((Join-Path $root 'VERSION'))).Trim()
Write-Host "Signed release $new (running $current)."

# ---------------------------------------------------------------- 2. backup
Step 'Backing up'
$code = Invoke-AppProgress 'Backing up' @('compose', 'exec', '-T', '-u', 'www-data', 'web', 'php', 'bin/backup.php', 'create', '--progress')
if ($code -ne 0) { throw 'The backup failed, so nothing was changed.' }
$backup = ($script:EcwAppOutput | Select-Object -Last 1) -as [string]

# ---------------------------------------------------------------- 3. a way back
Step "Keeping $current as the way back"
$rb = Join-Path $dir "rollback-$current"
$excludeDirs = @('updates', 'backups', 'backups-offsite', 'storage', 'cache', 'logs', 'node_modules', '.git', 'exports', 'local-export', 'dist') | ForEach-Object { Join-Path $root $_ }
robocopy $root $rb /MIR /XD $excludeDirs /XF '.env' /NFL /NDL /NJH /NJS /NP | Out-Null
if ($LASTEXITCODE -ge 8) { throw 'Could not copy the current version aside. Nothing was changed.' }
docker tag $img $rbImg | Out-Null

# ---------------------------------------------------------------- 4. install
Step "Installing $new (unpacking, then rebuilding: a few minutes)"
Add-Type -AssemblyName System.IO.Compression.FileSystem
$entries = [IO.Compression.ZipFile]::OpenRead($Zip)
$newFiles = $entries.Entries | Where-Object { $_.Name -ne '' } | ForEach-Object { $_.FullName.Replace('/', '\') }
$entries.Dispose()
Expand-Archive -Path $Zip -DestinationPath $root -Force
if (Test-Path "$PSScriptRoot\lan-ip.ps1") { & "$PSScriptRoot\lan-ip.ps1" }
docker compose up --build -d --wait --wait-timeout 600
$ok = ($LASTEXITCODE -eq 0)
if ($ok) {
    Step 'Checking that it works'
    docker compose exec -T web php bin/health.php --startup
    $ok = ($LASTEXITCODE -eq 0)
}

if ($ok) {
    Get-ChildItem $dir -Directory -Filter 'rollback-*' | Sort-Object LastWriteTime -Descending | Select-Object -Skip 2 | Remove-Item -Recurse -Force -Confirm:$false
    Complete-Steps "Updated to $new."
    Write-Host "If something is wrong: .\scripts\rollback.ps1 (puts $current back)."
    exit 0
}

# ---------------------------------------------------------------- 5. roll back
Write-Warning "Version $new did not start properly. Putting $current back..."
foreach ($f in $newFiles) {
    $target = Join-Path $root $f
    if (-not (Test-Path (Join-Path $rb $f)) -and (Test-Path $target) -and $f -notlike '.env*') { Remove-Item $target -Force -Confirm:$false }
}
robocopy $rb $root /E /NFL /NDL /NJH /NJS /NP | Out-Null
docker tag $rbImg $img | Out-Null
docker compose up -d --wait --wait-timeout 600
if ($LASTEXITCODE -ne 0) { docker compose up --build -d --wait --wait-timeout 600 }
Write-Host ''
Write-Host "Back on $current." -ForegroundColor Yellow
Write-Host "The update may already have added things to the database (only additions; $current ignores them)."
Write-Host "If anything still misbehaves, restore the backup made just before: .\scripts\restore.ps1 <its name above>"
exit 1
