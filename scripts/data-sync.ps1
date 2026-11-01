# Move the app's DATA between your PCs (the code goes through GitHub: Push.cmd / Pull.cmd).
#
#   .\scripts\data-sync.ps1 -Action Push   on the PC you STOP working on (DataPush.cmd)
#   .\scripts\data-sync.ps1 -Action Pull   on the PC you START working on (DataPull.cmd)
#
# The data is everything in the app's database (mailboxes and their connection, emails,
# cases, notes, deals, settings, users), unsent drafts' files, the business logo, and the
# key that unlocks the mailbox connections. It travels as ONE encrypted file through a
# folder both PCs see (a OneDrive folder by default), never through GitHub.
#
# Pull REPLACES this PC's data with the other PC's. What this PC had is saved first
# (backups\ecw-backup-...-prerestore.zip), so nothing is lost for good.
# Only one PC runs the app at a time: Push stops it here, Pull starts it there.
param([Parameter(Mandatory = $true)][ValidateSet('Push', 'Pull')][string]$Action)

# Windows PowerShell 5.1 turns Docker's progress lines (stderr) into errors: exit codes are checked instead.
$ErrorActionPreference = 'Continue'
$Root = Split-Path -Parent $PSScriptRoot
Set-Location $Root
$EnvFile = Join-Path $Root '.env'
$StateFile = Join-Path $Root 'backups\data-sync-state.json'
$Pc = $env:COMPUTERNAME
Add-Type -AssemblyName System.IO.Compression.FileSystem
. (Join-Path $PSScriptRoot 'progress.ps1')   # steps with their percentage, bars while a step runs

function Say($text, $color = 'Gray') { Write-Host $text -ForegroundColor $color }
function Fail($text) { Write-Host ''; Write-Host $text -ForegroundColor Red; exit 1 }
function Confirm-Yes($question) {
    $a = Read-Host "$question  Type YES (or yes) to continue"
    if ($a.Trim() -ne 'YES') { Say 'Cancelled. Nothing was changed.' 'Yellow'; exit 1 }   # yes / Yes / YES
}

# ------------------------------------------------------------------ .env (this PC only, never in git)
function Get-EnvValue($name) {
    if (-not (Test-Path $EnvFile)) { return $null }
    foreach ($line in [IO.File]::ReadAllLines($EnvFile)) {
        if ($line -match "^\s*$name\s*=\s*(.*)$") {
            $v = $Matches[1].Trim()
            if ($v.Length -ge 2 -and $v.StartsWith("'") -and $v.EndsWith("'")) { $v = $v.Substring(1, $v.Length - 2) }
            if ($v -ne '') { return $v }
        }
    }
    return $null
}
function Set-EnvValue($name, $value, $comment) {
    $lines = New-Object System.Collections.Generic.List[string]
    if (Test-Path $EnvFile) { foreach ($l in [IO.File]::ReadAllLines($EnvFile)) { if ($l -notmatch "^\s*$name\s*=") { $lines.Add($l) } } }
    if ($comment) { $lines.Add("# $comment") }
    $lines.Add("$name=$value")
    # UTF-8 without BOM and LF, as Docker reads it.
    [IO.File]::WriteAllText($EnvFile, ($lines -join "`n") + "`n", (New-Object Text.UTF8Encoding($false)))
}

# ------------------------------------------------------------------ checks and settings
function Assert-Docker {
    docker info *> $null
    if ($LASTEXITCODE -ne 0) { Fail 'Docker is not running. Start Docker Desktop, wait until it says "running", and try again.' }
}

