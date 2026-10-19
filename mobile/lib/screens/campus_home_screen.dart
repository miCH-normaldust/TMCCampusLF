import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'package:qr_flutter/qr_flutter.dart';

import '../core/api_client.dart';
import '../models/campus_post.dart';
import '../services/auth_service.dart';

class CampusHomeScreen extends StatefulWidget {
  const CampusHomeScreen({
    required this.api,
    required this.auth,
    required this.firstName,
    required this.onSignedOut,
    super.key,
  });

  final ApiClient api;
  final AuthService auth;
  final String firstName;
  final VoidCallback onSignedOut;

  @override
  State<CampusHomeScreen> createState() => _CampusHomeScreenState();
}

class _CampusHomeScreenState extends State<CampusHomeScreen> {
  int _tab = 0;
  String _type = '';
  String _search = '';
  Future<List<CampusPost>>? _posts;

  @override
  void initState() {
    super.initState();
    _posts = _fetchPosts();
  }

  Future<List<CampusPost>> _fetchPosts() async {
    final response = await widget.api.dio.get<Map<String, dynamic>>(
      '/posts',
      queryParameters: {
        if (_type.isNotEmpty) 'type': _type,
        if (_search.trim().isNotEmpty) 'search': _search.trim(),
      },
    );
    final data = response.data?['data'];
    if (data is! List) throw const FormatException('The feed response is invalid.');
    return data
        .whereType<Map<String, dynamic>>()
        .map(CampusPost.fromJson)
        .toList();
  }

  void _refreshFeed() {
    setState(() => _posts = _fetchPosts());
  }

  @override
  Widget build(BuildContext context) {
    const titles = ['CampusLF', 'Search', 'New post', 'Inbox', 'Profile'];
    final pages = [
      _feedPage(),
      _searchPage(),
      CreatePostScreen(api: widget.api, onCreated: () {
        setState(() {
          _tab = 0;
          _posts = _fetchPosts();
        });
      }),
      InboxScreen(api: widget.api),
      ProfileScreen(
        firstName: widget.firstName,
        api: widget.api,
        auth: widget.auth,
        onSignedOut: widget.onSignedOut,
      ),
    ];
    return Scaffold(
      appBar: AppBar(
        title: Row(
          children: [
            Container(
              padding: const EdgeInsets.all(7),
              decoration: BoxDecoration(color: const Color(0xFFF2B705), borderRadius: BorderRadius.circular(9)),
              child: const Text('LF', style: TextStyle(fontWeight: FontWeight.w800, color: Color(0xFF7A0F18))),
            ),
            const SizedBox(width: 10),
            Text(titles[_tab]),
          ],
        ),
      ),
      body: IndexedStack(index: _tab, children: pages),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _tab,
        onDestinationSelected: (index) => setState(() => _tab = index),
        destinations: const [
          NavigationDestination(icon: Icon(Icons.home_outlined), selectedIcon: Icon(Icons.home), label: 'Home'),
          NavigationDestination(icon: Icon(Icons.search), label: 'Search'),
          NavigationDestination(icon: Icon(Icons.add_circle_outline), selectedIcon: Icon(Icons.add_circle), label: 'Post'),
          NavigationDestination(icon: Icon(Icons.inbox_outlined), selectedIcon: Icon(Icons.inbox), label: 'Inbox'),
          NavigationDestination(icon: Icon(Icons.person_outline), selectedIcon: Icon(Icons.person), label: 'Profile'),
        ],
      ),
    );
  }

  Widget _feedPage() {
    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 8),
          child: Row(
            children: [
              Text('Hello, ${widget.firstName}', style: Theme.of(context).textTheme.titleLarge),
              const Spacer(),
              const Icon(Icons.location_on_outlined, size: 18),
              const Text('TMC'),
            ],
          ),
        ),
        SingleChildScrollView(
          scrollDirection: Axis.horizontal,
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
          child: Row(
            children: [
              _typeChip('All', ''),
              _typeChip('Lost', 'lost'),
              _typeChip('Found', 'found'),
            ],
          ),
        ),
        Expanded(child: _postList()),
      ],
    );
  }

  Widget _searchPage() {
    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.all(16),
          child: TextField(
            onChanged: (value) {
              _search = value;
              _refreshFeed();
            },
            decoration: const InputDecoration(
              labelText: 'Search items',
              prefixIcon: Icon(Icons.search),
              suffixIcon: Icon(Icons.tune),
            ),
          ),
        ),
        Expanded(child: _postList()),
      ],
    );
  }

  Widget _typeChip(String label, String value) {
    final selected = _type == value;
    return Padding(
      padding: const EdgeInsets.only(right: 8),
      child: ChoiceChip(
        label: Text(label),
        selected: selected,
        onSelected: (_) {
          setState(() {
            _type = value;
            _posts = _fetchPosts();
          });
        },
      ),
    );
  }

  Widget _postList() {
    return FutureBuilder<List<CampusPost>>(
      future: _posts,
      builder: (context, snapshot) {
        if (snapshot.connectionState == ConnectionState.waiting) {
          return const Center(child: CircularProgressIndicator());
        }
        if (snapshot.hasError) {
          return _EmptyState(
            icon: Icons.cloud_off_outlined,
            title: 'Could not load posts',
            message: widget.api.errorMessage(snapshot.error!),
            action: FilledButton.tonal(onPressed: _refreshFeed, child: const Text('Try again')),
          );
        }
        final posts = snapshot.data ?? const [];
        if (posts.isEmpty) {
          return _EmptyState(
            icon: Icons.search_off,
            title: 'No posts found',
            message: 'Try another search or check back later.',
            action: OutlinedButton(onPressed: () => setState(() {
              _search = '';
              _type = '';
              _posts = _fetchPosts();
            }), child: const Text('Clear filters')),
          );
        }
        return RefreshIndicator(
          onRefresh: () async => _refreshFeed(),
          child: ListView.separated(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 20),
            itemCount: posts.length,
            separatorBuilder: (_, _) => const SizedBox(height: 12),
            itemBuilder: (context, index) => _PostCard(
              post: posts[index],
              onTap: () => showModalBottomSheet<void>(
                context: context,
                isScrollControlled: true,
                builder: (_) => _PostDetailSheet(
                  api: widget.api,
                  post: posts[index],
                ),
              ),
            ),
          ),
        );
      },
    );
  }
}

