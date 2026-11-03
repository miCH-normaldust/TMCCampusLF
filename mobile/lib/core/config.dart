import 'package:flutter/foundation.dart' show kIsWeb;

class AppConfig {
  static const apiBaseUrl = String.fromEnvironment(
    'CAMPUSLF_API_URL',
    defaultValue: kIsWeb
        ? 'http://127.0.0.1:8000/api/v1'
        : 'http://10.0.2.2:8000/api/v1',
  );
}
