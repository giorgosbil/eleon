# Encrypt the database on disk (encryption at rest). Run once, in the project folder:
#   .\scripts\encrypt-at-rest.ps1          turn it on
#   .\scripts\encrypt-at-rest.ps1 -Status  show what is encrypted
#
# What it does: makes a backup, restarts the database with a keyring
# (compose.encryption.yaml, key in its own Docker volume "db_keyring"),
# encrypts every table, and adds COMPOSE_FILE to .env so every later
# "docker compose" command (and the other scripts) keep the keyring loaded.
#
# KEEP THE KEY VOLUME. Without db_keyring the database files cannot be opened.
# The app's own backups are SQL and restore without it.
param([switch]$Status)
$ErrorActionPreference = 'Stop'
Set-Location (Split-Path -Parent $PSScriptRoot)
$files = @('-f', 'compose.yaml', '-f', 'compose.encryption.yaml')
if ($Status) { docker compose @files exec -T web php bin/encrypt_tables.php --status; exit $LASTEXITCODE }

Write-Host '1/4 Backup first...'
docker compose exec -T -u www-data web php bin/backup.php create
if ($LASTEXITCODE -ne 0) { throw 'The backup failed, so nothing was changed.' }

Write-Host '2/4 Restarting the database with the keyring...'
docker compose @files up -d --wait --wait-timeout 300
if ($LASTEXITCODE -ne 0) { throw 'The database did not start with the keyring. Run "docker compose up -d" to go back as before.' }

Write-Host '3/4 Encrypting the tables (big mail tables take a while)...'
docker compose @files exec -T web php bin/encrypt_tables.php
if ($LASTEXITCODE -ne 0) { throw 'Encrypting stopped. Run the script again to continue.' }

Write-Host '4/4 Remembering it in .env (COMPOSE_FILE)...'
$envFile = Join-Path (Get-Location) '.env'
$has = (Test-Path $envFile) -and (Select-String -Path $envFile -Pattern '^COMPOSE_FILE=' -Quiet)
if (-not $has) {
    [IO.File]::AppendAllText($envFile, "`nCOMPOSE_FILE=compose.yaml;compose.encryption.yaml`nCOMPOSE_PATH_SEPARATOR=;`n", (New-Object Text.UTF8Encoding $false))
}
Write-Host ''
Write-Host 'Done. The database is encrypted on disk. Back up the Docker volume "db_keyring" somewhere safe (it holds the key).'