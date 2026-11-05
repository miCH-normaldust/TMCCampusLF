import 'package:flutter_test/flutter_test.dart';
import 'package:tmc_campuslf_mobile/core/config.dart';
import 'package:tmc_campuslf_mobile/models/campus_post.dart';

void main() {
  test('parses a public found post without exposing answer data', () {
    final post = CampusPost.fromJson({
      'id': 7,
      'type': 'found',
      'item_name': 'Blue water bottle',
      'description': 'Found near the library.',
      'category': 'Accessory',
      'color': 'Blue',
      'location': 'Library',
      'event_date': '2026-10-18',
      'status': 'active',
      'author_first_name': 'Mika',
      'is_mine': true,
      'is_handover_participant': true,
      'retrieval_request_status': 'pending',
      'verification_questions': [
        {'id': 1, 'question': 'What sticker is on the bottle?'},
      ],
    });

    expect(post.id, 7);
    expect(post.type, 'found');
    expect(post.questions, ['What sticker is on the bottle?']);
    expect(post.isMine, isTrue);
    expect(post.isHandoverParticipant, isTrue);
    expect(post.retrievalRequestStatus, 'pending');
    expect(post.toString(), isNot(contains('answer')));
  });

  test('resolves protected post image paths against the configured API origin', () {
    final post = CampusPost.fromJson({
      'id': 8,
      'images': [
        {
          'url': '/api/v1/media?path=posts%2Fitem.jpg&expires=123&signature=abc',
          'alt_text': 'Item photo',
        },
      ],
    });

    final imageUri = Uri.parse(post.images.single['url']!);
    final apiUri = Uri.parse(AppConfig.apiBaseUrl);

    expect(imageUri.hasScheme, isTrue);
    expect(imageUri.origin, apiUri.origin);
    expect(imageUri.path, '/api/v1/media');
    expect(imageUri.queryParameters['signature'], 'abc');
  });
}
