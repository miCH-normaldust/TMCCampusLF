# TMC CampusLF Mobile

## Run on an Android phone over Wi-Fi

You can pair Android Debug Bridge (ADB) over Wi-Fi and run the app without a USB cable. The phone and computer must be on the same local Wi-Fi network. On Android 11 or later:

1. On the phone, enable **Developer options** (tap **Build number** seven times in **Settings → About phone**, if needed).
2. Open **Developer options → Wireless debugging** and turn it on.
3. In Android Studio, choose **Pair Devices Using Wi-Fi** and scan the pairing QR code shown on the phone. Alternatively, use the phone's **Pair device with pairing code** option and `adb pair <phone-ip>:<pairing-port>` from a terminal, entering the temporary code shown on the phone.
4. After pairing, make sure Wireless debugging remains on. Android Studio normally connects automatically. If it does not, use **Wireless debugging → IP address & port** and run the project script with that connection address:

```powershell
cd C:\xampp\htdocs\campuslfproject
.\scripts\run-flutter-android.ps1 -WirelessAddress <phone-ip>:<debugging-port>
```

You can also select an already connected phone with `-DeviceId <adb-device-id>`. ADB's debugging connection and the app's API connection are separate: the app does **not** connect directly to MySQL. The script starts/uses the Laravel API on the computer at port 8000, then `adb reverse` routes the app's local API requests through the wireless ADB connection to that API. Laravel reads and writes the configured database. This local development method uses the local network, not an external Internet database. To connect from a phone without an active ADB debugging session, the server must instead be reachable on the network and the app must use that server's address; do not expose MySQL to the phone or public Internet.

Start the backend separately before launching:

```powershell
cd C:\xampp\htdocs\campuslfproject
.\scripts\start-backend.ps1
```

The wireless ADB pairing step is performed on the phone and is not something the code can enable remotely. Release builds must define `CAMPUSLF_API_URL` as the deployed HTTPS API address. Local HTTP traffic is permitted only for Android debug builds.
