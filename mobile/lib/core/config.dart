import 'package:flutter/foundation.dart'
    show defaultTargetPlatform, kDebugMode, kIsWeb, TargetPlatform;

class AppConfig {
  static const _configuredApiBaseUrl = String.fromEnvironment(
    'CAMPUSLF_API_URL',
  );

  static String get apiBaseUrl {
    if (_configuredApiBaseUrl.isNotEmpty) return _configuredApiBaseUrl;
    if (kIsWeb) {
      return 'http://127.0.0.1:8000/api/v1';
    }
    if (defaultTargetPlatform == TargetPlatform.android && kDebugMode) {
      return 'http://127.0.0.1:18000/api/v1';
    }

    return 'http://10.0.2.2:8000/api/v1';
  }
}
