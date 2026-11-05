# TMC CampusLF Mobile

## Connecting to the local Laravel API

The Android emulator uses `http://10.0.2.2:8000/api/v1` by default. A physical phone cannot reach that emulator-only host. For a USB-connected phone, bridge the local API port with ADB and override the URL:

```powershell
cd C:\xampp\htdocs\campuslfproject\mobile
adb reverse tcp:8000 tcp:8000
flutter run -d <device-id> --dart-define=CAMPUSLF_API_URL=http://127.0.0.1:8000/api/v1
```

Start the Laravel API on the computer at port 8000 before launching the app. From the repository root, `.\scripts\run-flutter-android.ps1 -DeviceId <device-id>` performs the API check, ADB port bridge, and app launch for a connected phone or emulator. Local HTTP traffic is permitted only for Android debug builds; release builds continue to block cleartext traffic.
