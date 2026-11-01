@echo off
rem Update Case Workspace.
rem   Drag an update (ecw-1.2.3.zip, with its .json next to it) onto this file,
rem   or double-click it to get the newest version from the update channel.
rem Backup first, then install, then a check; if the new version does not start,
rem the previous one is put back by itself. Data, .env and mailboxes are never touched.
cd /d "%~dp0"
if "%~1"=="" (
  powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\update.ps1"
) else (
  powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\update.ps1" -Zip "%~1"
)
echo.
pause
