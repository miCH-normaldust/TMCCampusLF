import 'package:flutter_secure_storage/flutter_secure_storage.dart';

class SecureTokenStore {
  const SecureTokenStore();

  static const _storage = FlutterSecureStorage();
  static const _tokenKey = 'campuslf_api_token';
  static const _themeModeKey = 'campuslf_theme_mode';

  Future<String?> read() => _storage.read(key: _tokenKey);

  Future<void> save(String token) =>
      _storage.write(key: _tokenKey, value: token);

  Future<void> clear() => _storage.delete(key: _tokenKey);

  Future<String?> readThemeMode() => _storage.read(key: _themeModeKey);

  Future<void> saveThemeMode(bool dark) =>
      _storage.write(key: _themeModeKey, value: dark ? 'dark' : 'light');
}
