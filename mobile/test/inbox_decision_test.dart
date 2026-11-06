import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:tmc_campuslf_mobile/core/api_client.dart';
import 'package:tmc_campuslf_mobile/screens/campus_home_screen.dart';

ApiClient _apiWithNotification(
  String type,
  String title, {
  int? conversationId,
  String? recipientRole,
}) {
  final api = ApiClient(readToken: () async => null);
  api.dio.interceptors.add(
    InterceptorsWrapper(
      onRequest: (options, handler) {
        if (options.path == '/notifications' && options.method == 'GET') {
          handler.resolve(
            Response<Map<String, dynamic>>(
              requestOptions: options,
              data: {
                'data': [
                  {
                    'id': 'notification-1',
                    'type': type,
                    'data': {
                      'title': title,
                      'body': '',
                      'conversation_id': ?conversationId,
                      'recipient_role': ?recipientRole,
                    },
                  },
                ],
              },
            ),
          );
          return;
        }
        if (options.method == 'PATCH') {
          handler.resolve(Response<void>(requestOptions: options));
          return;
        }
        handler.resolve(
          Response<Map<String, dynamic>>(
            requestOptions: options,
            data: {
              'data': <dynamic>[],
              'meta': {'last_page': 1},
            },
          ),
        );
      },
    ),
  );
  return api;
}

void main() {
  testWidgets('unsuccessful handover notification opens handover history', (
    tester,
  ) async {
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: InboxScreen(
            api: _apiWithNotification(
              'handover_unsuccessful',
              'Handover unsuccessful',
            ),
            onOpenProfile: (_) async {},
            onOpenPost: (_, {required isOwner, returnInboxTab}) async {},
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Handover unsuccessful'));
    await tester.pumpAndSettle();

    expect(
      tester
          .widget<ChoiceChip>(find.widgetWithText(ChoiceChip, 'History'))
          .selected,
      isTrue,
    );
    expect(find.text('No handover history yet'), findsOneWidget);
  });

  testWidgets('claim rejection notification opens sent claims', (tester) async {
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: InboxScreen(
            api: _apiWithNotification('claim_rejected', 'Claim not approved'),
            onOpenProfile: (_) async {},
            onOpenPost: (_, {required isOwner, returnInboxTab}) async {},
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Claim not approved'));
    await tester.pumpAndSettle();

    expect(
      tester
          .widget<ChoiceChip>(
            find.widgetWithText(ChoiceChip, 'Outbound · Sent'),
          )
          .selected,
      isTrue,
    );
    expect(find.text('No claims sent'), findsOneWidget);
  });

  testWidgets(
    'approved sighting notification opens active handovers before its chat',
    (tester) async {
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: InboxScreen(
              api: _apiWithNotification(
                'sighting_approved',
                'Sighting approved',
                conversationId: 7,
              ),
              onOpenProfile: (_) async {},
              onOpenPost: (_, {required isOwner, returnInboxTab}) async {},
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();
      await tester.tap(find.text('Sighting approved'));
      await tester.pumpAndSettle();

      expect(
        tester
            .widget<ChoiceChip>(find.widgetWithText(ChoiceChip, 'Active'))
            .selected,
        isTrue,
      );
      expect(find.text('No active handovers'), findsOneWidget);
      expect(find.text('Admin support'), findsNothing);
    },
  );

  testWidgets('sighting rejection for a post owner opens received evidence', (
    tester,
  ) async {
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: InboxScreen(
            api: _apiWithNotification(
              'sighting_rejected',
              'Sighting not approved',
              recipientRole: 'post_owner',
            ),
            onOpenProfile: (_) async {},
            onOpenPost: (_, {required isOwner, returnInboxTab}) async {},
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('Sighting not approved'));
    await tester.pumpAndSettle();

    expect(
      tester
          .widget<ChoiceChip>(
            find.widgetWithText(ChoiceChip, 'Inbound · Received'),
          )
          .selected,
      isTrue,
    );
    expect(find.text('No evidence received'), findsOneWidget);
  });

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
                : options.path.endsWith('/received-claims') && !claimRejected
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
          final data = options.path == '/received-claims'
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

    expect(find.text('Admin support · Blue bottle'), findsOneWidget);
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

  testWidgets('evidence decisions submit and show the owner reason', (
    tester,
  ) async {
    final api = ApiClient(readToken: () async => null);
    final requests = <RequestOptions>[];
    final evidence = <String, dynamic>{
      'id': 71,
      'post_id': 9,
      'item_name': 'Lost wallet',
      'finder_first_name': 'Test Finder',
      'evidence_text': 'Found near the library.',
      'author_decision': 'pending',
      'admin_decision': 'pending',
      'can_undo_owner_decision': false,
    };
    api.dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) {
          requests.add(options);
          if (options.path == '/received-evidence') {
            handler.resolve(
              Response<Map<String, dynamic>>(
                requestOptions: options,
                data: {
                  'data': [evidence],
                  'meta': {'last_page': 1},
                },
              ),
            );
            return;
          }
          if (options.path == '/sightings/71') {
            final body = options.data as Map<String, dynamic>;
            evidence['author_decision'] = body['decision'] == 'approve'
                ? 'approved'
                : 'rejected';
            evidence['author_decision_reason'] = body['reason'];
            handler.resolve(Response<void>(requestOptions: options));
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
        home: Scaffold(
          body: InboxScreen(
            api: api,
            initialTab: 4,
            onOpenProfile: (_) async {},
            onOpenPost: (_, {required isOwner, returnInboxTab}) async {},
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('List of handling proofs'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Approve evidence'));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byType(TextField),
      'The wallet details match the post.',
    );
    await tester.tap(find.text('Submit decision'));
    await tester.pumpAndSettle();

    expect(
      requests.any(
        (request) =>
            request.method == 'PATCH' &&
            request.path == '/sightings/71' &&
            (request.data as Map<String, dynamic>)['reason'] ==
                'The wallet details match the post.',
      ),
      isTrue,
    );
    expect(
      find.text('Owner decision reason: The wallet details match the post.'),
      findsOneWidget,
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
