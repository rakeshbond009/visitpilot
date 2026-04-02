# VisitPilot Build & Rename Script
$timestamp = Get-Date -Format "yyyyMMdd-HHmm"
$apkName = "visitpilot-$timestamp.apk"
$buildPath = "mobile_app\android\app\build\outputs\apk\release\app-release.apk"
$rootPath = ".\"

Write-Output "LOG: [$(Get-Date -Format 'HH:mm:ss')] --- Starting VisitPilot Production Build ---"

Write-Output "LOG: [$(Get-Date -Format 'HH:mm:ss')] Initializing build environment..."
Push-Location "mobile_app/android"

Write-Output "LOG: [$(Get-Date -Format 'HH:mm:ss')] Task 1: Cleaning previous build caches..."
.\gradlew.bat clean

Write-Output "LOG: [$(Get-Date -Format 'HH:mm:ss')] Task 2: Starting compilation for Release APK..."
.\gradlew.bat assembleRelease --no-daemon

Write-Output "LOG: [$(Get-Date -Format 'HH:mm:ss')] Task 3: Build step complete. Checking results..."
Pop-Location

# Step 3: Check if build was successful
if (Test-Path "mobile_app\android\app\build\outputs\apk\release\app-release.apk") {
    Write-Output "LOG: [$(Get-Date -Format 'HH:mm:ss')] Build Successful! Renaming and moving file..."
    $destination = "visitpilot-$timestamp.apk"
    Move-Item -Path "mobile_app\android\app\build\outputs\apk\release\app-release.apk" -Destination "$destination" -Force
    Write-Output "APK is ready at: $destination"
} else {
    Write-Output "CRITICAL ERROR: Build execution failed. Refer to logs above for details."
}
