# VisitPilot Build & Rename Script
$timestamp = Get-Date -Format "yyyyMMdd-HHmm"
$apkName = "visitpilot-$timestamp.apk"
$buildPath = "mobile_app\android\app\build\outputs\apk\release\app-release.apk"
$rootPath = ".\"

Write-Host "--- Starting VisitPilot Production Build ---" -ForegroundColor Cyan

# 1. Enter android directory and build
Push-Location mobile_app\android
.\gradlew.bat assembleRelease
Pop-Location

# 2. Check if build was successful
if (Test-Path $buildPath) {
    Write-Host "Build Successful! Moving to root as $apkName..." -ForegroundColor Green
    Move-Item -Path $buildPath -Destination "$rootPath$apkName" -Force
    Write-Host "--- APK is ready at: $rootPath$apkName ---" -ForegroundColor Yellow
} else {
    Write-Host "CRITICAL ERROR: Build failed. Check the error logs above." -ForegroundColor Red
}
