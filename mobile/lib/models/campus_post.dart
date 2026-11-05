import '../core/config.dart';

class CampusPost {
  const CampusPost({
    required this.id,
    required this.type,
    required this.itemName,
    required this.description,
    required this.category,
    required this.categoryId,
    required this.color,
    required this.location,
    required this.eventDate,
    required this.status,
    required this.retrievalRequestStatus,
    required this.authorId,
    required this.authorFirstName,
    required this.authorLastName,
    required this.authorProfilePictureUrl,
    required this.questions,
    required this.isMine,
    required this.isHandoverParticipant,
    required this.images,
    required this.createdAt,
    required this.reactionsCount,
    required this.commentsCount,
    required this.myReaction,
  });

  final int id;
  final String type;
  final String itemName;
  final String description;
  final String category;
  final int? categoryId;
  final String color;
  final String location;
  final String eventDate;
  final String status;
  final String? retrievalRequestStatus;
  final int? authorId;
  final String authorFirstName;
  final String authorLastName;
  final String? authorProfilePictureUrl;
  final List<String> questions;
  final bool isMine;
  final bool isHandoverParticipant;
  final List<Map<String, String>> images;
  final String createdAt;
  final int reactionsCount;
  final int commentsCount;
  final String? myReaction;

  factory CampusPost.fromJson(Map<String, dynamic> json) {
    final rawQuestions = json['verification_questions'];
    final rawImages = json['images'];
    return CampusPost(
      id: json['id'] as int,
      type: json['type'] as String? ?? 'lost',
      itemName: json['item_name'] as String? ?? 'Campus item',
      description: json['description'] as String? ?? '',
      category: json['category'] as String? ?? 'Other',
      categoryId: json['category_id'] as int?,
      color: json['color'] as String? ?? '',
      location: json['location'] as String? ?? '',
      eventDate: json['event_date'] as String? ?? '',
      status: json['status'] as String? ?? 'active',
      retrievalRequestStatus: json['retrieval_request_status'] as String?,
      authorId: json['author_id'] as int?,
      authorFirstName: json['author_first_name'] as String? ?? 'TMC member',
      authorLastName: json['author_last_name'] as String? ?? '',
      authorProfilePictureUrl: json['author_profile_picture_url'] as String?,
      questions: rawQuestions is List
          ? rawQuestions
                .whereType<Map<String, dynamic>>()
                .map((question) => question['question'] as String? ?? '')
                .toList()
          : const [],
      isMine: json['is_mine'] as bool? ?? false,
      isHandoverParticipant:
          json['is_handover_participant'] as bool? ?? false,
      images: rawImages is List
          ? rawImages
                .whereType<Map<String, dynamic>>()
                .map(
                  (image) => {
                    'url': _resolveMediaUrl(image['url'] as String? ?? ''),
                    'alt_text': image['alt_text'] as String? ?? '',
                  },
                )
                .toList()
          : const [],
      createdAt: json['created_at'] as String? ?? '',
      reactionsCount: json['reactions_count'] as int? ?? 0,
      commentsCount: json['comments_count'] as int? ?? 0,
      myReaction: json['my_reaction'] as String?,
    );
  }

  static String _resolveMediaUrl(String url) {
    if (url.isEmpty) return url;

    return Uri.parse(AppConfig.apiBaseUrl).resolve(url).toString();
  }

  /// Returns a copy of this post with the reaction fields replaced.
  ///
  /// Incoming reaction picks are injected in the UI instead of being parsed
  /// with JSON, so this keeps the immutable model for optimistic updates.
  CampusPost withReaction({
    required int reactionsCount,
    required String? myReaction,
  }) {
    return CampusPost(
      id: id,
      type: type,
      itemName: itemName,
      description: description,
      category: category,
      categoryId: categoryId,
      color: color,
      location: location,
      eventDate: eventDate,
      status: status,
      retrievalRequestStatus: retrievalRequestStatus,
      authorId: authorId,
      authorFirstName: authorFirstName,
      authorLastName: authorLastName,
      authorProfilePictureUrl: authorProfilePictureUrl,
      questions: questions,
      isMine: isMine,
      isHandoverParticipant: isHandoverParticipant,
      images: images,
      createdAt: createdAt,
      reactionsCount: reactionsCount,
      commentsCount: commentsCount,
      myReaction: myReaction,
    );
  }
}
