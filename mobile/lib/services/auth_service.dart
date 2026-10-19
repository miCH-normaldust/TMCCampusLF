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

  Future<Map<String, String>> captcha() async {
    final response = await _api.dio.get<Map<String, dynamic>>('/captcha');
    return {
      'question': response.data?['question'] as String? ?? '',
      'token': response.data?['token'] as String? ?? '',
    };
  }

  Future<String> register({
    required Map<String, dynamic> details,
    required String captchaToken,
    required String captchaAnswer,
  }) async {
    final response = await _api.dio.post<Map<String, dynamic>>(
      '/register',
      data: {
        ...details,
        'terms_accepted': true,
        'captcha_token': captchaToken,
        'captcha_answer': captchaAnswer,
      },
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
