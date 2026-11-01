@echo off
cd /d "%~dp0"
powershell -NoProfile -File "%~dp0scripts\branding.ps1"
pause
