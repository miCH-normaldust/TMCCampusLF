import 'package:dio/dio.dart';

import 'config.dart';
import 'secure_token_store.dart';

class ApiClient {
  ApiClient({SecureTokenStore? tokenStore, this._onUnauthorized})
      : _tokenStore = tokenStore ?? const SecureTokenStore() {
    dio = Dio(
      BaseOptions(
        baseUrl: AppConfig.apiBaseUrl,
        connectTimeout: const Duration(seconds: 12),
        receiveTimeout: const Duration(seconds: 20),
        headers: const {'Accept': 'application/json'},
      ),
    );
    dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) async {
          final token = await _tokenStore.read();
          if (token != null && token.isNotEmpty) {
            options.headers['Authorization'] = 'Bearer $token';
          }
          handler.next(options);
        },
        onError: (error, handler) async {
          if (error.response?.statusCode == 401) {
            await _tokenStore.clear();
            _onUnauthorized?.call();
          }
          handler.next(error);
        },
      ),
    );
  }

  final SecureTokenStore _tokenStore;
  final void Function()? _onUnauthorized;
  late final Dio dio;

  String errorMessage(Object error) {
    if (error is DioException) {
      final response = error.response?.data;
      if (response is Map<String, dynamic> && response['message'] is String) {
        return response['message'] as String;
      }
      if (response is Map<String, dynamic> && response['errors'] is Map) {
        final errors = response['errors'] as Map;
        for (final value in errors.values) {
          if (value is List && value.isNotEmpty) return value.first.toString();
        }
      }
      if (error.response == null) {
        return 'Could not connect to TMC CampusLF. Check your connection and try again.';
      }
    }
    return 'Something went wrong. Please try again.';
  }
}
