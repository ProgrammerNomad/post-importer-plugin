# Banner Image URL Update - PowerShell Launcher
# This script provides an easy way to run the banner image update workflow

param(
    [Parameter(Mandatory=$true)]
    [string]$WordPressUrl,
    
    [Parameter(Mandatory=$true)]
    [string]$ApiKey,
    
    [Parameter(Mandatory=$true)]
    [string]$JsonFile,
    
    [Parameter(Mandatory=$false)]
    [ValidateSet("update", "import")]
    [string]$Method = "update",
    
    [Parameter(Mandatory=$false)]
    [int]$Concurrent = 10
)

Write-Host "🚀 Banner Image URL Update Workflow" -ForegroundColor Cyan
Write-Host "=" * 50 -ForegroundColor Gray
Write-Host ""

# Check if JSON file exists
if (-not (Test-Path $JsonFile)) {
    Write-Host "❌ JSON file not found: $JsonFile" -ForegroundColor Red
    exit 1
}

# Display configuration
Write-Host "Configuration:" -ForegroundColor Yellow
Write-Host "  WordPress URL: $WordPressUrl" -ForegroundColor White
Write-Host "  JSON File: $JsonFile" -ForegroundColor White
Write-Host "  Method: $Method" -ForegroundColor White
Write-Host "  Concurrent: $Concurrent" -ForegroundColor White
Write-Host ""

# Get current directory
$currentDir = Get-Location

Write-Host "📍 Working Directory: $currentDir" -ForegroundColor Green
Write-Host ""

# Confirm before proceeding
$confirm = Read-Host "Do you want to proceed? (y/N)"
if ($confirm -ne "y" -and $confirm -ne "Y") {
    Write-Host "Operation cancelled by user." -ForegroundColor Yellow
    exit 0
}

Write-Host ""
$startTime = Get-Date

# Step 1: Update JSON URLs
Write-Host "🔄 STEP 1: Updating banner URLs in JSON file" -ForegroundColor Cyan
Write-Host "Command: python update_banner_urls.py $JsonFile" -ForegroundColor Gray
Write-Host "-" * 50 -ForegroundColor Gray

try {
    $result1 = python update_banner_urls.py $JsonFile
    if ($LASTEXITCODE -eq 0) {
        Write-Host "✅ JSON URLs updated successfully" -ForegroundColor Green
        Write-Host $result1
    } else {
        Write-Host "❌ Failed to update JSON URLs" -ForegroundColor Red
        Write-Host $result1
        exit 1
    }
} catch {
    Write-Host "❌ Exception occurred: $($_.Exception.Message)" -ForegroundColor Red
    exit 1
}

Write-Host ""

# Step 2: Update WordPress posts
if ($Method -eq "update") {
    $command2 = "python bulk_update_banners.py $WordPressUrl $ApiKey $JsonFile $Concurrent"
    $step2Description = "STEP 2: Bulk updating WordPress posts with new banner images"
} else {
    $command2 = "python async_api_importer.py $WordPressUrl $ApiKey $JsonFile --force-replace --max-concurrent $Concurrent"
    $step2Description = "STEP 2: Fresh importing all posts with force replace"
}

Write-Host "🔄 $step2Description" -ForegroundColor Cyan
Write-Host "Command: $command2" -ForegroundColor Gray
Write-Host "-" * 50 -ForegroundColor Gray

try {
    $result2 = Invoke-Expression $command2
    if ($LASTEXITCODE -eq 0) {
        Write-Host "✅ WordPress posts updated successfully" -ForegroundColor Green
        Write-Host $result2
    } else {
        Write-Host "❌ Failed to update WordPress posts" -ForegroundColor Red
        Write-Host $result2
        exit 1
    }
} catch {
    Write-Host "❌ Exception occurred: $($_.Exception.Message)" -ForegroundColor Red
    exit 1
}

# Final summary
$endTime = Get-Date
$totalTime = $endTime - $startTime

Write-Host ""
Write-Host "🎉 WORKFLOW COMPLETED SUCCESSFULLY!" -ForegroundColor Green
Write-Host "=" * 60 -ForegroundColor Gray
Write-Host "Total time: $($totalTime.TotalMinutes.ToString('F1')) minutes" -ForegroundColor White
Write-Host "Method used: $Method" -ForegroundColor White
Write-Host ""

Write-Host "What was accomplished:" -ForegroundColor Yellow
Write-Host "✅ Updated all banner URLs (removed resize/filter parameters)" -ForegroundColor Green
Write-Host "✅ Updated/imported all WordPress posts with new images" -ForegroundColor Green
Write-Host "✅ Cleaned up old featured images to save storage space" -ForegroundColor Green
Write-Host "✅ Created backup and log files for audit trail" -ForegroundColor Green
Write-Host ""

Write-Host "Files created:" -ForegroundColor Yellow
$logFiles = @("banner_update_log.txt", "import_log.txt")
foreach ($logFile in $logFiles) {
    if (Test-Path $logFile) {
        Write-Host "📄 $logFile" -ForegroundColor White
    }
}

# Look for backup files
$backupFiles = Get-ChildItem -Filter "posts_backup_*.json" -ErrorAction SilentlyContinue
foreach ($backupFile in $backupFiles) {
    Write-Host "💾 $($backupFile.Name)" -ForegroundColor White
}

Write-Host ""
Write-Host "🔍 Check the log files for detailed results and any failures." -ForegroundColor Cyan
Write-Host "💡 Your WordPress site should now have optimized banner images!" -ForegroundColor Green
