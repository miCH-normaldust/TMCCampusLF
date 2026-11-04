# TMC CampusLF Mobile

## Connecting to the local Laravel API

The Android emulator uses `http://10.0.2.2:8000/api/v1` by default. For a USB-connected Android phone, forward the API port from the phone to the development computer and override the URL:

```powershell
cd C:\xampp\htdocs\campuslfproject\mobile
adb reverse tcp:8000 tcp:8000
flutter run -d <device-id> --dart-define=CAMPUSLF_API_URL=http://127.0.0.1:8000/api/v1
```

Start the Laravel API on the computer at port 8000 before launching the app. Local HTTP traffic is permitted only for Android debug builds; release builds continue to block cleartext traffic.
