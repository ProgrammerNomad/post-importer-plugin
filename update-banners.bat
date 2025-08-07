@echo off
REM Banner Image URL Update - Windows Batch Launcher
REM This provides an easy way to run the banner image update workflow

if "%~3"=="" (
    echo.
    echo 🚀 Banner Image URL Update Workflow
    echo ================================
    echo.
    echo This script will:
    echo   1. Update all banner URLs in your JSON file
    echo   2. Bulk update all WordPress posts with new images  
    echo   3. Clean up old images to save storage space
    echo.
    echo Usage:
    echo   update-banners.bat ^<wordpress_url^> ^<api_key^> ^<json_file^> [method] [concurrent]
    echo.
    echo Methods:
    echo   update  - Update existing posts (default, recommended)
    echo   import  - Fresh import with force replace (faster)
    echo.
    echo Examples:
    echo   update-banners.bat https://climaterural.com/ your-api-key posts.json
    echo   update-banners.bat https://climaterural.com/ your-api-key posts.json update 10
    echo   update-banners.bat https://climaterural.com/ your-api-key posts.json import 20
    echo.
    pause
    exit /b 1
)

set WORDPRESS_URL=%1
set API_KEY=%2
set JSON_FILE=%3
set METHOD=%4
set CONCURRENT=%5

REM Set defaults
if "%METHOD%"=="" set METHOD=update
if "%CONCURRENT%"=="" set CONCURRENT=10

REM Remove quotes if present
set WORDPRESS_URL=%WORDPRESS_URL:"=%
set API_KEY=%API_KEY:"=%
set JSON_FILE=%JSON_FILE:"=%

echo.
echo 🚀 Banner Image URL Update Workflow
echo ==================================
echo WordPress URL: %WORDPRESS_URL%
echo JSON File: %JSON_FILE%
echo Method: %METHOD%
echo Concurrent: %CONCURRENT%
echo.

REM Check if JSON file exists
if not exist "%JSON_FILE%" (
    echo ❌ JSON file not found: %JSON_FILE%
    pause
    exit /b 1
)

echo 📍 Working Directory: %CD%
echo.

set /p CONFIRM="Do you want to proceed? (y/N): "
if /i not "%CONFIRM%"=="y" (
    echo Operation cancelled by user.
    pause
    exit /b 0
)

echo.
echo 🔄 STEP 1: Updating banner URLs in JSON file
echo Command: python update_banner_urls.py %JSON_FILE%
echo --------------------------------------------------

python update_banner_urls.py "%JSON_FILE%"
if %ERRORLEVEL% neq 0 (
    echo ❌ Failed to update JSON URLs
    pause
    exit /b 1
)

echo ✅ JSON URLs updated successfully
echo.

REM Step 2: Update WordPress posts
if /i "%METHOD%"=="update" (
    set COMMAND2=python bulk_update_banners.py "%WORDPRESS_URL%" "%API_KEY%" "%JSON_FILE%" %CONCURRENT%
    set STEP2_DESC=STEP 2: Bulk updating WordPress posts with new banner images
) else (
    set COMMAND2=python async_api_importer.py "%WORDPRESS_URL%" "%API_KEY%" "%JSON_FILE%" --force-replace --max-concurrent %CONCURRENT%
    set STEP2_DESC=STEP 2: Fresh importing all posts with force replace
)

echo 🔄 %STEP2_DESC%
echo Command: %COMMAND2%
echo --------------------------------------------------

%COMMAND2%
if %ERRORLEVEL% neq 0 (
    echo ❌ Failed to update WordPress posts
    pause
    exit /b 1
)

echo ✅ WordPress posts updated successfully
echo.

echo 🎉 WORKFLOW COMPLETED SUCCESSFULLY!
echo ============================================================
echo Method used: %METHOD%
echo.
echo What was accomplished:
echo ✅ Updated all banner URLs (removed resize/filter parameters)
echo ✅ Updated/imported all WordPress posts with new images
echo ✅ Cleaned up old featured images to save storage space
echo ✅ Created backup and log files for audit trail
echo.

echo Files created:
if exist "banner_update_log.txt" echo 📄 banner_update_log.txt
if exist "import_log.txt" echo 📄 import_log.txt

REM Look for backup files
for %%f in (posts_backup_*.json) do echo 💾 %%f

echo.
echo 🔍 Check the log files for detailed results and any failures.
echo 💡 Your WordPress site should now have optimized banner images!
echo.
pause
