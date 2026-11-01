# Make a backup now. The file appears in the "backups" folder next to this project.
# Usage (PowerShell, in the project folder):  .\scripts\backup.ps1
Set-Location (Split-Path -Parent $PSScriptRoot)
. (Join-Path $PSScriptRoot 'progress.ps1')
Start-Steps 1
Step 'Making a backup'
$code = Invoke-AppProgress 'Making a backup' @('compose', 'exec', '-T', '-u', 'www-data', 'web', 'php', 'bin/backup.php', 'create', '--progress')
if ($code -ne 0) { Write-Host 'The backup failed (see the lines above).' -ForegroundColor Red; exit $code }
Complete-Steps (($script:EcwAppOutput | Select-Object -Last 1) -as [string])
docker compose exec -T -u www-data web php bin/backup.php list
