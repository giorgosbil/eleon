# Restore a backup. This REPLACES all current data (a safety backup of it is made first).
# Usage (PowerShell, in the project folder):
#   .\scripts\restore.ps1                      -> lists the backups
#   .\scripts\restore.ps1 ecw-backup-20261004-020000-auto.zip
param([string]$Backup)
Set-Location (Split-Path -Parent $PSScriptRoot)
. (Join-Path $PSScriptRoot 'progress.ps1')
if (-not $Backup) {
    docker compose exec -T -u www-data web php bin/backup.php list
    Write-Host "`nRun again with the file name, for example: .\scripts\restore.ps1 <name>.zip"
    exit 0
}
$answer = Read-Host "Replace ALL current data with $Backup ? Type YES to continue"
if ($answer -ne 'YES') { Write-Host 'Cancelled.'; exit 1 }
Start-Steps 3
Step 'Pausing mail sync and the AI agents'
# Nothing may sync or act (AI agents, rules) while the data is replaced.
docker compose stop sync worker | Out-Null
Step 'Saving a safety copy, then bringing in the backup (only what differs is written)'
$code = Invoke-AppProgress 'Restoring' @('compose', 'exec', '-T', '-u', 'www-data', 'web', 'php', 'bin/backup.php', 'restore', $Backup, '--yes', '--progress')
Step 'Restarting the app'
# Restarted so the restored encryption key is used everywhere.
docker compose restart web | Out-Null
docker compose start sync worker | Out-Null
if ($code -eq 0) { Complete-Steps 'Restored.' } else { Write-Host 'The restore failed (see the lines above). The data is as it was.' -ForegroundColor Red }
exit $code