class _PostCard extends StatelessWidget {
  const _PostCard({required this.post, required this.onTap});
  final CampusPost post;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final found = post.type == 'found';
    final tagColor = found ? const Color(0xFF1B7F6B) : const Color(0xFFC62828);
    return Semantics(
      button: true,
      label: '${post.type} item: ${post.itemName}, ${post.category}, at ${post.location}',
      child: Card(
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(14),
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                if (post.images.isNotEmpty && post.images.first['url']!.isNotEmpty) ...[
                  ClipRRect(
                    borderRadius: BorderRadius.circular(10),
                    child: AspectRatio(
                      aspectRatio: 4 / 3,
                      child: Image.network(
                        post.images.first['url']!,
                        fit: BoxFit.cover,
                        semanticLabel: post.images.first['alt_text'],
                        errorBuilder: (_, _, _) => const ColoredBox(
                          color: Color(0xFFF2E4E1),
                          child: Center(child: Icon(Icons.image_not_supported_outlined, color: Color(0xFFA31621))),
                        ),
                      ),
                    ),
                  ),
                  const SizedBox(height: 12),
                ],
                Row(
                  children: [
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                      decoration: BoxDecoration(color: tagColor.withValues(alpha: 0.1), borderRadius: BorderRadius.circular(30)),
                      child: Text(found ? 'FOUND' : 'LOST', style: TextStyle(color: tagColor, fontSize: 11, fontWeight: FontWeight.w800)),
                    ),
                    const SizedBox(width: 8),
                    Text(post.category, style: const TextStyle(color: Color(0xFF6B6262), fontSize: 12)),
                    const Spacer(),
                    const Icon(Icons.more_horiz, color: Color(0xFF6B6262)),
                  ],
                ),
                const SizedBox(height: 13),
                Text(post.itemName, style: Theme.of(context).textTheme.titleLarge),
                const SizedBox(height: 8),
                Text(post.description, maxLines: 2, overflow: TextOverflow.ellipsis),
                const SizedBox(height: 14),
                Row(
                  children: [
                    const Icon(Icons.place_outlined, size: 17, color: Color(0xFF6B6262)),
                    const SizedBox(width: 4),
                    Expanded(child: Text(post.location, style: const TextStyle(color: Color(0xFF6B6262)))),
                    Text(post.eventDate, style: const TextStyle(color: Color(0xFF6B6262), fontSize: 12)),
                  ],
                ),
                const Divider(height: 24),
                Row(
                  children: [
                    CircleAvatar(radius: 14, backgroundColor: const Color(0xFFF2E4E1), child: Text(post.authorFirstName.isEmpty ? '?' : post.authorFirstName[0].toUpperCase(), style: const TextStyle(color: Color(0xFFA31621), fontSize: 12))),
                    const SizedBox(width: 8),
                    Text(post.authorFirstName, style: const TextStyle(fontSize: 13)),
                    const Spacer(),
                    Text(post.status.replaceAll('_', ' '), style: const TextStyle(fontSize: 12, color: Color(0xFF6B6262))),
                  ],
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _PostDetailSheet extends StatefulWidget {
  const _PostDetailSheet({required this.api, required this.post});
  final ApiClient api;
  final CampusPost post;

  @override
  State<_PostDetailSheet> createState() => _PostDetailSheetState();
}

class _PostDetailSheetState extends State<_PostDetailSheet> {
  final _answers = <TextEditingController>[];
  final _comment = TextEditingController();
  bool _busy = false;
  String? _message;
  late Future<List<Map<String, dynamic>>> _comments;

  @override
  void initState() {
    super.initState();
    _comments = _loadComments();
    for (var index = 0; index < widget.post.questions.length; index++) {
      _answers.add(TextEditingController());
    }
  }

  @override
  void dispose() {
    for (final answer in _answers) {
      answer.dispose();
    }
    _comment.dispose();
    super.dispose();
  }

  Future<List<Map<String, dynamic>>> _loadComments() async {
    final response = await widget.api.dio.get<Map<String, dynamic>>(
      '/posts/${widget.post.id}/comments',
    );
    final data = response.data?['data'];
    if (data is! List) return const [];
    return data.whereType<Map<String, dynamic>>().toList();
  }

  Future<void> _claim() async {
    setState(() {
      _busy = true;
      _message = null;
    });
    try {
      final response = await widget.api.dio.post<Map<String, dynamic>>(
        '/posts/${widget.post.id}/claims',
        data: {'answers': _answers.map((answer) => answer.text).toList()},
      );
      if (mounted) setState(() => _message = response.data?['message'] as String? ?? 'Claim sent for review.');
    } on DioException catch (error) {
      if (mounted) setState(() => _message = widget.api.errorMessage(error));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _sendComment() async {
    final body = _comment.text.trim();
    if (body.isEmpty) return;
    try {
      await widget.api.dio.post<void>(
        '/posts/${widget.post.id}/comments',
        data: {'body': body},
      );
      _comment.clear();
      setState(() => _comments = _loadComments());
    } on DioException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(widget.api.errorMessage(error))),
        );
      }
    }
  }

  Future<void> _react(String type) async {
    try {
      await widget.api.dio.post<void>(
        '/posts/${widget.post.id}/reactions',
        data: {'type': type},
      );
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Reaction updated: $type')),
        );
      }
    } on DioException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(widget.api.errorMessage(error))),
        );
      }
    }
  }

  Future<void> _report() async {
    final reason = TextEditingController();
    final accepted = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Report this post'),
        content: TextField(
          controller: reason,
          maxLength: 500,
          maxLines: 3,
          decoration: const InputDecoration(labelText: 'Reason'),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Cancel')),
          FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('Send report')),
        ],
      ),
    );
    if (accepted == true && reason.text.trim().isNotEmpty) {
      try {
        await widget.api.dio.post<void>(
          '/posts/${widget.post.id}/reports',
          data: {'reason': reason.text.trim()},
        );
        if (mounted) setState(() => _message = 'Your report was sent to the TMC administrators.');
      } on DioException catch (error) {
        if (mounted) setState(() => _message = widget.api.errorMessage(error));
      }
    }
    reason.dispose();
  }

  Future<void> _recordNoSighting() async {
    try {
      await widget.api.dio.post<void>(
        '/posts/${widget.post.id}/sightings',
        data: {'answer': 'no'},
      );
      if (mounted) setState(() => _message = 'Your response was recorded.');
    } on DioException catch (error) {
      if (mounted) setState(() => _message = widget.api.errorMessage(error));
    }
  }

  @override
  Widget build(BuildContext context) {
    final post = widget.post;
    return SafeArea(
      child: ConstrainedBox(
        constraints: BoxConstraints(maxHeight: MediaQuery.sizeOf(context).height * 0.9),
        child: SingleChildScrollView(
          padding: EdgeInsets.fromLTRB(20, 24, 20, MediaQuery.viewInsetsOf(context).bottom + 24),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(post.itemName, style: Theme.of(context).textTheme.headlineSmall),
              const SizedBox(height: 10),
              Text('${post.type.toUpperCase()} · ${post.category} · ${post.color}'),
              const SizedBox(height: 8),
              Text('${post.location} · ${post.eventDate}'),
              const SizedBox(height: 16),
              if (post.images.isNotEmpty && post.images.first['url']!.isNotEmpty)
                ClipRRect(
                  borderRadius: BorderRadius.circular(12),
                  child: AspectRatio(
                    aspectRatio: 4 / 3,
                    child: Image.network(
                      post.images.first['url']!,
                      fit: BoxFit.cover,
                      semanticLabel: post.images.first['alt_text'],
                      errorBuilder: (_, _, _) => const ColoredBox(
                        color: Color(0xFFF2E4E1),
                        child: Center(child: Icon(Icons.image_not_supported_outlined)),
                      ),
                    ),
                  ),
                ),
              const SizedBox(height: 12),
              Text(post.description),
              Align(
                alignment: Alignment.centerRight,
                child: IconButton(
                  tooltip: 'Report post',
                  onPressed: _report,
                  icon: const Icon(Icons.flag_outlined),
                ),
              ),
              Wrap(
                spacing: 6,
                children: [
                  for (final reaction in const ['like', 'haha', 'angry', 'sad'])
                    ActionChip(
                      avatar: Icon(
                        switch (reaction) {
                          'like' => Icons.thumb_up_alt_outlined,
                          'haha' => Icons.sentiment_satisfied_alt,
                          'angry' => Icons.sentiment_dissatisfied,
                          _ => Icons.sentiment_neutral,
                        },
                        size: 17,
                      ),
                      label: Text(reaction[0].toUpperCase() + reaction.substring(1)),
                      onPressed: () => _react(reaction),
                    ),
                ],
              ),
              if (post.type == 'found' && post.questions.isNotEmpty) ...[
                const SizedBox(height: 16),
                const Text('Claim this item', style: TextStyle(fontWeight: FontWeight.w700)),
                const SizedBox(height: 8),
                for (var index = 0; index < post.questions.length; index++) ...[
                  Text(post.questions[index]),
                  const SizedBox(height: 8),
                  TextField(controller: _answers[index], maxLines: 2, decoration: const InputDecoration(labelText: 'Your answer')),
                  const SizedBox(height: 12),
                ],
                const Text('Wrong answers use up one of your claim attempts. Your answers are private and reviewed by TMC administrators.'),
                const SizedBox(height: 12),
                FilledButton(
                  onPressed: _busy ? null : _claim,
                  child: _busy ? const CircularProgressIndicator(color: Colors.white) : const Text('Submit claim'),
                ),
              ] else if (post.type == 'lost' && post.isMine) ...[
                const SizedBox(height: 16),
                OutlinedButton.icon(
                  onPressed: () => showModalBottomSheet<void>(
                    context: context,
                    isScrollControlled: true,
                    builder: (_) => SightingReviewSheet(api: widget.api, post: post),
                  ),
                  icon: const Icon(Icons.fact_check_outlined),
                  label: const Text('Review sighting evidence'),
                ),
              ] else if (post.type == 'lost') ...[
                const SizedBox(height: 16),
                const Text('Have you seen this item?'),
                const SizedBox(height: 8),
                Row(
                  children: [
                    Expanded(
                      child: FilledButton(
                        onPressed: () => showModalBottomSheet<void>(
                          context: context,
                          isScrollControlled: true,
                          builder: (_) => SightingEvidenceSheet(
                            api: widget.api,
                            post: post,
                          ),
                        ),
                        child: const Text('Yes, I have evidence'),
                      ),
                    ),
                    const SizedBox(width: 8),
                    OutlinedButton(onPressed: _recordNoSighting, child: const Text('No')),
                  ],
                ),
              ],
              const Divider(height: 28),
              Text('Comments', style: Theme.of(context).textTheme.titleLarge),
              FutureBuilder<List<Map<String, dynamic>>>(
                future: _comments,
                builder: (context, snapshot) {
                  if (snapshot.connectionState == ConnectionState.waiting) {
                    return const Padding(
                      padding: EdgeInsets.all(12),
                      child: Center(child: CircularProgressIndicator()),
                    );
                  }
                  if (snapshot.hasError) {
                    return Text(widget.api.errorMessage(snapshot.error!));
                  }
                  final comments = snapshot.data ?? const [];
                  if (comments.isEmpty) return const Text('No comments yet.');
                  return Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      for (final comment in comments)
                        ListTile(
                          contentPadding: EdgeInsets.zero,
                          leading: const CircleAvatar(child: Icon(Icons.person_outline)),
                          title: Text(comment['author_first_name'] as String? ?? 'TMC member'),
                          subtitle: Text(comment['body'] as String? ?? ''),
                        ),
                    ],
                  );
                },
              ),
              Row(
                children: [
                  Expanded(child: TextField(controller: _comment, decoration: const InputDecoration(labelText: 'Add a comment'))),
                  IconButton(tooltip: 'Send comment', onPressed: _sendComment, icon: const Icon(Icons.send, color: Color(0xFFA31621))),
                ],
              ),
              const SizedBox(height: 8),
              const Text('Never post answers to a found item’s verification questions in public comments.', style: TextStyle(fontSize: 12)),
              if (_message != null) Padding(padding: const EdgeInsets.only(top: 12), child: Text(_message!)),
            ],
          ),
        ),
      ),
    );
  }
}

