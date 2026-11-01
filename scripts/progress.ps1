# Progress shown the same way by every script, so it is clear nothing is stuck:
#   == Step 3 of 5 (40%): Making the encrypted copy
#      [ 35%] Saving the database: email_messages (24,000 of about 79,000 rows)
# A bar (Write-Progress) moves while a long step runs, and a line is printed every few percent,
# so a plain window shows it moving too. In a script:  . "$PSScriptRoot\progress.ps1"
#   Start-Steps 5            how many steps there are
#   Step 'What happens now'  the next one, with its percentage of the whole
#   Invoke-AppProgress 'Making the copy' @('compose','exec','-T','web','php','bin/backup.php','create','--progress')
#                            a command in the app that prints "@@ <percent> <what>" (bin/backup.php)
#   Copy-WithProgress $from $to 'Copying the data file'
#   Complete-Steps 'Done.'

$script:EcwSteps = 1
$script:EcwStep = 0

function Start-Steps([int]$count) { $script:EcwSteps = [math]::Max(1, $count); $script:EcwStep = 0 }

function Step([string]$text) {
    $script:EcwStep++
    $pct = [int][math]::Floor(($script:EcwStep - 1) * 100 / $script:EcwSteps)
    Write-Host ''
    Write-Host ('== Step {0} of {1} ({2}%): {3}' -f $script:EcwStep, $script:EcwSteps, $pct, $text) -ForegroundColor Cyan
    Write-Progress -Id 1 -Activity 'Case Workspace' -Status ('Step {0} of {1}: {2}' -f $script:EcwStep, $script:EcwSteps, $text) -PercentComplete $pct
}

function Complete-Steps([string]$text = 'Done.') {
    Write-Progress -Id 2 -Activity 'Case Workspace' -Completed
    Write-Progress -Id 1 -Activity 'Case Workspace' -Completed
    Write-Host ''
    Write-Host ('== 100%: ' + $text) -ForegroundColor Green
}

# A command whose "@@ <percent> <what>" lines are its progress (php bin/backup.php ... --progress).
# Other lines are shown as they come, and kept in $script:EcwAppOutput (the result the command
# printed, e.g. "Backup created: backups/<name>"). @return the command's exit code
$script:EcwAppOutput = @()
function Invoke-AppProgress([string]$activity, [string[]]$dockerArgs) {
    $last = -100.0; $lastPhase = ''
    $script:EcwAppOutput = New-Object System.Collections.Generic.List[string]
    $prev = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    & docker @dockerArgs 2>&1 | ForEach-Object {
        $line = "$_"
        if ($line -match '^@@ ([\d.]+) (.*)$') {
            $p = [math]::Min(100.0, [double]::Parse($Matches[1], [Globalization.CultureInfo]::InvariantCulture))
            $what = $Matches[2]
            Write-Progress -Id 2 -ParentId 1 -Activity $activity -Status ('{0:N0}% - {1}' -f $p, $what) -PercentComplete ([int]$p)
            $phase = ($what -split ':')[0]
            if ($p - $last -ge 5 -or $phase -ne $lastPhase -or $p -ge 100) {
                $last = $p; $lastPhase = $phase
                Write-Host ('   [{0,3:N0}%] {1}' -f $p, $what)
            }
        } elseif ($line.Trim() -ne '' -and $line -notmatch '^\s*Container ') {
            $script:EcwAppOutput.Add($line)
            Write-Host "   $line"
        }
    }
    $code = $LASTEXITCODE
    $ErrorActionPreference = $prev
    Write-Progress -Id 2 -Activity $activity -Completed
    return $code
}

# A file copied in 4 MB pieces, with its percentage and MB (a OneDrive file downloads as it is read).
function Copy-WithProgress([string]$from, [string]$to, [string]$what) {
    $total = [math]::Max(1, (Get-Item -LiteralPath $from).Length)
    $in = [IO.File]::OpenRead($from)
    $out = [IO.File]::Create($to)
    try {
        $buf = New-Object byte[] (4MB)
        $done = 0L; $last = -100
        while (($n = $in.Read($buf, 0, $buf.Length)) -gt 0) {
            $out.Write($buf, 0, $n)
            $done += $n
            $p = [int][math]::Floor($done * 100 / $total)
            Write-Progress -Id 2 -ParentId 1 -Activity $what -Status ('{0}% - {1:N0} of {2:N0} MB' -f $p, ($done / 1MB), ($total / 1MB)) -PercentComplete $p
            if ($p - $last -ge 10 -or $done -ge $total) { $last = $p; Write-Host ('   [{0,3}%] {1}: {2:N0} of {3:N0} MB' -f $p, $what, ($done / 1MB), ($total / 1MB)) }
        }
    } finally {
        $in.Close(); $out.Close()
        Write-Progress -Id 2 -Activity $what -Completed
    }
}
