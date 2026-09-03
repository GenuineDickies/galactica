@echo off
title White Knight Roadside - setup
cd /d "%~dp0"

if not exist "C:\xampp\php\php.exe" (
    echo Could not find PHP at C:\xampp\php\php.exe
    echo Install XAMPP first, or edit this file to point at your php.exe.
    pause
    exit /b 1
)

"C:\xampp\php\php.exe" data\setup.php

echo.
pause