class SightingEvidenceSheet extends StatefulWidget {
  const SightingEvidenceSheet({required this.api, required this.post, super.key});
  final ApiClient api;
  final CampusPost post;

  @override
  State<SightingEvidenceSheet> createState() => _SightingEvidenceSheetState();
}

class _SightingEvidenceSheetState extends State<SightingEvidenceSheet> {
  final _description = TextEditingController();
  XFile? _photo;
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _description.dispose();
    super.dispose();
  }

  Future<void> _pickPhoto() async {
    final photo = await ImagePicker().pickImage(source: ImageSource.gallery, imageQuality: 85);
    if (photo != null && mounted) setState(() => _photo = photo);
  }

  Future<void> _submit() async {
    if (_description.text.trim().isEmpty || _photo == null) {
      setState(() => _error = 'Add a short description and an evidence photo.');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final data = FormData.fromMap({
        'answer': 'yes',
        'evidence_text': _description.text.trim(),
        'evidence_image': await MultipartFile.fromFile(_photo!.path, filename: _photo!.name),
      });
      await widget.api.dio.post<void>('/posts/${widget.post.id}/sightings', data: data);
      if (mounted) Navigator.pop(context);
    } on DioException catch (error) {
      if (mounted) setState(() => _error = widget.api.errorMessage(error));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: Padding(
        padding: EdgeInsets.fromLTRB(20, 24, 20, MediaQuery.viewInsetsOf(context).bottom + 24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text('Share sighting evidence', style: Theme.of(context).textTheme.titleLarge),
            const SizedBox(height: 8),
            const Text('Your description and photo go to the post author and TMC administrators for review.'),
            const SizedBox(height: 14),
            TextField(controller: _description, maxLines: 3, decoration: const InputDecoration(labelText: 'Where and when did you see it?')),
            const SizedBox(height: 12),
            OutlinedButton.icon(
              onPressed: _pickPhoto,
              icon: const Icon(Icons.photo_library_outlined),
              label: Text(_photo?.name ?? 'Choose evidence photo'),
            ),
            if (_error != null) Padding(padding: const EdgeInsets.all(8), child: Text(_error!, style: const TextStyle(color: Color(0xFFC62828)))),
            const SizedBox(height: 12),
            FilledButton(
              onPressed: _busy ? null : _submit,
              child: _busy ? const CircularProgressIndicator(color: Colors.white) : const Text('Send to owner'),
            ),
          ],
        ),
      ),
    );
  }
}

class SightingReviewSheet extends StatefulWidget {
  const SightingReviewSheet({required this.api, required this.post, super.key});
  final ApiClient api;
  final CampusPost post;

  @override
  State<SightingReviewSheet> createState() => _SightingReviewSheetState();
}

class _SightingReviewSheetState extends State<SightingReviewSheet> {
  late Future<List<Map<String, dynamic>>> _sightings;

  @override
  void initState() {
    super.initState();
    _sightings = _load();
  }

  Future<List<Map<String, dynamic>>> _load() async {
    final response = await widget.api.dio.get<Map<String, dynamic>>(
      '/posts/${widget.post.id}/sightings',
    );
    final data = response.data?['data'];
    if (data is! List) return const [];
    return data.whereType<Map<String, dynamic>>().toList();
  }

