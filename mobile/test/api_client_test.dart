import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:tmc_campuslf_mobile/core/api_client.dart';

void main() {
  test('sends the saved token as a bearer authorization header', () async {
    final api = ApiClient(readToken: () async => 'test-access-token');
    String? authorization;
    api.dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) {
          authorization = options.headers['Authorization'] as String?;
          handler.reject(DioException(requestOptions: options));
        },
      ),
    );

    await expectLater(
      api.dio.get<void>('/posts'),
      throwsA(isA<DioException>()),
    );

    expect(authorization, 'Bearer test-access-token');
  });

  test(
    'shows the specific profile validation error before the generic message',
    () {
      final api = ApiClient(readToken: () async => null);
      final error = DioException(
        requestOptions: RequestOptions(path: '/profile'),
        response: Response<Map<String, dynamic>>(
          requestOptions: RequestOptions(path: '/profile'),
          statusCode: 422,
          data: {
            'message': 'The given data was invalid.',
            'errors': {
              'contact_email': [
                'The contact email must be a valid email address.',
              ],
            },
          },
        ),
      );

      expect(
        api.errorMessage(error),
        'The contact email must be a valid email address.',
      );
    },
  );
}
