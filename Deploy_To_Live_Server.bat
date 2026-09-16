@echo off
color 0A
echo ========================================================
echo   Eco Quality - Live Server Deployment
echo ========================================================
echo.
echo Connecting to Oracle Cloud and pulling from GitHub...
echo.

ssh -i "%USERPROFILE%\.ssh\oracle_key.key" -o StrictHostKeyChecking=no ubuntu@168.138.165.221 "./update.sh"

echo.
echo ========================================================
echo   Deployment Complete! Your live website is updated.
echo ========================================================
echo.
pause