  Future<void> _decide(int id, String decision) async {
    try {
      await widget.api.dio.patch<void>(
        '/sightings/$id',
        data: {'decision': decision},
      );
      if (mounted) setState(() => _sightings = _load());
    } on DioException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(widget.api.errorMessage(error))),
        );
      }
    }
  }

  Future<void> _startHandover(int id) async {
    try {
      final response = await widget.api.dio.get<Map<String, dynamic>>(
        '/meetup-locations',
      );
      final locations = response.data?['data'];
      if (locations is! List || locations.isEmpty || !mounted) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('No active TMC meet-up locations are available.')),
          );
        }
        return;
      }
      final selected = await showModalBottomSheet<Map<String, dynamic>>(
        context: context,
        builder: (context) => SafeArea(
          child: ListView(
            shrinkWrap: true,
            children: [
              const ListTile(title: Text('Choose a safe TMC meet-up location')),
              for (final location in locations.whereType<Map<String, dynamic>>())
                ListTile(
                  title: Text(location['name'] as String? ?? ''),
                  subtitle: Text(location['operating_hours'] as String? ?? ''),
                  onTap: () => Navigator.pop(context, location),
                ),
            ],
          ),
        ),
      );
      if (selected == null) return;
      await widget.api.dio.post<void>(
        '/sightings/$id/handover',
        data: {'location_id': selected['id']},
      );
      if (mounted) {
        setState(() => _sightings = _load());
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Handover set up at ${selected['name']}. Both participants can confirm it from Inbox > Handovers.')),
        );
      }
    } on DioException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(widget.api.errorMessage(error))),
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: ConstrainedBox(
        constraints: BoxConstraints(maxHeight: MediaQuery.sizeOf(context).height * 0.85),
        child: FutureBuilder<List<Map<String, dynamic>>>(
          future: _sightings,
          builder: (context, snapshot) {
            if (snapshot.connectionState == ConnectionState.waiting) {
              return const Center(child: CircularProgressIndicator());
            }
            if (snapshot.hasError) {
              return Center(child: Text(widget.api.errorMessage(snapshot.error!)));
            }
            final sightings = snapshot.data ?? const [];
            if (sightings.isEmpty) {
              return const Padding(
                padding: EdgeInsets.all(28),
                child: Text('No sighting evidence has been submitted yet.'),
              );
            }
            return ListView(
              padding: const EdgeInsets.all(18),
              children: [
                Text('Review sightings for ${widget.post.itemName}', style: Theme.of(context).textTheme.titleLarge),
                const SizedBox(height: 12),
                for (final sighting in sightings) ...[
                  Card(
                    child: Padding(
                      padding: const EdgeInsets.all(14),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text('From ${sighting['user_first_name'] ?? 'TMC member'}', style: const TextStyle(fontWeight: FontWeight.w700)),
                          const SizedBox(height: 8),
                          Text(sighting['evidence_text'] as String? ?? ''),
                          if (sighting['evidence_image'] is String) ...[
                            const SizedBox(height: 10),
                            Image.network(
                              sighting['evidence_image'] as String,
                              semanticLabel: 'Sighting evidence photo',
                              errorBuilder: (_, _, _) => const Text('Evidence photo could not be loaded.'),
                            ),
                          ],
                          const SizedBox(height: 8),
                          Text('Your decision: ${sighting['author_decision'] ?? 'pending'} · Admin: ${sighting['admin_decision'] ?? 'pending'}'),
                          if (sighting['author_decision'] == 'pending')
                            Row(
                              children: [
                                Expanded(child: FilledButton(onPressed: () => _decide(sighting['id'] as int, 'approve'), child: const Text('Approve evidence'))),
                                const SizedBox(width: 8),
                                Expanded(child: OutlinedButton(onPressed: () => _decide(sighting['id'] as int, 'reject'), child: const Text('Reject'))),
                              ],
                            ),
                          if (sighting['admin_decision'] == 'approved' && sighting['handover_id'] == null)
                            Padding(
                              padding: const EdgeInsets.only(top: 10),
                              child: FilledButton.tonalIcon(
                                onPressed: () => _startHandover(sighting['id'] as int),
                                icon: const Icon(Icons.handshake_outlined),
                                label: const Text('Set up lost-item handover'),
                              ),
                            ),
                          if (sighting['handover_id'] != null)
                            const Padding(
                              padding: EdgeInsets.only(top: 10),
                              child: Text('Handover arranged. Both participants can open Inbox > Handovers to view and confirm it.'),
                            ),
                        ],
                      ),
                    ),
                  ),
                  const SizedBox(height: 10),
                ],
              ],
            );
          },
        ),
      ),
    );
  }
}

class CreatePostScreen extends StatefulWidget {
  const CreatePostScreen({required this.api, required this.onCreated, super.key});
  final ApiClient api;
  final VoidCallback onCreated;

  @override
  State<CreatePostScreen> createState() => _CreatePostScreenState();
}

