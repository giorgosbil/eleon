param([ValidateSet('original','eleon')][string]$Brand, [switch]$NoRestart)
$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$envPath = Join-Path $projectRoot '.env'
if (-not (Test-Path -LiteralPath $envPath)) { throw 'Run Start.ps1 first to create this PC''s .env.' }
if (-not $Brand) {
    Write-Host '1. Original: penguin + Case Workspace'
    Write-Host '2. Eleon: Eleon icon + Eleon'
    $choice = Read-Host 'Choose 1 or 2'
    if ($choice -eq '1') { $Brand = 'original' }
    elseif ($choice -eq '2') { $Brand = 'eleon' }
    else { throw 'Choose 1 or 2. Nothing was changed.' }
}
$text = [IO.File]::ReadAllText($envPath)
if ($text -match '(?m)^ECW_PRODUCT_BRAND=.*$') {
    $text = [regex]::Replace($text, '(?m)^ECW_PRODUCT_BRAND=[^\r\n]*', "ECW_PRODUCT_BRAND=$Brand")
} else { $text = $text.TrimEnd() + "`nECW_PRODUCT_BRAND=$Brand`n" }
[IO.File]::WriteAllText($envPath, $text, (New-Object Text.UTF8Encoding($false)))
Write-Host "This PC uses $Brand. The choice stays local through code pulls and data transfers."
if (-not $NoRestart) {
    Push-Location $projectRoot
    try {
        docker compose up -d --no-deps web sync worker telegram
        if ($LASTEXITCODE -ne 0) { throw 'Brand saved, but the application containers did not restart.' }
    } finally { Pop-Location }
}
