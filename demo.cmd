@echo off
rem Interview demo: demo <scenario>   (run "demo" alone for the list)
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0demo.ps1" %*
