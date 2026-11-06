import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:tmc_campuslf_mobile/core/api_client.dart';
import 'package:tmc_campuslf_mobile/screens/campus_home_screen.dart';

void main() {
  testWidgets(
    'rejecting a claim refreshes the inbox without a setState error',
    (tester) async {
      final api = ApiClient(readToken: () async => null);
      final requests = <RequestOptions>[];
      var claimRejected = false;
      api.dio.interceptors.add(
        InterceptorsWrapper(
          onRequest: (options, handler) {
            requests.add(options);
            final isDecision = options.path.endsWith(
              '/claims/17/post-author-decision',
            );
            final data = isDecision
                ? <String, dynamic>{'message': 'Claim rejected.'}
                : options.path.endsWith('/claims/awaiting-post-author') &&
                      !claimRejected
                ? <String, dynamic>{
                    'data': [
                      {
                        'id': 17,
                        'post_id': 9,
                        'item_name': 'Blue bottle',
                        'claimant_first_name': 'Test',
                        'claimant_last_name': 'Claimant',
                        'attempt_no': 1,
                        'answers': ['Blue sticker'],
                        'verification_questions': [
                          {
                            'question': 'What sticker?',
                            'answer': 'Blue sticker',
                          },
                        ],
                        'evidence_images': <String>[],
                      },
                    ],
                    'meta': {'last_page': 1},
                  }
                : <String, dynamic>{
                    'data': <dynamic>[],
                    'meta': {'last_page': 1},
                  };
            if (isDecision) claimRejected = true;
            handler.resolve(
              Response<Map<String, dynamic>>(
                requestOptions: options,
                data: data,
              ),
            );
          },
        ),
      );

      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: InboxScreen(
              api: api,
              initialTab: 3,
              onOpenProfile: (_) async {},
              onOpenPost: (_, {required isOwner, returnInboxTab}) async {},
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('List of claims'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Decline'));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byType(TextField),
        'The answer does not match.',
      );
      await tester.tap(find.text('Submit decision'));
      await tester.pumpAndSettle();

      expect(
        requests.any(
          (request) =>
              request.method == 'PATCH' &&
              request.path.endsWith('/claims/17/post-author-decision') &&
              (request.data as Map<String, dynamic>)['decision'] == 'reject',
        ),
        isTrue,
      );
      expect(tester.takeException(), isNull);
    },
  );

  testWidgets(
    'sending a message after leaving the conversation does not use a disposed controller',
    (tester) async {
      final api = ApiClient(readToken: () async => null);
      RequestInterceptorHandler? pendingHandler;
      RequestOptions? pendingRequest;
      api.dio.interceptors.add(
        InterceptorsWrapper(
          onRequest: (options, handler) {
            if (options.method == 'POST') {
              pendingHandler = handler;
              pendingRequest = options;
              return;
            }
            handler.resolve(
              Response<Map<String, dynamic>>(
                requestOptions: options,
                data: {'data': <dynamic>[]},
              ),
            );
          },
        ),
      );

      await tester.pumpWidget(
        MaterialApp(
          home: Builder(
            builder: (context) => Scaffold(
              body: TextButton(
                onPressed: () => Navigator.of(context).push<void>(
                  MaterialPageRoute<void>(
                    builder: (_) => ConversationScreen(
                      api: api,
                      conversation: {
                        'id': 31,
                        'type': 'direct',
                        'item_name': 'Blue bottle',
                        'other_user_id': 2,
                        'other_first_name': 'Sam',
                      },
                      onOpenProfile: (_) async {},
                    ),
                  ),
                ),
                child: const Text('Open conversation'),
              ),
            ),
          ),
        ),
      );
      await tester.tap(find.text('Open conversation'));
      await tester.pumpAndSettle();
      await tester.enterText(find.byType(TextField), 'Hello');
      await tester.tap(find.byTooltip('Send message'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 100));
      expect(pendingHandler, isNotNull);

      await tester.pageBack();
      await tester.pumpAndSettle();
      pendingHandler!.resolve(Response<void>(requestOptions: pendingRequest!));
      await tester.pumpAndSettle();

      expect(tester.takeException(), isNull);
    },
  );

  testWidgets('approved claims can nudge TMC administrators', (tester) async {
    final api = ApiClient(readToken: () async => null);
    final requests = <RequestOptions>[];
    api.dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) {
          requests.add(options);
          final data = options.path == '/claims/awaiting-post-author'
              ? <String, dynamic>{
                  'data': [
                    {
                      'id': 25,
                      'post_id': 9,
                      'item_name': 'Blue bottle',
                      'claimant_first_name': 'Test',
                      'claimant_last_name': 'Claimant',
                      'attempt_no': 1,
                      'status': 'pending',
                      'author_decision': 'approved',
                      'can_undo_owner_decision': true,
                      'answers': ['Blue sticker'],
                      'verification_questions': [
                        {'question': 'What sticker?', 'answer': 'Blue sticker'},
                      ],
                      'evidence_images': <String>[],
                    },
                  ],
                  'meta': {'last_page': 1},
                }
              : options.path == '/claims/25/admin-conversation'
              ? <String, dynamic>{
                  'id': 31,
                  'type': 'admin',
                  'item_name': 'Admin support · Blue bottle',
                  'other_first_name': 'TMC',
                  'other_last_name': 'administrators',
                  'other_user_id': null,
                }
              : <String, dynamic>{
                  'data': <dynamic>[],
                  'meta': {'last_page': 1},
                };
          handler.resolve(
            Response<Map<String, dynamic>>(requestOptions: options, data: data),
          );
        },
      ),
    );

    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: InboxScreen(
            api: api,
            initialTab: 3,
            onOpenProfile: (_) async {},
            onOpenPost: (_, {required isOwner, returnInboxTab}) async {},
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('List of claims'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Nudge TMC admins'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Cancel'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Nudge TMC admins'));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byType(TextField),
      'Please review this approved claim.',
    );
    await tester.tap(find.text('Open chat'));
    await tester.pumpAndSettle();

    expect(
      requests.any(
        (request) =>
            request.method == 'POST' &&
            request.path == '/claims/25/admin-conversation' &&
            (request.data as Map<String, dynamic>)['body'] ==
                'Please review this approved claim.',
      ),
      isTrue,
    );
    expect(tester.takeException(), isNull);
  });

  testWidgets(
    'requesting a handover reschedule opens and submits after closing details',
    (tester) async {
      final api = ApiClient(readToken: () async => null);
      final requests = <RequestOptions>[];
      api.dio.interceptors.add(
        InterceptorsWrapper(
          onRequest: (options, handler) {
            requests.add(options);
            final data = options.path == '/handovers'
                ? <String, dynamic>{
                    'data': [
                      {
                        'id': 42,
                        'item_name': 'Blue bottle',
                        'type': 'lost',
                        'other_first_name': 'Test',
                        'owner_confirmed_at': '2026-01-01T10:00:00Z',
                        'finder_confirmed_at': '2026-01-01T10:00:00Z',
                        'my_confirmation_at': '2026-01-01T10:00:00Z',
                        'completed': false,
                        'status': 'scheduled',
                        'scheduled_at': '2026-01-03T10:00:00Z',
                        'location': 'TMC campus',
                        'receipt_code': '123456',
                        'receipt_code_role': 'Owner',
                        'reschedule_attempts': 0,
                        'reschedule_limit': 2,
                      },
                    ],
                    'meta': {'last_page': 1},
                  }
                : <String, dynamic>{'message': 'Reschedule request sent.'};
            handler.resolve(
              Response<Map<String, dynamic>>(
                requestOptions: options,
                data: data,
              ),
            );
          },
        ),
      );

      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: InboxScreen(
              api: api,
              initialTab: 2,
              onOpenProfile: (_) async {},
              onOpenPost: (_, {required isOwner, returnInboxTab}) async {},
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Blue bottle'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('Request reschedule'));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.widgetWithText(TextField, 'Requested date'),
        '2099-12-31',
      );
      await tester.enterText(
        find.widgetWithText(TextField, 'Message to TMC administrators'),
        'I cannot attend on the scheduled date.',
      );
      await tester.tap(find.text('Send request'));
      await tester.pumpAndSettle();

      expect(
        requests.any(
          (request) =>
              request.method == 'POST' &&
              request.path == '/handovers/42/reschedule' &&
              (request.data as Map<String, String>)['requested_date'] ==
                  '2099-12-31' &&
              (request.data as Map<String, String>)['reason'] ==
                  'I cannot attend on the scheduled date.',
        ),
        isTrue,
      );
      expect(tester.takeException(), isNull);
    },
  );
}