class _CreatePostScreenState extends State<CreatePostScreen> {
  final _formKey = GlobalKey<FormState>();
  final _name = TextEditingController();
  final _color = TextEditingController();
  final _description = TextEditingController();
  final _question = TextEditingController();
  final _answer = TextEditingController();
  final _questionControllers = <TextEditingController>[];
  final _answerControllers = <TextEditingController>[];
  final _photos = <XFile>[];
  final _altTextControllers = <TextEditingController>[];
  List<Map<String, dynamic>> _categories = [];
  List<Map<String, dynamic>> _locations = [];
  String _type = 'lost';
  String _custody = 'with_me';
  int? _categoryId;
  int? _locationId;
  DateTime _eventDate = DateTime.now();
  bool _loading = true;
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _questionControllers.add(_question);
    _answerControllers.add(_answer);
    _loadCatalog();
  }

  @override
  void dispose() {
    _name.dispose();
    _color.dispose();
    _description.dispose();
    for (final controller in _questionControllers) {
      controller.dispose();
    }
    for (final controller in _answerControllers) {
      controller.dispose();
    }
    for (final controller in _altTextControllers) {
      controller.dispose();
    }
    super.dispose();
  }

  Future<void> _loadCatalog() async {
    try {
      final response = await widget.api.dio.get<Map<String, dynamic>>('/catalog');
      if (!mounted) {
        return;
      }
      setState(() {
        _categories = List<Map<String, dynamic>>.from(response.data?['categories'] as List? ?? const []);
        _locations = List<Map<String, dynamic>>.from(response.data?['locations'] as List? ?? const []);
        _loading = false;
      });
    } on DioException catch (error) {
      if (mounted) {
        setState(() {
          _error = widget.api.errorMessage(error);
          _loading = false;
        });
      }
    }
  }

  Future<void> _selectDate() async {
    final date = await showDatePicker(
      context: context,
      initialDate: _eventDate,
      firstDate: DateTime.now().subtract(const Duration(days: 365)),
      lastDate: DateTime.now(),
    );
    if (date != null) setState(() => _eventDate = date);
  }

  Future<void> _pickPhotos() async {
    final selected = await ImagePicker().pickMultiImage(imageQuality: 85);
    if (selected.isEmpty || !mounted) return;
    final remaining = 5 - _photos.length;
    if (remaining <= 0) {
      setState(() => _error = 'A post can include at most five photos.');
      return;
    }
    final selectedCategory = _categories.where((item) => item['id'] == _categoryId);
    final category = selectedCategory.isEmpty
        ? null
        : selectedCategory.first['name'] as String;
    final altText = [_name.text.trim(), category].whereType<String>().where((text) => text.isNotEmpty).join(', ');
    setState(() {
      for (final photo in selected.take(remaining)) {
        _photos.add(photo);
        _altTextControllers.add(TextEditingController(text: altText));
      }
      _error = selected.length > remaining ? 'Only the first five selected photos were added.' : null;
    });
  }

  void _removePhoto(int index) {
    setState(() {
      _photos.removeAt(index);
      _altTextControllers.removeAt(index).dispose();
    });
  }

  Future<void> _submit() async {
    if (_busy || !_formKey.currentState!.validate()) return;
    if (_categoryId == null || _locationId == null) {
      setState(() => _error = 'Choose a category and campus location.');
      return;
    }
    if (_type == 'found' &&
        (_questionControllers.any((item) => item.text.trim().isEmpty) ||
            _answerControllers.any((item) => item.text.trim().isEmpty))) {
      setState(() => _error = 'Add an answer for every verification question.');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final duplicateCheck = await widget.api.dio.post<Map<String, dynamic>>(
        '/posts/similar',
        data: {
          'type': _type,
          'item_name': _name.text.trim(),
          'category_id': _categoryId,
          'color': _color.text.trim(),
          'location_id': _locationId,
          'event_date': '${_eventDate.year.toString().padLeft(4, '0')}-${_eventDate.month.toString().padLeft(2, '0')}-${_eventDate.day.toString().padLeft(2, '0')}',
        },
      );
      final similar = duplicateCheck.data?['data'];
      if (similar is List && similar.isNotEmpty) {
        if (!mounted) {
          return;
        }
        final confirmed = await showDialog<bool>(
          context: context,
          builder: (context) => AlertDialog(
            title: const Text('Similar TMC posts'),
            content: SizedBox(
              width: double.maxFinite,
              child: ListView(
                shrinkWrap: true,
                children: [
                  const Text('Check that your item is different before posting:'),
                  const SizedBox(height: 8),
                  for (final item in similar.whereType<Map<String, dynamic>>())
                    ListTile(
                      contentPadding: EdgeInsets.zero,
                      title: Text(item['item_name'] as String? ?? 'Item'),
                      subtitle: Text('${item['location'] ?? ''} · ${item['event_date'] ?? ''}'),
                    ),
                ],
              ),
            ),
            actions: [
              TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Review these posts')),
              FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('This is a different item')),
            ],
          ),
        );
        if (confirmed != true) return;
      }
      final data = <String, dynamic>{
        'type': _type,
        'item_name': _name.text.trim(),
        'description': _description.text.trim(),
        'category_id': _categoryId,
        'color': _color.text.trim(),
        'location_id': _locationId,
        'event_date': '${_eventDate.year.toString().padLeft(4, '0')}-${_eventDate.month.toString().padLeft(2, '0')}-${_eventDate.day.toString().padLeft(2, '0')}',
        if (_type == 'found') 'custody': _custody,
        if (_type == 'found')
          'questions': List.generate(_questionControllers.length, (index) => {
            'question': _questionControllers[index].text.trim(),
            'answer': _answerControllers[index].text.trim(),
          }),
      };
      if (_photos.isNotEmpty) {
        data['photos'] = [
          for (var index = 0; index < _photos.length; index++)
            {
              'file': await MultipartFile.fromFile(
                _photos[index].path,
                filename: _photos[index].name,
              ),
              'alt_text': _altTextControllers[index].text.trim(),
            },
        ];
        await widget.api.dio.post<void>('/posts', data: FormData.fromMap(data));
      } else {
        await widget.api.dio.post<void>('/posts', data: data);
      }
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Your post is live on the TMC feed.')));
        widget.onCreated();
      }
    } on DioException catch (error) {
      if (mounted) setState(() => _error = widget.api.errorMessage(error));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) return const Center(child: CircularProgressIndicator());
    if (_error != null && _categories.isEmpty) {
      return _EmptyState(
        icon: Icons.cloud_off,
        title: 'Could not load campus options',
        message: _error!,
        action: FilledButton.tonal(onPressed: () {
          setState(() {
            _error = null;
            _loading = true;
          });
          _loadCatalog();
        }, child: const Text('Try again')),
      );
    }
    return Form(
      key: _formKey,
      child: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Text('Share a lost or found item', style: Theme.of(context).textTheme.headlineSmall),
          const SizedBox(height: 6),
          const Text('Keep identifying details private. Do not photograph serial numbers, IDs, or unique verification details.'),
          const SizedBox(height: 18),
          SegmentedButton<String>(
            segments: const [
              ButtonSegment(value: 'lost', label: Text('I lost something'), icon: Icon(Icons.search)),
              ButtonSegment(value: 'found', label: Text('I found something'), icon: Icon(Icons.check_circle_outline)),
            ],
            selected: {_type},
            onSelectionChanged: (values) => setState(() => _type = values.first),
          ),
          const SizedBox(height: 16),
          TextFormField(controller: _name, decoration: const InputDecoration(labelText: 'Item name *'), validator: _required),
          const SizedBox(height: 12),
          DropdownButtonFormField<int>(
            initialValue: _categoryId,
            decoration: const InputDecoration(labelText: 'Category *'),
            items: _categories.map((item) => DropdownMenuItem<int>(
              value: item['id'] as int,
              child: Text(item['name'] as String),
            )).toList(),
            onChanged: (value) => setState(() => _categoryId = value),
          ),
          const SizedBox(height: 12),
          TextFormField(controller: _color, decoration: const InputDecoration(labelText: 'Color *'), validator: _required),
          const SizedBox(height: 12),
          DropdownButtonFormField<int>(
            initialValue: _locationId,
            decoration: const InputDecoration(labelText: 'Campus area *'),
            items: _locations.map((item) => DropdownMenuItem<int>(
              value: item['id'] as int,
              child: Text(item['name'] as String),
            )).toList(),
            onChanged: (value) => setState(() => _locationId = value),
          ),
          const SizedBox(height: 12),
          OutlinedButton.icon(
            onPressed: _selectDate,
            icon: const Icon(Icons.calendar_today_outlined),
            label: Text('Date: ${_eventDate.year}-${_eventDate.month.toString().padLeft(2, '0')}-${_eventDate.day.toString().padLeft(2, '0')}'),
          ),
          const SizedBox(height: 12),
          TextFormField(controller: _description, minLines: 3, maxLines: 5, decoration: const InputDecoration(labelText: 'Description *'), validator: _required),
          const SizedBox(height: 16),
          const Text('Photos (up to 5)', style: TextStyle(fontWeight: FontWeight.w700)),
          const SizedBox(height: 6),
          const Text('Avoid serial numbers, lock-screen text, ID details, and other information used in your verification questions.'),
          OutlinedButton.icon(
            onPressed: _photos.length >= 5 ? null : _pickPhotos,
            icon: const Icon(Icons.add_photo_alternate_outlined),
            label: const Text('Add photos'),
          ),
          for (var index = 0; index < _photos.length; index++)
            Card(
              child: Padding(
                padding: const EdgeInsets.all(10),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        const Icon(Icons.image_outlined),
                        const SizedBox(width: 8),
                        Expanded(child: Text(_photos[index].name, maxLines: 1, overflow: TextOverflow.ellipsis)),
                        IconButton(
                          tooltip: 'Remove photo',
                          onPressed: () => _removePhoto(index),
                          icon: const Icon(Icons.close),
                        ),
                      ],
                    ),
                    TextFormField(
                      controller: _altTextControllers[index],
                      decoration: InputDecoration(labelText: 'Photo description ${index + 1} *'),
                      validator: _required,
                    ),
                  ],
                ),
              ),
            ),
          if (_type == 'found') ...[
            const SizedBox(height: 16),
            const Text('Safe custody', style: TextStyle(fontWeight: FontWeight.w700)),
            RadioGroup<String>(
              groupValue: _custody,
              onChanged: (value) => setState(() => _custody = value ?? 'with_me'),
              child: const Column(
                children: [
                  RadioListTile<String>(
                    contentPadding: EdgeInsets.zero,
                    title: Text('With me'),
                    value: 'with_me',
                  ),
                  RadioListTile<String>(
                    contentPadding: EdgeInsets.zero,
                    title: Text('Surrendered to the TMC office'),
                    value: 'office',
                  ),
                ],
              ),
            ),
            for (var index = 0; index < _questionControllers.length; index++) ...[
              const SizedBox(height: 8),
              TextFormField(controller: _questionControllers[index], decoration: InputDecoration(labelText: 'Verification question ${index + 1} *'), validator: _required),
              const SizedBox(height: 8),
              TextFormField(controller: _answerControllers[index], decoration: InputDecoration(labelText: 'Private answer ${index + 1} *'), validator: _required),
            ],
            if (_questionControllers.length < 3)
              TextButton.icon(
                onPressed: () => setState(() {
                  _questionControllers.add(TextEditingController());
                  _answerControllers.add(TextEditingController());
                }),
                icon: const Icon(Icons.add),
                label: const Text('Add verification question'),
              ),
          ],
          if (_error != null) Padding(padding: const EdgeInsets.symmetric(vertical: 10), child: Text(_error!, style: const TextStyle(color: Color(0xFFC62828)))),
          const SizedBox(height: 16),
          FilledButton(
            onPressed: _busy ? null : _submit,
            child: _busy ? const CircularProgressIndicator(color: Colors.white) : const Text('Post item'),
          ),
        ],
      ),
    );
  }

  String? _required(String? value) => value == null || value.trim().isEmpty ? 'This field is required.' : null;
}