# "Desktop", "Documents", "OneDrive" are those folders of this Windows user; any other name must be a
# full path. Never a folder inside the project: git would send the data to GitHub with the code.
function Resolve-SyncDir([string]$dir) {
    $known = @{ 'desktop' = [Environment]::GetFolderPath('Desktop'); 'documents' = [Environment]::GetFolderPath('MyDocuments'); 'onedrive' = $env:OneDrive }
    $first, $rest = $dir -split '[\\/]', 2
    if ($known.ContainsKey($first.ToLower()) -and $known[$first.ToLower()]) {
        $dir = $known[$first.ToLower()]
        $dir = if ($rest) { Join-Path $dir $rest } else { Join-Path $dir 'CaseWorkspace-Data' }
    }
    if (-not [IO.Path]::IsPathRooted($dir)) { Say 'Please give the full path, like C:\Users\you\OneDrive\CaseWorkspace-Data, or just Desktop / Documents / OneDrive.' 'Yellow'; return $null }
    $full = [IO.Path]::GetFullPath($dir).TrimEnd('\')
    if ($full.StartsWith($Root.TrimEnd('\'), [StringComparison]::OrdinalIgnoreCase)) { Say 'Not inside the app folder: from there the data would be sent to GitHub with the code. Choose another folder.' 'Yellow'; return $null }
    return $full
}

function Ask-SyncDir([string]$why) {
    $suggest = if ($env:OneDrive) { Join-Path $env:OneDrive 'CaseWorkspace-Data' } else { '' }
    Say ''
    if ($why) { Say $why 'Yellow' }
    Say 'Which folder does the data travel through? A folder BOTH PCs see:' 'Cyan'
    Say 'a OneDrive folder (same OneDrive account on both PCs), a network share, or the folder you copied it to (USB).'
    Say 'Type the full path, or just Desktop / Documents / OneDrive (they mean their CaseWorkspace-Data folder).'
    if ($suggest) { Say "Press Enter for: $suggest" }
    while ($true) {
        $answer = Read-Host 'Folder'
        $dir = if ($answer.Trim()) { $answer.Trim().Trim('"') } else { $suggest }
        if (-not $dir) { Fail 'No folder given.' }
        $dir = Resolve-SyncDir $dir
        if ($dir) { break }
    }
    Set-EnvValue 'ECW_DATA_SYNC_DIR' "'$dir'" 'DataPush.cmd / DataPull.cmd: the folder the data travels through between your PCs (scripts/data-sync.ps1).'
    Say "Saved in .env on this PC: $dir" 'Green'
    return $dir
}

function Get-SyncDir {
    $dir = Get-EnvValue 'ECW_DATA_SYNC_DIR'
    # A folder saved earlier is checked again: an older version took "Desktop" as a folder inside the app.
    if ($dir) {
        $checked = Resolve-SyncDir $dir
        if (-not $checked) {
            $dir = Ask-SyncDir "The folder saved earlier ($dir) cannot be used."
        } else {
            # Every time: has the location changed? Enter keeps it; a new one is pasted and remembered.
            $dir = $checked
            Say ''
            Say "Data folder: $dir" 'Cyan'
            while ($true) {
                $answer = Read-Host 'Press Enter if it is still there, or paste the new location'
                if (-not $answer.Trim()) { break }
                $new = Resolve-SyncDir $answer.Trim().Trim('"')
                if (-not $new) { continue }
                $dir = $new
                Set-EnvValue 'ECW_DATA_SYNC_DIR' "'$dir'" 'DataPush.cmd / DataPull.cmd: the folder the data travels through between your PCs (scripts/data-sync.ps1).'
                Say "New location saved on this PC: $dir" 'Green'
                break
            }
        }
    } else {
        $dir = Ask-SyncDir ''
    }
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Force -Path $dir -ErrorAction Stop | Out-Null }
    return $dir
}

# The data in a folder: what latest.json names, or (copied without it) the newest ecw-data file.
function Find-Data([string]$dir) {
    $info = Read-Json (Join-Path $dir 'latest.json')
    if ($info -and (Test-Path (Join-Path $dir $info.file))) { return $info }
    $zip = Get-ChildItem $dir -Filter 'ecw-data-*.zip' -ErrorAction SilentlyContinue | Sort-Object Name -Descending | Select-Object -First 1
    if (-not $zip) { return $info }   # latest.json alone: its file is still on its way (OneDrive)
    $from = '?'; $at = $zip.LastWriteTimeUtc.ToString('s') + 'Z'
    if ($zip.Name -match '^ecw-data-(\d{8})-(\d{6})-(.+)\.zip$') {
        $from = $Matches[3]
        $at = "$($Matches[1].Substring(0,4))-$($Matches[1].Substring(4,2))-$($Matches[1].Substring(6,2))T$($Matches[2].Substring(0,2)):$($Matches[2].Substring(2,2)):$($Matches[2].Substring(4,2))Z"
    }
    return [pscustomobject]@{ file = $zip.Name; from = $from; at = $at; size = $zip.Length }
}

# The data file holds every email and the mailbox key: it is always encrypted (AES-256),
# with a password kept in .env on each PC (the same on both).
function Assert-Password([bool]$secondPc) {
    if (Get-EnvValue 'ECW_BACKUP_PASSWORD') { return $false }
    Say ''
    if ($secondPc) {
        Say 'This PC needs the data password: the SAME one you chose on the other PC with DataPush.' 'Cyan'
    } else {
        Say 'Choose a password for the data file (at least 12 characters).' 'Cyan'
        Say 'The file travels through the cloud and holds all your email, so it is encrypted with it.'
        Say 'You will type the same password once on the other PC. Keep it somewhere safe: without it the file cannot be opened.'
    }
    while ($true) {
        $p1 = [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR((Read-Host 'Password' -AsSecureString)))
        if ($p1.Length -lt 12) { Say 'At least 12 characters, please.' 'Yellow'; continue }
        if ($p1.Contains("'")) { Say 'Please do not use the character  '' .' 'Yellow'; continue }
        $p2 = [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR((Read-Host 'The same again' -AsSecureString)))
        if ($p1 -cne $p2) { Say 'The two do not match. Again:' 'Yellow'; continue }
        break
    }
    Set-EnvValue 'ECW_BACKUP_PASSWORD' "'$p1'" 'Backups and the data moved between your PCs are encrypted with this (the same on both PCs). Never share it.'
    Say 'Saved in .env on this PC (never sent to GitHub).' 'Green'
    return $true   # the app must restart to read it
}

function Start-App {
    Say 'Starting the app (database changes apply by themselves)...'
    docker compose up -d --wait --wait-timeout 600
    if ($LASTEXITCODE -ne 0) { Fail 'The app did not start. Open Docker Desktop and look at the "web" container, or ask Claude.' }
}

# DataPull: only the database and the web part run until the data is in. Mail sync, the
# background worker and Telegram must not act on this PC's old data meanwhile: they started
# cases from new support emails here, and after the data came in those cases were there twice.
function Start-AppForPull {
    Say 'Starting the database (mail sync stays off until the data is in)...'
    docker compose stop sync worker telegram | Out-Null
    docker compose up -d --wait --wait-timeout 600 db web
    if ($LASTEXITCODE -ne 0) { Fail 'The app did not start. Open Docker Desktop and look at the "web" container, or ask Claude.' }
}

function Read-Json($path) { if (Test-Path $path) { return Get-Content $path -Raw | ConvertFrom-Json } return $null }
function Save-LocalState($file, $what) {
    New-Item -ItemType Directory -Force -Path (Split-Path $StateFile) | Out-Null
    @{ file = $file; action = $what; at = (Get-Date).ToUniversalTime().ToString('s') + 'Z'; pc = $Pc } | ConvertTo-Json | Set-Content -Path $StateFile -Encoding ASCII
}
function Read-ZipText($zipPath, $entry) {
    $z = [IO.Compression.ZipFile]::OpenRead($zipPath)
    try { $e = $z.GetEntry($entry); if (-not $e) { return $null }; $r = New-Object IO.StreamReader($e.Open()); try { return $r.ReadToEnd() } finally { $r.Close() } }
    finally { $z.Dispose() }
}

# ------------------------------------------------------------------ the two actions
Assert-Docker
$dir = Get-SyncDir
$sharedFile = Join-Path $dir 'latest.json'
$shared = Read-Json $sharedFile
$local = Read-Json $StateFile

if ($Action -eq 'Push') {
    Say "== Saving this PC's data ($Pc) to: $dir" 'Cyan'
    # The other PC sent data this PC never brought in: pushing now would replace their work.
    if ($shared -and $shared.from -ne $Pc -and (-not $local -or $local.file -ne $shared.file)) {
        Say ''
        Say "Careful: $($shared.from) sent its data on $($shared.at) and this PC never brought it in (DataPull)." 'Yellow'
        Say "Pushing now replaces that copy with this PC's data, so the work done on $($shared.from) would not reach you."
        Say "(It is not deleted on $($shared.from) itself.)"
        Confirm-Yes 'Push anyway?'
    }
    Start-Steps 5
    Step 'Getting the app ready'
    $restart = Assert-Password $false
    if ($restart) { Start-App } else {
        docker compose up -d --wait --wait-timeout 600 | Out-Null
    }
    Step 'Pausing mail sync on this PC (so the copy is one moment in time)'
    # Nothing syncs or acts while the copy is made, so it is one consistent moment.
    docker compose stop sync worker telegram | Out-Null
    Step 'Making the encrypted copy'
    $code = Invoke-AppProgress 'Making the encrypted copy' @('compose', 'exec', '-T', '-u', 'www-data', 'web', 'php', 'bin/backup.php', 'create', '--progress')
    if ($code -ne 0 -or ($script:EcwAppOutput -join "`n") -notmatch 'backups/(ecw-backup-[\w-]+\.zip)') { docker compose start sync worker telegram | Out-Null; Fail 'The copy could not be made (see the lines above).' }
    $name = $Matches[1]
    $made = Join-Path $Root "backups\$name"
    $enc = Read-ZipText $made 'encryption.json' | ConvertFrom-Json
    if (-not $enc.encrypted) { docker compose start sync worker telegram | Out-Null; Remove-Item $made; Fail 'The copy was not encrypted (the app did not read the password). Run DataPush again; if it repeats, ask Claude.' }

    $stamp = (Get-Date).ToUniversalTime().ToString('yyyyMMdd-HHmmss')
    $target = "ecw-data-$stamp-$Pc.zip"
    Step "Copying it to the shared folder ($([math]::Round((Get-Item $made).Length / 1MB)) MB)"
    try {
        Copy-WithProgress $made (Join-Path $dir "$target.part") 'Copying to the shared folder'
        Move-Item (Join-Path $dir "$target.part") (Join-Path $dir $target) -Force -ErrorAction Stop
    } catch { docker compose start sync worker telegram | Out-Null; Fail "Could not copy to $dir : $($_.Exception.Message)" }
    Remove-Item $made   # the shared folder has it; the daily backups stay as they are
    Step 'Finishing'
    $code = (git rev-parse --short HEAD 2>$null)
    [ordered]@{ file = $target; from = $Pc; at = (Get-Date).ToUniversalTime().ToString('s') + 'Z'; size = (Get-Item (Join-Path $dir $target)).Length; code = "$code" } |
        ConvertTo-Json | Set-Content -Path $sharedFile -Encoding ASCII
    # Keep the newest three in the shared folder.
    Get-ChildItem $dir -Filter 'ecw-data-*.zip' | Sort-Object Name -Descending | Select-Object -Skip 3 | Remove-Item -Force
    Save-LocalState $target 'pushed'

    Complete-Steps "Saved: $target"
    if ($dir -like '*OneDrive*') { Say 'Leave this PC on until OneDrive shows the folder as up to date (green tick), so the file reaches the other PC.' 'Yellow' }
    elseif (-not $dir.StartsWith('\\')) {
        Say "The other PC cannot see this folder by itself: copy the folder $dir to it (USB stick, network)," 'Yellow'
        Say 'and on that PC give its place there when DataPull asks (or use a OneDrive folder on both PCs).' 'Yellow'
    }
    Say ''
    $stop = Read-Host 'Stop the app on this PC now, so only the other PC syncs mail? (Y/n)'
    if ($stop -match '^[nN]') { docker compose start sync worker telegram | Out-Null; Say 'The app keeps running here. Push again before you switch PCs.' 'Yellow' }
    else { docker compose stop | Out-Null; Say 'The app is stopped on this PC. On the other PC: Pull.cmd (code), then DataPull.cmd (data).' 'Green' }
    exit 0
}

# ---------------------------------------------------------------- Pull
$shared = Find-Data $dir
while (-not $shared) {
    # Nothing here: say exactly where it looked and what is there, and let the person point at the right folder.
    Say ''
    Say "No data found in: $dir" 'Yellow'
    $there = @(Get-ChildItem $dir -ErrorAction SilentlyContinue | Select-Object -First 8 | ForEach-Object { $_.Name })
    Say $(if ($there.Count) { 'That folder has: ' + ($there -join ', ') } else { 'That folder is empty.' })
    Say 'The data is a file named ecw-data-....zip (with latest.json beside it), made by DataPush.cmd on the other PC.'
    $again = Read-Host 'Type the folder it is in (or press Enter to stop)'
    if (-not $again.Trim()) { Fail 'Nothing was changed. Run DataPush.cmd on the other PC first, or copy its CaseWorkspace-Data folder to this PC.' }
    $resolved = Resolve-SyncDir $again.Trim().Trim('"')
    if (-not $resolved) { continue }
    if (-not (Test-Path $resolved)) { Say "That folder does not exist: $resolved" 'Yellow'; continue }
    $dir = $resolved
    Set-EnvValue 'ECW_DATA_SYNC_DIR' "'$dir'" 'DataPush.cmd / DataPull.cmd: the folder the data travels through between your PCs (scripts/data-sync.ps1).'
    $shared = Find-Data $dir
}
Say "== Bringing the data from: $dir" 'Cyan'
$src = Join-Path $dir $shared.file
if (-not (Test-Path $src) -or (Get-Item $src).Length -ne [long]$shared.size) {
    Fail "The data file from $($shared.from) is not complete here yet ($($shared.file)). If the folder is in OneDrive, wait until it has finished downloading, then run DataPull again."
}
if ($local -and $local.file -eq $shared.file) {
    Say "This PC already has that data ($($shared.from), $($shared.at))." 'Green'
    Start-App
    exit 0
}
# Data from newer code than this PC has: bring the code first.
$manifest = Read-ZipText $src 'manifest.json' | ConvertFrom-Json
# The code's migrations: the core's, and each module's (modules\<name>\migrations).
$known = @{}
Get-ChildItem (Join-Path $Root 'database\migrations') -Filter '*.sql' -ErrorAction SilentlyContinue | ForEach-Object { $known[$_.Name] = $true }
Get-ChildItem (Join-Path $Root 'modules') -Directory -ErrorAction SilentlyContinue | ForEach-Object {
    Get-ChildItem (Join-Path $_.FullName 'migrations') -Filter '*.sql' -ErrorAction SilentlyContinue | ForEach-Object { $known[$_.Name] = $true }
}
$missing = @($manifest.migrations | Where-Object { -not $known.ContainsKey($_) })
if ($missing.Count -gt 0) { Fail "That data comes from newer code than this PC has (for example $($missing[0])). Run Pull.cmd first, then DataPull.cmd again." }

Say ''
Say "Data from $($shared.from), saved $($shared.at) (UTC)." 'White'
Say "It REPLACES everything the app has on this PC ($Pc): emails, cases, notes, deals, settings." 'Yellow'
if ($local -and $local.action -eq 'pulled') { Say "Anything you did on this PC after your last DataPull ($($local.at)) and did not DataPush is replaced too." 'Yellow' }
Confirm-Yes 'Bring it in?'
# The safety copy of what this PC has is a whole backup (minutes on a big mailbox). Not needed
# when nothing was done here since this PC last sent or brought in its data.
Say ''
Say "Keep a safety copy of what this PC has now (backups\...-prerestore.zip), so it can be brought back?"
Say 'Recommended if you worked on this PC since your last DataPush. It adds a few minutes.'
$keep = Read-Host 'Safety copy first? (Y/n)'
$safety = -not ($keep -match '^[nN]')

Start-Steps 4
Step 'Getting the app ready'
$restart = Assert-Password $true
Start-AppForPull
Step "Copying the data file in ($([math]::Round((Get-Item $src).Length / 1MB)) MB)"
try { Copy-WithProgress $src (Join-Path $Root "backups\$($shared.file)") 'Copying the data file' } catch { Fail "Could not read $src : $($_.Exception.Message)" }
# Nothing may sync or act while the data is replaced (Start-AppForPull kept them off).
Step $(if ($safety) { 'Saving a safety copy, then bringing in the data (only what differs is written)' } else { 'Bringing in the data (only what differs is written)' })
$restoreArgs = @('compose', 'exec', '-T', '-u', 'www-data', 'web', 'php', 'bin/backup.php', 'restore', "/opt/ecw/backups/$($shared.file)", '--yes', '--progress')
if (-not $safety) { $restoreArgs += '--no-safety' }
$code = Invoke-AppProgress 'Bringing in the data' $restoreArgs
Remove-Item (Join-Path $Root "backups\$($shared.file)") -ErrorAction SilentlyContinue
if ($code -ne 0) {
    docker compose start sync worker telegram | Out-Null
    Fail 'The data could not be brought in (wrong password?). This PC still has its own data; nothing was replaced.'
}
Step 'Applying database changes and restarting the app'
# The restored key and the database changes of this code version: restart everything.
docker compose exec -T -u www-data web php bin/migrate.php | Out-Null
docker compose restart web | Out-Null
# Now mail sync and the rest start, on the data just brought in.
docker compose up -d --wait --wait-timeout 600 | Out-Null
Save-LocalState $shared.file 'pulled'
Complete-Steps "This PC now has the data of $($shared.from) from $($shared.at)."
Say 'Log in again if the app asks. Your mailboxes stay connected and catch up with new email by themselves.'
exit 0
