import 'package:dio/dio.dart';

import '../core/api_client.dart';
import '../core/secure_token_store.dart';

class AuthService {
  AuthService(this._api, this._tokens);

  final ApiClient _api;
  final SecureTokenStore _tokens;

  Future<String?> readToken() => _tokens.read();

  Future<String> login(String email, String password) async {
    final response = await _api.dio.post<Map<String, dynamic>>(
      '/login',
      data: {'email': email.trim(), 'password': password},
    );
    final token = response.data?['token'];
    if (token is! String || token.isEmpty) {
      throw StateError('The server did not return an access token.');
    }
    await _tokens.save(token);
    return response.data?['user']?['first_name'] as String? ?? 'TMC member';
  }

  Future<String> register({required Map<String, dynamic> details}) async {
    final response = await _api.dio.post<Map<String, dynamic>>(
      '/register',
      data: {...details, 'terms_accepted': true},
    );
    return response.data?['message'] as String? ??
        'A verification code was sent to your TMC email.';
  }

  Future<String> verifyOtp(String email, String code) async {
    final response = await _api.dio.post<Map<String, dynamic>>(
      '/verify-otp',
      data: {'email': email.trim(), 'code': code},
    );
    return response.data?['message'] as String? ?? 'Email verified.';
  }

  Future<String> resendOtp(String email) async {
    final response = await _api.dio.post<Map<String, dynamic>>(
      '/resend-otp',
      data: {'email': email.trim()},
    );
    return response.data?['message'] as String? ??
        'A verification code was sent to your TMC email.';
  }

  Future<String> requestPasswordReset(String email) async {
    final response = await _api.dio.post<Map<String, dynamic>>(
      '/forgot-password',
      data: {'email': email.trim()},
    );
    return response.data?['message'] as String? ??
        'A password reset code was sent to your Gmail address.';
  }

  Future<String> verifyPasswordResetOtp(String email, String code) async {
    final response = await _api.dio.post<Map<String, dynamic>>(
      '/forgot-password/verify-otp',
      data: {'email': email.trim(), 'code': code},
    );
    final token = response.data?['reset_token'];
    if (token is! String || token.isEmpty) {
      throw StateError('The server did not return a password reset token.');
    }
    return token;
  }

  Future<String> resetPassword({
    required String email,
    required String resetToken,
    required String password,
    required String passwordConfirmation,
  }) async {
    final response = await _api.dio.post<Map<String, dynamic>>(
      '/forgot-password/reset',
      data: {
        'email': email.trim(),
        'reset_token': resetToken,
        'password': password,
        'password_confirmation': passwordConfirmation,
      },
    );
    return response.data?['message'] as String? ??
        'Password changed. You can now sign in.';
  }

  Future<void> logout() async {
    try {
      await _api.dio.post<void>('/logout');
    } on DioException {
      rethrow;
    } finally {
      await _tokens.clear();
    }
  }
}
