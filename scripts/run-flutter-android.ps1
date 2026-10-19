$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$mobileRoot = Join-Path $projectRoot 'mobile'
$adb = Join-Path $env:LOCALAPPDATA 'Android\Sdk\platform-tools\adb.exe'

if (-not (Test-Path $adb)) {
    throw "Android Debug Bridge was not found at $adb. Install Android SDK Platform-Tools."
}

Set-Location $projectRoot
$deviceMatch = & $adb devices | Select-String -Pattern '^(emulator-\d+)\s+device$' |
    Select-Object -First 1
$device = if ($deviceMatch) { $deviceMatch.Matches[0].Groups[1].Value } else { '' }
if (-not $device) {
    flutter emulators --launch Medium_Phone
    if ($LASTEXITCODE -ne 0) {
        throw 'Could not start the Medium_Phone emulator. Start an Android emulator in Android Studio and retry.'
    }
}

$deadline = (Get-Date).AddMinutes(5)
while ((Get-Date) -lt $deadline -and -not $device) {
    $deviceMatch = & $adb devices | Select-String -Pattern '^(emulator-\d+)\s+device$' |
        Select-Object -First 1
    if ($deviceMatch) {
        $device = $deviceMatch.Matches[0].Groups[1].Value
    }
    if (-not $device) {
        Start-Sleep -Seconds 2
    }
}

if (-not $device) {
    throw 'The emulator did not register with ADB within three minutes.'
}

$deadline = (Get-Date).AddMinutes(10)
while ((Get-Date) -lt $deadline) {
    $bootCompleted = (& $adb -s $device shell getprop sys.boot_completed 2>$null | Out-String).Trim()
    if ($bootCompleted -eq '1') {
        break
    }
    Start-Sleep -Seconds 2
}

if ($bootCompleted -ne '1') {
    throw 'The Android emulator did not finish booting within three minutes.'
}

try {
    $null = Invoke-RestMethod -Uri 'http://127.0.0.1:8000/api/v1/catalog' -TimeoutSec 5
} catch {
    throw 'The Laravel API is not responding at http://127.0.0.1:8000. Start it with scripts/start-backend.ps1 before running Flutter.'
}

& $adb -s $device reverse tcp:8000 tcp:8000
if ($LASTEXITCODE -ne 0) {
    throw "Could not forward the Laravel API port to Android device $device."
}

Set-Location $mobileRoot
flutter pub get
if ($LASTEXITCODE -ne 0) {
    throw 'Flutter package resolution failed.'
}

flutter run --debug -d $device --dart-define=CAMPUSLF_API_URL=http://127.0.0.1:8000/api/v1
exit $LASTEXITCODE
