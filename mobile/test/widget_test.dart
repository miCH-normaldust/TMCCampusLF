import 'package:flutter_test/flutter_test.dart';
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
      'verification_questions': [
        {'id': 1, 'question': 'What sticker is on the bottle?'},
      ],
    });

    expect(post.id, 7);
    expect(post.type, 'found');
    expect(post.questions, ['What sticker is on the bottle?']);
    expect(post.toString(), isNot(contains('answer')));
  });
}
