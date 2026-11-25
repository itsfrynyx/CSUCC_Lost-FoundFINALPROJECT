@echo off
echo ========================================
echo  Campus Lost & Found - ngrok
echo ========================================
echo.

REM Check if ngrok exists in current directory
if not exist "ngrok.exe" (
    echo ERROR: ngrok.exe not found in current directory!
    echo.
    echo Please:
    echo 1. Download ngrok from https://ngrok.com/download
    echo 2. Extract ngrok.exe to this folder
    echo 3. Run this script again
    echo.
    pause
    exit /b
)

echo Checking if Apache is running...
echo.

REM Ask user for port
echo What port is your bookstore running on?
echo Common options:
echo   80  - Default (http://localhost/bookstore/)
echo   8080 - Alternative port
echo   3000 - Some development servers
echo.
set /p PORT="Enter port number (default: 80): "

REM Default to 80 if no input
if "%PORT%"=="" set PORT=80

echo.
echo Starting ngrok tunnel on port %PORT%...
echo.
echo ========================================
echo  Your bookstore will be accessible at:
echo  https://[random].ngrok-free.app/bookstore/php/Sign_in.php
echo ========================================
echo.
echo IMPORTANT:
echo - Keep this window open while testing
echo - Press Ctrl+C to stop ngrok
echo - The URL changes each time you restart
echo - Click "Visit Site" on the warning page
echo.
echo Starting in 3 seconds...
timeout /t 3 /nobreak >nul

REM Start ngrok
ngrok http %PORT%

pause