class InboxScreen extends StatefulWidget {
  const InboxScreen({required this.api, super.key});
  final ApiClient api;

  @override
  State<InboxScreen> createState() => _InboxScreenState();
}

class _InboxScreenState extends State<InboxScreen> {
  int _tab = 0;
  late Future<List<Map<String, dynamic>>> _notifications;
  late Future<List<Map<String, dynamic>>> _conversations;
  late Future<List<Map<String, dynamic>>> _handovers;

  @override
  void initState() {
    super.initState();
    _notifications = _load();
    _conversations = _loadConversations();
    _handovers = _loadHandovers();
  }

  Future<List<Map<String, dynamic>>> _load() async {
    final response = await widget.api.dio.get<Map<String, dynamic>>('/notifications');
    final data = response.data?['data'];
    if (data is! List) return const [];
    return data.whereType<Map<String, dynamic>>().toList();
  }

  Future<List<Map<String, dynamic>>> _loadConversations() async {
    final response = await widget.api.dio.get<Map<String, dynamic>>('/conversations');
    final data = response.data?['data'];
    if (data is! List) return const [];
    return data.whereType<Map<String, dynamic>>().toList();
  }

  Future<List<Map<String, dynamic>>> _loadHandovers() async {
    final response = await widget.api.dio.get<Map<String, dynamic>>('/handovers');
    final data = response.data?['data'];
    if (data is! List) return const [];
    return data.whereType<Map<String, dynamic>>().toList();
  }

  Future<void> _markRead(String id) async {
    try {
      await widget.api.dio.patch<void>('/notifications/$id/read');
      if (mounted) setState(() => _notifications = _load());
    } on DioException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(widget.api.errorMessage(error))),
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        const SizedBox(height: 8),
        SegmentedButton<int>(
          segments: const [
            ButtonSegment(value: 0, label: Text('Notifications'), icon: Icon(Icons.notifications_none)),
            ButtonSegment(value: 1, label: Text('Chats'), icon: Icon(Icons.chat_bubble_outline)),
            ButtonSegment(value: 2, label: Text('Handovers'), icon: Icon(Icons.handshake_outlined)),
          ],
          selected: {_tab},
          onSelectionChanged: (values) => setState(() {
            _tab = values.first;
            if (_tab == 2) _handovers = _loadHandovers();
          }),
        ),
        const SizedBox(height: 8),
        Expanded(
          child: _tab == 0
              ? _notificationList()
              : _tab == 1
                  ? _conversationList()
                  : _handoverList(),
        ),
      ],
    );
  }

  Widget _notificationList() {
    return FutureBuilder<List<Map<String, dynamic>>>(
      future: _notifications,
      builder: (context, snapshot) {
        if (snapshot.connectionState == ConnectionState.waiting) {
          return const Center(child: CircularProgressIndicator());
        }
        if (snapshot.hasError) {
          return _EmptyState(
            icon: Icons.cloud_off,
            title: 'Inbox unavailable',
            message: widget.api.errorMessage(snapshot.error!),
            action: FilledButton.tonal(
              onPressed: () => setState(() => _notifications = _load()),
              child: const Text('Try again'),
            ),
          );
        }
        final notifications = snapshot.data ?? const [];
        if (notifications.isEmpty) {
          return const _EmptyState(
            icon: Icons.mark_email_unread_outlined,
            title: 'Your inbox is clear',
            message: 'Claim updates, notifications, and approved conversations will appear here.',
          );
        }
        return RefreshIndicator(
          onRefresh: () async => setState(() => _notifications = _load()),
          child: ListView.builder(
            padding: const EdgeInsets.all(16),
            itemCount: notifications.length,
            itemBuilder: (context, index) {
              final notification = notifications[index];
              final data = notification['data'] as Map<String, dynamic>? ?? const {};
              final id = notification['id'] as String? ?? '';
              return Card(
                child: ListTile(
                  leading: Icon(
                    notification['read_at'] == null ? Icons.notifications_active : Icons.notifications_none,
                    color: const Color(0xFFA31621),
                  ),
                  title: Text(data['title'] as String? ?? 'CampusLF update'),
                  subtitle: Text(data['body'] as String? ?? ''),
                  onTap: id.isEmpty ? null : () => _markRead(id),
                ),
              );
            },
          ),
        );
      },
    );
  }

  Widget _conversationList() {
    return FutureBuilder<List<Map<String, dynamic>>>(
      future: _conversations,
      builder: (context, snapshot) {
        if (snapshot.connectionState == ConnectionState.waiting) {
          return const Center(child: CircularProgressIndicator());
        }
        if (snapshot.hasError) {
          return _EmptyState(
            icon: Icons.cloud_off,
            title: 'Chats unavailable',
            message: widget.api.errorMessage(snapshot.error!),
            action: FilledButton.tonal(
              onPressed: () => setState(() => _conversations = _loadConversations()),
              child: const Text('Try again'),
            ),
          );
        }
        final conversations = snapshot.data ?? const [];
        if (conversations.isEmpty) {
          return const _EmptyState(
            icon: Icons.chat_bubble_outline,
            title: 'No approved chats',
            message: 'Chats open after a TMC administrator approves a claim.',
          );
        }
        return ListView.builder(
          padding: const EdgeInsets.all(16),
          itemCount: conversations.length,
          itemBuilder: (context, index) {
            final conversation = conversations[index];
            return Card(
              child: ListTile(
                leading: const CircleAvatar(child: Icon(Icons.chat_outlined)),
                title: Text(conversation['item_name'] as String? ?? 'TMC item'),
                subtitle: Text('Chat with ${conversation['other_first_name'] ?? 'TMC member'}'),
                trailing: const Icon(Icons.chevron_right),
                onTap: () => Navigator.of(context).push(
                  MaterialPageRoute(
                    builder: (_) => ConversationScreen(
                      api: widget.api,
                      conversation: conversation,
                    ),
                  ),
                ),
              ),
            );
          },
        );
      },
    );
  }

  Future<void> _openHandover(Map<String, dynamic> handover) async {
    try {
      final sightingId = handover['sighting_id'] as int?;
      final conversationId = handover['conversation_id'] as int?;
      final response = sightingId != null
          ? await widget.api.dio.post<Map<String, dynamic>>(
              '/sightings/$sightingId/handover',
            )
          : await widget.api.dio.post<Map<String, dynamic>>(
              '/conversations/$conversationId/handover',
            );
      final code = response.data?['code'] as String?;
      final handoverId = response.data?['id'] as int?;
      if (code == null || handoverId == null || !mounted) return;
      final entry = TextEditingController(text: code);
      await showDialog<void>(
        context: context,
        builder: (dialogContext) => AlertDialog(
          title: const Text('Confirm item handover'),
          content: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                QrImageView(data: code, size: 190, semanticsLabel: 'Handover code QR'),
                SelectableText(code, style: Theme.of(context).textTheme.headlineSmall),
                Text('Valid until ${response.data?['expires_at'] ?? 'expiry time'}'),
                Text('Meet at ${handover['location'] ?? 'your selected TMC location'}'),
                const SizedBox(height: 12),
                const Text('Both people must confirm the exchange. No payments or rewards are handled here.'),
                const SizedBox(height: 12),
                TextField(
                  controller: entry,
                  keyboardType: TextInputType.number,
                  maxLength: 8,
                  decoration: const InputDecoration(labelText: 'Handover code'),
                ),
              ],
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(dialogContext),
              child: const Text('Close'),
            ),
            FilledButton(
              onPressed: () async {
                try {
                  final result = await widget.api.dio.post<Map<String, dynamic>>(
                    '/handovers/$handoverId/confirm',
                    data: {'code': entry.text.trim()},
                  );
                  if (dialogContext.mounted) Navigator.pop(dialogContext);
                  if (mounted) {
                    ScaffoldMessenger.of(context).showSnackBar(
                      SnackBar(
                        content: Text(
                          result.data?['message'] as String? ??
                              'Confirmation saved.',
                        ),
                      ),
                    );
                    setState(() => _handovers = _loadHandovers());
                  }
                } on DioException catch (error) {
                  if (dialogContext.mounted) {
                    ScaffoldMessenger.of(dialogContext).showSnackBar(
                      SnackBar(content: Text(widget.api.errorMessage(error))),
                    );
                  }
                }
              },
              child: const Text('Confirm handover'),
            ),
          ],
        ),
      );
      entry.dispose();
    } on DioException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(widget.api.errorMessage(error))),
        );
      }
    }
  }

  Widget _handoverList() {
    return FutureBuilder<List<Map<String, dynamic>>>(
      future: _handovers,
      builder: (context, snapshot) {
        if (snapshot.connectionState == ConnectionState.waiting) {
          return const Center(child: CircularProgressIndicator());
        }
        if (snapshot.hasError) {
          return _EmptyState(
            icon: Icons.cloud_off,
            title: 'Handovers unavailable',
            message: widget.api.errorMessage(snapshot.error!),
            action: FilledButton.tonal(
              onPressed: () => setState(() => _handovers = _loadHandovers()),
              child: const Text('Try again'),
            ),
          );
        }
        final handovers = snapshot.data ?? const [];
        if (handovers.isEmpty) {
          return const _EmptyState(
            icon: Icons.handshake_outlined,
            title: 'No handovers yet',
            message: 'Approved found-item claims and lost-item sightings will appear here once a handover is set up.',
          );
        }
        return RefreshIndicator(
          onRefresh: () async => setState(() => _handovers = _loadHandovers()),
          child: ListView.builder(
            padding: const EdgeInsets.all(16),
            itemCount: handovers.length,
            itemBuilder: (context, index) {
              final handover = handovers[index];
              final ownerConfirmed = handover['owner_confirmed_at'] != null;
              final finderConfirmed = handover['finder_confirmed_at'] != null;
              final verified = handover['verified'] == true;
              final itemType = (handover['type'] as String? ?? 'item').toUpperCase();
              return Card(
                child: ListTile(
                  leading: const CircleAvatar(
                    child: Icon(Icons.handshake_outlined),
                  ),
                  title: Text(handover['item_name'] as String? ?? 'TMC item'),
                  subtitle: Text(
                    '$itemType item · With ${handover['other_first_name'] ?? 'TMC member'}'
                    '\n${handover['location'] ?? 'Meet-up location not selected'}'
                    '\nOwner: ${ownerConfirmed ? 'confirmed' : 'waiting'} · Finder: ${finderConfirmed ? 'confirmed' : 'waiting'}'
                    '${verified ? '\nVerified at the TMC desk' : ''}',
                  ),
                  isThreeLine: true,
                  trailing: verified
                      ? const Icon(Icons.verified_outlined, color: Color(0xFF16836D))
                      : const Icon(Icons.chevron_right),
                  onTap: verified ? null : () => _openHandover(handover),
                ),
              );
            },
          ),
        );
      },
    );
  }
}

