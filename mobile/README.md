# TMC CampusLF Mobile

## Connecting to the local Laravel API

Android debug builds use `http://127.0.0.1:18000/api/v1`, with ADB forwarding device port 18000 to the computer's API port 8000. Using a separate device port avoids conflicts with services already bound to port 8000 on some phones. The Android Gradle build automatically runs `adb reverse tcp:18000 tcp:8000` for connected, authorized devices, so this works with Android Studio's Run button as well as `flutter run`. Connect the phone with USB debugging enabled and accept its authorization prompt.

```powershell
cd C:\xampp\htdocs\campuslfproject
.\scripts\start-backend.ps1
```

Start the Laravel API on the computer at port 8000 before launching the app. For the integrated API check and launch flow, `.\scripts\run-flutter-android.ps1 -DeviceId <device-id>` is also available. Release builds must define `CAMPUSLF_API_URL` as the deployed HTTPS API address. Local HTTP traffic is permitted only for Android debug builds.
