# Go back to the version that ran before the last update. In the app folder:
#   .\scripts\rollback.ps1
# Puts back the files kept by update.ps1 (updates\rollback-<version>) and
# rebuilds. Data stays as it is; to also go back in data, restore the backup
# update.ps1 made first: .\scripts\restore.ps1 <backup name>
$ErrorActionPreference = 'Stop'
Set-Location (Split-Path -Parent $PSScriptRoot)
. (Join-Path $PSScriptRoot 'progress.ps1')
$root = (Get-Location).Path
$rb = Get-ChildItem (Join-Path $root 'updates') -Directory -Filter 'rollback-*' -ErrorAction SilentlyContinue | Sort-Object LastWriteTime -Descending | Select-Object -First 1
if (-not $rb) { throw 'There is no earlier version kept (updates\rollback-*).' }
$version = $rb.Name -replace '^rollback-', ''
$current = ([IO.File]::ReadAllText((Join-Path $root 'VERSION'))).Trim()
Write-Host "Going back from $current to $version..."
Start-Steps 3
Step "Putting the files of $version back"
robocopy $rb.FullName $root /E /NFL /NDL /NJH /NJS /NP | Out-Null
if ($LASTEXITCODE -ge 8) { throw 'Copying the files back failed.' }
Step 'Rebuilding and starting it (a few minutes)'
docker compose up --build -d --wait --wait-timeout 600
if ($LASTEXITCODE -ne 0) { throw 'The previous version did not start. Check "docker compose logs web".' }
Step 'Checking that it works'
docker compose exec -T web php bin/health.php --startup
Complete-Steps "Back on $version."
