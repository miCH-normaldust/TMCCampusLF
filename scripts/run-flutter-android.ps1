param(
    [string]$DeviceId,
    [string]$WirelessAddress
)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path -Parent $PSScriptRoot
$mobileRoot = Join-Path $projectRoot 'mobile'
$adb = Join-Path $env:LOCALAPPDATA 'Android\Sdk\platform-tools\adb.exe'

if (-not (Test-Path $adb)) {
    throw "Android Debug Bridge was not found at $adb. Install Android SDK Platform-Tools."
}

Set-Location $projectRoot
if ($WirelessAddress) {
    & $adb connect $WirelessAddress
    if ($LASTEXITCODE -ne 0) {
        throw "Could not connect to wireless Android device at $WirelessAddress. Pair it from Android Studio or Android Wireless debugging first."
    }
    if (-not $DeviceId) {
        $DeviceId = $WirelessAddress
    }
}
$deviceRows = & $adb devices | Select-String -Pattern '^\S+\s+device$'
$availableDevices = @($deviceRows | ForEach-Object { ($_ -split '\s+')[0] })
if ($DeviceId) {
    if ($availableDevices -notcontains $DeviceId) {
        throw "Android device $DeviceId is not connected or authorized."
    }
    $device = $DeviceId
} elseif ($availableDevices.Count -eq 1) {
    $device = $availableDevices[0]
} elseif ($availableDevices.Count -gt 1) {
    $physicalDevices = @($availableDevices | Where-Object { $_ -notmatch '^emulator-\d+$' })
    if ($physicalDevices.Count -eq 1) {
        $device = $physicalDevices[0]
    } else {
        throw "More than one Android device is connected. Run this script with -DeviceId and choose one of: $($availableDevices -join ', ')."
    }
} else {
    $device = ''
}
if (-not $device) {
    flutter emulators --launch Medium_Phone
    if ($LASTEXITCODE -ne 0) {
        throw 'Could not start the Medium_Phone emulator. Start an Android emulator in Android Studio and retry.'
    }
}

$deadline = (Get-Date).AddMinutes(5)
while ((Get-Date) -lt $deadline -and -not $device) {
    $deviceRows = & $adb devices | Select-String -Pattern '^\S+\s+device$'
    $availableDevices = @($deviceRows | ForEach-Object { ($_ -split '\s+')[0] })
    if ($availableDevices.Count -eq 1) {
        $device = $availableDevices[0]
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
    throw "Android device $device did not finish booting within the allowed time."
}

try {
    $null = Invoke-RestMethod -Uri 'http://127.0.0.1:8000/api/v1/catalog' -TimeoutSec 5
} catch {
    throw 'The Laravel API is not responding at http://127.0.0.1:8000. Start it with scripts/start-backend.ps1 before running Flutter.'
}

& $adb -s $device reverse tcp:18000 tcp:8000
if ($LASTEXITCODE -ne 0) {
    throw "Could not forward the Laravel API port to Android device $device."
}

Set-Location $mobileRoot
flutter pub get
if ($LASTEXITCODE -ne 0) {
    throw 'Flutter package resolution failed.'
}

flutter run --debug -d $device --dart-define=CAMPUSLF_API_URL=http://127.0.0.1:18000/api/v1
exit $LASTEXITCODE
