# TMC CampusLF

TMC CampusLF is a Trinidad Municipal College lost-and-found system. The Laravel application in `web/` provides the shared MySQL-backed API and an administrator-only web portal. The Flutter app in `mobile/` is for student, staff, and instructor accounts.

The project specification is included at [docs/SPEC.md](docs/SPEC.md).

## Requirements

- PHP 8.2 or later with PDO MySQL, OpenSSL, Fileinfo, Mbstring, GD, and ZIP extensions
- Composer 2
- MySQL Server 8 (MySQL Workbench is the management client; it does not replace the server)
- Node.js 20.19+ or 22.12+ and npm
- Flutter SDK 3.13 or later
- Android Studio with an Android emulator for Android development

The project includes `.vscode/settings.json` to add the XAMPP PHP directory to new VS Code integrated terminals. Reopen the terminal after opening the workspace for `php` to be available there.

## Configure the Laravel server

1. Start the MySQL Server service. In MySQL Workbench, create the schema if it does not already exist:

   ```sql
   CREATE DATABASE tmc_campuslf CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

2. Configure `web/.env` to match the MySQL Server host, port, schema, username, and password. Member registrations and masterlist records require a Gmail address ending in `@gmail.com`. Set `TMC_DEFAULT_ADMIN_PASSWORD` to a unique password of at least 12 characters. Keep `.env` private.
3. From the `web/` folder, install PHP and web asset dependencies:

   ```powershell
   cd C:\xampp\htdocs\campuslfproject\web
   composer install
   npm install
   ```

   If this is a fresh setup without an application key, generate it once, then create the schema and default records:

   ```powershell
   php artisan key:generate
   php artisan migrate --seed
   php artisan storage:link
   ```

   The seeder creates the administrator from `TMC_DEFAULT_ADMIN_EMAIL` and `TMC_DEFAULT_ADMIN_PASSWORD`, plus categories, campus areas, meet-up locations, and default settings. For an existing installation, `php artisan migrate --seed` is safe to rerun.

4. Run the API/admin portal and Vite in separate VS Code terminals. Start the API from the project root with the supplied script; it checks that Laravel can reach the configured MySQL database:

   ```powershell
   cd C:\xampp\htdocs\campuslfproject
   .\scripts\start-backend.ps1
   ```

   In another terminal, start Vite from `web/`:

   ```powershell
   cd C:\xampp\htdocs\campuslfproject\web
   npm run dev
   ```

   Or build static assets with `npm run build`; that lets the Blade pages work without the Vite development server. Open `http://127.0.0.1:8000/admin/login`. Local OTP email is written to Laravel's log (`web/storage/logs/laravel.log`) while `MAIL_MAILER=log`.

   For asynchronous matching and scheduled archiving, run these in additional terminals from `web/`:

   ```powershell
   php artisan queue:work
   php artisan schedule:work
   ```

## Run the mobile app

Start the Laravel API first. The Flutter app's default Android-emulator API URL is `http://10.0.2.2:8000/api/v1`. The provided emulator launch script and VS Code debug configuration use `adb reverse` and `http://127.0.0.1:8000/api/v1` instead, which also works with AVDs that do not route to the emulator host alias. Cleartext HTTP is enabled only in the Android debug manifest. From the project root, start the configured emulator and run the debug app:

```powershell
.\scripts\run-flutter-android.ps1
```

The script launches the `Medium_Phone` AVD, waits for Android to finish booting, fetches Flutter dependencies, and runs the app in debug mode. In VS Code, use **Terminal → Run Task → CampusLF: Run Flutter on Android**. Alternatively, start the emulator in Android Studio and run `flutter run --debug` from `mobile/`.

On the first Android build, Gradle may download the Android SDK Platforms 35/36, NDK `28.2.13676358`, and CMake `3.22.1`. Keep the Android SDK licenses accepted and allow those initial downloads to finish before retrying a build.

For a physical phone on the same Wi-Fi, bind the API to all interfaces (the supplied backend script already does this) and override the API URL with the development computer's LAN address:

```powershell
cd C:\xampp\htdocs\campuslfproject\mobile
flutter run --dart-define=CAMPUSLF_API_URL=http://192.168.1.10:8000/api/v1
```

Use HTTPS outside local development. API access tokens are stored with `flutter_secure_storage`. Both applications use the same Laravel API and MySQL Server database; Flutter never connects directly to MySQL.

## Current implementation

The Laravel application includes role-separated administrator and user authentication, TMC-domain registration with CAPTCHA and email OTP, Sanctum tokens, searchable/paginated posts, image re-encoding, encrypted verification answers, claims, sightings, comments/reactions/reports, notifications, conversations, meet-up selection, handovers for both found-item claims and approved lost-item sightings, member feedback, match scoring, and post archiving. The administrator portal includes a collapsible navigation drawer, a monitoring dashboard, a searchable Community Feed, dedicated claim, sighting, handover, report, feedback, user/masterlist, catalog, and meet-up workflows, plus activity and audit views. The Flutter app provides registration/OTP, login, feed/search, post creation and editing, claims, sightings, interactions, notifications, inbox/chat, meet-up selection, QR handover, member feedback, and logout.

This is an initial implementation, not a production-ready deployment. Push/FCM delivery and some specification details (including complete end-to-end coverage, additional account/device security flows, and comprehensive moderation of reported comments/messages) still require implementation and verification.

## Validation

```powershell
cd mobile
flutter analyze
flutter test
```

Run Laravel checks after installing PHP and Composer dependencies:

```powershell
cd C:\xampp\htdocs\campuslfproject\web
php artisan test
php artisan route:list
```

Run `npm run build` from `web/` to verify Blade asset compilation, and `flutter analyze` / `flutter test` from `mobile/` to validate the mobile app.
