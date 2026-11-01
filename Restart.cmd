@echo off
rem Restart Case Workspace (the email system): after a reboot, a Docker crash or a new network.
rem   Double-click it. Docker is restarted if it does not answer, the network address is found
rem   again, and the app is started and checked.
rem   Another address:  Restart.cmd -IP 192.168.2.50     Rebuild too:  Restart.cmd -Build
cd /d "%~dp0"
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0scripts\restart.ps1" %*
echo.
pause