class ConversationScreen extends StatefulWidget {
  const ConversationScreen({
    required this.api,
    required this.conversation,
    super.key,
  });

  final ApiClient api;
  final Map<String, dynamic> conversation;

  @override
  State<ConversationScreen> createState() => _ConversationScreenState();
}

class _ConversationScreenState extends State<ConversationScreen> {
  final _message = TextEditingController();
  late Future<List<Map<String, dynamic>>> _messages;
  Timer? _polling;
  bool _sending = false;

  int get _conversationId => widget.conversation['id'] as int;

  @override
  void initState() {
    super.initState();
    _messages = _loadMessages();
    _polling = Timer.periodic(const Duration(seconds: 5), (_) {
      if (mounted) setState(() => _messages = _loadMessages());
    });
  }

  @override
  void dispose() {
    _polling?.cancel();
    _message.dispose();
    super.dispose();
  }

  Future<List<Map<String, dynamic>>> _loadMessages() async {
    final response = await widget.api.dio.get<Map<String, dynamic>>(
      '/conversations/$_conversationId/messages',
    );
    final data = response.data?['data'];
    if (data is! List) return const [];
    return data.whereType<Map<String, dynamic>>().toList();
  }

  Future<void> _send() async {
    final body = _message.text.trim();
    if (_sending || body.isEmpty) return;
    setState(() => _sending = true);
    try {
      await widget.api.dio.post<void>(
        '/conversations/$_conversationId/messages',
        data: {'body': body},
      );
      _message.clear();
      setState(() => _messages = _loadMessages());
    } on DioException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(widget.api.errorMessage(error))),
        );
      }
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  Future<void> _chooseLocation() async {
    try {
      final response = await widget.api.dio.get<Map<String, dynamic>>('/meetup-locations');
      final locations = response.data?['data'];
      if (locations is! List || locations.isEmpty || !mounted) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('No active TMC meet-up locations are available.')),
          );
        }
        return;
      }
      final selected = await showModalBottomSheet<Map<String, dynamic>>(
        context: context,
        builder: (context) => SafeArea(
          child: ListView(
            shrinkWrap: true,
            children: [
              const ListTile(title: Text('Choose a safe TMC meet-up location')),
              for (final item in locations.whereType<Map<String, dynamic>>())
                ListTile(
                  title: Text(item['name'] as String? ?? ''),
                  subtitle: Text(item['operating_hours'] as String? ?? ''),
                  onTap: () => Navigator.pop(context, item),
                ),
            ],
          ),
        ),
      );
      if (selected == null) return;
      await widget.api.dio.patch<void>(
        '/conversations/$_conversationId/location',
        data: {'location_id': selected['id']},
      );
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Meet-up location saved: ${selected['name']}')),
        );
      }
    } on DioException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(widget.api.errorMessage(error))),
        );
      }
    }
  }

  Future<void> _showHandoverCode() async {
    try {
      final response = await widget.api.dio.post<Map<String, dynamic>>(
        '/conversations/$_conversationId/handover',
      );
      final code = response.data?['code'] as String?;
      if (code == null || !mounted) return;
      final entry = TextEditingController(text: code);
      await showDialog<void>(
        context: context,
        builder: (context) => AlertDialog(
          title: const Text('Confirm item handover'),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              QrImageView(data: code, size: 190, semanticsLabel: 'Handover code QR'),
              SelectableText(code, style: Theme.of(context).textTheme.headlineSmall),
              Text('Valid until ${response.data?['expires_at'] ?? 'expiry time'}'),
              const SizedBox(height: 12),
              const Text('Both people must confirm the exchange. No payments or rewards are handled here.'),
              const SizedBox(height: 12),
              TextField(controller: entry, keyboardType: TextInputType.number, maxLength: 8, decoration: const InputDecoration(labelText: 'Handover code')),
            ],
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(context), child: const Text('Close')),
            FilledButton(
              onPressed: () async {
                try {
                  final result = await widget.api.dio.post<Map<String, dynamic>>(
                    '/handovers/${response.data?['id'] ?? ''}/confirm',
                    data: {'code': entry.text.trim()},
                  );
                  if (context.mounted) {
                    Navigator.pop(context);
                    ScaffoldMessenger.of(context).showSnackBar(
                      SnackBar(content: Text(result.data?['message'] as String? ?? 'Confirmation saved.')),
                    );
                  }
                } on DioException catch (error) {
                  if (context.mounted) {
                    ScaffoldMessenger.of(context).showSnackBar(
                      SnackBar(content: Text(widget.api.errorMessage(error))),
                    );
                  }
                }
              },
              child: const Text('Confirm handover'),
            ),
          ],
        ),
      );
      entry.dispose();
    } on DioException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(widget.api.errorMessage(error))),
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final itemName = widget.conversation['item_name'] as String? ?? 'TMC item';
    return Scaffold(
      appBar: AppBar(title: Text(itemName)),
      body: Column(
        children: [
          const MaterialBanner(
            content: Text('Do not share contact details. Meet only at an active TMC meet-up location.'),
            leading: Icon(Icons.shield_outlined),
            actions: [SizedBox.shrink()],
          ),
          Row(
            children: [
              Expanded(child: TextButton.icon(onPressed: _chooseLocation, icon: const Icon(Icons.place_outlined), label: const Text('Meet-up location'))),
              Expanded(child: TextButton.icon(onPressed: _showHandoverCode, icon: const Icon(Icons.qr_code_2), label: const Text('Handover'))),
            ],
          ),
          Expanded(
            child: FutureBuilder<List<Map<String, dynamic>>>(
              future: _messages,
              builder: (context, snapshot) {
                if (snapshot.connectionState == ConnectionState.waiting) {
                  return const Center(child: CircularProgressIndicator());
                }
                if (snapshot.hasError) {
                  return Center(child: Text(widget.api.errorMessage(snapshot.error!)));
                }
                final messages = snapshot.data ?? const [];
                if (messages.isEmpty) return const Center(child: Text('Start a safe, item-related conversation.'));
                return ListView.builder(
                  reverse: true,
                  padding: const EdgeInsets.all(16),
                  itemCount: messages.length,
                  itemBuilder: (context, index) {
                    final message = messages[messages.length - index - 1];
                    return Align(
                      alignment: Alignment.centerLeft,
                      child: Card(
                        child: Padding(
                          padding: const EdgeInsets.all(12),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(message['sender_first_name'] as String? ?? 'TMC member', style: const TextStyle(fontWeight: FontWeight.w700)),
                              Text(message['body'] as String? ?? ''),
                            ],
                          ),
                        ),
                      ),
                    );
                  },
                );
              },
            ),
          ),
          SafeArea(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(12, 8, 12, 8),
              child: Row(
                children: [
                  Expanded(child: TextField(controller: _message, maxLength: 2000, decoration: const InputDecoration(labelText: 'Message'))),
                  IconButton(tooltip: 'Send message', onPressed: _sending ? null : _send, icon: const Icon(Icons.send, color: Color(0xFFA31621))),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class ProfileScreen extends StatefulWidget {
  const ProfileScreen({
    required this.firstName,
    required this.api,
    required this.auth,
    required this.onSignedOut,
    super.key,
  });

  final String firstName;
  final ApiClient api;
  final AuthService auth;
  final VoidCallback onSignedOut;

  @override
  State<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends State<ProfileScreen> {
  final _feedbackFormKey = GlobalKey<FormState>();
  final _feedbackMessage = TextEditingController();
  String _feedbackCategory = 'suggestion';
  bool _sendingFeedback = false;

  @override
  void dispose() {
    _feedbackMessage.dispose();
    super.dispose();
  }

  Future<void> _sendFeedback() async {
    if (!_feedbackFormKey.currentState!.validate()) return;
    setState(() => _sendingFeedback = true);
    try {
      final response = await widget.api.dio.post<Map<String, dynamic>>(
        '/feedback',
        data: {
          'category': _feedbackCategory,
          'message': _feedbackMessage.text.trim(),
        },
      );
      if (!mounted) return;
      _feedbackMessage.clear();
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            response.data?['message'] as String? ??
                'Thank you. Your feedback was sent.',
          ),
        ),
      );
    } on DioException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(widget.api.errorMessage(error))),
        );
      }
    } finally {
      if (mounted) setState(() => _sendingFeedback = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: const EdgeInsets.all(20),
      children: [
        CircleAvatar(
          radius: 34,
          backgroundColor: const Color(0xFFF2E4E1),
          child: Text(widget.firstName.isEmpty ? '?' : widget.firstName[0].toUpperCase(), style: const TextStyle(fontSize: 26, color: Color(0xFFA31621))),
        ),
        const SizedBox(height: 12),
        Center(child: Text(widget.firstName, style: Theme.of(context).textTheme.titleLarge)),
        const SizedBox(height: 24),
        const Card(child: ListTile(leading: Icon(Icons.privacy_tip_outlined), title: Text('Privacy and disclaimer'), subtitle: Text('CampusLF connects people; it does not keep or insure items.'))),
        const SizedBox(height: 12),
        Card(
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Form(
              key: _feedbackFormKey,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text('Send feedback', style: Theme.of(context).textTheme.titleMedium),
                  const SizedBox(height: 8),
                  const Text('Share a suggestion, report a problem, or tell us about your experience.'),
                  const SizedBox(height: 12),
                  DropdownButtonFormField<String>(
                    initialValue: _feedbackCategory,
                    decoration: const InputDecoration(labelText: 'Feedback type'),
                    items: const [
                      DropdownMenuItem(value: 'suggestion', child: Text('Suggestion')),
                      DropdownMenuItem(value: 'bug', child: Text('Report a problem')),
                      DropdownMenuItem(value: 'experience', child: Text('My experience')),
                      DropdownMenuItem(value: 'other', child: Text('Other')),
                    ],
                    onChanged: _sendingFeedback
                        ? null
                        : (value) {
                            if (value != null) {
                              setState(() => _feedbackCategory = value);
                            }
                          },
                  ),
                  const SizedBox(height: 8),
                  TextFormField(
                    controller: _feedbackMessage,
                    minLines: 3,
                    maxLines: 5,
                    maxLength: 3000,
                    decoration: const InputDecoration(
                      labelText: 'Your feedback',
                      alignLabelWithHint: true,
                      border: OutlineInputBorder(),
                    ),
                    validator: (value) {
                      final message = value?.trim() ?? '';
                      if (message.length < 10) {
                        return 'Please enter at least 10 characters.';
                      }
                      return null;
                    },
                  ),
                  const SizedBox(height: 8),
                  FilledButton.icon(
                    onPressed: _sendingFeedback ? null : _sendFeedback,
                    icon: _sendingFeedback
                        ? const SizedBox(
                            width: 18,
                            height: 18,
                            child: CircularProgressIndicator(
                              strokeWidth: 2,
                              color: Colors.white,
                            ),
                          )
                        : const Icon(Icons.send),
                    label: Text(_sendingFeedback ? 'Sending...' : 'Send feedback'),
                  ),
                ],
              ),
            ),
          ),
        ),
        const SizedBox(height: 20),
        FilledButton.tonalIcon(
          onPressed: () async {
            try {
              await widget.auth.logout();
              widget.onSignedOut();
            } on DioException catch (error) {
              if (context.mounted) {
                widget.onSignedOut();
                ScaffoldMessenger.of(context).showSnackBar(
                  SnackBar(content: Text(authErrorMessage(error))),
                );
              }
            }
          },
          icon: const Icon(Icons.logout),
          label: const Text('Log out'),
        ),
      ],
    );
  }
}

String authErrorMessage(DioException error) {
  final response = error.response?.data;
  if (response is Map<String, dynamic> && response['message'] is String) {
    return response['message'] as String;
  }
  return 'Could not reach the server. Your local session was cleared.';
}

class _EmptyState extends StatelessWidget {
  const _EmptyState({
    required this.icon,
    required this.title,
    required this.message,
    this.action,
  });

  final IconData icon;
  final String title;
  final String message;
  final Widget? action;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(28),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, size: 48, color: const Color(0xFFA31621)),
            const SizedBox(height: 12),
            Text(title, style: Theme.of(context).textTheme.titleLarge, textAlign: TextAlign.center),
            const SizedBox(height: 8),
            Text(message, textAlign: TextAlign.center, style: const TextStyle(color: Color(0xFF6B6262))),
            if (action != null) ...[const SizedBox(height: 18), action!],
          ],
        ),
      ),
    );
  }
}
