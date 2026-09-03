@echo off
title White Knight Roadside - local server
cd /d "%~dp0"
echo White Knight Roadside is running at http://127.0.0.1:8088
echo Sign in as admin@setup.com / admin123  (temporary setup login)
echo Close this window to stop the server.
echo.
"C:\xampp\php\php.exe" -S 127.0.0.1:8088 -t "%~dp0public"
