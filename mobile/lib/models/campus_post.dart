class CampusPost {
  const CampusPost({
    required this.id,
    required this.type,
    required this.itemName,
    required this.description,
    required this.category,
    required this.color,
    required this.location,
    required this.eventDate,
    required this.status,
    required this.authorFirstName,
    required this.questions,
    required this.isMine,
    required this.images,
  });

  final int id;
  final String type;
  final String itemName;
  final String description;
  final String category;
  final String color;
  final String location;
  final String eventDate;
  final String status;
  final String authorFirstName;
  final List<String> questions;
  final bool isMine;
  final List<Map<String, String>> images;

  factory CampusPost.fromJson(Map<String, dynamic> json) {
    final rawQuestions = json['verification_questions'];
    final rawImages = json['images'];
    return CampusPost(
      id: json['id'] as int,
      type: json['type'] as String? ?? 'lost',
      itemName: json['item_name'] as String? ?? 'Campus item',
      description: json['description'] as String? ?? '',
      category: json['category'] as String? ?? 'Other',
      color: json['color'] as String? ?? '',
      location: json['location'] as String? ?? '',
      eventDate: json['event_date'] as String? ?? '',
      status: json['status'] as String? ?? 'active',
      authorFirstName: json['author_first_name'] as String? ?? 'TMC member',
      questions: rawQuestions is List
          ? rawQuestions
              .whereType<Map<String, dynamic>>()
              .map((question) => question['question'] as String? ?? '')
              .toList()
          : const [],
      isMine: json['is_mine'] as bool? ?? false,
      images: rawImages is List
          ? rawImages.whereType<Map<String, dynamic>>().map((image) => {
              'url': image['url'] as String? ?? '',
              'alt_text': image['alt_text'] as String? ?? '',
            }).toList()
          : const [],
    );
  }
}
