import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:image_picker/image_picker.dart';
import 'package:pdf/widgets.dart' as pw;
import 'package:printing/printing.dart';
import 'package:share_plus/share_plus.dart';

import '../core/api_client.dart';
import '../core/config.dart';
import '../core/theme.dart';
import '../models/campus_post.dart';
import '../services/auth_service.dart';
import '../widgets/campuslf_brand_mark.dart';

/// Locally applied reaction status so a tap updates the count and highlight
/// immediately, before the API responds.
class _ReactionState {
  const _ReactionState(this.count, this.reaction);

  final int count;
  final String? reaction;
}

CampusPost _applyReaction(CampusPost post, Map<int, _ReactionState> overrides) {
  final override = overrides[post.id];
  if (override == null) return post;
  return post.withReaction(
    reactionsCount: override.count,
    myReaction: override.reaction,
  );
}

String _resolveMediaUrl(String url) {
  final uri = Uri.tryParse(url);
  if (uri == null || uri.hasScheme) return url;

  final apiUri = Uri.parse(AppConfig.apiBaseUrl);
  final rootUri = apiUri.replace(
    pathSegments: apiUri.pathSegments.take(
      apiUri.pathSegments.length > 2 ? apiUri.pathSegments.length - 2 : 0,
    ),
    query: null,
    fragment: null,
  );
  return rootUri.resolveUri(uri).toString();
}

class _PostPhotoSlideshow extends StatefulWidget {
  const _PostPhotoSlideshow({
    super.key,
    required this.images,
    this.expand = false,
    this.borderRadius = 12,
  });

  final List<Map<String, String>> images;
  final bool expand;
  final double borderRadius;

  @override
  State<_PostPhotoSlideshow> createState() => _PostPhotoSlideshowState();
}

class _PostPhotoSlideshowState extends State<_PostPhotoSlideshow> {
  final PageController _controller = PageController();
  int _currentPage = 0;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final photos = widget.images
        .where((image) => image['url']?.isNotEmpty == true)
        .toList();
    if (photos.isEmpty) return const SizedBox.shrink();

    final slideshow = ClipRRect(
      borderRadius: BorderRadius.circular(widget.borderRadius),
      child: Stack(
        fit: StackFit.expand,
        children: [
          PageView.builder(
            controller: _controller,
            itemCount: photos.length,
            onPageChanged: (page) => setState(() => _currentPage = page),
            itemBuilder: (context, index) => Image.network(
              _resolveMediaUrl(photos[index]['url']!),
              fit: BoxFit.cover,
              semanticLabel: photos[index]['alt_text'],
              errorBuilder: (context, _, _) => ColoredBox(
                color: Theme.of(context).colorScheme.surfaceContainerHighest,
                child: Center(
                  child: Icon(
                    Icons.image_not_supported_outlined,
                    color: Theme.of(context).colorScheme.primary,
                  ),
                ),
              ),
            ),
          ),
          if (photos.length > 1)
            Positioned(
              right: 6,
              bottom: 6,
              child: Container(
                padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 3),
                decoration: BoxDecoration(
                  color: Colors.black54,
                  borderRadius: BorderRadius.circular(20),
                ),
                child: Text(
                  '${_currentPage + 1}/${photos.length}',
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 9,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ),
            ),
        ],
      ),
    );

    return widget.expand
        ? SizedBox.expand(child: slideshow)
        : AspectRatio(aspectRatio: 1, child: slideshow);
  }
}

Future<void> _viewEvidence(
  BuildContext context,
  String title,
  List<String> imageUrls,
) {
  final urls = imageUrls.where((url) => url.isNotEmpty).toList();
  return showDialog<void>(
    context: context,
    builder: (context) => Dialog(
      child: SizedBox(
        width: 600,
        height: MediaQuery.sizeOf(context).height * 0.75,
        child: Column(
          children: [
            ListTile(
              title: Text(title),
              trailing: IconButton(
                tooltip: 'Close',
                onPressed: () => Navigator.pop(context),
                icon: const Icon(Icons.close),
              ),
            ),
            Expanded(
              child: PageView.builder(
                itemCount: urls.length,
                itemBuilder: (context, index) => InteractiveViewer(
                  child: Image.network(
                    _resolveMediaUrl(urls[index]),
                    fit: BoxFit.contain,
                    errorBuilder: (_, _, _) => const Center(
                      child: Text('Evidence photo could not be loaded.'),
                    ),
                  ),
                ),
              ),
            ),
            if (urls.length > 1)
              Padding(
                padding: const EdgeInsets.all(8),
                child: Text('Swipe to view all ${urls.length} photos'),
              ),
          ],
        ),
      ),
    ),
  );
}

Map<int, List<Map<String, dynamic>>> _groupByPost(
  List<Map<String, dynamic>> submissions,
) {
  final groups = <int, List<Map<String, dynamic>>>{};
  for (final submission in submissions) {
    final postId = int.tryParse('${submission['post_id']}');
    if (postId != null) {
      groups.putIfAbsent(postId, () => []).add(submission);
    }
  }
  return groups;
}

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
  String _search = '';
  String _status = '';
  int? _categoryId;
  int _communityMode = 0;
  Future<List<CampusPost>>? _posts;
  Future<List<Map<String, dynamic>>>? _people;
  List<Map<String, dynamic>> _categories = [];
  Timer? _feedSearchTimer;
  Timer? _peopleSearchTimer;
  final TextEditingController _homeSearchController = TextEditingController();
  final TextEditingController _peopleSearchController = TextEditingController();
  final Map<int, _ReactionState> _reactionOverrides = {};
  int _inboxRefreshKey = 0;
  int _profileRefreshKey = 0;
  int _postRefreshKey = 0;
  final ScrollController _feedScrollController = ScrollController();
  int? _returnToInboxTab;

  @override
  void initState() {
    super.initState();
    _posts = _fetchPosts();
    _people = _fetchPeople();
    _loadFeedCategories();
  }

  Future<List<CampusPost>> _fetchPosts() async {
    final response = await widget.api.dio.get<Map<String, dynamic>>(
      '/posts',
      queryParameters: {
        'type': _communityMode == 1 ? 'found' : 'lost',
        if (_status.isNotEmpty) 'status': _status,
        if (_categoryId != null) 'category_id': _categoryId,
        if (_search.trim().isNotEmpty) 'search': _search.trim(),
      },
    );
    final data = response.data?['data'];
    if (data is! List) {
      throw const FormatException('The feed response is invalid.');
    }
    return data
        .whereType<Map<String, dynamic>>()
        .map(CampusPost.fromJson)
        .toList();
  }

  void _refreshFeed() {
    setState(() {
      _posts = _fetchPosts();
    });
  }

  void _scheduleFeedSearch(String value) {
    _feedSearchTimer?.cancel();
    _feedSearchTimer = Timer(const Duration(milliseconds: 300), () {
      _applyFeedSearch(value);
    });
  }

  void _applyFeedSearch(String value) {
    _feedSearchTimer?.cancel();
    if (!mounted) return;
    final search = value.trim();
    if (search == _search) return;
    setState(() {
      _search = search;
      _posts = _fetchPosts();
    });
  }

  Future<CampusPost?> _findOwnPost(int postId) async {
    var page = 1;
    while (true) {
      final response = await widget.api.dio.get<Map<String, dynamic>>(
        '/my-posts',
        queryParameters: {'page': page},
      );
      final payload = response.data;
      final data = payload?['data'];
      if (data is! List) {
        throw const FormatException('The posts response is invalid.');
      }
      for (final item in data.whereType<Map<String, dynamic>>()) {
        final post = CampusPost.fromJson(item);
        if (post.id == postId) return post;
      }

      final meta = payload?['meta'];
      final lastPage = meta is Map
          ? int.tryParse('${meta['last_page']}') ?? page
          : page;
      if (page >= lastPage) return null;
      page++;
    }
  }

  Future<CampusPost> _loadPostForFeed(
    int postId, {
    required bool isOwner,
  }) async {
    if (isOwner) {
      final ownPost = await _findOwnPost(postId);
      if (ownPost != null) return ownPost;
    }

    final response = await widget.api.dio.get<Map<String, dynamic>>(
      '/posts/$postId',
    );
    final rawPost = response.data?['data'];
    final postData = rawPost is Map<String, dynamic> ? rawPost : response.data;
    if (postData == null || postData['id'] is! int) {
      throw const FormatException('The post response is invalid.');
    }
    return CampusPost.fromJson(postData);
  }

  Future<void> _openPostInFeed(
    int postId, {
    required bool isOwner,
    int? returnInboxTab,
  }) async {
    late final CampusPost post;
    try {
      post = await _loadPostForFeed(postId, isOwner: isOwner);
    } on DioException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(widget.api.errorMessage(error))));
      }
      return;
    } on FormatException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(error.message)));
      }
      return;
    } on TypeError {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('The post response is invalid.')),
        );
      }
      return;
    }

    if (!mounted) return;
    _homeSearchController.clear();
    setState(() {
      _tab = 0;
      _openHandovers = false;
      _returnToInboxTab = returnInboxTab;
      _communityMode = 0;
      _search = '';
      _status = '';
      _categoryId = null;
      _posts = Future.value([post]);
    });
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (_feedScrollController.hasClients) {
        _feedScrollController.jumpTo(0);
      }
    });

    try {
      final feedPosts = await _fetchPosts();
      if (!mounted) return;
      setState(() {
        _posts = Future.value([
          post,
          ...feedPosts.where((feedPost) => feedPost.id != postId),
        ]);
      });
    } on DioException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(widget.api.errorMessage(error))));
      }
    } on FormatException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context)
            .showSnackBar(SnackBar(content: Text(error.message)));
      }
    } on TypeError {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('The feed response is invalid.')),
        );
      }
    }
  }

  CampusPost _postWithReaction(CampusPost post) =>
      _applyReaction(post, _reactionOverrides);

  /// Re-tapping the current bottom navigation destination refreshes that page.
  void _refreshActiveTab(int index) {
    if (index == 0) {
      setState(() {
        _posts = _fetchPosts();
        _people = _fetchPeople();
      });
      unawaited(_loadFeedCategories());
    } else if (index == 2) {
      setState(() => _inboxRefreshKey++);
    } else if (index == 3) {
      setState(() => _profileRefreshKey++);
    } else if (index == 1) {
      unawaited(_refreshPostPage());
    }
  }

  Future<void> _refreshPostPage() async {
    if (!mounted) return;
    setState(() => _postRefreshKey++);
    await WidgetsBinding.instance.endOfFrame;
  }

  Future<void> _openUserProfile(int userId) async {
    try {
      final response = await widget.api.dio.get<Map<String, dynamic>>(
        '/profile',
      );
      if (!mounted) return;
      if (response.data?['id'] == userId) {
        if (Navigator.of(context).canPop()) {
          Navigator.of(context).popUntil((route) => route.isFirst);
        }
        setState(() => _tab = 3);
        return;
      }
      await Navigator.of(context).push(
        MaterialPageRoute<void>(
          builder: (_) => PublicProfileScreen(
            userId: userId,
            api: widget.api,
            onOpenProfile: _openUserProfile,
          ),
        ),
      );
    } on DioException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(widget.api.errorMessage(error))));
      }
    }
  }

  Future<void> _loadFeedCategories() async {
    try {
      final response = await widget.api.dio.get<Map<String, dynamic>>(
        '/catalog',
      );
      final data = response.data?['categories'];
      if (mounted && data is List) {
        setState(() {
          _categories = data.whereType<Map<String, dynamic>>().toList();
        });
      }
    } on DioException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(widget.api.errorMessage(error))));
      }
    }
  }

  Future<List<Map<String, dynamic>>> _fetchPeople() async {
    final response = await widget.api.dio.get<Map<String, dynamic>>(
      '/people',
      queryParameters: {
        if (_peopleSearchController.text.trim().isNotEmpty)
          'search': _peopleSearchController.text.trim(),
      },
    );
    final data = response.data?['data'];
    if (data is! List) {
      throw const FormatException('The people response is invalid.');
    }
    return data.whereType<Map<String, dynamic>>().toList();
  }

  void _searchPeople(String _) {
    _peopleSearchTimer?.cancel();
    _peopleSearchTimer = Timer(const Duration(milliseconds: 300), () {
      if (mounted) {
        setState(() {
          _people = _fetchPeople();
        });
      }
    });
  }

  Future<void> _reactToPost(CampusPost post) async {
    final current = _postWithReaction(post);
    final wasLiked = current.myReaction == 'like';
    final optimisticCount = current.reactionsCount + (wasLiked ? -1 : 1);
    setState(() {
      _reactionOverrides[post.id] = _ReactionState(
        optimisticCount < 0 ? 0 : optimisticCount,
        wasLiked ? null : 'like',
      );
    });
    try {
      final response = await widget.api.dio.post<Map<String, dynamic>>(
        '/posts/${post.id}/reactions',
        data: {'type': wasLiked ? 'none' : 'like'},
      );
      if (!mounted) return;
      setState(() {
        _reactionOverrides[post.id] = _ReactionState(
          response.data?['reactions_count'] as int? ?? optimisticCount,
          response.data?['reaction'] as String?,
        );
      });
    } on DioException catch (error) {
      if (!mounted) return;
      setState(() {
        _reactionOverrides[post.id] = _ReactionState(
          current.reactionsCount,
          current.myReaction,
        );
      });
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(widget.api.errorMessage(error))));
    }
  }

  Future<void> _sharePost(CampusPost post) async {
    await SharePlus.instance.share(
      ShareParams(
        text:
            '${post.type.toUpperCase()} item: ${post.itemName}\n'
            '${post.description}\n'
            'Category: ${post.category} · ${post.location}\n'
            'Posted on CampusLF.',
        subject: 'CampusLF ${post.type} item',
      ),
    );
  }

  void _openHandoverInbox() {
    setState(() {
      _tab = 2;
      _openHandovers = true;
    });
  }

  bool _openHandovers = false;

  Future<void> _editOwnedPost(CampusPost post) async {
    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (sheetContext) => CreatePostScreen(
        api: widget.api,
        initialPost: post,
        onCreated: () {
          Navigator.of(sheetContext).pop();
          _refreshFeed();
        },
      ),
    );
  }

  Future<void> _deleteOwnedPost(CampusPost post) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: const Text('Delete your post?'),
        content: Text(
          '“${post.itemName}” will be removed. This cannot be undone.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(dialogContext, false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(dialogContext, true),
            child: const Text('Delete post'),
          ),
        ],
      ),
    );
    if (confirmed != true) return;
    try {
      await widget.api.dio.delete<void>('/posts/${post.id}');
      if (mounted) {
        _refreshFeed();
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(const SnackBar(content: Text('Your post was deleted.')));
      }
    } on DioException catch (error) {
      final response = error.response?.data;
      final requiresConfirmation =
          error.response?.statusCode == 409 &&
          response is Map &&
          response['requires_confirmation'] == true;
      if (requiresConfirmation && mounted) {
        final confirmPending = await showDialog<bool>(
          context: context,
          builder: (dialogContext) => AlertDialog(
            title: const Text('Pending requests'),
            content: const Text(
              'People have pending claims or evidence reviews for this post. '
              'Deleting it will notify them. Continue?',
            ),
            actions: [
              TextButton(
                onPressed: () => Navigator.pop(dialogContext, false),
                child: const Text('Keep post'),
              ),
              FilledButton(
                onPressed: () => Navigator.pop(dialogContext, true),
                child: const Text('Delete and notify'),
              ),
            ],
          ),
        );
        if (confirmPending != true) return;
        try {
          await widget.api.dio.delete<void>(
            '/posts/${post.id}',
            queryParameters: {'confirm_pending': true},
          );
          if (mounted) {
            _refreshFeed();
            ScaffoldMessenger.of(context).showSnackBar(
              const SnackBar(content: Text('Your post was deleted.')),
            );
          }
          return;
        } on DioException catch (retryError) {
          if (mounted) {
            ScaffoldMessenger.of(context).showSnackBar(
              SnackBar(content: Text(widget.api.errorMessage(retryError))),
            );
          }
          return;
        }
      }
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(widget.api.errorMessage(error))));
      }
    }
  }

  Future<void> _requestPostRetrieved(CampusPost post) async {
    final reason = await showDialog<String>(
      context: context,
      builder: (_) => const _TextEntryDialog(
        title: 'Request retrieved status',
        description: 'TMC administrators will review your request before this post is marked as retrieved.',
        label: 'Optional details',
        actionLabel: 'Send request',
        maxLines: 3,
        maxLength: 1000,
      ),
    );
    if (reason == null) return;

    try {
      final response = await widget.api.dio.post<Map<String, dynamic>>(
        '/posts/${post.id}/retrieval-request',
        data: {'reason': reason},
      );
      if (mounted) {
        _refreshFeed();
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
              response.data?['message'] as String? ??
                  'Your request was sent for administrator approval.',
            ),
          ),
        );
      }
    } on DioException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(widget.api.errorMessage(error))));
      }
    }
  }

  @override
  void dispose() {
    _homeSearchController.dispose();
    _peopleSearchController.dispose();
    _feedSearchTimer?.cancel();
    _peopleSearchTimer?.cancel();
    _feedScrollController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    const titles = ['Community Feed', 'New post', 'Inbox', 'Profile'];
    final secondaryPageDecoration = BoxDecoration(
      gradient: LinearGradient(
        colors: [
          Theme.of(context).colorScheme.surface,
          Theme.of(context).scaffoldBackgroundColor,
        ],
        begin: Alignment.topCenter,
        end: Alignment.center,
      ),
    );
    final pages = [
      _feedPage(),
      CreatePostScreen(
        key: ValueKey('new-post-$_postRefreshKey'),
        api: widget.api,
        onRefresh: _refreshPostPage,
        onCreated: () {
          setState(() {
            _tab = 0;
            _posts = _fetchPosts();
            _postRefreshKey++;
          });
        },
      ),
      DecoratedBox(
        decoration: secondaryPageDecoration,
        child: InboxScreen(
          key: ValueKey(
            'inbox-${_openHandovers ? 'handovers' : 'chats'}-$_inboxRefreshKey',
          ),
          api: widget.api,
          onOpenProfile: _openUserProfile,
          onOpenPost: _openPostInFeed,
          initialTab: _openHandovers ? 2 : 0,
        ),
      ),
      DecoratedBox(
        decoration: secondaryPageDecoration,
        child: ProfileScreen(
          key: ValueKey('profile-$_profileRefreshKey'),
          firstName: widget.firstName,
          api: widget.api,
          auth: widget.auth,
          onEditPost: _editOwnedPost,
          onDeletePost: _deleteOwnedPost,
          onRequestRetrieved: _requestPostRetrieved,
          onOpenHandovers: _openHandoverInbox,
          onSignedOut: widget.onSignedOut,
        ),
      ),
    ];
    return Scaffold(
      appBar: AppBar(
        leading: _returnToInboxTab == null
            ? null
            : IconButton(
                tooltip: _returnToInboxTab == 3
                    ? 'Back to claims'
                    : 'Back to evidence',
                onPressed: () => setState(() {
                  _tab = 2;
                  _returnToInboxTab = null;
                }),
                icon: const Icon(Icons.arrow_back),
              ),
        title: Row(
          children: [
            const CampusLfBrandMark(),
            const SizedBox(width: 10),
            Text(titles[_tab]),
          ],
        ),
      ),
      body: IndexedStack(index: _tab, children: pages),
      bottomNavigationBar: NavigationBar(
        selectedIndex: _tab,
        onDestinationSelected: (index) {
          if (index == _tab) {
            _refreshActiveTab(index);
            return;
          }
          setState(() {
            _tab = index;
            _returnToInboxTab = null;
            if (index != 2) _openHandovers = false;
          });
        },
        destinations: const [
          NavigationDestination(
            icon: Icon(Icons.home_outlined),
            selectedIcon: Icon(Icons.home),
            label: 'Home',
          ),
          NavigationDestination(
            icon: Icon(Icons.add_circle_outline),
            selectedIcon: Icon(Icons.add_circle),
            label: 'Post',
          ),
          NavigationDestination(
            icon: Icon(Icons.inbox_outlined),
            selectedIcon: Icon(Icons.inbox),
            label: 'Inbox',
          ),
          NavigationDestination(
            icon: Icon(Icons.person_outline),
            selectedIcon: Icon(Icons.person),
            label: 'Profile',
          ),
        ],
      ),
    );
  }

  Widget _feedPage() {
    return CustomScrollView(
      controller: _feedScrollController,
      slivers: [
        SliverToBoxAdapter(
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16),
            child: SegmentedButton<int>(
              segments: const [
                ButtonSegment(
                  value: 0,
                  label: Text('Lost'),
                  icon: Icon(Icons.search),
                ),
                ButtonSegment(
                  value: 1,
                  label: Text('Found'),
                  icon: Icon(Icons.check_circle_outline),
                ),
                ButtonSegment(
                  value: 2,
                  label: Text('People'),
                  icon: Icon(Icons.people_outline),
                ),
              ],
              selected: {_communityMode},
              onSelectionChanged: (selection) {
                final mode = selection.first;
                setState(() {
                  _communityMode = mode;
                  if (mode < 2) _posts = _fetchPosts();
                });
              },
            ),
          ),
        ),
        SliverToBoxAdapter(
          child: _communityMode < 2
              ? Padding(
                  padding: const EdgeInsets.fromLTRB(16, 10, 16, 6),
                  child: Card(
                    margin: EdgeInsets.zero,
                    elevation: 0,
                    shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(16),
                      side: BorderSide(color: CampusTheme.border),
                    ),
                    child: Padding(
                      padding: const EdgeInsets.all(14),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Row(
                            children: [
                              Expanded(
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Text(
                                      'Browse ${_communityMode == 0 ? 'lost' : 'found'} items',
                                      style: TextStyle(
                                        fontSize: 17,
                                        fontWeight: FontWeight.w800,
                                        color: Theme.of(context)
                                            .colorScheme
                                            .onSurface,
                                      ),
                                    ),
                                    SizedBox(height: 3),
                                    Text(
                                      'Search, filter, and browse ${_communityMode == 0 ? 'lost' : 'found'} items.',
                                      style: TextStyle(
                                        fontSize: 11,
                                        color: Theme.of(context)
                                            .colorScheme
                                            .onSurfaceVariant,
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                              FutureBuilder<List<CampusPost>>(
                                future: _posts,
                                builder: (context, snapshot) => Container(
                                  padding: const EdgeInsets.symmetric(
                                    horizontal: 10,
                                    vertical: 6,
                                  ),
                                  decoration: BoxDecoration(
                                    color: Theme.of(context)
                                        .colorScheme
                                        .surfaceContainerHighest,
                                    borderRadius: BorderRadius.circular(20),
                                  ),
                                  child: Text(
                                    '${snapshot.data?.length ?? 0} items',
                                    style: TextStyle(
                                      color: Theme.of(context)
                                          .colorScheme
                                          .primary,
                                      fontSize: 11,
                                      fontWeight: FontWeight.w700,
                                    ),
                                  ),
                                ),
                              ),
                            ],
                          ),
                          const SizedBox(height: 12),
                          TextField(
                            controller: _homeSearchController,
                            textInputAction: TextInputAction.search,
                            onChanged: _scheduleFeedSearch,
                            onSubmitted: _applyFeedSearch,
                            decoration: const InputDecoration(
                              labelText: 'Search',
                              hintText: 'Item, description, or person',
                              prefixIcon: Icon(Icons.search_rounded),
                              isDense: true,
                            ),
                          ),
                          const SizedBox(height: 10),
                          LayoutBuilder(
                            builder: (context, constraints) {
                              const columns = 2;
                              final gap = 8.0;
                              final width =
                                  (constraints.maxWidth - gap * (columns - 1)) /
                                  columns;
                              return Wrap(
                                spacing: gap,
                                runSpacing: 8,
                                children: [
                                  SizedBox(
                                    width: width,
                                    child: DropdownButtonFormField<String>(
                                      initialValue: _status,
                                      isExpanded: true,
                                      decoration: const InputDecoration(
                                        labelText: 'Status',
                                        isDense: true,
                                        contentPadding: EdgeInsets.symmetric(
                                          horizontal: 12,
                                          vertical: 8,
                                        ),
                                      ),
                                      items: const [
                                        DropdownMenuItem(
                                          value: '',
                                          child: Text('All statuses'),
                                        ),
                                        DropdownMenuItem(
                                          value: 'active',
                                          child: Text('Active'),
                                        ),
                                        DropdownMenuItem(
                                          value: 'claim_pending',
                                          child: Text('Claim pending'),
                                        ),
                                        DropdownMenuItem(
                                          value: 'handover_pending',
                                          child: Text('Handover pending'),
                                        ),
                                      ],
                                      onChanged: (value) {
                                        setState(() {
                                          _status = value ?? '';
                                          _posts = _fetchPosts();
                                        });
                                      },
                                    ),
                                  ),
                                  SizedBox(
                                    width: width,
                                    child: DropdownButtonFormField<int?>(
                                      initialValue: _categoryId,
                                      isExpanded: true,
                                      decoration: const InputDecoration(
                                        labelText: 'Category',
                                        isDense: true,
                                        contentPadding: EdgeInsets.symmetric(
                                          horizontal: 12,
                                          vertical: 8,
                                        ),
                                      ),
                                      items: [
                                        const DropdownMenuItem<int?>(
                                          value: null,
                                          child: Text('All categories'),
                                        ),
                                        ..._categories.map(
                                          (category) => DropdownMenuItem<int?>(
                                            value: category['id'] as int,
                                            child: Text(
                                              category['name'] as String? ??
                                                  'Category',
                                              overflow: TextOverflow.ellipsis,
                                            ),
                                          ),
                                        ),
                                      ],
                                      onChanged: (value) => setState(() {
                                        _categoryId = value;
                                        _posts = _fetchPosts();
                                      }),
                                    ),
                                  ),
                                ],
                              );
                            },
                          ),
                        ],
                      ),
                    ),
                  ),
                )
              : Padding(
                  padding: const EdgeInsets.fromLTRB(16, 10, 16, 6),
                  child: TextField(
                    controller: _peopleSearchController,
                    onChanged: _searchPeople,
                    decoration: const InputDecoration(
                      hintText: 'Search people by name or department',
                      prefixIcon: Icon(Icons.person_search_outlined),
                    ),
                  ),
                ),
        ),
        if (_communityMode < 2) _feedPostsSliver() else _feedPeopleSliver(),
      ],
    );
  }

  Widget _feedPostsSliver() {
    return FutureBuilder<List<CampusPost>>(
      future: _posts,
      builder: (context, snapshot) {
        if (snapshot.connectionState == ConnectionState.waiting) {
          return const SliverFillRemaining(
            hasScrollBody: false,
            child: Center(child: CircularProgressIndicator()),
          );
        }
        if (snapshot.hasError) {
          return SliverFillRemaining(
            hasScrollBody: false,
            child: _EmptyState(
              icon: Icons.cloud_off_outlined,
              title: 'Could not load posts',
              message: widget.api.errorMessage(snapshot.error!),
              action: FilledButton.tonal(
                onPressed: _refreshFeed,
                child: const Text('Try again'),
              ),
            ),
          );
        }
        final posts = snapshot.data ?? const [];
        if (posts.isEmpty) {
          return SliverFillRemaining(
            hasScrollBody: false,
            child: _EmptyState(
              icon: Icons.search_off,
              title: 'No posts found',
              message: 'Try another search or check back later.',
            ),
          );
        }
        return SliverPadding(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 20),
          sliver: SliverToBoxAdapter(
            child: _FlexiblePostGrid(
              itemCount: posts.length,
              itemBuilder: (context, index) {
                final post = _postWithReaction(posts[index]);
                return _PostCard(
                  post: post,
                  compact: true,
                  onTapAuthor: post.authorId == null
                      ? () {}
                      : () => _openUserProfile(post.authorId!),
                  onOwnerEdit: () => _editOwnedPost(post),
                  onOwnerDelete: () => _deleteOwnedPost(post),
                  onRequestRetrieved: () => _requestPostRetrieved(post),
                  onReport: () =>
                      unawaited(_reportPost(context, widget.api, post)),
                  onTap: () => showModalBottomSheet<void>(
                    context: context,
                    isScrollControlled: true,
                    builder: (_) => _PostDetailSheet(
                      api: widget.api,
                      post: post,
                      onOpenProfile: _openUserProfile,
                      onClaimChanged: _refreshFeed,
                      onReactionChanged: (reaction, count) {
                        if (!mounted) return;
                        setState(() {
                          _reactionOverrides[post.id] = _ReactionState(
                            count,
                            reaction,
                          );
                        });
                      },
                    ),
                  ),
                  onReact: () => _reactToPost(post),
                  onShare: () => _sharePost(post),
                  onPrimaryAction: () {
                    if (post.status == 'handover_pending') {
                      _openHandoverInbox();
                    } else {
                      showModalBottomSheet<void>(
                        context: context,
                        isScrollControlled: true,
                        builder: (_) => _PostDetailSheet(
                          api: widget.api,
                          post: post,
                          onOpenProfile: _openUserProfile,
                          onClaimChanged: _refreshFeed,
                          onReactionChanged: (reaction, count) {
                            if (!mounted) return;
                            setState(() {
                              _reactionOverrides[post.id] = _ReactionState(
                                count,
                                reaction,
                              );
                            });
                          },
                        ),
                      );
                    }
                  },
                );
              },
            ),
          ),
        );
      },
    );
  }

  Widget _feedPeopleSliver() {
    return FutureBuilder<List<Map<String, dynamic>>>(
      future: _people,
      builder: (context, snapshot) {
        if (snapshot.connectionState == ConnectionState.waiting) {
          return const SliverFillRemaining(
            hasScrollBody: false,
            child: Center(child: CircularProgressIndicator()),
          );
        }
        if (snapshot.hasError) {
          return SliverFillRemaining(
            hasScrollBody: false,
            child: _EmptyState(
              icon: Icons.cloud_off_outlined,
              title: 'Could not load people',
              message: widget.api.errorMessage(snapshot.error!),
              action: FilledButton.tonal(
                onPressed: () => setState(() {
                  _people = _fetchPeople();
                }),
                child: const Text('Try again'),
              ),
            ),
          );
        }
        final people = snapshot.data ?? const [];
        if (people.isEmpty) {
          return const SliverFillRemaining(
            hasScrollBody: false,
            child: _EmptyState(
              icon: Icons.people_outline,
              title: 'No people found',
              message: 'Try another name or department.',
            ),
          );
        }
        return SliverPadding(
          padding: const EdgeInsets.fromLTRB(16, 4, 16, 18),
          sliver: SliverList(
            delegate: SliverChildBuilderDelegate((context, index) {
              if (index.isOdd) return const SizedBox(height: 8);
              final person = people[index ~/ 2];
              final name = person['name'] as String? ?? '';
              final pictureUrl = person['profile_picture_url'] as String?;
              final personId = person['id'] as int?;
              return Card(
                child: ListTile(
                  leading: CircleAvatar(
                    backgroundColor: Theme.of(context)
                        .colorScheme
                        .surfaceContainerHighest,
                    backgroundImage: pictureUrl == null
                        ? null
                        : NetworkImage(pictureUrl),
                    child: pictureUrl == null
                        ? Text(
                            name.isEmpty ? '?' : name[0].toUpperCase(),
                            style: TextStyle(
                              color: Theme.of(context).colorScheme.primary,
                              fontWeight: FontWeight.w700,
                            ),
                          )
                        : null,
                  ),
                  title: Text(name.isEmpty ? 'Campus member' : name),
                  subtitle: Text(
                    person['department'] as String? ?? 'TMC community',
                  ),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: personId == null
                      ? null
                      : () => _openUserProfile(personId),
                ),
              );
            }, childCount: people.length * 2 - 1),
          ),
        );
      },
    );
  }
}

class _FlexiblePostGrid extends StatelessWidget {
  const _FlexiblePostGrid({required this.itemCount, required this.itemBuilder});

  final int itemCount;
  final Widget Function(BuildContext, int) itemBuilder;

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(
      builder: (context, constraints) {
        const spacing = 12.0;
        final itemWidth = (constraints.maxWidth - spacing) / 2;
        return Wrap(
          spacing: spacing,
          runSpacing: spacing,
          children: [
            for (var index = 0; index < itemCount; index++)
              SizedBox(width: itemWidth, child: itemBuilder(context, index)),
          ],
        );
      },
    );
  }
}

class _PostCard extends StatelessWidget {
  const _PostCard({
    required this.post,
    this.compact = false,
    required this.onTapAuthor,
    required this.onOwnerEdit,
    required this.onOwnerDelete,
    required this.onRequestRetrieved,
    required this.onTap,
    required this.onReact,
    required this.onShare,
    required this.onPrimaryAction,
    required this.onReport,
  });
  final CampusPost post;
  final bool compact;
  final VoidCallback onTapAuthor;
  final VoidCallback onOwnerEdit;
  final VoidCallback onOwnerDelete;
  final VoidCallback onRequestRetrieved;
  final VoidCallback onTap;
  final VoidCallback onReact;
  final VoidCallback onShare;
  final VoidCallback onPrimaryAction;
  final VoidCallback onReport;

  @override
  Widget build(BuildContext context) {
    final found = post.type == 'found';
    final dark = Theme.of(context).brightness == Brightness.dark;
    final tagColor = found
        ? (dark ? CampusTheme.foundDark : CampusTheme.found)
        : (dark ? CampusTheme.lostDark : CampusTheme.lost);
    final tagSurface = found
        ? (dark ? CampusTheme.foundSurfaceDark : CampusTheme.foundSurface)
        : (dark ? CampusTheme.lostSurfaceDark : CampusTheme.lostSurface);
    final neutralSurface = dark
        ? CampusTheme.otherSurfaceDark
        : CampusTheme.otherSurface;
    final primaryLabel = post.status == 'handover_pending'
        ? 'Handover'
        : found
        ? post.hasSubmittedClaim
              ? 'Check claim status'
              : 'Claim'
        : 'I found it';
    final card = Card(
      clipBehavior: Clip.antiAlias,
      elevation: 2,
      shadowColor: const Color(0x223F278A),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(20),
        side: BorderSide(color: tagColor.withValues(alpha: .3)),
      ),
      child: compact
          ? _compactCard(context, found, tagColor)
          : Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                _postAuthorHeader(context),
                LayoutBuilder(
                  builder: (context, constraints) {
                    final wide = constraints.maxWidth >= 700;
                    final hasImage = post.images.any(
                      (image) => image['url']?.isNotEmpty == true,
                    );
                    final details = _postDetails(
                      context,
                      found,
                      tagColor,
                      tagSurface,
                      neutralSurface,
                    );
                    if (wide) {
                      return SizedBox(
                        height: 260,
                        child: Row(
                          crossAxisAlignment: CrossAxisAlignment.stretch,
                          children: [
                            if (hasImage)
                              SizedBox(width: 260, child: _postImage(context)),
                            Expanded(child: details),
                          ],
                        ),
                      );
                    }
                    return Column(
                      children: [
                        if (hasImage)
                          InkWell(onTap: onTap, child: _postImage(context)),
                        details,
                      ],
                    );
                  },
                ),
                const Divider(height: 1, indent: 14, endIndent: 14),
                Padding(
                  padding: const EdgeInsets.fromLTRB(8, 2, 8, 2),
                  child: Row(
                    children: [
                      TextButton.icon(
                        onPressed: onReact,
                        icon: Icon(
                          post.myReaction == 'like'
                              ? Icons.favorite
                              : Icons.favorite_border,
                          color: post.myReaction == 'like'
                              ? Theme.of(context).colorScheme.primary
                              : null,
                          size: 19,
                        ),
                        label: Text('${post.reactionsCount}'),
                      ),
                      TextButton.icon(
                        onPressed: onTap,
                        icon: const Icon(Icons.mode_comment_outlined, size: 19),
                        label: Text('${post.commentsCount}'),
                      ),
                      IconButton(
                        tooltip: 'Share post',
                        onPressed: onShare,
                        icon: const Icon(Icons.share_outlined, size: 19),
                      ),
                      const Spacer(),
                      if ((post.status == 'handover_pending' &&
                              post.isHandoverParticipant) ||
                          (['active', 'claim_pending'].contains(post.status) &&
                              !post.isMine &&
                              (post.type != 'found' ||
                                  post.status != 'handover_pending')))
                        Flexible(
                          child: FilledButton.tonal(
                            onPressed: onPrimaryAction,
                            style: FilledButton.styleFrom(
                              minimumSize: const Size(0, 38),
                              padding: const EdgeInsets.symmetric(
                                horizontal: 8,
                              ),
                              visualDensity: VisualDensity.compact,
                            ),
                            child: Text(
                              primaryLabel,
                              maxLines: 1,
                              softWrap: false,
                              overflow: TextOverflow.ellipsis,
                            ),
                          ),
                        ),
                    ],
                  ),
                ),
              ],
            ),
    );
    return card;
  }

  Widget _compactCard(BuildContext context, bool found, Color tagColor) {
    final hasImage = post.images.any(
      (image) => image['url']?.isNotEmpty == true,
    );
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      mainAxisSize: MainAxisSize.min,
      children: [
        AspectRatio(
          aspectRatio: 1,
          child: hasImage
              ? InkWell(onTap: onTap, child: _postImage(context, expand: true))
              : _postImagePlaceholder(context, found),
        ),
        _postAuthorHeader(context, dense: true),
        InkWell(
          onTap: onTap,
          child: _compactPostDetails(context, found, tagColor),
        ),
      ],
    );
  }

  Widget _postAuthorHeader(BuildContext context, {bool dense = false}) {
    final avatarRadius = dense ? 12.0 : 19.0;
    return Padding(
      padding: dense
          ? const EdgeInsets.fromLTRB(7, 4, 2, 1)
          : const EdgeInsets.fromLTRB(14, 10, 6, 10),
      child: Row(
        children: [
          Expanded(
            child: InkWell(
              onTap: onTapAuthor,
              borderRadius: BorderRadius.circular(24),
              child: Row(
                children: [
                  CircleAvatar(
                    radius: avatarRadius,
                    backgroundColor: Theme.of(context)
                        .colorScheme
                        .surfaceContainerHighest,
                    backgroundImage: post.authorProfilePictureUrl == null
                        ? null
                        : NetworkImage(post.authorProfilePictureUrl!),
                    child: post.authorProfilePictureUrl == null
                        ? Text(
                            post.authorFirstName.isEmpty
                                ? '?'
                                : post.authorFirstName[0].toUpperCase(),
                            style: TextStyle(
                              color: Theme.of(context).colorScheme.primary,
                              fontWeight: FontWeight.w700,
                              fontSize: dense ? 10 : null,
                            ),
                          )
                        : null,
                  ),
                  SizedBox(width: dense ? 6 : 9),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          '${post.authorFirstName} ${post.authorLastName}'
                              .trim(),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: TextStyle(
                            fontWeight: FontWeight.w700,
                            fontSize: dense ? 12 : null,
                          ),
                        ),
                        if (!dense)
                          Text(
                            _timeAgo(post.createdAt),
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                            style: TextStyle(
                              fontSize: 11,
                              color: Theme.of(context)
                                  .colorScheme
                                  .onSurfaceVariant,
                            ),
                          ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
          ),
          PopupMenuButton<String>(
            tooltip: 'Post menu',
            padding: EdgeInsets.zero,
            constraints: BoxConstraints(
              minWidth: dense ? 32 : 42,
              minHeight: dense ? 32 : 42,
            ),
            onSelected: (value) {
              switch (value) {
                case 'details':
                  onTap();
                  break;
                case 'report':
                  onReport();
                  break;
                case 'edit':
                  onOwnerEdit();
                  break;
                case 'delete':
                  onOwnerDelete();
                  break;
                case 'retrieved':
                  onRequestRetrieved();
                  break;
              }
            },
            itemBuilder: (_) => [
              const PopupMenuItem(
                value: 'details',
                child: ListTile(
                  leading: Icon(Icons.open_in_new),
                  title: Text('View post'),
                ),
              ),
              if (post.isMine) ...[
                PopupMenuItem(
                  value: 'edit',
                  enabled:
                      post.status == 'active' &&
                      post.retrievalRequestStatus != 'pending',
                  child: const ListTile(
                    leading: Icon(Icons.edit_outlined),
                    title: Text('Edit post'),
                  ),
                ),
                if (['active', 'archived'].contains(post.status))
                  PopupMenuItem(
                    value: 'retrieved',
                    enabled: post.retrievalRequestStatus != 'pending',
                    child: ListTile(
                      leading: const Icon(Icons.task_alt),
                      title: Text(
                        post.retrievalRequestStatus == 'pending'
                            ? 'Retrieval request pending'
                            : 'Request mark as retrieved',
                      ),
                    ),
                  ),
                const PopupMenuItem(
                  value: 'delete',
                  child: ListTile(
                    leading: Icon(Icons.delete_outline),
                    title: Text('Delete post'),
                  ),
                ),
              ] else
                const PopupMenuItem(
                  value: 'report',
                  child: ListTile(
                    leading: Icon(Icons.flag_outlined),
                    title: Text('Report post'),
                  ),
                ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _postImagePlaceholder(BuildContext context, bool found) {
    final color = found ? CampusTheme.found : CampusTheme.lost;
    final surface = found ? CampusTheme.foundSurface : CampusTheme.lostSurface;
    return InkWell(
      onTap: onTap,
      child: AspectRatio(
        aspectRatio: 1,
        child: ColoredBox(
          color: surface,
          child: Center(
            child: Icon(
              found ? Icons.inventory_2_outlined : Icons.search_rounded,
              size: 40,
              color: color,
            ),
          ),
        ),
      ),
    );
  }

  Widget _postImage(BuildContext context, {bool expand = false}) {
    return _PostPhotoSlideshow(
      key: ValueKey('post-images-${post.id}'),
      images: post.images,
      expand: expand,
      borderRadius: expand ? 0 : 12,
    );
  }

  Widget _compactPostDetails(BuildContext context, bool found, Color tagColor) {
    final muted = Theme.of(context).colorScheme.onSurfaceVariant;
    return Padding(
      padding: const EdgeInsets.fromLTRB(7, 2, 7, 6),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(
            post.itemName,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w700),
          ),
          const SizedBox(height: 3),
          Row(
            children: [
              Expanded(
                child: Text(
                  found ? 'FOUND' : 'LOST',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    color: tagColor,
                    fontSize: 8,
                    fontWeight: FontWeight.w800,
                  ),
                ),
              ),
              const Text(' · ', style: TextStyle(fontSize: 8)),
              Expanded(
                flex: 2,
                child: Text(
                  post.status.replaceAll('_', ' ').toUpperCase(),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    color: muted,
                    fontSize: 8,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ),
              const Text(' · ', style: TextStyle(fontSize: 8)),
              Expanded(
                flex: 2,
                child: Text(
                  post.category,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    color: muted,
                    fontSize: 8,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _postDetails(
    BuildContext context,
    bool found,
    Color tagColor,
    Color tagSurface,
    Color neutralSurface,
  ) {
    final dark = Theme.of(context).brightness == Brightness.dark;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 14, 16, 8),
          child: Wrap(
            spacing: 8,
            runSpacing: 6,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              _PostTag(
                label: found ? 'FOUND' : 'LOST',
                color: tagColor,
                surface: tagSurface,
              ),
              _PostTag(
                label: post.category,
                color: dark ? CampusTheme.otherDark : CampusTheme.other,
                surface: neutralSurface,
              ),
              if (post.isMine && post.retrievalRequestStatus != null)
                _PostTag(
                  label:
                      'RETRIEVAL ${post.retrievalRequestStatus!.toUpperCase()}',
                  color: dark ? CampusTheme.otherDark : CampusTheme.other,
                  surface: neutralSurface,
                ),
              Text(
                post.status.replaceAll('_', ' ').toUpperCase(),
                style: TextStyle(
                  fontSize: 9,
                  letterSpacing: .3,
                  fontWeight: FontWeight.w800,
                  color: Theme.of(context).colorScheme.onSurfaceVariant,
                ),
              ),
            ],
          ),
        ),
        InkWell(
          onTap: onTap,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(16, 4, 16, 12),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  post.itemName,
                  style: Theme.of(context).textTheme.titleLarge,
                ),
                const SizedBox(height: 6),
                Text(
                  post.description,
                  maxLines: 4,
                  overflow: TextOverflow.ellipsis,
                ),
                const SizedBox(height: 12),
                Wrap(
                  spacing: 14,
                  runSpacing: 6,
                  children: [
                    _PostMeta(
                      icon: Icons.category_outlined,
                      label: post.category,
                    ),
                    _PostMeta(icon: Icons.place_outlined, label: post.location),
                    _PostMeta(
                      icon: Icons.event_outlined,
                      label: post.eventDate,
                    ),
                  ],
                ),
              ],
            ),
          ),
        ),
      ],
    );
  }
}

class _PostTag extends StatelessWidget {
  const _PostTag({required this.label, required this.color, this.surface});
  final String label;
  final Color color;
  final Color? surface;

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 5),
    decoration: BoxDecoration(
      color: surface ?? color.withValues(alpha: .10),
      borderRadius: BorderRadius.circular(30),
    ),
    child: Text(
      label,
      style: TextStyle(color: color, fontSize: 10, fontWeight: FontWeight.w800),
    ),
  );
}

class _PostMeta extends StatelessWidget {
  const _PostMeta({required this.icon, required this.label});
  final IconData icon;
  final String label;

  @override
  Widget build(BuildContext context) => Row(
    mainAxisSize: MainAxisSize.min,
    children: [
      Icon(
        icon,
        size: 15,
        color: Theme.of(context).colorScheme.onSurfaceVariant,
      ),
      const SizedBox(width: 4),
      Text(
        label,
        style: TextStyle(
          fontSize: 11,
          color: Theme.of(context).colorScheme.onSurfaceVariant,
        ),
      ),
    ],
  );
}

class _SectionBanner extends StatelessWidget {
  const _SectionBanner({
    required this.icon,
    required this.title,
    required this.subtitle,
    required this.tint,
  });

  final IconData icon;
  final String title;
  final String subtitle;
  final Color tint;

  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.symmetric(horizontal: 15, vertical: 13),
    decoration: BoxDecoration(
      gradient: LinearGradient(
        colors: [
          tint.withValues(alpha: .14),
          Theme.of(context).colorScheme.surface,
        ],
      ),
      border: Border.all(color: tint.withValues(alpha: .18)),
      borderRadius: BorderRadius.circular(16),
    ),
    child: Row(
      children: [
        Container(
          width: 42,
          height: 42,
          decoration: BoxDecoration(
            color: tint.withValues(alpha: .13),
            borderRadius: BorderRadius.circular(13),
          ),
          child: Icon(icon, color: tint),
        ),
        const SizedBox(width: 12),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                title,
                style: const TextStyle(
                  fontSize: 14,
                  fontWeight: FontWeight.w800,
                ),
              ),
              const SizedBox(height: 3),
              Text(
                subtitle,
                style: TextStyle(
                  fontSize: 11,
                  color: Theme.of(context).colorScheme.onSurfaceVariant,
                ),
              ),
            ],
          ),
        ),
      ],
    ),
  );
}

String _timeAgo(String timestamp) {
  final createdAt = DateTime.tryParse(timestamp)?.toLocal();
  if (createdAt == null) return 'Recently';
  final difference = DateTime.now().difference(createdAt);
  if (difference.inMinutes < 1) return 'Just now';
  if (difference.inHours < 1) return '${difference.inMinutes}m ago';
  if (difference.inDays < 1) return '${difference.inHours}h ago';
  if (difference.inDays < 7) return '${difference.inDays}d ago';
  return '${createdAt.month}/${createdAt.day}/${createdAt.year}';
}

Future<void> _reportPost(
  BuildContext context,
  ApiClient api,
  CampusPost post,
) async {
  final reason = await showDialog<String>(
    context: context,
    builder: (_) => const _TextEntryDialog(
      title: 'Report this post',
      label: 'Reason',
      actionLabel: 'Send report',
      maxLength: 500,
      maxLines: 3,
      requiredInput: true,
    ),
  );
  if (reason == null || !context.mounted) return;

  try {
    await api.dio.post<void>(
      '/posts/${post.id}/reports',
      data: {'reason': reason},
    );
    if (context.mounted) {
      ScaffoldMessenger.maybeOf(context)?.showSnackBar(
        const SnackBar(
          content: Text('Your report was sent to the TMC administrators.'),
        ),
      );
    }
  } on DioException catch (error) {
    if (context.mounted) {
      ScaffoldMessenger.maybeOf(context)
          ?.showSnackBar(SnackBar(content: Text(api.errorMessage(error))));
    }
  }
}

class _TextEntryDialog extends StatefulWidget {
  const _TextEntryDialog({
    required this.title,
    required this.label,
    required this.actionLabel,
    this.initialText = '',
    this.description,
    this.maxLength,
    this.maxLines = 1,
    this.requiredInput = false,
  });

  final String title;
  final String label;
  final String actionLabel;
  final String initialText;
  final String? description;
  final int? maxLength;
  final int maxLines;
  final bool requiredInput;

  @override
  State<_TextEntryDialog> createState() => _TextEntryDialogState();
}

class _TextEntryDialogState extends State<_TextEntryDialog> {
  late final TextEditingController _controller;
  String? _error;

  @override
  void initState() {
    super.initState();
    _controller = TextEditingController(text: widget.initialText);
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      scrollable: true,
      title: Text(widget.title),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (widget.description != null) ...[
            Text(widget.description!),
            const SizedBox(height: 12),
          ],
          TextField(
            controller: _controller,
            maxLength: widget.maxLength,
            maxLines: widget.maxLines,
            onChanged: (_) {
              if (_error != null) setState(() => _error = null);
            },
            decoration: InputDecoration(
              labelText: widget.label,
              errorText: _error,
            ),
          ),
        ],
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(context),
          child: const Text('Cancel'),
        ),
        FilledButton(
          onPressed: () {
            final value = _controller.text.trim();
            if (widget.requiredInput && value.isEmpty) {
              setState(() => _error = 'This field is required.');
              return;
            }
            Navigator.pop(context, value);
          },
          child: Text(widget.actionLabel),
        ),
      ],
    );
  }
}

class _EditProfileDialog extends StatefulWidget {
  const _EditProfileDialog({required this.api, required this.profile});

  final ApiClient api;
  final Map<String, dynamic> profile;

  @override
  State<_EditProfileDialog> createState() => _EditProfileDialogState();
}

class _EditProfileDialogState extends State<_EditProfileDialog> {
  late final TextEditingController _firstName;
  late final TextEditingController _lastName;
  late final TextEditingController _profileStatus;
  late final TextEditingController _bio;
  late final TextEditingController _currentAddress;
  late final TextEditingController _permanentAddress;
  late final TextEditingController _contactNumber;
  late final TextEditingController _contactEmail;
  XFile? _picture;
  bool _saving = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    final profile = widget.profile;
    _firstName = TextEditingController(
      text: profile['first_name'] as String? ?? '',
    );
    _lastName = TextEditingController(
      text: profile['last_name'] as String? ?? '',
    );
    _profileStatus = TextEditingController(
      text: profile['profile_status'] as String? ?? '',
    );
    _bio = TextEditingController(text: profile['bio'] as String? ?? '');
    _currentAddress = TextEditingController(
      text: profile['current_address'] as String? ?? '',
    );
    _permanentAddress = TextEditingController(
      text: profile['permanent_address'] as String? ?? '',
    );
    _contactNumber = TextEditingController(
      text: profile['contact_number'] as String? ?? '',
    );
    _contactEmail = TextEditingController(
      text: profile['contact_email'] as String? ?? '',
    );
  }

  @override
  void dispose() {
    _firstName.dispose();
    _lastName.dispose();
    _profileStatus.dispose();
    _bio.dispose();
    _currentAddress.dispose();
    _permanentAddress.dispose();
    _contactNumber.dispose();
    _contactEmail.dispose();
    super.dispose();
  }

  Future<void> _pickPicture() async {
    try {
      final selected = await ImagePicker().pickImage(
        source: ImageSource.gallery,
        imageQuality: 85,
      );
      if (selected != null && mounted) {
        setState(() {
          _picture = selected;
          _error = null;
        });
      }
    } on PlatformException catch (error) {
      if (mounted) {
        setState(
          () => _error = error.message ?? 'Could not select a profile picture.',
        );
      }
    } on Exception {
      if (mounted) {
        setState(() => _error = 'Could not select a profile picture.');
      }
    }
  }

  Future<Response<Map<String, dynamic>>> _uploadProfilePicture(
    Map<String, dynamic> fields,
    XFile picture,
  ) async {
    final pictureBytes = await picture.readAsBytes();
    return widget.api.dio.post<Map<String, dynamic>>(
      '/profile',
      data: FormData.fromMap({
        ...fields,
        'profile_picture': MultipartFile.fromBytes(
          pictureBytes,
          filename: picture.name,
          contentType: MultipartFile.lookupMediaType(picture.name),
        ),
      }),
    );
  }

  Future<void> _save() async {
    if (_saving) return;
    if (_firstName.text.trim().isEmpty || _lastName.text.trim().isEmpty) {
      setState(() => _error = 'First and last name are required.');
      return;
    }
    setState(() {
      _saving = true;
      _error = null;
    });
    try {
      final fields = <String, dynamic>{
        'first_name': _firstName.text.trim(),
        'last_name': _lastName.text.trim(),
        'profile_status': _profileStatus.text.trim(),
        'bio': _bio.text.trim(),
        'current_address': _currentAddress.text.trim(),
        'permanent_address': _permanentAddress.text.trim(),
        'contact_number': _contactNumber.text.trim(),
        'contact_email': _contactEmail.text.trim(),
      };
      final picture = _picture;
      final response = picture == null
          ? await widget.api.dio.put<Map<String, dynamic>>(
              '/profile',
              data: fields,
            )
          : await _uploadProfilePicture(fields, picture);
      if (mounted) Navigator.pop(context, response.data);
    } on DioException catch (error) {
      if (mounted) {
        setState(() => _error = widget.api.errorMessage(error));
      }
    } on Exception {
      if (mounted) {
        setState(
          () => _error =
              'Could not read the selected picture. Choose it again and retry.',
        );
      }
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      scrollable: true,
      title: const Text('Edit profile'),
      content: SizedBox(
        width: 460,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            if (_error != null)
              Align(
                alignment: Alignment.centerLeft,
                child: Text(
                  _error!,
                  style: TextStyle(color: Theme.of(context).colorScheme.error),
                ),
              ),
            Align(
              alignment: Alignment.centerLeft,
              child: Padding(
                padding: const EdgeInsets.only(bottom: 8),
                child: Text(
                  'Profile details you add here are visible to other campus members.',
                  style: TextStyle(
                    fontSize: 12,
                    color: Theme.of(context).colorScheme.onSurfaceVariant,
                  ),
                ),
              ),
            ),
            TextField(
              controller: _firstName,
              decoration: const InputDecoration(labelText: 'First name *'),
            ),
            TextField(
              controller: _lastName,
              decoration: const InputDecoration(labelText: 'Last name *'),
            ),
            TextField(
              controller: _profileStatus,
              maxLength: 100,
              decoration: const InputDecoration(labelText: 'Status'),
            ),
            TextField(
              controller: _bio,
              maxLines: 3,
              maxLength: 500,
              decoration: const InputDecoration(labelText: 'Bio'),
            ),
            TextField(
              controller: _currentAddress,
              decoration: const InputDecoration(labelText: 'Current address'),
            ),
            TextField(
              controller: _permanentAddress,
              decoration: const InputDecoration(labelText: 'Permanent address'),
            ),
            TextField(
              controller: _contactNumber,
              keyboardType: TextInputType.phone,
              decoration: const InputDecoration(labelText: 'Mobile number'),
            ),
            TextField(
              controller: _contactEmail,
              keyboardType: TextInputType.emailAddress,
              decoration: const InputDecoration(labelText: 'Contact email'),
            ),
            Align(
              alignment: Alignment.centerLeft,
              child: TextButton.icon(
                onPressed: _saving ? null : _pickPicture,
                icon: const Icon(Icons.add_a_photo_outlined),
                label: Text(_picture?.name ?? 'Add or change profile picture'),
              ),
            ),
          ],
        ),
      ),
      actions: [
        TextButton(
          onPressed: _saving ? null : () => Navigator.pop(context),
          child: const Text('Cancel'),
        ),
        FilledButton(
          onPressed: _saving ? null : _save,
          child: _saving
              ? const SizedBox.square(
                  dimension: 18,
                  child: CircularProgressIndicator(strokeWidth: 2),
                )
              : const Text('Save'),
        ),
      ],
    );
  }
}

class _PostDetailSheet extends StatefulWidget {
  const _PostDetailSheet({
    required this.api,
    required this.post,
    this.onOpenProfile,
    this.onClaimChanged,
    this.onReactionChanged,
  });
  final ApiClient api;
  final CampusPost post;
  final void Function(String? reaction, int count)? onReactionChanged;
  final VoidCallback? onClaimChanged;
  final Future<void> Function(int)? onOpenProfile;

  @override
  State<_PostDetailSheet> createState() => _PostDetailSheetState();
}

class _PostDetailSheetState extends State<_PostDetailSheet> {
  final _answers = <TextEditingController>[];
  final List<XFile> _claimPhotos = [];
  final _comment = TextEditingController();
  bool _busy = false;
  double _claimUploadProgress = 0;
  bool _claimUploadSent = false;
  String? _claimFeedback;
  String? _message;
  bool _loadingOwnClaim = false;
  bool _claimSubmitted = false;
  bool _claimCanEdit = false;
  bool _claimStatusNeedsConfirmation = false;
  bool _editingClaim = false;
  int? _claimId;
  List<String> _claimAnswersBeforeEdit = [];
  bool _claimRejected = false;
  bool _claimCanSubmitAnother = false;
  int _claimAttemptsUsed = 0;
  int _claimAttemptLimit = 3;
  List<String> _ownClaimImages = [];
  bool _loadingOwnSighting = false;
  String? _ownSightingText;
  String? _ownSightingImage;
  int? _ownSightingId;
  bool _sightingRejected = false;
  bool _sightingCanSubmitAnother = false;
  int _sightingAttemptsUsed = 0;
  int _sightingAttemptLimit = 3;
  String? _replyToName;
  int? _replyToId;
  String? _myReaction;
  int _reactionsCount = 0;
  late Future<List<Map<String, dynamic>>> _comments;

  @override
  void initState() {
    super.initState();
    _comments = _loadComments();
    _myReaction = widget.post.myReaction;
    _reactionsCount = widget.post.reactionsCount;
    for (var index = 0; index < widget.post.questions.length; index++) {
      _answers.add(TextEditingController());
    }
    if (!widget.post.isMine && widget.post.type == 'found') {
      unawaited(_loadOwnClaim());
    }
    if (!widget.post.isMine && widget.post.type == 'lost') {
      unawaited(_loadOwnSighting());
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

  Future<void> _loadOwnClaim({bool preserveSubmitted = false}) async {
    setState(() => _loadingOwnClaim = true);
    try {
      final response = await widget.api.dio.get<Map<String, dynamic>>(
        '/posts/${widget.post.id}/my-claim',
        options: Options(receiveTimeout: const Duration(seconds: 60)),
      );
      final claim = response.data?['data'];
      if (claim is Map<String, dynamic> && mounted) {
        final answers = claim['answers'];
        final evidenceImages = claim['evidence_images'];
        final attemptsUsed = int.tryParse('${claim['attempts_used']}');
        final handoverUnsuccessful = claim['handover_unsuccessful'] == true;
        if (answers is List) {
          for (
            var index = 0;
            index < answers.length && index < _answers.length;
            index++
          ) {
            _answers[index].text = answers[index] as String? ?? '';
          }
        }
        setState(() {
          _claimSubmitted = true;
          _claimStatusNeedsConfirmation = false;
          _claimFeedback = null;
          _claimId = claim['id'] as int?;
          _claimCanEdit = claim['editable'] as bool? ?? false;
          _claimRejected = claim['is_rejected'] as bool? ?? false;
          _claimCanSubmitAnother =
              claim['can_submit_another'] as bool? ?? false;
          _claimAttemptsUsed = attemptsUsed ?? 1;
          _claimAttemptLimit = int.tryParse('${claim['attempt_limit']}') ?? 3;
          _ownClaimImages = evidenceImages is List
              ? evidenceImages.whereType<String>().toList()
              : [];
          final status = claim['status'] as String? ?? 'pending';
          final authorDecision =
              claim['author_decision'] as String? ?? 'pending';
          final decisionReason =
              claim['author_decision_reason'] as String? ??
              claim['review_reason'] as String?;
          final handoverFailureReason =
              claim['handover_failure_reason'] as String?;
          if (handoverUnsuccessful) {
            final handoverMessage = _claimAttemptsUsed >= _claimAttemptLimit
                ? 'The handover was unsuccessful. You used all $_claimAttemptLimit claim attempts for this item.'
                : _claimCanSubmitAnother
                ? 'The handover was unsuccessful and the item is active again. You can submit another claim.'
                : 'The handover was unsuccessful, but this item is not accepting another claim now.';
            _message = [
              handoverMessage,
              if (handoverFailureReason != null &&
                  handoverFailureReason.isNotEmpty)
                handoverFailureReason,
            ].join(' ');
          } else if (status == 'approved' && !_claimRejected) {
            _message = 'Your claim was approved. The handover is pending.';
          } else if (authorDecision == 'approved' && !_claimRejected) {
            _message = [
              'The post owner approved your claim. It is waiting for administrator review.',
              if (decisionReason != null && decisionReason.isNotEmpty)
                decisionReason,
            ].join(' ');
          } else if (_claimRejected) {
            final rejectionReason = decisionReason;
            if (_claimAttemptsUsed >= _claimAttemptLimit) {
              _message = [
                'You used all $_claimAttemptLimit claim attempts for this item.',
                if (rejectionReason != null && rejectionReason.isNotEmpty)
                  rejectionReason,
              ].join(' ');
            } else if (_claimCanSubmitAnother) {
              _message = [
                'Your claim was not approved. You can submit another claim.',
                if (rejectionReason != null && rejectionReason.isNotEmpty)
                  rejectionReason,
              ].join(' ');
            } else {
              _message = 'Your claim was not approved, but this item is not accepting another claim now.';
            }
          } else {
            _message = 'Your claim is under review. You can edit it until the post owner makes a decision.';
          }
        });
        if (preserveSubmitted) widget.onClaimChanged?.call();
      } else if (mounted) {
        setState(() {
          _claimSubmitted = false;
          _claimStatusNeedsConfirmation = false;
          _claimId = null;
          _claimCanEdit = false;
          _editingClaim = false;
          _claimRejected = false;
          _claimCanSubmitAnother = false;
          _claimAttemptsUsed = 0;
          _ownClaimImages = [];
          if (preserveSubmitted) {
            _message =
                'No submitted claim was found. You can try submitting again.';
          }
        });
        if (preserveSubmitted) widget.onClaimChanged?.call();
      }
    } on DioException catch (error) {
      if (mounted) {
        setState(() {
          _claimStatusNeedsConfirmation = preserveSubmitted && _claimSubmitted;
          _message = preserveSubmitted && _claimSubmitted
              ? 'Could not confirm your claim status. Check again before submitting another claim.'
              : widget.api.errorMessage(error);
        });
      }
    } finally {
      if (mounted) setState(() => _loadingOwnClaim = false);
    }
  }

  Future<void> _loadOwnSighting() async {
    setState(() => _loadingOwnSighting = true);
    try {
      final response = await widget.api.dio.get<Map<String, dynamic>>(
        '/posts/${widget.post.id}/my-sighting',
      );
      final sighting = response.data?['data'];
      if (sighting is Map<String, dynamic> && mounted) {
        setState(() {
          _ownSightingId = sighting['id'] as int?;
          _ownSightingText = sighting['evidence_text'] as String?;
          _ownSightingImage = sighting['evidence_image'] as String?;
          _sightingRejected = sighting['is_rejected'] as bool? ?? false;
          _sightingCanSubmitAnother =
              sighting['can_submit_another'] as bool? ??
              sighting['can_reapply'] as bool? ??
              false;
          _sightingAttemptsUsed = sighting['attempts_used'] as int? ?? 0;
          _sightingAttemptLimit = sighting['attempt_limit'] as int? ?? 3;
          final ownerDecision =
              sighting['author_decision'] as String? ?? 'pending';
          final adminDecision =
              sighting['admin_decision'] as String? ?? 'pending';
          if (_sightingRejected) {
            final reason =
                sighting['admin_reason'] as String? ??
                'Your evidence was not approved.';
            _message = _sightingCanSubmitAnother
                ? '$reason You can submit new evidence.'
                : '$reason You used all $_sightingAttemptLimit evidence attempts for this item.';
          } else if (adminDecision == 'approved') {
            _message = 'Your evidence was approved. Open Inbox to chat and arrange the handover.';
          } else if (ownerDecision == 'approved') {
            _message = 'The post owner approved your evidence. It is waiting for TMC review.';
          } else if (_sightingCanSubmitAnother) {
            _message = 'Your evidence is under review. You can submit another report during the first hour.';
          } else {
            _message = 'Your evidence was sent and is waiting for the post owner to review it.';
          }
        });
      }
    } on DioException catch (error) {
      if (mounted) setState(() => _message = widget.api.errorMessage(error));
    } finally {
      if (mounted) setState(() => _loadingOwnSighting = false);
    }
  }

  Future<void> _claim() async {
    if (_busy ||
        _loadingOwnClaim ||
        _editingClaim ||
        (_claimSubmitted && !_claimCanSubmitAnother)) {
      return;
    }

    setState(() {
      _busy = true;
      _claimUploadProgress = 0;
      _claimUploadSent = false;
      _claimFeedback = 'Preparing your claim...';
      _message = 'Preparing your claim...';
    });
    try {
      final response = await widget.api.dio.post<Map<String, dynamic>>(
        '/posts/${widget.post.id}/claims',
        data: await _claimFormData(),
        options: Options(
          sendTimeout: const Duration(seconds: 90),
          receiveTimeout: const Duration(seconds: 60),
        ),
        onSendProgress: (sent, total) {
          if (!mounted) return;
          setState(() {
            _claimUploadSent = total > 0 && sent >= total;
            _claimUploadProgress = total > 0 ? sent / total : 0;
            _claimFeedback = _claimUploadSent
                ? 'Evidence uploaded. Waiting for the server...'
                : total > 0
                ? 'Uploading evidence: ${(100 * sent / total).round()}%'
                : 'Submitting your claim...';
          });
        },
      );
      if (mounted) {
        final attemptsUsed = int.tryParse(
          '${response.data?['attempts_used'] ?? response.data?['attempt_no']}',
        );
        final attemptLimit = int.tryParse('${response.data?['attempt_limit']}');
        setState(() {
          _claimSubmitted = true;
          _claimStatusNeedsConfirmation = false;
          _claimFeedback =
              response.data?['message'] as String? ?? 'Claim sent for review.';
          _claimId = response.data?['claim_id'] as int?;
          _claimCanEdit = response.data?['editable'] as bool? ?? false;
          _claimRejected = false;
          _claimAttemptsUsed = attemptsUsed ?? _claimAttemptsUsed + 1;
          _claimAttemptLimit = attemptLimit ?? _claimAttemptLimit;
          _claimCanSubmitAnother = false;
          _message =
              response.data?['message'] as String? ?? 'Claim sent for review.';
          _claimPhotos.clear();
        });
        widget.onClaimChanged?.call();
        unawaited(_loadOwnClaim());
      }
    } on DioException catch (error) {
      if (mounted) {
        final statusCode = error.response?.statusCode;
        if (statusCode != null && statusCode >= 400 && statusCode < 500) {
          setState(() => _claimFeedback = widget.api.errorMessage(error));
        } else {
          setState(() {
            _claimSubmitted = true;
            _claimCanEdit = false;
            _claimCanSubmitAnother = false;
            _claimStatusNeedsConfirmation = true;
            _claimFeedback = 'Claim submission could not be confirmed. Checking its status...';
            _busy = false;
          });
          widget.onClaimChanged?.call();
          await _loadOwnClaim(preserveSubmitted: true);
          if (mounted && !_claimSubmitted) {
            setState(() => _claimFeedback = widget.api.errorMessage(error));
          }
        }
      }
    } finally {
      if (mounted) {
        setState(() {
          _busy = false;
          _claimUploadProgress = 0;
          _claimUploadSent = false;
        });
      }
    }
  }

  Future<void> _pickClaimPhotos() async {
    try {
      final photos = await ImagePicker().pickMultiImage(
        imageQuality: 85,
        maxWidth: 1600,
        maxHeight: 1600,
      );
      if (!mounted || photos.isEmpty) return;
      if (photos.length > 5) {
        setState(() => _claimFeedback = 'Choose no more than five photos.');
        return;
      }
      for (final photo in photos) {
        if (await photo.length() > 5 * 1024 * 1024) {
          if (!mounted) return;
          setState(() {
            _claimFeedback =
                '${photo.name} is larger than 5 MB. Choose a smaller photo.';
          });
          return;
        }
      }
      if (!mounted) return;
      setState(() {
        _claimPhotos
          ..clear()
          ..addAll(photos);
        _claimFeedback = null;
        _message = null;
      });
    } on PlatformException catch (error) {
      if (mounted) {
        setState(
          () => _claimFeedback =
              error.message ?? 'Could not select claim evidence photos.',
        );
      }
    } on Exception {
      if (mounted) {
        setState(
          () => _claimFeedback = 'Could not select claim evidence photos.',
        );
      }
    }
  }

  Future<FormData> _claimFormData({bool patch = false}) async {
    final fields = <String, dynamic>{
      for (var index = 0; index < _answers.length; index++)
        'answers[$index]': _answers[index].text,
      if (patch) '_method': 'PATCH',
    };
    for (var index = 0; index < _claimPhotos.length; index++) {
      final photo = _claimPhotos[index];
      fields['evidence_images[$index]'] = await MultipartFile.fromFile(
        photo.path,
        filename: photo.name,
      );
    }

    return FormData.fromMap(fields);
  }

  void _beginClaimEdit() {
    setState(() {
      _claimAnswersBeforeEdit = _answers.map((answer) => answer.text).toList();
      _claimPhotos.clear();
      _editingClaim = true;
      _message = null;
    });
  }

  void _cancelClaimEdit() {
    setState(() {
      for (var index = 0; index < _answers.length; index++) {
        _answers[index].text = _claimAnswersBeforeEdit[index];
      }
      _claimPhotos.clear();
      _editingClaim = false;
      _claimAnswersBeforeEdit = [];
      _message = null;
    });
  }

  Future<void> _saveClaimEdits() async {
    final claimId = _claimId;
    if (_busy || !_editingClaim || claimId == null) return;

    setState(() {
      _busy = true;
      _claimUploadProgress = 0;
      _claimUploadSent = false;
      _message = 'Preparing your claim changes...';
    });
    try {
      final response = await widget.api.dio.post<Map<String, dynamic>>(
        '/posts/${widget.post.id}/claims/$claimId',
        data: await _claimFormData(patch: true),
        options: Options(
          sendTimeout: const Duration(seconds: 90),
          receiveTimeout: const Duration(seconds: 60),
        ),
        onSendProgress: (sent, total) {
          if (!mounted) return;
          setState(() {
            _claimUploadSent = total > 0 && sent >= total;
            _claimUploadProgress = total > 0 ? sent / total : 0;
            _message = _claimUploadSent
                ? 'Changes uploaded. Waiting for the server...'
                : total > 0
                ? 'Uploading evidence: ${(100 * sent / total).round()}%'
                : 'Saving your claim changes...';
          });
        },
      );
      if (mounted) {
        setState(() {
          _editingClaim = false;
          _claimAnswersBeforeEdit = [];
          _claimPhotos.clear();
        });
        widget.onClaimChanged?.call();
        await _loadOwnClaim();
        if (mounted) {
          setState(() {
            _message =
                response.data?['message'] as String? ??
                'Your claim changes were saved.';
          });
        }
      }
    } on DioException catch (error) {
      if (mounted) setState(() => _message = widget.api.errorMessage(error));
    } finally {
      if (mounted) {
        setState(() {
          _busy = false;
          _claimUploadProgress = 0;
          _claimUploadSent = false;
        });
      }
    }
  }

  Future<void> _sendComment() async {
    final body = _comment.text.trim();
    if (body.isEmpty) return;
    try {
      await widget.api.dio.post<void>(
        '/posts/${widget.post.id}/comments',
        data: {'body': body, if (_replyToId != null) 'parent_id': _replyToId},
      );
      if (!mounted) return;
      _comment.clear();
      setState(() {
        _replyToId = null;
        _replyToName = null;
        _comments = _loadComments();
      });
    } on DioException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(widget.api.errorMessage(error))));
      }
    }
  }

  Future<void> _react() async {
    final previousReaction = _myReaction;
    final previousCount = _reactionsCount;
    final wasLiked = previousReaction == 'like';
    final optimisticCount = previousCount + (wasLiked ? -1 : 1);
    setState(() {
      _myReaction = wasLiked ? null : 'like';
      _reactionsCount = optimisticCount < 0 ? 0 : optimisticCount;
    });
    widget.onReactionChanged?.call(_myReaction, _reactionsCount);
    try {
      final response = await widget.api.dio.post<Map<String, dynamic>>(
        '/posts/${widget.post.id}/reactions',
        data: {'type': wasLiked ? 'none' : 'like'},
      );
      if (!mounted) return;
      setState(() {
        _myReaction = response.data?['reaction'] as String?;
        _reactionsCount =
            response.data?['reactions_count'] as int? ?? _reactionsCount;
      });
      widget.onReactionChanged?.call(_myReaction, _reactionsCount);
    } on DioException catch (error) {
      if (!mounted) return;
      setState(() {
        _myReaction = previousReaction;
        _reactionsCount = previousCount;
      });
      widget.onReactionChanged?.call(_myReaction, _reactionsCount);
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(widget.api.errorMessage(error))));
    }
  }

  Widget _commentTile(Map<String, dynamic> comment, {bool reply = false}) {
    final firstName = comment['author_first_name'] as String? ?? 'TMC';
    final lastName = comment['author_last_name'] as String? ?? '';
    final pictureUrl = comment['author_profile_picture_url'] as String?;
    final authorId = comment['author_id'] as int?;
    final replies = comment['replies'];
    return Padding(
      padding: EdgeInsets.only(left: reply ? 28 : 0, bottom: 4),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          ListTile(
            contentPadding: EdgeInsets.zero,
            leading: CircleAvatar(
              backgroundColor: Theme.of(context)
                  .colorScheme
                  .surfaceContainerHighest,
              backgroundImage: pictureUrl == null
                  ? null
                  : NetworkImage(pictureUrl),
              child: pictureUrl == null
                  ? Text(firstName.isEmpty ? '?' : firstName[0].toUpperCase())
                  : null,
            ),
            title: Text('$firstName $lastName'.trim()),
            subtitle: Text(comment['body'] as String? ?? ''),
            onTap: widget.onOpenProfile != null && authorId != null
                ? () => widget.onOpenProfile!(authorId)
                : null,
          ),
          if (!reply)
            TextButton.icon(
              onPressed: () => setState(() {
                _replyToId = comment['id'] as int?;
                _replyToName = '$firstName $lastName'.trim();
              }),
              icon: const Icon(Icons.reply, size: 16),
              label: const Text('Reply'),
            ),
          if (replies is List)
            for (final item in replies.whereType<Map<String, dynamic>>())
              _commentTile(item, reply: true),
        ],
      ),
    );
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
        constraints: BoxConstraints(
          maxHeight: MediaQuery.sizeOf(context).height * 0.9,
        ),
        child: SingleChildScrollView(
          padding: EdgeInsets.fromLTRB(
            20,
            24,
            20,
            MediaQuery.viewInsetsOf(context).bottom + 24,
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                post.itemName,
                style: Theme.of(context).textTheme.headlineSmall,
              ),
              const SizedBox(height: 10),
              Text(
                '${post.type.toUpperCase()} · ${post.category} · ${post.color}',
              ),
              const SizedBox(height: 8),
              Text('${post.location} · ${post.eventDate}'),
              const SizedBox(height: 16),
              if (post.images.any((image) => image['url']?.isNotEmpty == true))
                _PostPhotoSlideshow(
                  key: ValueKey('detail-post-images-${post.id}'),
                  images: post.images,
                ),
              const SizedBox(height: 12),
              Text(post.description),
              if (!post.isMine)
                Align(
                  alignment: Alignment.centerRight,
                  child: IconButton(
                    tooltip: 'Report post',
                    onPressed: () =>
                        unawaited(_reportPost(context, widget.api, post)),
                    icon: const Icon(Icons.flag_outlined),
                  ),
                ),
              Wrap(
                spacing: 6,
                children: [
                  FilledButton.tonalIcon(
                    onPressed: _react,
                    icon: Icon(
                      _myReaction == 'like'
                          ? Icons.favorite
                          : Icons.favorite_border,
                      color: _myReaction == 'like'
                          ? Theme.of(context).colorScheme.primary
                          : null,
                    ),
                    label: Text('$_reactionsCount'),
                  ),
                ],
              ),
              if (post.type == 'found' &&
                  !post.isMine &&
                  post.status != 'handover_pending' &&
                  post.questions.isNotEmpty) ...[
                const SizedBox(height: 16),
                const Text(
                  'Claim this item',
                  style: TextStyle(fontWeight: FontWeight.w700),
                ),
                const SizedBox(height: 8),
                for (var index = 0; index < post.questions.length; index++) ...[
                  Text(post.questions[index]),
                  const SizedBox(height: 8),
                  TextField(
                    controller: _answers[index],
                    maxLines: 2,
                    enabled:
                        !_loadingOwnClaim &&
                        !_busy &&
                        (!_claimSubmitted ||
                            _claimCanSubmitAnother ||
                            _editingClaim),
                    decoration: const InputDecoration(labelText: 'Your answer'),
                  ),
                  const SizedBox(height: 12),
                ],
                OutlinedButton.icon(
                  onPressed:
                      _busy ||
                          (_claimSubmitted &&
                              !_claimCanSubmitAnother &&
                              !_editingClaim)
                      ? null
                      : _pickClaimPhotos,
                  icon: const Icon(Icons.add_photo_alternate_outlined),
                  label: Text(
                    _claimPhotos.isNotEmpty
                        ? '${_claimPhotos.length} replacement photo(s) selected'
                        : _editingClaim
                        ? 'Replace submitted ownership photos (optional)'
                        : 'Add photos proving ownership (required)',
                  ),
                ),
                if (_ownClaimImages.isNotEmpty) ...[
                  const SizedBox(height: 8),
                  const Text('Previously submitted photos'),
                  Wrap(
                    spacing: 8,
                    children: [
                      for (final imageUrl in _ownClaimImages)
                        ClipRRect(
                          borderRadius: BorderRadius.circular(8),
                          child: Image.network(
                            _resolveMediaUrl(imageUrl),
                            width: 64,
                            height: 64,
                            fit: BoxFit.cover,
                          ),
                        ),
                    ],
                  ),
                ],
                if (_claimPhotos.isNotEmpty)
                  Padding(
                    padding: const EdgeInsets.only(top: 6),
                    child: Text(
                      _claimPhotos.map((photo) => photo.name).join(', '),
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
                Text(
                  'Claim attempts used: $_claimAttemptsUsed of $_claimAttemptLimit',
                ),
                const Text(
                  'Submit photos of the item and answer every ownership question. The post owner reviews your claim first; TMC administrators review it after owner approval. When editing, selecting photos replaces the submitted photos.',
                ),
                const SizedBox(height: 12),
                if (_claimCanEdit && !_editingClaim)
                  OutlinedButton.icon(
                    onPressed: _busy || _loadingOwnClaim
                        ? null
                        : _beginClaimEdit,
                    icon: const Icon(Icons.edit_outlined),
                    label: const Text('Edit claim'),
                  ),
                if (_claimStatusNeedsConfirmation)
                  OutlinedButton.icon(
                    onPressed: _loadingOwnClaim || _busy
                        ? null
                        : () =>
                              unawaited(_loadOwnClaim(preserveSubmitted: true)),
                    icon: const Icon(Icons.refresh),
                    label: const Text('Check claim status'),
                  ),
                if (_editingClaim) ...[
                  OutlinedButton(
                    onPressed: _busy ? null : _cancelClaimEdit,
                    child: const Text('Cancel editing'),
                  ),
                  const SizedBox(height: 8),
                  FilledButton(
                    onPressed: _busy || _loadingOwnClaim
                        ? null
                        : _saveClaimEdits,
                    child: _busy
                        ? const CircularProgressIndicator(color: Colors.white)
                        : const Text('Save claim changes'),
                  ),
                ],
                if (!_editingClaim &&
                    (!_claimSubmitted ||
                        _claimCanSubmitAnother ||
                        _claimRejected))
                  FilledButton(
                    onPressed:
                        _busy ||
                            _loadingOwnClaim ||
                            _claimPhotos.isEmpty ||
                            (_claimSubmitted && !_claimCanSubmitAnother)
                        ? null
                        : _claim,
                    child: _busy || _loadingOwnClaim
                        ? const CircularProgressIndicator(color: Colors.white)
                        : Text(
                            !_claimSubmitted || _claimRejected
                                ? 'Submit claim'
                                : _claimCanSubmitAnother
                                ? 'Resubmit claim'
                                : _claimRejected
                                ? 'Claim attempts used'
                                : 'Already submitted claim',
                          ),
                  ),
                if (_claimFeedback != null)
                  Padding(
                    padding: const EdgeInsets.only(top: 8),
                    child: Text(_claimFeedback!),
                  ),
                if (_busy && _claimUploadProgress > 0)
                  Padding(
                    padding: const EdgeInsets.only(top: 8),
                    child: LinearProgressIndicator(
                      value: _claimUploadSent ? 1 : _claimUploadProgress,
                    ),
                  ),
              ] else if (post.type == 'lost' && post.isMine) ...[
                const SizedBox(height: 16),
                OutlinedButton.icon(
                  onPressed: () => showModalBottomSheet<void>(
                    context: context,
                    isScrollControlled: true,
                    builder: (_) =>
                        SightingReviewSheet(api: widget.api, post: post),
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
                        onPressed:
                            _loadingOwnSighting ||
                                (_ownSightingId != null &&
                                    !_sightingCanSubmitAnother)
                            ? null
                            : () => showModalBottomSheet<void>(
                                context: context,
                                isScrollControlled: true,
                                builder: (_) => SightingEvidenceSheet(
                                  api: widget.api,
                                  post: post,
                                  initialText: _ownSightingText,
                                  initialImageUrl: _ownSightingImage,
                                  editable:
                                      _ownSightingId == null ||
                                      _sightingCanSubmitAnother,
                                  resubmission: _sightingCanSubmitAnother,
                                  onSubmitted: _loadOwnSighting,
                                ),
                              ),
                        child: Text(
                          _loadingOwnSighting
                              ? 'Checking evidence…'
                              : _ownSightingId == null
                              ? 'Yes, I have evidence'
                              : _sightingCanSubmitAnother
                              ? 'Submit new evidence'
                              : _sightingRejected
                              ? 'Evidence attempts used'
                              : 'Evidence submitted',
                        ),
                      ),
                    ),
                    const SizedBox(width: 8),
                    OutlinedButton(
                      onPressed: _recordNoSighting,
                      child: const Text('No'),
                    ),
                  ],
                ),
                Text(
                  'Evidence attempt: ${_sightingAttemptsUsed < _sightingAttemptLimit ? _sightingAttemptsUsed + 1 : _sightingAttemptsUsed} of $_sightingAttemptLimit',
                  style: Theme.of(context).textTheme.bodySmall,
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
                      for (final comment in comments) _commentTile(comment),
                    ],
                  );
                },
              ),
              if (_replyToId != null)
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  title: Text('Replying to ${_replyToName ?? 'comment'}'),
                  trailing: IconButton(
                    tooltip: 'Cancel reply',
                    onPressed: () => setState(() {
                      _replyToId = null;
                      _replyToName = null;
                    }),
                    icon: const Icon(Icons.close),
                  ),
                ),
              Row(
                children: [
                  Expanded(
                    child: TextField(
                      controller: _comment,
                      decoration: const InputDecoration(
                        labelText: 'Add a comment or reply',
                      ),
                    ),
                  ),
                  IconButton(
                    tooltip: 'Send comment',
                    onPressed: _sendComment,
                    icon: Icon(
                      Icons.send,
                      color: Theme.of(context).colorScheme.primary,
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 8),
              const Text(
                'Never post answers to a found item’s verification questions in public comments.',
                style: TextStyle(fontSize: 12),
              ),
              if (_message != null)
                Padding(
                  padding: const EdgeInsets.only(top: 12),
                  child: Text(_message!),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

class SightingEvidenceSheet extends StatefulWidget {
  const SightingEvidenceSheet({
    required this.api,
    required this.post,
    required this.editable,
    this.initialText,
    this.initialImageUrl,
    this.resubmission = false,
    this.onSubmitted,
    super.key,
  });
  final ApiClient api;
  final CampusPost post;
  final String? initialText;
  final String? initialImageUrl;
  final bool editable;
  final bool resubmission;
  final VoidCallback? onSubmitted;

  @override
  State<SightingEvidenceSheet> createState() => _SightingEvidenceSheetState();
}

class _SightingEvidenceSheetState extends State<SightingEvidenceSheet> {
  late final TextEditingController _description;
  XFile? _photo;
  bool _busy = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _description = TextEditingController(text: widget.initialText ?? '');
  }

  @override
  void dispose() {
    _description.dispose();
    super.dispose();
  }

  Future<void> _pickPhoto() async {
    try {
      final photo = await ImagePicker().pickImage(
        source: ImageSource.gallery,
        imageQuality: 85,
      );
      if (photo != null && mounted) setState(() => _photo = photo);
    } on PlatformException catch (error) {
      if (mounted) {
        setState(
          () => _error = error.message ?? 'Could not select an evidence photo.',
        );
      }
    } on Exception {
      if (mounted) {
        setState(() => _error = 'Could not select an evidence photo.');
      }
    }
  }

  Future<void> _submit() async {
    if (_description.text.trim().isEmpty ||
        (_photo == null && widget.initialImageUrl == null)) {
      setState(() => _error = 'Add a short description and an evidence photo.');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final fields = <String, dynamic>{
        'answer': 'yes',
        'evidence_text': _description.text.trim(),
      };
      if (_photo != null) {
        fields['evidence_image'] = await MultipartFile.fromFile(
          _photo!.path,
          filename: _photo!.name,
        );
      }
      final data = FormData.fromMap(fields);
      await widget.api.dio.post<void>(
        '/posts/${widget.post.id}/sightings',
        data: data,
      );
      widget.onSubmitted?.call();
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
        padding: EdgeInsets.fromLTRB(
          20,
          24,
          20,
          MediaQuery.viewInsetsOf(context).bottom + 24,
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(
              widget.resubmission || widget.initialText == null
                  ? 'Share sighting evidence'
                  : 'Edit sighting evidence',
              style: Theme.of(context).textTheme.titleLarge,
            ),
            const SizedBox(height: 8),
            Text(
              widget.resubmission
                  ? 'Submit another evidence report for this item.'
                  : 'Send evidence for this item to its owner.',
            ),
            const SizedBox(height: 14),
            TextField(
              controller: _description,
              maxLines: 3,
              enabled: widget.editable,
              decoration: const InputDecoration(
                labelText: 'Where and when did you see it?',
              ),
            ),
            const SizedBox(height: 12),
            OutlinedButton.icon(
              onPressed: widget.editable ? _pickPhoto : null,
              icon: const Icon(Icons.photo_library_outlined),
              label: Text(_photo?.name ?? 'Choose evidence photo'),
            ),
            if (_photo == null && widget.initialImageUrl != null) ...[
              const SizedBox(height: 8),
              ClipRRect(
                borderRadius: BorderRadius.circular(12),
                child: Image.network(
                  _resolveMediaUrl(widget.initialImageUrl!),
                  height: 150,
                  fit: BoxFit.cover,
                  errorBuilder: (_, _, _) =>
                      const Text('Previously submitted photo could not load.'),
                ),
              ),
            ],
            if (_error != null)
              Padding(
                padding: const EdgeInsets.all(8),
                child: Text(
                  _error!,
                  style: TextStyle(color: Theme.of(context).colorScheme.error),
                ),
              ),
            const SizedBox(height: 12),
            FilledButton(
              onPressed: _busy || !widget.editable ? null : _submit,
              child: _busy
                  ? const CircularProgressIndicator(color: Colors.white)
                  : Text(
                      widget.resubmission || widget.initialText == null
                          ? 'Send to owner'
                          : 'Send to owner',
                    ),
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
      if (mounted) {
        setState(() {
          _sightings = _load();
        });
      }
    } on DioException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(widget.api.errorMessage(error))));
      }
    }
  }

  Future<void> _undoDecision(int id) async {
    try {
      await widget.api.dio.patch<void>('/sightings/$id/undo-owner-decision');
      if (mounted) {
        setState(() {
          _sightings = _load();
        });
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text(
              'Evidence decision undone. The affected evidence is awaiting review again.',
            ),
          ),
        );
      }
    } on DioException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(widget.api.errorMessage(error))));
      }
    }
  }

  Future<void> _startHandover(int id) async {
    try {
      await widget.api.dio.post<Map<String, dynamic>>(
        '/sightings/$id/handover',
      );
      if (mounted) {
        setState(() {
          _sightings = _load();
        });
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text(
              'Handover is ready. Both participants can confirm it from Inbox > Handovers.',
            ),
          ),
        );
      }
    } on DioException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(widget.api.errorMessage(error))));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: ConstrainedBox(
        constraints: BoxConstraints(
          maxHeight: MediaQuery.sizeOf(context).height * 0.85,
        ),
        child: FutureBuilder<List<Map<String, dynamic>>>(
          future: _sightings,
          builder: (context, snapshot) {
            if (snapshot.connectionState == ConnectionState.waiting) {
              return const Center(child: CircularProgressIndicator());
            }
            if (snapshot.hasError) {
              return Center(
                child: Text(widget.api.errorMessage(snapshot.error!)),
              );
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
                Text(
                  'Review sightings for ${widget.post.itemName}',
                  style: Theme.of(context).textTheme.titleLarge,
                ),
                const SizedBox(height: 12),
                for (final sighting in sightings) ...[
                  Card(
                    child: Padding(
                      padding: const EdgeInsets.all(14),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            'From ${sighting['user_first_name'] ?? 'TMC member'}',
                            style: const TextStyle(fontWeight: FontWeight.w700),
                          ),
                          const SizedBox(height: 8),
                          Text(sighting['evidence_text'] as String? ?? ''),
                          if (sighting['evidence_image'] is String) ...[
                            const SizedBox(height: 10),
                            OutlinedButton.icon(
                              onPressed: () => _viewEvidence(
                                context,
                                'Sighting evidence',
                                [sighting['evidence_image'] as String],
                              ),
                              icon: const Icon(Icons.visibility_outlined),
                              label: const Text('View Evidence'),
                            ),
                          ],
                          const SizedBox(height: 8),
                          Text(
                            'Your decision: ${sighting['author_decision'] ?? 'pending'} · Admin: ${sighting['admin_decision'] ?? 'pending'}',
                          ),
                          if (sighting['author_decision'] == 'pending')
                            Row(
                              children: [
                                Expanded(
                                  child: FilledButton(
                                    onPressed: () => _decide(
                                      sighting['id'] as int,
                                      'approve',
                                    ),
                                    child: const Text('Approve evidence'),
                                  ),
                                ),
                                const SizedBox(width: 8),
                                Expanded(
                                  child: OutlinedButton(
                                    onPressed: () => _decide(
                                      sighting['id'] as int,
                                      'reject',
                                    ),
                                    child: const Text('Reject'),
                                  ),
                                ),
                              ],
                            ),
                          if (sighting['can_undo_owner_decision'] == true)
                            OutlinedButton.icon(
                              onPressed: () async {
                                final confirmed = await showDialog<bool>(
                                  context: context,
                                  builder: (dialogContext) => AlertDialog(
                                    title: const Text(
                                      'Undo evidence decision?',
                                    ),
                                    content: const Text(
                                      'This will return this evidence and any evidence rejected because of it to owner review.',
                                    ),
                                    actions: [
                                      TextButton(
                                        onPressed: () =>
                                            Navigator.pop(dialogContext, false),
                                        child: const Text('Cancel'),
                                      ),
                                      FilledButton(
                                        onPressed: () =>
                                            Navigator.pop(dialogContext, true),
                                        child: const Text('Undo'),
                                      ),
                                    ],
                                  ),
                                );
                                if (confirmed == true) {
                                  await _undoDecision(sighting['id'] as int);
                                }
                              },
                              icon: const Icon(Icons.undo),
                              label: const Text('Undo decision'),
                            ),
                          if (sighting['admin_decision'] == 'approved' &&
                              sighting['handover_id'] == null)
                            Padding(
                              padding: const EdgeInsets.only(top: 10),
                              child: FilledButton.tonalIcon(
                                onPressed: () =>
                                    _startHandover(sighting['id'] as int),
                                icon: const Icon(Icons.handshake_outlined),
                                label: const Text('Open handover'),
                              ),
                            ),
                          if (sighting['handover_id'] != null)
                            const Padding(
                              padding: EdgeInsets.only(top: 10),
                              child: Text(
                                'Approved. Both participants can open Inbox > Handovers to confirm the exchange.',
                              ),
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
  const CreatePostScreen({
    required this.api,
    required this.onCreated,
    this.onRefresh,
    this.initialPost,
    super.key,
  });
  final ApiClient api;
  final VoidCallback onCreated;
  final Future<void> Function()? onRefresh;
  final CampusPost? initialPost;

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
  final _categoryOther = TextEditingController();
  final _locationOther = TextEditingController();
  final _questionControllers = <TextEditingController>[];
  final _answerControllers = <TextEditingController>[];
  final _photos = <XFile>[];
  final _altTextControllers = <TextEditingController>[];
  List<Map<String, dynamic>> _categories = [];
  List<Map<String, dynamic>> _locations = [];
  late String _type;
  String _custody = 'with_me';
  int? _categoryId;
  int? _locationId;
  bool _categoryOtherSelected = false;
  bool _locationOtherSelected = false;
  DateTime _eventDate = DateTime.now();
  bool _loading = true;
  bool _busy = false;
  bool _editDataFailed = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _type = widget.initialPost?.type ?? 'lost';
    _questionControllers.add(_question);
    _answerControllers.add(_answer);
    _loadCatalog();
  }

  @override
  void dispose() {
    _name.dispose();
    _color.dispose();
    _description.dispose();
    _categoryOther.dispose();
    _locationOther.dispose();
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
      final response = await widget.api.dio.get<Map<String, dynamic>>(
        '/catalog',
      );
      if (!mounted) {
        return;
      }
      setState(() {
        _categories = List<Map<String, dynamic>>.from(
          response.data?['categories'] as List? ?? const [],
        );
        _locations = List<Map<String, dynamic>>.from(
          response.data?['locations'] as List? ?? const [],
        );
        _loading = widget.initialPost != null;
        _error = null;
      });
      if (widget.initialPost != null) await _loadEditData();
    } on DioException catch (error) {
      if (mounted) {
        setState(() {
          _error = widget.api.errorMessage(error);
          _loading = false;
        });
      }
    }
  }

  Future<void> _loadEditData() async {
    final post = widget.initialPost!;
    try {
      final response = await widget.api.dio.get<Map<String, dynamic>>(
        '/my-posts/${post.id}/edit-data',
      );
      final data = response.data;
      if (data == null) {
        throw const FormatException('Post details were not returned.');
      }
      if (!mounted) return;
      for (final controller in _questionControllers) {
        controller.dispose();
      }
      for (final controller in _answerControllers) {
        controller.dispose();
      }
      _questionControllers.clear();
      _answerControllers.clear();
      final rawQuestions = data['questions'];
      if (rawQuestions is List) {
        for (final item in rawQuestions.whereType<Map<String, dynamic>>()) {
          _questionControllers.add(
            TextEditingController(text: item['question'] as String? ?? ''),
          );
          _answerControllers.add(
            TextEditingController(text: item['answer'] as String? ?? ''),
          );
        }
      }
      if (_questionControllers.isEmpty) {
        _questionControllers.add(TextEditingController());
        _answerControllers.add(TextEditingController());
      }
      if (!mounted) return;
      setState(() {
        _name.text = data['item_name'] as String? ?? '';
        _color.text = data['color'] as String? ?? '';
        _description.text = data['description'] as String? ?? '';
        _categoryOther.text = data['category_other'] as String? ?? '';
        _locationOther.text = data['location_other'] as String? ?? '';
        _categoryOtherSelected = _categoryOther.text.isNotEmpty;
        _locationOtherSelected = _locationOther.text.isNotEmpty;
        _categoryId = _categoryOtherSelected
            ? null
            : data['category_id'] as int?;
        _locationId = _locationOtherSelected
            ? null
            : data['location_id'] as int?;
        _custody = data['custody'] as String? ?? 'with_me';
        _eventDate =
            DateTime.tryParse(data['event_date'] as String? ?? '') ??
            DateTime.now();
        _type = data['type'] as String? ?? post.type;
        _editDataFailed = false;
        _loading = false;
        _error = null;
      });
    } on DioException catch (error) {
      if (mounted) {
        setState(() {
          _editDataFailed = true;
          _loading = false;
          _error = widget.api.errorMessage(error);
        });
      }
    } on FormatException catch (error) {
      if (mounted) {
        setState(() {
          _editDataFailed = true;
          _loading = false;
          _error = error.message;
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
    if (date != null && mounted) setState(() => _eventDate = date);
  }

  Future<void> _pickPhotos() async {
    try {
      final selected = await ImagePicker().pickMultiImage(imageQuality: 85);
      if (selected.isEmpty || !mounted) return;
      final remaining = 5 - _photos.length;
      if (remaining <= 0) {
        setState(() => _error = 'A post can include at most five photos.');
        return;
      }
      final selectedCategory = _categories.where(
        (item) => item['id'] == _categoryId,
      );
      final category = _categoryOtherSelected
          ? _categoryOther.text.trim()
          : selectedCategory.isEmpty
          ? null
          : selectedCategory.first['name'] as String;
      final altText = [
        _name.text.trim(),
        category,
      ].whereType<String>().where((text) => text.isNotEmpty).join(', ');
      setState(() {
        for (final photo in selected.take(remaining)) {
          _photos.add(photo);
          _altTextControllers.add(TextEditingController(text: altText));
        }
        _error = selected.length > remaining
            ? 'Only the first five selected photos were added.'
            : null;
      });
    } on PlatformException catch (error) {
      if (mounted) {
        setState(
          () => _error = error.message ?? 'Could not select post photos.',
        );
      }
    } on Exception {
      if (mounted) setState(() => _error = 'Could not select post photos.');
    }
  }

  void _removePhoto(int index) {
    setState(() {
      _photos.removeAt(index);
      _altTextControllers.removeAt(index).dispose();
    });
  }

  Future<void> _submit() async {
    if (_busy || _editDataFailed || !_formKey.currentState!.validate()) return;
    if ((!_categoryOtherSelected && _categoryId == null) ||
        (_categoryOtherSelected && _categoryOther.text.trim().isEmpty) ||
        (!_locationOtherSelected && _locationId == null) ||
        (_locationOtherSelected && _locationOther.text.trim().isEmpty)) {
      setState(() => _error = 'Choose a category and place.');
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
      if (widget.initialPost == null &&
          !_categoryOtherSelected &&
          !_locationOtherSelected) {
        final duplicateCheck = await widget.api.dio.post<Map<String, dynamic>>(
          '/posts/similar',
          data: {
            'type': _type,
            'item_name': _name.text.trim(),
            'category_id': _categoryId,
            'color': _color.text.trim(),
            'location_id': _locationId,
            'event_date':
                '${_eventDate.year.toString().padLeft(4, '0')}-${_eventDate.month.toString().padLeft(2, '0')}-${_eventDate.day.toString().padLeft(2, '0')}',
          },
        );
        final similar = duplicateCheck.data?['data'];
        if (similar is List && similar.isNotEmpty) {
          if (!mounted) return;
          final confirmed = await showDialog<bool>(
            context: context,
            builder: (context) => AlertDialog(
              title: const Text('Similar TMC posts'),
              content: SizedBox(
                width: double.maxFinite,
                child: ListView(
                  shrinkWrap: true,
                  children: [
                    const Text(
                      'Check that your item is different before posting:',
                    ),
                    const SizedBox(height: 8),
                    for (final item
                        in similar.whereType<Map<String, dynamic>>())
                      ListTile(
                        contentPadding: EdgeInsets.zero,
                        title: Text(item['item_name'] as String? ?? 'Item'),
                        subtitle: Text(
                          '${item['location'] ?? ''} · ${item['event_date'] ?? ''}',
                        ),
                      ),
                  ],
                ),
              ),
              actions: [
                TextButton(
                  onPressed: () => Navigator.pop(context, false),
                  child: const Text('Review these posts'),
                ),
                FilledButton(
                  onPressed: () => Navigator.pop(context, true),
                  child: const Text('This is a different item'),
                ),
              ],
            ),
          );
          if (confirmed != true) return;
        }
      }
      final data = <String, dynamic>{
        'type': _type,
        'item_name': _name.text.trim(),
        'description': _description.text.trim(),
        if (_categoryOtherSelected)
          'category_other': _categoryOther.text.trim()
        else
          'category_id': _categoryId,
        'color': _color.text.trim(),
        if (_locationOtherSelected)
          'location_other': _locationOther.text.trim()
        else
          'location_id': _locationId,
        'event_date':
            '${_eventDate.year.toString().padLeft(4, '0')}-${_eventDate.month.toString().padLeft(2, '0')}-${_eventDate.day.toString().padLeft(2, '0')}',
        if (_type == 'found') 'custody': _custody,
        if (_type == 'found')
          'questions': List.generate(
            _questionControllers.length,
            (index) => {
              'question': _questionControllers[index].text.trim(),
              'answer': _answerControllers[index].text.trim(),
            },
          ),
      };
      final endpoint = widget.initialPost == null
          ? '/posts'
          : '/posts/${widget.initialPost!.id}';
      if (_photos.isNotEmpty) {
        final formData = FormData();
        if (widget.initialPost != null) {
          formData.fields.add(const MapEntry('_method', 'PUT'));
        }
        for (final entry in data.entries) {
          if (entry.value is List) {
            for (var index = 0; index < (entry.value as List).length; index++) {
              final item = (entry.value as List)[index];
              if (item is Map<String, dynamic>) {
                formData.fields.add(
                  MapEntry(
                    '${entry.key}[$index][question]',
                    item['question']?.toString() ?? '',
                  ),
                );
                formData.fields.add(
                  MapEntry(
                    '${entry.key}[$index][answer]',
                    item['answer']?.toString() ?? '',
                  ),
                );
              }
            }
            continue;
          }
          formData.fields.add(MapEntry(entry.key, entry.value.toString()));
        }
        for (var index = 0; index < _photos.length; index++) {
          formData.files.add(
            MapEntry(
              'photos[$index][file]',
              await MultipartFile.fromFile(
                _photos[index].path,
                filename: _photos[index].name,
              ),
            ),
          );
          formData.fields.add(
            MapEntry(
              'photos[$index][alt_text]',
              _altTextControllers[index].text.trim(),
            ),
          );
        }
        await widget.api.dio.post<void>(endpoint, data: formData);
      } else if (widget.initialPost != null) {
        await widget.api.dio.put<void>(endpoint, data: data);
      } else {
        await widget.api.dio.post<void>(endpoint, data: data);
      }
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
              widget.initialPost == null
                  ? 'Your post is live on the TMC feed.'
                  : 'Your post was updated.',
            ),
          ),
        );
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
    if (_editDataFailed) {
      return _EmptyState(
        icon: Icons.cloud_off_outlined,
        title: 'Could not load post for editing',
        message: _error ?? 'Please try again.',
        action: FilledButton.tonal(
          onPressed: () {
            setState(() {
              _loading = true;
              _editDataFailed = false;
              _error = null;
            });
            _loadCatalog();
          },
          child: const Text('Try again'),
        ),
      );
    }
    if (_error != null && _categories.isEmpty) {
      return _EmptyState(
        icon: Icons.cloud_off,
        title: 'Could not load campus options',
        message: _error!,
        action: FilledButton.tonal(
          onPressed: () {
            setState(() {
              _error = null;
              _loading = true;
            });
            _loadCatalog();
          },
          child: const Text('Try again'),
        ),
      );
    }
    final formContents = ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.all(16),
      children: [
        _SectionBanner(
          icon: Icons.add_a_photo_outlined,
          title: widget.initialPost == null
              ? 'Share with the community'
              : 'Edit your post',
          subtitle: 'Post a lost or found item and help bring it home.',
          tint: CampusTheme.found,
        ),
        const SizedBox(height: 14),
        const Card(
          child: Padding(
            padding: EdgeInsets.all(12),
            child: Text(
              'Keep identifying details private. Do not photograph serial numbers, IDs, or unique verification details.',
            ),
          ),
        ),
        const SizedBox(height: 18),
        SegmentedButton<String>(
          segments: const [
            ButtonSegment(
              value: 'lost',
              label: Text('I lost something'),
              icon: Icon(Icons.search),
            ),
            ButtonSegment(
              value: 'found',
              label: Text('I found something'),
              icon: Icon(Icons.check_circle_outline),
            ),
          ],
          selected: {_type},
          onSelectionChanged: (values) => setState(() => _type = values.first),
        ),
        const SizedBox(height: 16),
        TextFormField(
          controller: _name,
          decoration: const InputDecoration(labelText: 'Item name *'),
          validator: _required,
        ),
        const SizedBox(height: 12),
        DropdownButtonFormField<int>(
          initialValue: _categoryOtherSelected ? -1 : _categoryId,
          decoration: const InputDecoration(labelText: 'Category *'),
          items: [
            ..._categories.map(
              (item) => DropdownMenuItem<int>(
                value: item['id'] as int,
                child: Text(item['name'] as String),
              ),
            ),
            const DropdownMenuItem<int>(value: -1, child: Text('Other')),
          ],
          onChanged: (value) => setState(() {
            _categoryOtherSelected = value == -1;
            _categoryId = value == null || value == -1 ? null : value;
            if (!_categoryOtherSelected) _categoryOther.clear();
          }),
        ),
        if (_categoryOtherSelected) ...[
          const SizedBox(height: 8),
          TextFormField(
            controller: _categoryOther,
            decoration: const InputDecoration(
              labelText: 'If others, please specify:',
            ),
            validator: (value) =>
                _categoryOtherSelected ? _required(value) : null,
          ),
        ],
        const SizedBox(height: 12),
        TextFormField(
          controller: _color,
          decoration: const InputDecoration(labelText: 'Color *'),
          validator: _required,
        ),
        const SizedBox(height: 12),
        DropdownButtonFormField<int>(
          initialValue: _locationOtherSelected ? -1 : _locationId,
          decoration: const InputDecoration(labelText: 'Place *'),
          items: [
            ..._locations.map(
              (item) => DropdownMenuItem<int>(
                value: item['id'] as int,
                child: Text(item['name'] as String),
              ),
            ),
            const DropdownMenuItem<int>(value: -1, child: Text('Other')),
          ],
          onChanged: (value) => setState(() {
            _locationOtherSelected = value == -1;
            _locationId = value == null || value == -1 ? null : value;
            if (!_locationOtherSelected) _locationOther.clear();
          }),
        ),
        if (_locationOtherSelected) ...[
          const SizedBox(height: 8),
          TextFormField(
            controller: _locationOther,
            decoration: const InputDecoration(
              labelText: 'If others, please specify:',
            ),
            validator: (value) =>
                _locationOtherSelected ? _required(value) : null,
          ),
        ],
        const SizedBox(height: 12),
        OutlinedButton.icon(
          onPressed: _selectDate,
          icon: const Icon(Icons.calendar_today_outlined),
          label: Text(
            'Date: ${_eventDate.year}-${_eventDate.month.toString().padLeft(2, '0')}-${_eventDate.day.toString().padLeft(2, '0')}',
          ),
        ),
        const SizedBox(height: 12),
        TextFormField(
          controller: _description,
          minLines: 3,
          maxLines: 5,
          decoration: const InputDecoration(labelText: 'Description *'),
          validator: _required,
        ),
        const SizedBox(height: 16),
        const Text(
          'Photos (up to 5)',
          style: TextStyle(fontWeight: FontWeight.w700),
        ),
        const SizedBox(height: 6),
        const Text(
          'Avoid serial numbers, lock-screen text, ID details, and other information used in your verification questions.',
        ),
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
                      Expanded(
                        child: Text(
                          _photos[index].name,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                        ),
                      ),
                      IconButton(
                        tooltip: 'Remove photo',
                        onPressed: () => _removePhoto(index),
                        icon: const Icon(Icons.close),
                      ),
                    ],
                  ),
                  TextFormField(
                    controller: _altTextControllers[index],
                    decoration: InputDecoration(
                      labelText: 'Photo description ${index + 1} *',
                    ),
                    validator: _required,
                  ),
                ],
              ),
            ),
          ),
        if (_type == 'found') ...[
          const SizedBox(height: 16),
          const Text(
            'Safe custody',
            style: TextStyle(fontWeight: FontWeight.w700),
          ),
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
            TextFormField(
              controller: _questionControllers[index],
              decoration: InputDecoration(
                labelText: 'Verification question ${index + 1} *',
              ),
              validator: _required,
            ),
            const SizedBox(height: 8),
            TextFormField(
              controller: _answerControllers[index],
              decoration: InputDecoration(
                labelText: 'Private answer ${index + 1} *',
              ),
              validator: _required,
            ),
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
        if (_error != null)
          Padding(
            padding: const EdgeInsets.symmetric(vertical: 10),
            child: Text(
              _error!,
              style: TextStyle(color: Theme.of(context).colorScheme.error),
            ),
          ),
        const SizedBox(height: 16),
        FilledButton(
          onPressed: _busy ? null : _submit,
          child: _busy
              ? const CircularProgressIndicator(color: Colors.white)
              : Text(widget.initialPost == null ? 'Post item' : 'Save changes'),
        ),
      ],
    );
    return Form(
      key: _formKey,
      child: widget.onRefresh == null
          ? formContents
          : RefreshIndicator(onRefresh: widget.onRefresh!, child: formContents),
    );
  }

  String? _required(String? value) =>
      value == null || value.trim().isEmpty ? 'This field is required.' : null;
}

class InboxScreen extends StatefulWidget {
  const InboxScreen({
    required this.api,
    required this.onOpenProfile,
    required this.onOpenPost,
    this.initialTab = 0,
    super.key,
  });
  final ApiClient api;
  final Future<void> Function(int) onOpenProfile;
  final Future<void> Function(int, {required bool isOwner, int? returnInboxTab})
  onOpenPost;
  final int initialTab;

  @override
  State<InboxScreen> createState() => _InboxScreenState();
}

class _OwnerDecisionDialog extends StatefulWidget {
  const _OwnerDecisionDialog({required this.decision, required this.subject});

  final String decision;
  final String subject;

  @override
  State<_OwnerDecisionDialog> createState() => _OwnerDecisionDialogState();
}

class _OwnerDecisionDialogState extends State<_OwnerDecisionDialog> {
  final _reasonController = TextEditingController();
  String? _reasonError;

  @override
  void dispose() {
    _reasonController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: Text(
        widget.decision == 'approve'
            ? 'Approve this ${widget.subject}?'
            : 'Decline this ${widget.subject}?',
      ),
      content: TextField(
        controller: _reasonController,
        maxLength: 1000,
        maxLines: 3,
        onChanged: (_) {
          if (_reasonError != null) {
            setState(() {
              _reasonError = null;
            });
          }
        },
        decoration: InputDecoration(
          labelText: 'Reason for your decision (required)',
          alignLabelWithHint: true,
          errorText: _reasonError,
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(context),
          child: const Text('Cancel'),
        ),
        FilledButton(
          onPressed: () {
            final value = _reasonController.text.trim();
            if (value.isEmpty) {
              setState(() {
                _reasonError = 'A reason is required.';
              });
              return;
            }
            Navigator.pop(context, value);
          },
          child: const Text('Submit decision'),
        ),
      ],
    );
  }
}

class _HandoverRescheduleDialog extends StatefulWidget {
  const _HandoverRescheduleDialog();

  @override
  State<_HandoverRescheduleDialog> createState() =>
      _HandoverRescheduleDialogState();
}

class _HandoverRescheduleDialogState extends State<_HandoverRescheduleDialog> {
  final _dateController = TextEditingController();
  final _reasonController = TextEditingController();

  @override
  void dispose() {
    _dateController.dispose();
    _reasonController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      scrollable: true,
      title: const Text('Request a handover reschedule'),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          TextField(
            controller: _dateController,
            keyboardType: TextInputType.datetime,
            decoration: const InputDecoration(
              labelText: 'Requested date',
              hintText: 'YYYY-MM-DD',
            ),
          ),
          TextField(
            controller: _reasonController,
            maxLines: 3,
            decoration: const InputDecoration(
              labelText: 'Message to TMC administrators',
            ),
          ),
        ],
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.pop(context),
          child: const Text('Cancel'),
        ),
        FilledButton(
          onPressed: () => Navigator.pop(context, {
            'requested_date': _dateController.text.trim(),
            'reason': _reasonController.text.trim(),
          }),
          child: const Text('Send request'),
        ),
      ],
    );
  }
}

class _InboxScreenState extends State<InboxScreen> {
  int _tab = 0;
  late Future<List<Map<String, dynamic>>> _notifications;
  late Future<List<Map<String, dynamic>>> _conversations;
  late Future<List<Map<String, dynamic>>> _handovers;
  late Future<List<Map<String, dynamic>>> _authorClaims;
  late Future<List<Map<String, dynamic>>> _evidence;
  late Future<List<Map<String, dynamic>>> _outboundClaims;
  late Future<List<Map<String, dynamic>>> _outboundEvidence;
  String _conversationFilter = 'all';
  String _handoverView = 'active';
  String _claimDirection = 'inbound';
  String _evidenceDirection = 'inbound';

  @override
  void initState() {
    super.initState();
    _tab = widget.initialTab;
    _notifications = _load();
    _conversations = _loadConversations();
    _handovers = _loadHandovers();
    _authorClaims = _loadAuthorClaims();
    _evidence = _loadEvidence();
    _outboundClaims = Future.value(const <Map<String, dynamic>>[]);
    _outboundEvidence = Future.value(const <Map<String, dynamic>>[]);
  }

  @override
  void didUpdateWidget(covariant InboxScreen oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.initialTab != widget.initialTab) {
      _tab = widget.initialTab;
    }
  }

  Future<List<Map<String, dynamic>>> _load() async {
    final response = await widget.api.dio.get<Map<String, dynamic>>(
      '/notifications',
    );
    final data = response.data?['data'];
    if (data is! List) return const [];
    return data.whereType<Map<String, dynamic>>().toList();
  }

  Future<List<Map<String, dynamic>>> _loadConversations() async {
    final response = await widget.api.dio.get<Map<String, dynamic>>(
      '/conversations',
    );
    final data = response.data?['data'];
    if (data is! List) return const [];
    return data.whereType<Map<String, dynamic>>().toList();
  }

  Future<List<Map<String, dynamic>>> _loadHandovers() async {
    final results = await Future.wait([
      _loadAllPages('/handovers'),
      _loadAllPages('/retrieval-history'),
    ]);
    final handovers = [...results[0], ...results[1]];
    handovers.sort((first, second) {
      final firstDate =
          DateTime.tryParse('${first['history_at'] ?? ''}') ??
          DateTime.fromMillisecondsSinceEpoch(0);
      final secondDate =
          DateTime.tryParse('${second['history_at'] ?? ''}') ??
          DateTime.fromMillisecondsSinceEpoch(0);
      return secondDate.compareTo(firstDate);
    });
    return handovers;
  }

  Future<List<Map<String, dynamic>>> _loadAuthorClaims() async {
    return _loadAllPages('/received-claims');
  }

  Future<List<Map<String, dynamic>>> _loadEvidence() async {
    return _loadAllPages('/received-evidence');
  }

  Future<List<Map<String, dynamic>>> _loadOutboundClaims() async {
    return _loadAllPages('/my-claims');
  }

  Future<List<Map<String, dynamic>>> _loadOutboundEvidence() async {
    return _loadAllPages('/my-sightings');
  }

  Future<List<Map<String, dynamic>>> _loadAllPages(String path) async {
    final submissions = <Map<String, dynamic>>[];
    var page = 1;
    while (true) {
      final response = await widget.api.dio.get<Map<String, dynamic>>(
        path,
        queryParameters: {'page': page},
      );
      final payload = response.data;
      final data = payload?['data'];
      if (data is List) {
        submissions.addAll(data.whereType<Map<String, dynamic>>());
      }
      final meta = payload?['meta'];
      final lastPage = meta is Map
          ? int.tryParse('${meta['last_page']}') ?? page
          : page;
      if (page >= lastPage) return submissions;
      page++;
    }
  }

  Future<void> _markRead(String id) async {
    try {
      await widget.api.dio.patch<void>('/notifications/$id/read');
      if (mounted) {
        setState(() {
          _notifications = _load();
        });
      }
    } on DioException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(widget.api.errorMessage(error))));
      }
    }
  }

  Future<void> _openOriginPost(
    int postId, {
    required bool isOwner,
    int? returnInboxTab,
  }) => widget.onOpenPost(
    postId,
    isOwner: isOwner,
    returnInboxTab: returnInboxTab,
  );

  Future<void> _openNotification(Map<String, dynamic> notification) async {
    final id = notification['id'] as String?;
    if (id != null && id.isNotEmpty) await _markRead(id);
    if (!mounted) return;
    final data = notification['data'] as Map<String, dynamic>? ?? const {};
    final conversationId = data['conversation_id'] as int?;
    final notificationType = notification['type'] as String? ?? '';
    if (const {
      'handover_completed',
      'handover_unsuccessful',
      'post_retrieval_approved',
    }.contains(notificationType)) {
      setState(() {
        _tab = 2;
        _handoverView = 'history';
        _handovers = _loadHandovers();
      });
      return;
    }
    if (const {
      'handover_scheduled',
      'handover_reschedule_requested',
      'handover_reschedule_rejected',
    }.contains(notificationType)) {
      setState(() {
        _tab = 2;
        _handoverView = 'active';
        _handovers = _loadHandovers();
      });
      return;
    }
    if (const {
      'lost_sighting',
      'sighting_submitted',
    }.contains(notificationType)) {
      setState(() {
        _tab = 4;
        _evidenceDirection = 'inbound';
        _evidence = _loadEvidence();
      });
      return;
    }
    if (const {
      'sighting_owner_reviewed',
      'sighting_owner_decision_undone',
    }.contains(notificationType)) {
      setState(() {
        _tab = 4;
        _evidenceDirection = 'outbound';
        _outboundEvidence = _loadOutboundEvidence();
      });
      return;
    }
    if (notificationType == 'sighting_rejected') {
      final isInbound = data['recipient_role'] == 'post_owner';
      setState(() {
        _tab = 4;
        _evidenceDirection = isInbound ? 'inbound' : 'outbound';
        if (isInbound) {
          _evidence = _loadEvidence();
        } else {
          _outboundEvidence = _loadOutboundEvidence();
        }
      });
      return;
    }
    if (notificationType == 'sighting_approved') {
      setState(() {
        _tab = 2;
        _handoverView = 'active';
        _handovers = _loadHandovers();
      });
      return;
    }
    if (notificationType == 'claim_pending') {
      setState(() {
        _tab = 3;
        _claimDirection = 'inbound';
        _authorClaims = _loadAuthorClaims();
      });
      return;
    }
    if (const {
      'claim_owner_approved',
      'claim_owner_rejected',
      'claim_owner_decision_undone',
    }.contains(notificationType)) {
      setState(() {
        _tab = 3;
        _claimDirection = 'outbound';
        _outboundClaims = _loadOutboundClaims();
      });
      return;
    }
    if (notificationType == 'claim_approved') {
      setState(() {
        _tab = 2;
        _handoverView = 'active';
        _handovers = _loadHandovers();
      });
      return;
    }
    if (notificationType == 'claim_rejected') {
      setState(() {
        _tab = 3;
        _claimDirection = 'outbound';
        _outboundClaims = _loadOutboundClaims();
      });
      return;
    }

    if (conversationId != null) {
      try {
        final response = await widget.api.dio.get<Map<String, dynamic>>(
          '/conversations',
        );
        final raw = response.data?['data'];
        final conversations = raw is List
            ? raw.whereType<Map<String, dynamic>>().toList()
            : const <Map<String, dynamic>>[];
        final conversation = conversations
            .cast<Map<String, dynamic>?>()
            .firstWhere(
              (item) => item?['id'] == conversationId,
              orElse: () => null,
            );
        if (conversation != null && mounted) {
          await Navigator.of(context).push(
            MaterialPageRoute<void>(
              builder: (_) => ConversationScreen(
                api: widget.api,
                conversation: conversation,
                onOpenProfile: widget.onOpenProfile,
              ),
            ),
          );
        }
      } on DioException catch (error) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(content: Text(widget.api.errorMessage(error))),
          );
        }
      }
      return;
    }

    final postId = data['post_id'] as int?;
    if (postId == null) return;
    await _openOriginPost(postId, isOwner: false);
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        const Padding(
          padding: EdgeInsets.fromLTRB(14, 12, 14, 9),
          child: _SectionBanner(
            icon: Icons.inbox_rounded,
            title: 'Your campus inbox',
            subtitle: 'Updates, chats, evidence, claims, and handovers.',
            tint: CampusTheme.blue,
          ),
        ),
        Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            _inboxTabButton(0, Icons.notifications_none, 'Notifications'),
            _inboxTabButton(1, Icons.chat_bubble_outline, 'Chats'),
            _inboxTabButton(2, Icons.handshake_outlined, 'Handovers'),
            _inboxTabButton(3, Icons.fact_check_outlined, 'Claims'),
            _inboxTabButton(4, Icons.photo_library_outlined, 'Evidence'),
          ],
        ),
        const SizedBox(height: 8),
        Expanded(
          child: _tab == 0
              ? _notificationList()
              : _tab == 1
              ? _conversationList()
              : _tab == 2
              ? _handoverList()
              : _tab == 3
              ? _authorClaimList()
              : _evidenceList(),
        ),
      ],
    );
  }

  Widget _inboxTabButton(int tab, IconData icon, String label) {
    return Tooltip(
      message: label,
      child: IconButton.filledTonal(
        onPressed: () => setState(() {
          _tab = tab;
          if (tab == 1) _conversations = _loadConversations();
          if (tab == 2) _handovers = _loadHandovers();
          if (tab == 3) _authorClaims = _loadAuthorClaims();
          if (tab == 4) _evidence = _loadEvidence();
        }),
        icon: Icon(icon),
        style: IconButton.styleFrom(
          backgroundColor: _tab == tab
              ? Theme.of(context).colorScheme.secondaryContainer
              : Colors.transparent,
        ),
      ),
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
              onPressed: () => setState(() {
                _notifications = _load();
              }),
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
          onRefresh: () async {
            setState(() {
              _notifications = _load();
            });
          },
          child: ListView.builder(
            padding: const EdgeInsets.all(16),
            itemCount: notifications.length,
            itemBuilder: (context, index) {
              final notification = notifications[index];
              final data =
                  notification['data'] as Map<String, dynamic>? ?? const {};
              final id = notification['id'] as String? ?? '';
              return Card(
                child: ListTile(
                  leading: Icon(
                    notification['read_at'] == null
                        ? Icons.notifications_active
                        : Icons.notifications_none,
                    color: Theme.of(context).colorScheme.primary,
                  ),
                  title: Text(data['title'] as String? ?? 'CampusLF update'),
                  subtitle: Text(data['body'] as String? ?? ''),
                  onTap: id.isEmpty
                      ? null
                      : () => _openNotification(notification),
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
              onPressed: () => setState(() {
                _conversations = _loadConversations();
              }),
              child: const Text('Try again'),
            ),
          );
        }
        final allConversations = snapshot.data ?? const [];
        final conversations = allConversations.where((conversation) {
          final type = conversation['type'] as String? ?? 'claim';
          final isRequest = conversation['is_request'] as bool? ?? false;
          if (_conversationFilter == 'request') return isRequest;
          if (_conversationFilter == 'all') return true;
          return type == _conversationFilter;
        }).toList();

        final categoryFilterRow = Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12),
          child: Wrap(
            spacing: 8,
            children: [
              for (final filter in const [
                ('all', 'All'),
                ('request', 'Requests'),
                ('direct', 'Direct'),
                ('claim', 'Claims'),
                ('evidence', 'Evidence'),
                ('admin', 'TMC admins'),
              ])
                ChoiceChip(
                  label: Text(filter.$2),
                  selected: _conversationFilter == filter.$1,
                  onSelected: (_) =>
                      setState(() => _conversationFilter = filter.$1),
                ),
            ],
          ),
        );

        if (conversations.isEmpty) {
          return Column(
            children: [
              categoryFilterRow,
              const Expanded(
                child: _EmptyState(
                  icon: Icons.chat_bubble_outline,
                  title: 'No chats in this category',
                  message: 'Direct messages, claims, evidence, and TMC support conversations appear here.',
                ),
              ),
            ],
          );
        }
        return Column(
          children: [
            categoryFilterRow,
            Expanded(
              child: ListView.builder(
                padding: const EdgeInsets.all(16),
                itemCount: conversations.length,
                itemBuilder: (context, index) {
                  final conversation = conversations[index];
                  final otherUserId = conversation['other_user_id'] as int?;
                  final firstName =
                      conversation['other_first_name'] as String? ?? 'TMC';
                  final lastName =
                      conversation['other_last_name'] as String? ?? '';
                  final pictureUrl =
                      conversation['other_profile_picture_url'] as String?;
                  final isRequest =
                      conversation['is_request'] as bool? ?? false;
                  return Card(
                    child: ListTile(
                      leading: otherUserId == null
                          ? CircleAvatar(
                              child: Icon(
                                conversation['type'] == 'admin'
                                    ? Icons.support_agent
                                    : Icons.person_outline,
                              ),
                            )
                          : InkWell(
                              onTap: () => widget.onOpenProfile(otherUserId),
                              customBorder: const CircleBorder(),
                              child: CircleAvatar(
                                backgroundColor: Theme.of(context)
                                    .colorScheme
                                    .surfaceContainerHighest,
                                backgroundImage: pictureUrl == null
                                    ? null
                                    : NetworkImage(pictureUrl),
                                child: pictureUrl == null
                                    ? Text(
                                        firstName.isEmpty
                                            ? '?'
                                            : firstName[0].toUpperCase(),
                                        style: TextStyle(
                                          color: Theme.of(context)
                                              .colorScheme
                                              .primary,
                                        ),
                                      )
                                    : null,
                              ),
                            ),
                      title: Row(
                        children: [
                          Expanded(
                            child: Text(
                              conversation['item_name'] as String? ??
                                  'TMC item',
                              overflow: TextOverflow.ellipsis,
                            ),
                          ),
                          if (isRequest)
                            Container(
                              padding: const EdgeInsets.symmetric(
                                horizontal: 6,
                                vertical: 2,
                              ),
                              decoration: BoxDecoration(
                                color: Theme.of(context)
                                    .colorScheme
                                    .errorContainer,
                                borderRadius: BorderRadius.circular(6),
                              ),
                              child: Text(
                                'Request',
                                style: TextStyle(
                                  fontSize: 11,
                                  color: Theme.of(context)
                                      .colorScheme
                                      .onErrorContainer,
                                  fontWeight: FontWeight.bold,
                                ),
                              ),
                            ),
                        ],
                      ),
                      subtitle: InkWell(
                        onTap: otherUserId == null
                            ? null
                            : () => widget.onOpenProfile(otherUserId),
                        child: Text(
                          isRequest
                              ? 'Message Request from $firstName $lastName'
                                    .trim()
                              : 'Chat with $firstName $lastName'.trim(),
                          style: TextStyle(
                            color: Theme.of(context).colorScheme.primary,
                          ),
                        ),
                      ),
                      trailing: const Icon(Icons.chevron_right),
                      onTap: () async {
                        await Navigator.of(context).push(
                          MaterialPageRoute<void>(
                            builder: (_) => ConversationScreen(
                              api: widget.api,
                              conversation: conversation,
                              onOpenProfile: widget.onOpenProfile,
                            ),
                          ),
                        );
                        if (mounted) {
                          setState(() {
                            _conversations = _loadConversations();
                          });
                        }
                      },
                    ),
                  );
                },
              ),
            ),
          ],
        );
      },
    );
  }

  Future<bool> _decideEvidence(int id, String decision, String reason) async {
    try {
      await widget.api.dio.patch<void>(
        '/sightings/$id',
        data: {'decision': decision, 'reason': reason},
      );
      if (mounted) {
        setState(() {
          _evidence = _loadEvidence();
          _handovers = _loadHandovers();
        });
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
              decision == 'approve'
                  ? 'Evidence approved. Other pending evidence for this post was rejected.'
                  : 'Evidence rejected.',
            ),
          ),
        );
      }
      return true;
    } on DioException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(widget.api.errorMessage(error))));
      }
      return false;
    }
  }

  Future<bool> _requestEvidenceDecision(int id, String decision) async {
    final reason = await showDialog<String>(
      context: context,
      builder: (_) =>
          _OwnerDecisionDialog(decision: decision, subject: 'evidence'),
    );
    if (reason == null || reason.isEmpty) return false;
    return _decideEvidence(id, decision, reason);
  }

  Future<bool> _undoEvidenceDecision(int id) async {
    try {
      await widget.api.dio.patch<void>('/sightings/$id/undo-owner-decision');
      if (mounted) {
        setState(() {
          _evidence = _loadEvidence();
          _handovers = _loadHandovers();
        });
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text(
              'Evidence decision undone. The affected evidence is awaiting review again.',
            ),
          ),
        );
      }
      return true;
    } on DioException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(widget.api.errorMessage(error))));
      }
      return false;
    }
  }

  Future<void> _messageAdminAboutEvidence(Map<String, dynamic> evidence) async {
    try {
      final body = await showDialog<String>(
        context: context,
        builder: (_) => _TextEntryDialog(
          title: 'Message TMC administrators',
          initialText:
              'Please review the evidence for ${evidence['item_name'] ?? 'my lost item'}. I believe it is valid.',
          label: 'Message',
          actionLabel: 'Open chat',
          maxLines: 4,
          maxLength: 2000,
          requiredInput: true,
        ),
      );
      if (body == null || !mounted) return;
      final response = await widget.api.dio.post<Map<String, dynamic>>(
        '/sightings/${evidence['id']}/admin-conversation',
        data: {'body': body},
      );
      if (!mounted) return;
      final conversation = response.data;
      if (conversation == null) return;
      setState(() {
        _tab = 1;
        _conversationFilter = 'admin';
        _conversations = _loadConversations();
      });
      await Navigator.of(context).push(
        MaterialPageRoute<void>(
          builder: (_) => ConversationScreen(
            api: widget.api,
            conversation: conversation,
            onOpenProfile: widget.onOpenProfile,
          ),
        ),
      );
    } on DioException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(widget.api.errorMessage(error))));
      }
    }
  }

  Future<void> _messageAdminAboutClaim(Map<String, dynamic> claim) async {
    try {
      final body = await showDialog<String>(
        context: context,
        builder: (_) => _TextEntryDialog(
          title: 'Message TMC administrators',
          initialText:
              'Please review the claim for ${claim['item_name'] ?? 'my found item'}. I approved it and believe it is ready for handover.',
          label: 'Message',
          actionLabel: 'Open chat',
          maxLines: 4,
          maxLength: 2000,
          requiredInput: true,
        ),
      );
      if (body == null || !mounted) return;
      final response = await widget.api.dio.post<Map<String, dynamic>>(
        '/claims/${claim['id']}/admin-conversation',
        data: {'body': body},
      );
      if (!mounted) return;
      final conversation = response.data;
      if (conversation == null) return;
      setState(() {
        _tab = 1;
        _conversationFilter = 'admin';
        _conversations = _loadConversations();
      });
      await Navigator.of(context).push(
        MaterialPageRoute<void>(
          builder: (_) => ConversationScreen(
            api: widget.api,
            conversation: conversation,
            onOpenProfile: widget.onOpenProfile,
          ),
        ),
      );
    } on DioException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(widget.api.errorMessage(error))));
      }
    }
  }

  Widget _evidenceList() {
    return Column(
      children: [
        _submissionDirectionSelector(
          value: _evidenceDirection,
          onChanged: (direction) => setState(() {
            _evidenceDirection = direction;
            if (direction == 'inbound') {
              _evidence = _loadEvidence();
            } else {
              _outboundEvidence = _loadOutboundEvidence();
            }
          }),
        ),
        Expanded(
          child: _evidenceDirection == 'inbound'
              ? _inboundEvidenceList()
              : _outboundEvidenceList(),
        ),
      ],
    );
  }

  Widget _inboundEvidenceList() {
    return FutureBuilder<List<Map<String, dynamic>>>(
      future: _evidence,
      builder: (context, snapshot) {
        if (snapshot.connectionState == ConnectionState.waiting) {
          return const Center(child: CircularProgressIndicator());
        }
        if (snapshot.hasError) {
          return _EmptyState(
            icon: Icons.cloud_off,
            title: 'Evidence unavailable',
            message: widget.api.errorMessage(snapshot.error!),
            action: FilledButton.tonal(
              onPressed: () => setState(() {
                _evidence = _loadEvidence();
              }),
              child: const Text('Try again'),
            ),
          );
        }
        final evidence = snapshot.data ?? const [];
        if (evidence.isEmpty) {
          return const _EmptyState(
            icon: Icons.photo_library_outlined,
            title: 'No evidence received',
            message: 'Proofs sent for your lost posts will appear here.',
          );
        }
        final groups = _groupByPost(evidence);
        final postIds = groups.keys.toList();
        return RefreshIndicator(
          onRefresh: () async {
            setState(() {
              _evidence = _loadEvidence();
            });
          },
          child: ListView.separated(
            padding: const EdgeInsets.all(14),
            itemCount: postIds.length,
            separatorBuilder: (_, _) => const SizedBox(height: 10),
            itemBuilder: (context, index) {
              final postId = postIds[index];
              final items = groups[postId]!;
              final pendingCount = items
                  .where(
                    (item) =>
                        (item['author_decision'] as String? ?? 'pending') ==
                        'pending',
                  )
                  .length;
              final rejectedCount = items
                  .where(
                    (item) =>
                        (item['author_decision'] as String? ?? 'pending') ==
                            'rejected' ||
                        (item['admin_decision'] as String? ?? 'pending') ==
                            'rejected',
                  )
                  .length;
              final approvedCount = items.where((item) {
                final authorDecision =
                    item['author_decision'] as String? ?? 'pending';
                final adminDecision =
                    item['admin_decision'] as String? ?? 'pending';
                return authorDecision != 'rejected' &&
                    adminDecision != 'rejected' &&
                    (authorDecision == 'approved' ||
                        adminDecision == 'approved');
              }).length;
              return Card(
                child: Padding(
                  padding: const EdgeInsets.all(14),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        items.first['item_name'] as String? ?? 'Lost item',
                        style: Theme.of(context).textTheme.titleMedium,
                      ),
                      Text(
                        '${items.length} handling proof(s) · $pendingCount awaiting your decision · $approvedCount approved · $rejectedCount rejected',
                      ),
                      const SizedBox(height: 10),
                      Wrap(
                        spacing: 8,
                        runSpacing: 8,
                        children: [
                          OutlinedButton.icon(
                            onPressed: () => _openOriginPost(
                              postId,
                              isOwner: true,
                              returnInboxTab: 4,
                            ),
                            icon: const Icon(Icons.open_in_new),
                            label: const Text('View Post'),
                          ),
                          FilledButton.tonalIcon(
                            onPressed: () =>
                                _showEvidenceForPost(postId, items),
                            icon: const Icon(Icons.list_alt),
                            label: const Text('List of handling proofs'),
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
              );
            },
          ),
        );
      },
    );
  }

  Widget _outboundEvidenceList() {
    return FutureBuilder<List<Map<String, dynamic>>>(
      future: _outboundEvidence,
      builder: (context, snapshot) {
        if (snapshot.connectionState == ConnectionState.waiting) {
          return const Center(child: CircularProgressIndicator());
        }
        if (snapshot.hasError) {
          return _EmptyState(
            icon: Icons.cloud_off,
            title: 'Sent evidence unavailable',
            message: widget.api.errorMessage(snapshot.error!),
            action: FilledButton.tonal(
              onPressed: () => setState(() {
                _outboundEvidence = _loadOutboundEvidence();
              }),
              child: const Text('Try again'),
            ),
          );
        }
        final evidence = snapshot.data ?? const [];
        if (evidence.isEmpty) {
          return const _EmptyState(
            icon: Icons.outbox_outlined,
            title: 'No evidence sent',
            message: 'Proofs you send for other people’s lost posts will appear here.',
          );
        }
        return RefreshIndicator(
          onRefresh: () async {
            setState(() {
              _outboundEvidence = _loadOutboundEvidence();
            });
          },
          child: ListView.separated(
            padding: const EdgeInsets.all(14),
            itemCount: evidence.length,
            separatorBuilder: (_, _) => const SizedBox(height: 8),
            itemBuilder: (context, index) {
              final item = evidence[index];
              final image = item['evidence_image'] as String?;
              return Card(
                child: ListTile(
                  title: Text(item['item_name'] as String? ?? 'Lost item'),
                  subtitle: Text(
                    '${_evidenceStatus(item)}'
                    '${(item['evidence_text'] as String? ?? '').isEmpty ? '' : '\n${item['evidence_text']}'}',
                  ),
                  isThreeLine:
                      (item['evidence_text'] as String? ?? '').isNotEmpty,
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () {
                    final postId = item['post_id'] as int?;
                    if (postId != null) {
                      _openOriginPost(
                        postId,
                        isOwner: false,
                        returnInboxTab: 4,
                      );
                    }
                  },
                  leading: image == null
                      ? const Icon(Icons.photo_outlined)
                      : IconButton(
                          tooltip: 'View submitted proof',
                          onPressed: () => _viewEvidence(
                            context,
                            'Submitted proof',
                            [image],
                          ),
                          icon: const Icon(Icons.visibility_outlined),
                        ),
                ),
              );
            },
          ),
        );
      },
    );
  }

  String _evidenceStatus(Map<String, dynamic> item) {
    final handoverStatus = item['handover_status'] as String?;
    if (handoverStatus == 'completed') return 'Retrieved';
    if (handoverStatus == 'unsuccessful') return 'Handover unsuccessful';
    if (item['admin_decision'] == 'approved') return 'Approved by TMC';
    if (item['admin_decision'] == 'rejected' ||
        item['author_decision'] == 'rejected') {
      return 'Not approved';
    }
    if (item['author_decision'] == 'approved') {
      return 'Approved by post owner · Awaiting TMC review';
    }
    return 'Awaiting post owner review';
  }

  Widget _submissionDirectionSelector({
    required String value,
    required ValueChanged<String> onChanged,
  }) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(12, 4, 12, 0),
      child: Wrap(
        alignment: WrapAlignment.center,
        spacing: 8,
        children: [
          ChoiceChip(
            label: const Text('Inbound · Received'),
            selected: value == 'inbound',
            onSelected: (_) => onChanged('inbound'),
          ),
          ChoiceChip(
            label: const Text('Outbound · Sent'),
            selected: value == 'outbound',
            onSelected: (_) => onChanged('outbound'),
          ),
        ],
      ),
    );
  }

  Future<void> _showEvidenceForPost(
    int postId,
    List<Map<String, dynamic>> submissions,
  ) async {
    var items = List<Map<String, dynamic>>.of(submissions);
    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      builder: (sheetContext) => StatefulBuilder(
        builder: (context, setSheetState) => SafeArea(
          child: SizedBox(
            height: MediaQuery.sizeOf(context).height * 0.8,
            child: Column(
              children: [
                ListTile(
                  title: Text('Handling proofs · ${items.first['item_name']}'),
                  trailing: IconButton(
                    tooltip: 'Close',
                    onPressed: () => Navigator.pop(sheetContext),
                    icon: const Icon(Icons.close),
                  ),
                ),
                Align(
                  alignment: Alignment.centerLeft,
                  child: Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 16),
                    child: OutlinedButton.icon(
                      onPressed: () => _openOriginPost(
                        postId,
                        isOwner: true,
                        returnInboxTab: 4,
                      ),
                      icon: const Icon(Icons.open_in_new),
                      label: const Text('View Origin Post'),
                    ),
                  ),
                ),
                Expanded(
                  child: ListView.separated(
                    padding: const EdgeInsets.all(14),
                    itemCount: items.length,
                    separatorBuilder: (_, _) => const SizedBox(height: 8),
                    itemBuilder: (context, index) {
                      final item = items[index];
                      final id = item['id'] as int;
                      final authorDecision =
                          item['author_decision'] as String? ?? 'pending';
                      final adminDecision =
                          item['admin_decision'] as String? ?? 'pending';
                      final statusLabel =
                          authorDecision == 'rejected' ||
                              adminDecision == 'rejected'
                          ? 'Rejected'
                          : adminDecision == 'approved'
                          ? 'Approved'
                          : authorDecision == 'approved'
                          ? 'Approved by owner · Awaiting TMC review'
                          : 'Awaiting owner review';
                      return Card(
                        child: Padding(
                          padding: const EdgeInsets.all(14),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                'Evidence from ${item['finder_first_name'] ?? 'TMC member'} · $statusLabel',
                              ),
                              if ((item['evidence_text'] as String? ?? '')
                                  .isNotEmpty)
                                Padding(
                                  padding: const EdgeInsets.only(top: 8),
                                  child: Text(item['evidence_text'] as String),
                                ),
                              if (item['author_decision_reason'] is String)
                                Padding(
                                  padding: const EdgeInsets.only(top: 8),
                                  child: Text(
                                    'Owner decision reason: ${item['author_decision_reason']}',
                                  ),
                                ),
                              if (item['evidence_image'] is String)
                                OutlinedButton.icon(
                                  onPressed: () => _viewEvidence(
                                    context,
                                    'Handling proof',
                                    [item['evidence_image'] as String],
                                  ),
                                  icon: const Icon(Icons.visibility_outlined),
                                  label: const Text('View Evidence'),
                                ),
                              if (adminDecision != 'approved' &&
                                  adminDecision != 'rejected') ...[
                                if (item['can_undo_owner_decision'] == true)
                                  OutlinedButton.icon(
                                    onPressed: () async {
                                      final confirmed = await showDialog<bool>(
                                        context: context,
                                        builder: (dialogContext) => AlertDialog(
                                          title: const Text(
                                            'Undo evidence decision?',
                                          ),
                                          content: const Text(
                                            'This will return this evidence and any evidence rejected because of it to owner review.',
                                          ),
                                          actions: [
                                            TextButton(
                                              onPressed: () => Navigator.pop(
                                                dialogContext,
                                                false,
                                              ),
                                              child: const Text('Cancel'),
                                            ),
                                            FilledButton(
                                              onPressed: () => Navigator.pop(
                                                dialogContext,
                                                true,
                                              ),
                                              child: const Text('Undo'),
                                            ),
                                          ],
                                        ),
                                      );
                                      if (confirmed != true ||
                                          !await _undoEvidenceDecision(id)) {
                                        return;
                                      }
                                      final refreshed = await _loadEvidence();
                                      if (!mounted) return;
                                      setState(() {
                                        _evidence = Future.value(refreshed);
                                      });
                                      setSheetState(() {
                                        items = refreshed
                                            .where(
                                              (entry) =>
                                                  entry['post_id'] == postId,
                                            )
                                            .toList();
                                      });
                                    },
                                    icon: const Icon(Icons.undo),
                                    label: const Text('Undo decision'),
                                  )
                                else if (authorDecision == 'pending')
                                  Wrap(
                                    spacing: 8,
                                    children: [
                                      FilledButton.tonal(
                                        onPressed: () async {
                                          if (await _requestEvidenceDecision(
                                            id,
                                            'approve',
                                          )) {
                                            final refreshed =
                                                await _loadEvidence();
                                            if (!mounted) return;
                                            setState(() {
                                              _evidence = Future.value(
                                                refreshed,
                                              );
                                            });
                                            setSheetState(() {
                                              items = refreshed
                                                  .where(
                                                    (entry) =>
                                                        entry['post_id'] ==
                                                        postId,
                                                  )
                                                  .toList();
                                            });
                                          }
                                        },
                                        child: const Text('Approve evidence'),
                                      ),
                                      OutlinedButton(
                                        onPressed: () async {
                                          if (await _requestEvidenceDecision(
                                            id,
                                            'reject',
                                          )) {
                                            final refreshed =
                                                await _loadEvidence();
                                            if (!mounted) return;
                                            setState(() {
                                              _evidence = Future.value(
                                                refreshed,
                                              );
                                            });
                                            setSheetState(() {
                                              items = refreshed
                                                  .where(
                                                    (entry) =>
                                                        entry['post_id'] ==
                                                        postId,
                                                  )
                                                  .toList();
                                            });
                                          }
                                        },
                                        child: const Text('Reject'),
                                      ),
                                    ],
                                  )
                                else if (authorDecision == 'approved') ...[
                                  Text(
                                    item['admin_reason'] as String? ??
                                        'Waiting for TMC administrator review.',
                                  ),
                                  OutlinedButton.icon(
                                    onPressed: () =>
                                        _messageAdminAboutEvidence(item),
                                    icon: const Icon(Icons.support_agent),
                                    label: const Text('Nudge TMC admins'),
                                  ),
                                ] else ...[
                                  const Text(
                                    'This evidence was rejected and is no longer awaiting TMC review.',
                                  ),
                                ],
                              ],
                            ],
                          ),
                        ),
                      );
                    },
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Future<bool> _requestAuthorClaimDecision(int id, String decision) async {
    final reason = await showDialog<String>(
      context: context,
      builder: (_) =>
          _OwnerDecisionDialog(decision: decision, subject: 'claim'),
    );
    if (reason == null || reason.isEmpty) return false;
    return _decideAuthorClaim(id, decision, reason);
  }

  Future<bool> _decideAuthorClaim(
    int id,
    String decision,
    String reason,
  ) async {
    try {
      await widget.api.dio.patch<void>(
        '/claims/$id/post-author-decision',
        data: {'decision': decision, 'reason': reason},
      );
      if (mounted) {
        setState(() {
          _authorClaims = _loadAuthorClaims();
          _handovers = _loadHandovers();
        });
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
              decision == 'approve'
                  ? 'Claim approved. Other pending claims for this post were rejected. TMC administrators will review this claim next.'
                  : 'You declined the claim and sent your reason to the claimant.',
            ),
          ),
        );
      }
      return true;
    } on DioException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(widget.api.errorMessage(error))));
      }
      return false;
    }
  }

  Future<bool> _undoAuthorClaimDecision(int id) async {
    try {
      await widget.api.dio.patch<void>('/claims/$id/post-author-decision/undo');
      if (mounted) {
        setState(() {
          _authorClaims = _loadAuthorClaims();
          _handovers = _loadHandovers();
        });
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text(
              'Claim decision undone. The affected claims are awaiting review again.',
            ),
          ),
        );
      }
      return true;
    } on DioException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(widget.api.errorMessage(error))));
      }
      return false;
    }
  }

  Widget _authorClaimList() {
    return Column(
      children: [
        _submissionDirectionSelector(
          value: _claimDirection,
          onChanged: (direction) => setState(() {
            _claimDirection = direction;
            if (direction == 'inbound') {
              _authorClaims = _loadAuthorClaims();
            } else {
              _outboundClaims = _loadOutboundClaims();
            }
          }),
        ),
        Expanded(
          child: _claimDirection == 'inbound'
              ? _inboundClaimList()
              : _outboundClaimList(),
        ),
      ],
    );
  }

  Widget _inboundClaimList() {
    return FutureBuilder<List<Map<String, dynamic>>>(
      future: _authorClaims,
      builder: (context, snapshot) {
        if (snapshot.connectionState == ConnectionState.waiting) {
          return const Center(child: CircularProgressIndicator());
        }
        if (snapshot.hasError) {
          return _EmptyState(
            icon: Icons.cloud_off,
            title: 'Claim approvals unavailable',
            message: widget.api.errorMessage(snapshot.error!),
            action: FilledButton.tonal(
              onPressed: () => setState(() {
                _authorClaims = _loadAuthorClaims();
              }),
              child: const Text('Try again'),
            ),
          );
        }
        final claims = snapshot.data ?? const [];
        if (claims.isEmpty) {
          return const _EmptyState(
            icon: Icons.fact_check_outlined,
            title: 'No claims received',
            message: 'Claims submitted for your found posts will appear here.',
          );
        }
        final groups = _groupByPost(claims);
        final postIds = groups.keys.toList();
        return RefreshIndicator(
          onRefresh: () async {
            setState(() {
              _authorClaims = _loadAuthorClaims();
            });
          },
          child: ListView.separated(
            padding: const EdgeInsets.all(16),
            itemCount: postIds.length,
            separatorBuilder: (_, _) => const SizedBox(height: 10),
            itemBuilder: (context, index) {
              final postId = postIds[index];
              final postClaims = groups[postId]!;
              final pendingCount = postClaims
                  .where(
                    (claim) =>
                        (claim['author_decision'] as String? ?? 'pending') ==
                            'pending' &&
                        (claim['status'] as String? ?? 'pending') == 'pending',
                  )
                  .length;
              return Card(
                child: Padding(
                  padding: const EdgeInsets.all(14),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        postClaims.first['item_name'] as String? ??
                            'Found item',
                        style: Theme.of(context).textTheme.titleMedium,
                      ),
                      Text(
                        '${postClaims.length} claim(s) · $pendingCount awaiting your decision',
                      ),
                      const SizedBox(height: 10),
                      Wrap(
                        spacing: 8,
                        runSpacing: 8,
                        children: [
                          OutlinedButton.icon(
                            onPressed: () => _openOriginPost(
                              postId,
                              isOwner: true,
                              returnInboxTab: 3,
                            ),
                            icon: const Icon(Icons.open_in_new),
                            label: const Text('View Post'),
                          ),
                          FilledButton.tonalIcon(
                            onPressed: () =>
                                _showClaimsForPost(postId, postClaims),
                            icon: const Icon(Icons.list_alt),
                            label: const Text('List of claims'),
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
              );
            },
          ),
        );
      },
    );
  }

  Widget _outboundClaimList() {
    return FutureBuilder<List<Map<String, dynamic>>>(
      future: _outboundClaims,
      builder: (context, snapshot) {
        if (snapshot.connectionState == ConnectionState.waiting) {
          return const Center(child: CircularProgressIndicator());
        }
        if (snapshot.hasError) {
          return _EmptyState(
            icon: Icons.cloud_off,
            title: 'Sent claims unavailable',
            message: widget.api.errorMessage(snapshot.error!),
            action: FilledButton.tonal(
              onPressed: () => setState(() {
                _outboundClaims = _loadOutboundClaims();
              }),
              child: const Text('Try again'),
            ),
          );
        }
        final claims = snapshot.data ?? const [];
        if (claims.isEmpty) {
          return const _EmptyState(
            icon: Icons.outbox_outlined,
            title: 'No claims sent',
            message: 'Claims you submit for found items will appear here.',
          );
        }
        return RefreshIndicator(
          onRefresh: () async {
            setState(() {
              _outboundClaims = _loadOutboundClaims();
            });
          },
          child: ListView.separated(
            padding: const EdgeInsets.all(14),
            itemCount: claims.length,
            separatorBuilder: (_, _) => const SizedBox(height: 8),
            itemBuilder: (context, index) {
              final claim = claims[index];
              final answers = claim['answers'] is List
                  ? (claim['answers'] as List).whereType<String>().toList()
                  : const <String>[];
              final images = claim['evidence_images'] is List
                  ? (claim['evidence_images'] as List)
                        .whereType<String>()
                        .toList()
                  : const <String>[];
              return Card(
                child: Padding(
                  padding: const EdgeInsets.all(14),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        claim['item_name'] as String? ?? 'Found item',
                        style: Theme.of(context).textTheme.titleMedium,
                      ),
                      Text(_claimStatus(claim)),
                      if (answers.isNotEmpty)
                        Padding(
                          padding: const EdgeInsets.only(top: 6),
                          child: Text('Your answers: ${answers.join(' · ')}'),
                        ),
                      if (claim['author_decision_reason'] is String)
                        Padding(
                          padding: const EdgeInsets.only(top: 6),
                          child: Text(
                            'Post owner: ${claim['author_decision_reason']}',
                          ),
                        ),
                      if (claim['review_reason'] is String)
                        Padding(
                          padding: const EdgeInsets.only(top: 6),
                          child: Text('TMC: ${claim['review_reason']}'),
                        ),
                      Wrap(
                        spacing: 8,
                        children: [
                          if (images.isNotEmpty)
                            OutlinedButton.icon(
                              onPressed: () => _viewEvidence(
                                context,
                                'Claim ownership photos',
                                images,
                              ),
                              icon: const Icon(Icons.photo_library_outlined),
                              label: const Text('View photos'),
                            ),
                          OutlinedButton.icon(
                            onPressed: () {
                              final postId = claim['post_id'] as int?;
                              if (postId != null) {
                                _openOriginPost(
                                  postId,
                                  isOwner: false,
                                  returnInboxTab: 3,
                                );
                              }
                            },
                            icon: const Icon(Icons.open_in_new),
                            label: const Text('View Post'),
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
              );
            },
          ),
        );
      },
    );
  }

  String _claimStatus(Map<String, dynamic> claim) {
    final handoverStatus = claim['handover_status'] as String?;
    if (handoverStatus == 'completed') return 'Retrieved';
    if (handoverStatus == 'unsuccessful') return 'Handover unsuccessful';
    if (claim['status'] == 'rejected') return 'Not approved by TMC';
    if (claim['author_decision'] == 'rejected') {
      return 'Declined by post owner';
    }
    if (claim['status'] == 'approved') return 'Approved by TMC';
    if (claim['author_decision'] == 'approved') {
      return 'Approved by post owner · Awaiting TMC review';
    }
    return 'Awaiting post owner review';
  }

  Future<void> _showClaimsForPost(
    int postId,
    List<Map<String, dynamic>> claims,
  ) async {
    var items = List<Map<String, dynamic>>.of(claims);
    final itemName = claims.first['item_name'] as String? ?? 'Found item';
    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      builder: (sheetContext) => StatefulBuilder(
        builder: (context, setSheetState) => SafeArea(
          child: SizedBox(
            height: MediaQuery.sizeOf(context).height * 0.8,
            child: Column(
              children: [
                ListTile(
                  title: Text('Claims · $itemName'),
                  trailing: IconButton(
                    tooltip: 'Close',
                    onPressed: () => Navigator.pop(sheetContext),
                    icon: const Icon(Icons.close),
                  ),
                ),
                Align(
                  alignment: Alignment.centerLeft,
                  child: Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 16),
                    child: OutlinedButton.icon(
                      onPressed: () => _openOriginPost(
                        postId,
                        isOwner: true,
                        returnInboxTab: 3,
                      ),
                      icon: const Icon(Icons.open_in_new),
                      label: const Text('View Origin Post'),
                    ),
                  ),
                ),
                Expanded(
                  child: items.isEmpty
                      ? const Center(
                          child: Text('No claims are awaiting your decision.'),
                        )
                      : ListView.separated(
                          padding: const EdgeInsets.all(14),
                          itemCount: items.length,
                          separatorBuilder: (_, _) => const SizedBox(height: 8),
                          itemBuilder: (context, index) {
                            final claim = items[index];
                            final claimant = [
                              claim['claimant_first_name'] as String? ?? 'TMC',
                              claim['claimant_last_name'] as String? ??
                                  'member',
                            ].join(' ');
                            final answers = claim['answers'] is List
                                ? (claim['answers'] as List)
                                      .whereType<String>()
                                      .toList()
                                : const <String>[];
                            final questions =
                                claim['verification_questions'] is List
                                ? (claim['verification_questions'] as List)
                                      .whereType<Map<String, dynamic>>()
                                      .toList()
                                : const <Map<String, dynamic>>[];
                            final images = claim['evidence_images'] is List
                                ? (claim['evidence_images'] as List)
                                      .whereType<String>()
                                      .toList()
                                : const <String>[];
                            final claimId = claim['id'] as int;
                            final authorDecision =
                                claim['author_decision'] as String? ??
                                'pending';
                            final claimStatus =
                                claim['status'] as String? ?? 'pending';
                            final canUndo =
                                claim['can_undo_owner_decision'] == true;
                            final statusLabel = claimStatus == 'approved'
                                ? 'Approved'
                                : claimStatus == 'rejected' ||
                                      authorDecision == 'rejected'
                                ? 'Rejected'
                                : authorDecision == 'approved'
                                ? 'Approved by owner · Awaiting TMC review'
                                : 'Awaiting owner review';
                            return Card(
                              child: Padding(
                                padding: const EdgeInsets.all(14),
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Text(
                                      'Claim by $claimant · Attempt ${claim['attempt_no'] ?? 1} · $statusLabel',
                                      style: const TextStyle(
                                        fontWeight: FontWeight.w700,
                                      ),
                                    ),
                                    if (images.isNotEmpty)
                                      OutlinedButton.icon(
                                        onPressed: () => _viewEvidence(
                                          context,
                                          'Claimant ownership photos',
                                          images,
                                        ),
                                        icon: const Icon(
                                          Icons.visibility_outlined,
                                        ),
                                        label: Text(
                                          'View Evidence (${images.length})',
                                        ),
                                      ),
                                    for (
                                      var answerIndex = 0;
                                      answerIndex < questions.length;
                                      answerIndex++
                                    ) ...[
                                      Text(
                                        'Verification question: ${questions[answerIndex]['question'] ?? ''}',
                                        style: const TextStyle(
                                          fontWeight: FontWeight.w700,
                                        ),
                                      ),
                                      Text(
                                        'Claimant answered: ${answerIndex < answers.length ? answers[answerIndex] : 'No answer'}',
                                      ),
                                      Text(
                                        'Your recorded answer: ${questions[answerIndex]['answer'] ?? ''}',
                                      ),
                                      const SizedBox(height: 8),
                                    ],
                                    if (authorDecision == 'pending' &&
                                        claimStatus == 'pending') ...[
                                      const Text(
                                        'Your decision and reason will be sent to the claimant. If approved, TMC administrators review the claim next.',
                                      ),
                                      const SizedBox(height: 10),
                                      Wrap(
                                        spacing: 8,
                                        children: [
                                          FilledButton.tonal(
                                            onPressed: () async {
                                              if (await _requestAuthorClaimDecision(
                                                claimId,
                                                'approve',
                                              )) {
                                                final refreshed =
                                                    await _loadAuthorClaims();
                                                if (!mounted) return;
                                                setState(() {
                                                  _authorClaims = Future.value(
                                                    refreshed,
                                                  );
                                                });
                                                setSheetState(() {
                                                  items = refreshed
                                                      .where(
                                                        (entry) =>
                                                            entry['post_id'] ==
                                                            postId,
                                                      )
                                                      .toList();
                                                });
                                              }
                                            },
                                            child: const Text('Approve claim'),
                                          ),
                                          OutlinedButton(
                                            onPressed: () async {
                                              if (await _requestAuthorClaimDecision(
                                                claimId,
                                                'reject',
                                              )) {
                                                final refreshed =
                                                    await _loadAuthorClaims();
                                                if (!mounted) return;
                                                setState(() {
                                                  _authorClaims = Future.value(
                                                    refreshed,
                                                  );
                                                });
                                                setSheetState(() {
                                                  items = refreshed
                                                      .where(
                                                        (entry) =>
                                                            entry['post_id'] ==
                                                            postId,
                                                      )
                                                      .toList();
                                                });
                                              }
                                            },
                                            child: const Text('Decline'),
                                          ),
                                        ],
                                      ),
                                    ] else ...[
                                      if (canUndo)
                                        OutlinedButton.icon(
                                          onPressed: () async {
                                            final confirmed =
                                                await showDialog<bool>(
                                                  context: context,
                                                  builder: (dialogContext) =>
                                                      AlertDialog(
                                                        title: const Text(
                                                          'Undo claim decision?',
                                                        ),
                                                        content: const Text(
                                                          'This will return this claim and any claims rejected because of it to owner review.',
                                                        ),
                                                        actions: [
                                                          TextButton(
                                                            onPressed: () =>
                                                                Navigator.pop(
                                                                  dialogContext,
                                                                  false,
                                                                ),
                                                            child: const Text(
                                                              'Cancel',
                                                            ),
                                                          ),
                                                          FilledButton(
                                                            onPressed: () =>
                                                                Navigator.pop(
                                                                  dialogContext,
                                                                  true,
                                                                ),
                                                            child: const Text(
                                                              'Undo',
                                                            ),
                                                          ),
                                                        ],
                                                      ),
                                                );
                                            if (confirmed != true ||
                                                !await _undoAuthorClaimDecision(
                                                  claimId,
                                                )) {
                                              return;
                                            }
                                            final refreshed =
                                                await _loadAuthorClaims();
                                            if (!mounted) return;
                                            setState(() {
                                              _authorClaims = Future.value(
                                                refreshed,
                                              );
                                            });
                                            setSheetState(() {
                                              items = refreshed
                                                  .where(
                                                    (entry) =>
                                                        entry['post_id'] ==
                                                        postId,
                                                  )
                                                  .toList();
                                            });
                                          },
                                          icon: const Icon(Icons.undo),
                                          label: const Text('Undo decision'),
                                        ),
                                      if (authorDecision == 'approved' &&
                                          claimStatus == 'pending') ...[
                                        const Padding(
                                          padding: EdgeInsets.only(top: 8),
                                          child: Text(
                                            'Waiting for TMC administrators to approve the handover.',
                                          ),
                                        ),
                                        OutlinedButton.icon(
                                          onPressed: () =>
                                              _messageAdminAboutClaim(claim),
                                          icon: const Icon(Icons.support_agent),
                                          label: const Text('Nudge TMC admins'),
                                        ),
                                      ],
                                      if (claimStatus == 'rejected' &&
                                          (claim['review_reason'] as String?)
                                                  ?.isNotEmpty ==
                                              true)
                                        Padding(
                                          padding: const EdgeInsets.only(
                                            top: 8,
                                          ),
                                          child: Text(
                                            'TMC decision: ${claim['review_reason']}',
                                          ),
                                        ),
                                    ],
                                  ],
                                ),
                              ),
                            );
                          },
                        ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Future<void> _openHandover(Map<String, dynamic> handover) async {
    if (handover['history_kind'] == 'retrieval_request') {
      await showDialog<void>(
        context: context,
        builder: (dialogContext) => AlertDialog(
          title: Text(handover['item_name'] as String? ?? 'Campus item'),
          content: Text(
            'TMC administrators approved this retrieval request'
            '${handover['history_at'] is String ? ' on ${handover['history_at']}' : ''}.',
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(dialogContext),
              child: const Text('Close'),
            ),
          ],
        ),
      );
      return;
    }
    final completed = handover['completed'] == true;
    final confirmed = handover['my_confirmation_at'] != null;
    final status = handover['status'] as String? ?? 'awaiting_admin';
    final canApprove = _canApproveHandover(handover);
    final rescheduleAttempts =
        int.tryParse('${handover['reschedule_attempts'] ?? 0}') ?? 0;
    final rescheduleLimit =
        int.tryParse('${handover['reschedule_limit'] ?? 2}') ?? 2;
    final canRequestReschedule =
        status == 'scheduled' && rescheduleAttempts < rescheduleLimit;
    final receiptCode = handover['receipt_code'] as String?;
    final requestReschedule = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        scrollable: true,
        title: Text(handover['item_name'] as String? ?? 'Item handover'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              'Other participant: ${handover['other_first_name'] ?? 'TMC member'}',
            ),
            const SizedBox(height: 12),
            Text(
              'Item owner: ${handover['owner_confirmed_at'] != null ? 'agreed' : 'waiting'}',
            ),
            Text(
              'Other participant: ${handover['finder_confirmed_at'] != null ? 'agreed' : 'waiting'}',
            ),
            const SizedBox(height: 12),
            Text(
              completed
                  ? 'TMC confirmed that the owner retrieved the item.'
                  : switch (status) {
                      'awaiting_admin' || 'awaiting_participants' =>
                        confirmed
                            ? 'Your approval is recorded. Waiting for the other participant.'
                            : 'Approve this handover to let the admin set the schedule and place.',
                      'awaiting_schedule' => 'Both participants approved. The admin will set the schedule and place.',
                      'reschedule_requested' =>
                        'A reschedule request is waiting for TMC review.',
                      'unsuccessful' => 'This transaction was unsuccessful. The original post is active again.',
                      'scheduled' => 'Your handover is scheduled. Bring this receipt to the listed place.',
                      _ =>
                        confirmed
                            ? 'Your approval is recorded. Waiting for the other participant.'
                            : canApprove
                            ? 'Approve this handover to let the admin set the schedule and place.'
                            : 'Waiting for the handover status to update.',
                    },
            ),
            if (handover['scheduled_at'] is String) ...[
              const SizedBox(height: 8),
              Text('Date: ${handover['scheduled_at']}'),
              Text('Place: ${handover['location'] ?? 'TMC campus'}'),
            ],
            if ((handover['failure_reason'] as String? ?? '').isNotEmpty) ...[
              const SizedBox(height: 8),
              Text('Outcome: ${handover['failure_reason']}'),
            ],
            if (receiptCode != null) ...[
              const SizedBox(height: 12),
              const Text('Handover receipt'),
              Text(
                'Your ${handover['receipt_code_role'] ?? 'participant'} verification code',
              ),
              SelectableText(
                receiptCode,
                style: Theme.of(context).textTheme.titleLarge,
              ),
            ],
            if (status == 'scheduled' || status == 'reschedule_requested') ...[
              const SizedBox(height: 8),
              Text(
                'Reschedule requests: $rescheduleAttempts / $rescheduleLimit',
              ),
              if (status == 'scheduled' && !canRequestReschedule)
                const Text('You have used all reschedule attempts.'),
              if (handover['reschedule_reason'] is String)
                Text('Request: ${handover['reschedule_reason']}'),
            ],
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(dialogContext),
            child: const Text('Close'),
          ),
          if (canApprove)
            FilledButton.icon(
              onPressed: () =>
                  _approveHandover(handover, dialogContext: dialogContext),
              icon: const Icon(Icons.check_circle_outline),
              label: const Text('Agree to handover'),
            ),
          if (canRequestReschedule)
            OutlinedButton(
              onPressed: () => Navigator.pop(dialogContext, true),
              child: const Text('Request reschedule'),
            ),
          if (receiptCode != null)
            TextButton.icon(
              onPressed: () => _printHandoverReceipt(handover, receiptCode),
              icon: const Icon(Icons.print_outlined),
              label: const Text('Print receipt'),
            ),
          if (receiptCode != null)
            TextButton.icon(
              onPressed: () => SharePlus.instance.share(
                ShareParams(
                  subject: 'CampusLF handover receipt',
                  text:
                      'TMC CampusLF handover receipt\n'
                      'Transaction: ${handover['transaction_reference']}\n'
                      '${handover['item_name']}\n'
                      'Owner: ${handover['owner_name']}\n'
                      'Other participant: ${handover['other_participant_name']}\n'
                      'Date: ${handover['scheduled_at']}\n'
                      'Place: ${handover['location'] ?? 'TMC campus'}\n'
                      'Your ${handover['receipt_code_role'] ?? 'participant'} verification code: $receiptCode',
                ),
              ),
              icon: const Icon(Icons.ios_share),
              label: const Text('Share receipt'),
            ),
        ],
      ),
    );
    if (requestReschedule == true && mounted) {
      await _requestHandoverReschedule(handover);
    }
  }

  bool _canApproveHandover(Map<String, dynamic> handover) {
    final status = handover['status'] as String? ?? 'awaiting_admin';
    return handover['my_confirmation_at'] == null &&
        handover['scheduled_at'] == null &&
        handover['completed'] != true &&
        !const {
          'scheduled',
          'completed',
          'unsuccessful',
          'reschedule_requested',
        }.contains(status);
  }

  Future<void> _approveHandover(
    Map<String, dynamic> handover, {
    BuildContext? dialogContext,
  }) async {
    try {
      final response = await widget.api.dio.post<Map<String, dynamic>>(
        '/handovers/${handover['id']}/confirm',
      );
      if (dialogContext != null && dialogContext.mounted) {
        Navigator.pop(dialogContext);
      }
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            response.data?['message'] as String? ??
                'Your handover approval was recorded.',
          ),
        ),
      );
      setState(() {
        _handovers = _loadHandovers();
      });
    } on DioException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(widget.api.errorMessage(error))));
      }
    }
  }

  Future<void> _printHandoverReceipt(
    Map<String, dynamic> handover,
    String receiptCode,
  ) async {
    final document = pw.Document();
    document.addPage(
      pw.Page(
        build: (context) => pw.Center(
          child: pw.Column(
            mainAxisSize: pw.MainAxisSize.min,
            crossAxisAlignment: pw.CrossAxisAlignment.center,
            children: [
              pw.Text(
                'TMC CAMPUSLF - HANDOVER RECEIPT',
                style: pw.TextStyle(
                  fontSize: 18,
                  fontWeight: pw.FontWeight.bold,
                ),
              ),
              pw.SizedBox(height: 24),
              pw.Text(
                'Transaction: ${handover['transaction_reference'] ?? 'HL-${handover['id']}'}',
              ),
              pw.Text(
                'Type: ${(handover['type'] as String? ?? 'item').toUpperCase()} item',
              ),
              pw.Text(
                handover['item_name'] as String? ?? 'Campus item',
                style: pw.TextStyle(
                  fontSize: 22,
                  fontWeight: pw.FontWeight.bold,
                ),
              ),
              pw.SizedBox(height: 8),
              pw.Text('Owner: ${handover['owner_name'] ?? 'Campus member'}'),
              pw.Text(
                'Other participant: ${handover['other_participant_name'] ?? handover['other_first_name'] ?? 'Campus member'}',
              ),
              pw.SizedBox(height: 12),
              pw.Text('Date: ${handover['scheduled_at'] ?? 'Not available'}'),
              pw.Text('Place: ${handover['location'] ?? 'TMC campus'}'),
              pw.SizedBox(height: 24),
              pw.Text(
                'YOUR ${((handover['receipt_code_role'] as String?) ?? 'PARTICIPANT').toUpperCase()} VERIFICATION CODE',
              ),
              pw.SizedBox(height: 8),
              pw.Text(
                receiptCode,
                style: pw.TextStyle(
                  fontSize: 26,
                  fontWeight: pw.FontWeight.bold,
                  letterSpacing: 4,
                ),
              ),
              pw.SizedBox(height: 24),
              pw.Text(
                'Bring your code to the scheduled location. TMC staff must verify both participants’ codes to confirm retrieval.',
                textAlign: pw.TextAlign.center,
              ),
            ],
          ),
        ),
      ),
    );
    try {
      await Printing.layoutPdf(
        onLayout: (_) => document.save(),
        name: 'CampusLF handover receipt',
      );
    } on PlatformException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(error.message ?? 'Could not open the print dialog.'),
          ),
        );
      }
    }
  }

  Future<void> _requestHandoverReschedule(Map<String, dynamic> handover) async {
    final request = await showDialog<Map<String, String>>(
      context: context,
      builder: (_) => const _HandoverRescheduleDialog(),
    );
    if (request == null) return;

    try {
      final response = await widget.api.dio.post<Map<String, dynamic>>(
        '/handovers/${handover['id']}/reschedule',
        data: request,
      );
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            response.data?['message'] as String? ??
                'Your request was sent to TMC.',
          ),
        ),
      );
      setState(() {
        _handovers = _loadHandovers();
      });
    } on DioException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(widget.api.errorMessage(error))));
      }
    }
  }

  Widget _handoverList() {
    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(12, 4, 12, 0),
          child: Wrap(
            alignment: WrapAlignment.center,
            spacing: 8,
            children: [
              ChoiceChip(
                label: const Text('Active'),
                selected: _handoverView == 'active',
                onSelected: (_) => setState(() {
                  _handoverView = 'active';
                  _handovers = _loadHandovers();
                }),
              ),
              ChoiceChip(
                label: const Text('History'),
                selected: _handoverView == 'history',
                onSelected: (_) => setState(() {
                  _handoverView = 'history';
                  _handovers = _loadHandovers();
                }),
              ),
            ],
          ),
        ),
        Expanded(child: _handoverItems()),
      ],
    );
  }

  Widget _handoverItems() {
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
              onPressed: () => setState(() {
                _handovers = _loadHandovers();
              }),
              child: const Text('Try again'),
            ),
          );
        }
        final allHandovers = snapshot.data ?? const [];
        final handovers = allHandovers.where((handover) {
          final status = handover['status'] as String? ?? '';
          final isHistory = status == 'completed' || status == 'unsuccessful';
          return _handoverView == 'history' ? isHistory : !isHistory;
        }).toList();
        if (handovers.isEmpty) {
          return _EmptyState(
            icon: _handoverView == 'history'
                ? Icons.history
                : Icons.handshake_outlined,
            title: _handoverView == 'history'
                ? 'No handover history yet'
                : 'No active handovers',
            message: _handoverView == 'history'
                ? 'Completed retrievals and unsuccessful transactions will appear here.'
                : 'Claims and lost-item evidence appear here after the required approvals.',
          );
        }
        return RefreshIndicator(
          onRefresh: () async {
            setState(() {
              _handovers = _loadHandovers();
            });
          },
          child: ListView.builder(
            padding: const EdgeInsets.all(16),
            itemCount: handovers.length,
            itemBuilder: (context, index) {
              final handover = handovers[index];
              final ownerConfirmed = handover['owner_confirmed_at'] != null;
              final finderConfirmed = handover['finder_confirmed_at'] != null;
              final completed = handover['completed'] == true;
              final status = handover['status'] as String? ?? 'awaiting_admin';
              final canApprove = _canApproveHandover(handover);
              final statusLabel = switch (status) {
                'awaiting_admin' => 'Awaiting participant agreements',
                'awaiting_participants' => 'Awaiting participant agreements',
                'awaiting_schedule' => 'Both participants agreed',
                'reschedule_requested' => 'Reschedule requested',
                'scheduled' => 'Scheduled',
                'completed' => 'Completed',
                'unsuccessful' => 'Unsuccessful',
                _ =>
                  canApprove
                      ? 'Awaiting participant agreements'
                      : status.replaceAll('_', ' '),
              };
              final itemType = (handover['type'] as String? ?? 'item')
                  .toUpperCase();
              final participantSummary =
                  handover['history_kind'] == 'retrieval_request'
                  ? 'TMC-approved retrieval request'
                  : 'Owner: ${ownerConfirmed ? 'approved' : 'waiting'} · Other participant: ${finderConfirmed ? 'approved' : 'waiting'}';
              final historyDate =
                  handover['history_at'] ??
                  handover['completed_at'] ??
                  handover['unsuccessful_at'];
              final statusDate = handover['scheduled_at'] is String
                  ? handover['scheduled_at'] as String
                  : _handoverView == 'history' && historyDate is String
                  ? historyDate
                  : null;
              return Card(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    ListTile(
                      leading: CircleAvatar(
                        child: Icon(
                          completed
                              ? Icons.verified_outlined
                              : Icons.handshake_outlined,
                          color: completed
                              ? Theme.of(context).colorScheme.primary
                              : null,
                        ),
                      ),
                      title: Text(
                        handover['item_name'] as String? ?? 'TMC item',
                      ),
                      subtitle: Text(
                        '$itemType item · With ${handover['other_first_name'] ?? 'TMC member'}'
                        '\n$participantSummary'
                        '\n$statusLabel${statusDate == null ? '' : ' · $statusDate'}',
                      ),
                      isThreeLine: true,
                      trailing: const Icon(Icons.chevron_right),
                      onTap: () => _openHandover(handover),
                    ),
                    if (canApprove)
                      Align(
                        alignment: AlignmentDirectional.centerEnd,
                        child: Padding(
                          padding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
                          child: FilledButton.icon(
                            onPressed: () => _approveHandover(handover),
                            icon: const Icon(Icons.check_circle_outline),
                            label: const Text('Approve handover'),
                          ),
                        ),
                      ),
                  ],
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
    required this.onOpenProfile,
    super.key,
  });

  final ApiClient api;
  final Map<String, dynamic> conversation;
  final Future<void> Function(int) onOpenProfile;

  @override
  State<ConversationScreen> createState() => _ConversationScreenState();
}

class _ConversationScreenState extends State<ConversationScreen> {
  final _message = TextEditingController();
  late Map<String, dynamic> _conversation;
  late Future<List<Map<String, dynamic>>> _messages;
  Timer? _polling;
  bool _sending = false;
  bool _actingOnRequest = false;

  int get _conversationId => _conversation['id'] as int;

  @override
  void initState() {
    super.initState();
    _conversation = Map<String, dynamic>.from(widget.conversation);
    _messages = _loadMessages();
    _polling = Timer.periodic(const Duration(seconds: 5), (_) {
      if (mounted) {
        setState(() {
          _messages = _loadMessages();
        });
      }
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

  Future<void> _acceptRequest() async {
    if (_actingOnRequest) return;
    setState(() => _actingOnRequest = true);
    try {
      final response = await widget.api.dio.post<Map<String, dynamic>>(
        '/conversations/$_conversationId/accept',
      );
      if (mounted && response.data != null) {
        setState(() {
          _conversation = Map<String, dynamic>.from(response.data!);
          _messages = _loadMessages();
        });
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Message request accepted.')),
        );
      }
    } on DioException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(widget.api.errorMessage(error))));
      }
    } finally {
      if (mounted) setState(() => _actingOnRequest = false);
    }
  }

  Future<void> _declineRequest() async {
    if (_actingOnRequest) return;
    setState(() => _actingOnRequest = true);
    try {
      await widget.api.dio.post<void>(
        '/conversations/$_conversationId/decline',
      );
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Message request declined.')),
        );
        Navigator.of(context).pop();
      }
    } on DioException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(widget.api.errorMessage(error))));
      }
    } finally {
      if (mounted) setState(() => _actingOnRequest = false);
    }
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
      if (!mounted) return;
      _message.clear();
      setState(() {
        _messages = _loadMessages();
      });
    } on DioException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(widget.api.errorMessage(error))));
      }
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final isRequest = _conversation['is_request'] as bool? ?? false;
    final isPendingSender =
        _conversation['is_pending_sender'] as bool? ?? false;
    final itemName = _conversation['item_name'] as String? ?? 'TMC item';
    final otherUserId = _conversation['other_user_id'] as int?;
    final otherFirstName =
        _conversation['other_first_name'] as String? ?? 'TMC';
    final otherLastName = _conversation['other_last_name'] as String? ?? '';
    final otherPicture = _conversation['other_profile_picture_url'] as String?;
    return Scaffold(
      appBar: AppBar(
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            Text(itemName, style: const TextStyle(fontSize: 15)),
            InkWell(
              onTap: otherUserId == null
                  ? null
                  : () => widget.onOpenProfile(otherUserId),
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  CircleAvatar(
                    radius: 11,
                    backgroundColor: Theme.of(context)
                        .colorScheme
                        .surfaceContainerHighest,
                    backgroundImage: otherPicture == null
                        ? null
                        : NetworkImage(otherPicture),
                    child: otherPicture == null
                        ? Text(
                            otherFirstName.isEmpty
                                ? '?'
                                : otherFirstName[0].toUpperCase(),
                            style: TextStyle(
                              color: Theme.of(context).colorScheme.primary,
                              fontSize: 10,
                            ),
                          )
                        : null,
                  ),
                  const SizedBox(width: 5),
                  Text(
                    '$otherFirstName $otherLastName'.trim(),
                    style: TextStyle(
                      fontSize: 12,
                      color: Theme.of(context).colorScheme.primary,
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
      body: Column(
        children: [
          if (isRequest)
            MaterialBanner(
              content: Text(
                '$otherFirstName sent you a message request. Accept to start chatting.',
              ),
              leading: const Icon(Icons.mark_email_unread_outlined),
              actions: [
                TextButton(
                  onPressed: _actingOnRequest ? null : _declineRequest,
                  child: const Text('Decline'),
                ),
                FilledButton(
                  onPressed: _actingOnRequest ? null : _acceptRequest,
                  child: const Text('Accept'),
                ),
              ],
            )
          else if (isPendingSender)
            MaterialBanner(
              content: Text(
                'Message request sent. Waiting for $otherFirstName to accept.',
              ),
              leading: const Icon(Icons.hourglass_empty),
              actions: const [SizedBox.shrink()],
            )
          else
            const MaterialBanner(
              content: Text(
                'Only proceed with the item exchange after all required approvals. Confirm it here after the handover is complete.',
              ),
              leading: Icon(Icons.shield_outlined),
              actions: [SizedBox.shrink()],
            ),
          Expanded(
            child: FutureBuilder<List<Map<String, dynamic>>>(
              future: _messages,
              builder: (context, snapshot) {
                if (snapshot.connectionState == ConnectionState.waiting) {
                  return const Center(child: CircularProgressIndicator());
                }
                if (snapshot.hasError) {
                  return Center(
                    child: Text(widget.api.errorMessage(snapshot.error!)),
                  );
                }
                final messages = snapshot.data ?? const [];
                if (messages.isEmpty) {
                  return Center(
                    child: Text(
                      isRequest
                          ? '$otherFirstName sent you a message request.'
                          : 'Start a safe, direct conversation.',
                    ),
                  );
                }
                return ListView.builder(
                  reverse: true,
                  padding: const EdgeInsets.all(16),
                  itemCount: messages.length,
                  itemBuilder: (context, index) {
                    final message = messages[messages.length - index - 1];
                    final senderId = message['sender_id'] as int?;
                    final senderRole = message['sender_role'] as String?;
                    final senderProfileId = senderRole == 'admin'
                        ? null
                        : senderId;
                    final isOtherSender = _conversation['type'] == 'admin'
                        ? senderRole == 'admin'
                        : senderId == otherUserId;
                    final senderFirstName =
                        message['sender_first_name'] as String? ?? 'TMC';
                    final senderPicture =
                        message['sender_profile_picture_url'] as String?;
                    final profileAvatar = InkWell(
                      onTap: senderProfileId == null
                          ? null
                          : () => widget.onOpenProfile(senderProfileId),
                      customBorder: const CircleBorder(),
                      child: CircleAvatar(
                        radius: 18,
                        backgroundColor: Theme.of(context)
                            .colorScheme
                            .surfaceContainerHighest,
                        backgroundImage: senderPicture == null
                            ? null
                            : NetworkImage(senderPicture),
                        child: senderPicture == null
                            ? Text(
                                senderFirstName.isEmpty
                                    ? '?'
                                    : senderFirstName[0].toUpperCase(),
                                style: TextStyle(
                                  color: Theme.of(context).colorScheme.primary,
                                ),
                              )
                            : null,
                      ),
                    );
                    final messageCard = Card(
                      child: Padding(
                        padding: const EdgeInsets.all(12),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            InkWell(
                              onTap: senderProfileId == null
                                  ? null
                                  : () => widget.onOpenProfile(senderProfileId),
                              child: Text(
                                '$senderFirstName ${message['sender_last_name'] as String? ?? ''}'
                                    .trim(),
                                style: TextStyle(
                                  fontWeight: FontWeight.w700,
                                  color: Theme.of(context).colorScheme.primary,
                                ),
                              ),
                            ),
                            Text(message['body'] as String? ?? ''),
                          ],
                        ),
                      ),
                    );
                    return Padding(
                      padding: const EdgeInsets.symmetric(vertical: 5),
                      child: Row(
                        mainAxisAlignment: isOtherSender
                            ? MainAxisAlignment.start
                            : MainAxisAlignment.end,
                        crossAxisAlignment: CrossAxisAlignment.end,
                        children: isOtherSender
                            ? [
                                profileAvatar,
                                const SizedBox(width: 6),
                                Flexible(child: messageCard),
                              ]
                            : [
                                Flexible(child: messageCard),
                                const SizedBox(width: 6),
                                profileAvatar,
                              ],
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
              child: isRequest
                  ? Row(
                      children: [
                        Expanded(
                          child: OutlinedButton(
                            onPressed: _actingOnRequest
                                ? null
                                : _declineRequest,
                            child: const Text('Decline Request'),
                          ),
                        ),
                        const SizedBox(width: 12),
                        Expanded(
                          child: FilledButton(
                            onPressed: _actingOnRequest ? null : _acceptRequest,
                            child: const Text('Accept Request'),
                          ),
                        ),
                      ],
                    )
                  : Row(
                      children: [
                        Expanded(
                          child: TextField(
                            controller: _message,
                            maxLength: 2000,
                            decoration: const InputDecoration(
                              labelText: 'Message',
                            ),
                          ),
                        ),
                        IconButton(
                          tooltip: 'Send message',
                          onPressed: _sending ? null : _send,
                          icon: Icon(
                            Icons.send,
                            color: Theme.of(context).colorScheme.primary,
                          ),
                        ),
                      ],
                    ),
            ),
          ),
        ],
      ),
    );
  }
}

class PublicProfileScreen extends StatefulWidget {
  const PublicProfileScreen({
    required this.userId,
    required this.api,
    required this.onOpenProfile,
    super.key,
  });

  final int userId;
  final ApiClient api;
  final Future<void> Function(int) onOpenProfile;

  @override
  State<PublicProfileScreen> createState() => _PublicProfileScreenState();
}

class _PublicProfileScreenState extends State<PublicProfileScreen> {
  Map<String, dynamic>? _profile;
  late Future<List<CampusPost>> _posts;
  final Map<int, _ReactionState> _reactionOverrides = {};
  bool _loadingProfile = true;
  String? _profileError;

  @override
  void initState() {
    super.initState();
    _posts = _loadPosts();
    _loadProfile();
  }

  Future<void> _loadProfile() async {
    setState(() {
      _loadingProfile = true;
      _profileError = null;
    });
    try {
      final response = await widget.api.dio.get<Map<String, dynamic>>(
        '/people/${widget.userId}',
      );
      if (mounted) {
        setState(() {
          _profile = response.data;
          _loadingProfile = false;
        });
      }
    } on DioException catch (error) {
      if (mounted) {
        setState(() {
          _profileError = widget.api.errorMessage(error);
          _loadingProfile = false;
        });
      }
    }
  }

  Future<List<CampusPost>> _loadPosts() async {
    final response = await widget.api.dio.get<Map<String, dynamic>>(
      '/people/${widget.userId}/posts',
    );
    final data = response.data?['data'];
    if (data is! List) {
      throw const FormatException('The user posts response is invalid.');
    }
    return data
        .whereType<Map<String, dynamic>>()
        .map(CampusPost.fromJson)
        .toList();
  }

  Future<void> _openMessage() async {
    try {
      final response = await widget.api.dio.post<Map<String, dynamic>>(
        '/conversations/direct',
        data: {'recipient_id': widget.userId},
      );
      final conversation = response.data;
      if (!mounted || conversation == null) return;
      await Navigator.of(context).push(
        MaterialPageRoute<void>(
          builder: (_) => ConversationScreen(
            api: widget.api,
            conversation: conversation,
            onOpenProfile: widget.onOpenProfile,
          ),
        ),
      );
    } on DioException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(widget.api.errorMessage(error))));
      }
    }
  }

  Future<void> _showPost(CampusPost post) => showModalBottomSheet<void>(
    context: context,
    isScrollControlled: true,
    builder: (_) => _PostDetailSheet(
      api: widget.api,
      post: post,
      onOpenProfile: widget.onOpenProfile,
      onClaimChanged: () {
        if (mounted) {
          setState(() {
            _posts = _loadPosts();
          });
        }
      },
      onReactionChanged: (reaction, count) {
        if (!mounted) return;
        setState(() {
          _reactionOverrides[post.id] = _ReactionState(count, reaction);
        });
      },
    ),
  );

  Future<void> _react(CampusPost post) async {
    final current = _applyReaction(post, _reactionOverrides);
    final wasLiked = current.myReaction == 'like';
    final optimisticCount = current.reactionsCount + (wasLiked ? -1 : 1);
    setState(() {
      _reactionOverrides[post.id] = _ReactionState(
        optimisticCount < 0 ? 0 : optimisticCount,
        wasLiked ? null : 'like',
      );
    });
    try {
      final response = await widget.api.dio.post<Map<String, dynamic>>(
        '/posts/${post.id}/reactions',
        data: {'type': wasLiked ? 'none' : 'like'},
      );
      if (!mounted) return;
      setState(() {
        _reactionOverrides[post.id] = _ReactionState(
          response.data?['reactions_count'] as int? ?? optimisticCount,
          response.data?['reaction'] as String?,
        );
      });
    } on DioException catch (error) {
      if (!mounted) return;
      setState(() {
        _reactionOverrides[post.id] = _ReactionState(
          current.reactionsCount,
          current.myReaction,
        );
      });
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(widget.api.errorMessage(error))));
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_loadingProfile) {
      return const Scaffold(body: Center(child: CircularProgressIndicator()));
    }
    if (_profileError != null) {
      return Scaffold(
        appBar: AppBar(title: const Text('Profile')),
        body: _EmptyState(
          icon: Icons.cloud_off_outlined,
          title: 'Could not load profile',
          message: _profileError!,
          action: FilledButton.tonal(
            onPressed: _loadProfile,
            child: const Text('Try again'),
          ),
        ),
      );
    }
    final profile = _profile ?? const <String, dynamic>{};
    final firstName = profile['first_name'] as String? ?? 'TMC';
    final lastName = profile['last_name'] as String? ?? '';
    final pictureUrl = profile['profile_picture_url'] as String?;
    final details = <(String, String?)>[
      ('Status', profile['profile_status'] as String?),
      ('Department', profile['department'] as String?),
      ('Current address', profile['current_address'] as String?),
      ('Permanent address', profile['permanent_address'] as String?),
      ('Mobile number', profile['contact_number'] as String?),
      ('Contact email', profile['contact_email'] as String?),
    ];
    return Scaffold(
      appBar: AppBar(title: const Text('Profile')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Card(
            child: Padding(
              padding: const EdgeInsets.all(20),
              child: Column(
                children: [
                  CircleAvatar(
                    radius: 48,
                    backgroundColor: Theme.of(context)
                        .colorScheme
                        .surfaceContainerHighest,
                    backgroundImage: pictureUrl == null
                        ? null
                        : NetworkImage(pictureUrl),
                    child: pictureUrl == null
                        ? Text(
                            firstName.isEmpty
                                ? '?'
                                : firstName[0].toUpperCase(),
                            style: TextStyle(
                              fontSize: 34,
                              color: Theme.of(context).colorScheme.primary,
                            ),
                          )
                        : null,
                  ),
                  const SizedBox(height: 12),
                  Text(
                    '$firstName $lastName'.trim(),
                    textAlign: TextAlign.center,
                    style: Theme.of(context).textTheme.titleLarge,
                  ),
                  if ((profile['bio'] as String?)?.trim().isNotEmpty ==
                      true) ...[
                    const SizedBox(height: 8),
                    Text(profile['bio'] as String, textAlign: TextAlign.center),
                  ],
                  const SizedBox(height: 12),
                  FilledButton.icon(
                    onPressed: _openMessage,
                    icon: const Icon(Icons.message_outlined),
                    label: const Text('Message'),
                  ),
                ],
              ),
            ),
          ),
          if (details.any((detail) => detail.$2?.trim().isNotEmpty == true))
            Card(
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'Public details',
                      style: Theme.of(context).textTheme.titleMedium,
                    ),
                    const SizedBox(height: 12),
                    for (final (label, value) in details)
                      if (value?.trim().isNotEmpty == true)
                        Padding(
                          padding: const EdgeInsets.only(bottom: 12),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                label,
                                style: TextStyle(
                                  fontSize: 12,
                                  color: Theme.of(context)
                                      .colorScheme
                                      .onSurfaceVariant,
                                ),
                              ),
                              Text(value!),
                            ],
                          ),
                        ),
                  ],
                ),
              ),
            ),
          const SizedBox(height: 12),
          Text(
            'Posts by $firstName',
            style: Theme.of(context).textTheme.titleLarge,
          ),
          const SizedBox(height: 8),
          FutureBuilder<List<CampusPost>>(
            future: _posts,
            builder: (context, snapshot) {
              if (snapshot.connectionState == ConnectionState.waiting) {
                return const Padding(
                  padding: EdgeInsets.all(24),
                  child: Center(child: CircularProgressIndicator()),
                );
              }
              if (snapshot.hasError) {
                return _EmptyState(
                  icon: Icons.cloud_off_outlined,
                  title: 'Could not load posts',
                  message: widget.api.errorMessage(snapshot.error!),
                  action: FilledButton.tonal(
                    onPressed: () => setState(() {
                      _posts = _loadPosts();
                    }),
                    child: const Text('Try again'),
                  ),
                );
              }
              final posts = snapshot.data ?? const [];
              if (posts.isEmpty) {
                return const _EmptyState(
                  icon: Icons.inventory_2_outlined,
                  title: 'No active posts',
                  message: 'This member has no visible community posts.',
                );
              }
              final displayPosts = [
                for (final post in posts)
                  _applyReaction(post, _reactionOverrides),
              ];
              return _FlexiblePostGrid(
                itemCount: displayPosts.length,
                itemBuilder: (context, index) {
                  final post = displayPosts[index];
                  return _PostCard(
                    post: post,
                    compact: true,
                    onTapAuthor: () {},
                    onOwnerEdit: () {},
                    onOwnerDelete: () {},
                    onRequestRetrieved: () {},
                    onReport: () =>
                        unawaited(_reportPost(context, widget.api, post)),
                    onTap: () => _showPost(post),
                    onReact: () => _react(post),
                    onShare: () => SharePlus.instance.share(
                      ShareParams(
                        text:
                            '${post.type.toUpperCase()} item: ${post.itemName}\n'
                            '${post.description}\n'
                            'Posted on CampusLF.',
                        subject: 'CampusLF ${post.type} item',
                      ),
                    ),
                    onPrimaryAction: () => _showPost(post),
                  );
                },
              );
            },
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
    required this.onEditPost,
    required this.onDeletePost,
    required this.onRequestRetrieved,
    required this.onOpenHandovers,
    required this.onSignedOut,
    super.key,
  });

  final String firstName;
  final ApiClient api;
  final AuthService auth;
  final Future<void> Function(CampusPost) onEditPost;
  final Future<void> Function(CampusPost) onDeletePost;
  final Future<void> Function(CampusPost) onRequestRetrieved;
  final VoidCallback onOpenHandovers;
  final VoidCallback onSignedOut;

  @override
  State<ProfileScreen> createState() => _ProfileScreenState();
}

class _ProfileScreenState extends State<ProfileScreen> {
  final _feedbackFormKey = GlobalKey<FormState>();
  final _feedbackMessage = TextEditingController();
  final _postSearch = TextEditingController();
  String _feedbackCategory = 'suggestion';
  String _postStatus = '';
  int? _postCategoryId;
  Timer? _postSearchTimer;
  Map<String, dynamic>? _profile;
  List<Map<String, dynamic>> _categories = [];
  List<CampusPost> _myPosts = [];
  bool _profileLoading = true;
  bool _loadingMyPosts = false;
  bool _loadingMorePosts = false;
  bool _hasMorePosts = false;
  int _myPostsPage = 1;
  int _requestVersion = 0;
  final Map<int, _ReactionState> _reactionOverrides = {};
  String? _profileError;
  String? _postsError;
  bool _sendingFeedback = false;

  @override
  void initState() {
    super.initState();
    _loadProfile();
    _loadCategories();
    _loadMyPosts();
  }

  @override
  void dispose() {
    _feedbackMessage.dispose();
    _postSearch.dispose();
    _postSearchTimer?.cancel();
    super.dispose();
  }

  Future<void> _loadProfile() async {
    setState(() {
      _profileLoading = true;
      _profileError = null;
    });
    try {
      final response = await widget.api.dio.get<Map<String, dynamic>>(
        '/profile',
      );
      if (mounted) {
        setState(() {
          _profile = response.data;
          _profileLoading = false;
        });
      }
    } on DioException catch (error) {
      if (mounted) {
        setState(() {
          _profileError = widget.api.errorMessage(error);
          _profileLoading = false;
        });
      }
    }
  }

  Future<void> _loadCategories() async {
    try {
      final response = await widget.api.dio.get<Map<String, dynamic>>(
        '/catalog',
      );
      final categories = response.data?['categories'];
      if (mounted && categories is List) {
        setState(
          () => _categories = categories
              .whereType<Map<String, dynamic>>()
              .toList(),
        );
      }
    } on DioException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(widget.api.errorMessage(error))));
      }
    }
  }

  Future<void> _loadMyPosts({bool reset = true}) async {
    if (!reset && (_loadingMyPosts || _loadingMorePosts)) return;
    final version = reset ? ++_requestVersion : _requestVersion;
    final page = reset ? 1 : _myPostsPage + 1;
    setState(() {
      if (reset) {
        _loadingMyPosts = true;
        _loadingMorePosts = false;
        _postsError = null;
      } else {
        _loadingMorePosts = true;
      }
    });
    try {
      final response = await widget.api.dio.get<Map<String, dynamic>>(
        '/my-posts',
        queryParameters: {
          'page': page,
          if (_postStatus.isNotEmpty) 'status': _postStatus,
          if (_postCategoryId != null) 'category_id': _postCategoryId,
          if (_postSearch.text.trim().isNotEmpty)
            'search': _postSearch.text.trim(),
        },
      );
      if (!mounted || version != _requestVersion) return;
      final data = response.data?['data'];
      final meta = response.data?['meta'];
      if (data is! List) {
        throw const FormatException('The posts response is invalid.');
      }

      final posts = data
          .whereType<Map<String, dynamic>>()
          .map(CampusPost.fromJson)
          .toList();
      setState(() {
        _myPosts = reset ? posts : [..._myPosts, ...posts];
        _myPostsPage = page;
        _hasMorePosts =
            meta is Map &&
            (meta['current_page'] as int? ?? 1) <
                (meta['last_page'] as int? ?? 1);
        _loadingMyPosts = false;
        _loadingMorePosts = false;
        _postsError = null;
      });
    } on DioException catch (error) {
      if (mounted && version == _requestVersion) {
        setState(() {
          _loadingMyPosts = false;
          _loadingMorePosts = false;
          _postsError = widget.api.errorMessage(error);
        });
      }
    } on FormatException catch (error) {
      if (mounted && version == _requestVersion) {
        setState(() {
          _loadingMyPosts = false;
          _loadingMorePosts = false;
          _postsError = error.message;
        });
      }
    }
  }

  Future<void> _toggleReaction(CampusPost post) async {
    final current = _applyReaction(post, _reactionOverrides);
    final wasLiked = current.myReaction == 'like';
    final optimisticCount = current.reactionsCount + (wasLiked ? -1 : 1);
    setState(() {
      _reactionOverrides[post.id] = _ReactionState(
        optimisticCount < 0 ? 0 : optimisticCount,
        wasLiked ? null : 'like',
      );
    });
    try {
      final response = await widget.api.dio.post<Map<String, dynamic>>(
        '/posts/${post.id}/reactions',
        data: {'type': wasLiked ? 'none' : 'like'},
      );
      if (!mounted) return;
      setState(() {
        _reactionOverrides[post.id] = _ReactionState(
          response.data?['reactions_count'] as int? ?? optimisticCount,
          response.data?['reaction'] as String?,
        );
      });
    } on DioException catch (error) {
      if (!mounted) return;
      setState(() {
        _reactionOverrides[post.id] = _ReactionState(
          current.reactionsCount,
          current.myReaction,
        );
      });
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(SnackBar(content: Text(widget.api.errorMessage(error))));
    }
  }

  Future<void> _editPost(CampusPost post) async {
    await widget.onEditPost(post);
    if (mounted) _loadMyPosts();
  }

  Future<void> _deletePost(CampusPost post) async {
    await widget.onDeletePost(post);
    if (mounted) _loadMyPosts();
  }

  Future<void> _requestRetrieved(CampusPost post) async {
    await widget.onRequestRetrieved(post);
    if (mounted) _loadMyPosts();
  }

  Future<void> _editProfile() async {
    final updatedProfile = await showDialog<Map<String, dynamic>>(
      context: context,
      builder: (_) => _EditProfileDialog(
        api: widget.api,
        profile: _profile ?? const <String, dynamic>{},
      ),
    );
    if (updatedProfile != null && mounted) {
      setState(() => _profile = updatedProfile);
    }
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
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(widget.api.errorMessage(error))));
      }
    } finally {
      if (mounted) setState(() => _sendingFeedback = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_profileLoading) {
      return const Center(child: CircularProgressIndicator());
    }
    if (_profileError != null) {
      return _EmptyState(
        icon: Icons.cloud_off_outlined,
        title: 'Could not load profile',
        message: _profileError!,
        action: FilledButton.tonal(
          onPressed: _loadProfile,
          child: const Text('Try again'),
        ),
      );
    }
    final profile = _profile ?? const <String, dynamic>{};
    final firstName = profile['first_name'] as String? ?? widget.firstName;
    final lastName = profile['last_name'] as String? ?? '';
    final fullName = '$firstName $lastName'.trim();
    final pictureUrl = profile['profile_picture_url'] as String?;
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        const _SectionBanner(
          icon: Icons.account_circle_outlined,
          title: 'Your profile',
          subtitle: 'Manage your details and keep track of your listings.',
          tint: CampusTheme.blue,
        ),
        const SizedBox(height: 16),
        Center(
          child: InkWell(
            onTap: _editProfile,
            customBorder: const CircleBorder(),
            child: CircleAvatar(
              radius: 48,
              backgroundColor: Theme.of(context)
                  .colorScheme
                  .surfaceContainerHighest,
              backgroundImage: pictureUrl == null
                  ? null
                  : NetworkImage(pictureUrl),
              child: pictureUrl == null
                  ? Text(
                      firstName.isEmpty ? '?' : firstName[0].toUpperCase(),
                      style: TextStyle(
                        fontSize: 34,
                        color: Theme.of(context).colorScheme.primary,
                      ),
                    )
                  : null,
            ),
          ),
        ),
        const SizedBox(height: 12),
        Center(
          child: Text(
            fullName,
            textAlign: TextAlign.center,
            style: Theme.of(context).textTheme.titleLarge,
          ),
        ),
        const SizedBox(height: 8),
        Center(
          child: Text(
            (profile['bio'] as String?)?.trim().isNotEmpty == true
                ? profile['bio'] as String
                : 'Add a short bio to your profile.',
            textAlign: TextAlign.center,
            style: Theme.of(context).textTheme.bodyMedium?.copyWith(
              color: Theme.of(context).colorScheme.onSurfaceVariant,
            ),
          ),
        ),
        Center(
          child: TextButton.icon(
            onPressed: _editProfile,
            icon: const Icon(Icons.edit_outlined),
            label: const Text('Edit profile'),
          ),
        ),
        Card(
          child: ExpansionTile(
            leading: const Icon(Icons.contact_page_outlined),
            title: const Text('Personal details'),
            subtitle: const Text(
              'Details shown here are visible to other campus members',
            ),
            childrenPadding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
            children: [
              _profileDetail('Status', profile['profile_status'] as String?),
              _profileDetail(
                'Current address',
                profile['current_address'] as String?,
              ),
              _profileDetail(
                'Permanent address',
                profile['permanent_address'] as String?,
              ),
              _profileDetail(
                'Mobile number',
                profile['contact_number'] as String?,
              ),
              _profileDetail(
                'Contact email',
                profile['contact_email'] as String?,
              ),
            ],
          ),
        ),
        const SizedBox(height: 16),
        Text('My posts', style: Theme.of(context).textTheme.titleLarge),
        const SizedBox(height: 8),
        TextField(
          controller: _postSearch,
          onChanged: (_) {
            _postSearchTimer?.cancel();
            _postSearchTimer = Timer(
              const Duration(milliseconds: 350),
              _loadMyPosts,
            );
          },
          decoration: InputDecoration(
            labelText: 'Search my posts',
            prefixIcon: const Icon(Icons.search),
            suffixIcon: IconButton(
              tooltip: 'Clear search',
              onPressed: () {
                _postSearch.clear();
                _loadMyPosts();
              },
              icon: const Icon(Icons.close),
            ),
          ),
        ),
        const SizedBox(height: 8),
        DropdownButtonFormField<int?>(
          initialValue: _postCategoryId,
          decoration: const InputDecoration(labelText: 'Category'),
          items: [
            const DropdownMenuItem<int?>(
              value: null,
              child: Text('All categories'),
            ),
            ..._categories.map(
              (category) => DropdownMenuItem<int?>(
                value: category['id'] as int,
                child: Text(category['name'] as String? ?? 'Category'),
              ),
            ),
          ],
          onChanged: (value) {
            setState(() => _postCategoryId = value);
            _loadMyPosts();
          },
        ),
        SingleChildScrollView(
          scrollDirection: Axis.horizontal,
          child: Row(
            children: [
              _postStatusChip('All', ''),
              _postStatusChip('Active', 'active'),
              _postStatusChip('Archived', 'archived'),
              _postStatusChip('Retrieved', 'retrieved'),
            ],
          ),
        ),
        if (_loadingMyPosts && _myPosts.isEmpty)
          const Padding(
            padding: EdgeInsets.all(28),
            child: Center(child: CircularProgressIndicator()),
          )
        else if (_postsError != null && _myPosts.isEmpty)
          _EmptyState(
            icon: Icons.cloud_off_outlined,
            title: 'Could not load your posts',
            message: _postsError!,
            action: FilledButton.tonal(
              onPressed: _loadMyPosts,
              child: const Text('Try again'),
            ),
          )
        else if (_myPosts.isEmpty)
          const Padding(
            padding: EdgeInsets.symmetric(vertical: 24),
            child: Center(child: Text('No posts match these filters.')),
          )
        else ...[
          _FlexiblePostGrid(
            itemCount: _myPosts.length,
            itemBuilder: (context, index) {
              final post = _applyReaction(_myPosts[index], _reactionOverrides);
              return _PostCard(
                post: post,
                compact: true,
                onTapAuthor: () {},
                onOwnerEdit: () => unawaited(_editPost(post)),
                onOwnerDelete: () => unawaited(_deletePost(post)),
                onRequestRetrieved: () => unawaited(_requestRetrieved(post)),
                onReport: () =>
                    unawaited(_reportPost(context, widget.api, post)),
                onTap: () => showModalBottomSheet<void>(
                  context: context,
                  isScrollControlled: true,
                  builder: (_) => _PostDetailSheet(
                    api: widget.api,
                    post: post,
                    onReactionChanged: (reaction, count) {
                      if (!mounted) return;
                      setState(() {
                        _reactionOverrides[post.id] = _ReactionState(
                          count,
                          reaction,
                        );
                      });
                    },
                  ),
                ),
                onReact: () => unawaited(_toggleReaction(post)),
                onShare: () => SharePlus.instance.share(
                  ShareParams(
                    text:
                        '${post.type.toUpperCase()} item: ${post.itemName}\n'
                        '${post.description}\n'
                        'Category: ${post.category} · ${post.location}\n'
                        'Posted on CampusLF.',
                    subject: 'CampusLF ${post.type} item',
                  ),
                ),
                onPrimaryAction: post.status == 'handover_pending'
                    ? widget.onOpenHandovers
                    : () => showModalBottomSheet<void>(
                        context: context,
                        isScrollControlled: true,
                        builder: (_) => _PostDetailSheet(
                          api: widget.api,
                          post: post,
                          onReactionChanged: (reaction, count) {
                            if (!mounted) return;
                            setState(() {
                              _reactionOverrides[post.id] = _ReactionState(
                                count,
                                reaction,
                              );
                            });
                          },
                        ),
                      ),
              );
            },
          ),
          if (_postsError != null)
            Text(
              _postsError!,
              style: TextStyle(color: Theme.of(context).colorScheme.error),
            ),
          if (_hasMorePosts)
            Center(
              child: TextButton(
                onPressed: _loadingMorePosts
                    ? null
                    : () => _loadMyPosts(reset: false),
                child: _loadingMorePosts
                    ? const CircularProgressIndicator()
                    : const Text('Load more posts'),
              ),
            ),
        ],
        const SizedBox(height: 8),
        const Card(
          child: ListTile(
            leading: Icon(Icons.privacy_tip_outlined),
            title: Text('Privacy and disclaimer'),
            subtitle: Text(
              'CampusLF connects people; it does not keep or insure items.',
            ),
          ),
        ),
        const SizedBox(height: 12),
        Card(
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Form(
              key: _feedbackFormKey,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text(
                    'Send feedback',
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                  const SizedBox(height: 8),
                  const Text(
                    'Share a suggestion, report a problem, or tell us about your experience.',
                  ),
                  const SizedBox(height: 12),
                  DropdownButtonFormField<String>(
                    initialValue: _feedbackCategory,
                    decoration: const InputDecoration(
                      labelText: 'Feedback type',
                    ),
                    items: const [
                      DropdownMenuItem(
                        value: 'suggestion',
                        child: Text('Suggestion'),
                      ),
                      DropdownMenuItem(
                        value: 'bug',
                        child: Text('Report a problem'),
                      ),
                      DropdownMenuItem(
                        value: 'experience',
                        child: Text('My experience'),
                      ),
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
                    validator: (value) => (value?.trim().length ?? 0) < 10
                        ? 'Please enter at least 10 characters.'
                        : null,
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
                    label: Text(
                      _sendingFeedback ? 'Sending...' : 'Send feedback',
                    ),
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

  Widget _postStatusChip(String label, String value) {
    return Padding(
      padding: const EdgeInsets.only(right: 8, top: 8, bottom: 8),
      child: ChoiceChip(
        label: Text(label),
        selected: _postStatus == value,
        onSelected: (_) {
          setState(() => _postStatus = value);
          _loadMyPosts();
        },
      ),
    );
  }

  Widget _profileDetail(String label, String? value) {
    final detail = value?.trim();
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Align(
        alignment: Alignment.centerLeft,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              label,
              style: Theme.of(context).textTheme.labelMedium?.copyWith(
                color: Theme.of(context).colorScheme.onSurfaceVariant,
              ),
            ),
            const SizedBox(height: 3),
            Text(detail == null || detail.isEmpty ? 'Not provided' : detail),
          ],
        ),
      ),
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
            Icon(icon, size: 48, color: Theme.of(context).colorScheme.primary),
            const SizedBox(height: 12),
            Text(
              title,
              style: Theme.of(context).textTheme.titleLarge,
              textAlign: TextAlign.center,
            ),
            const SizedBox(height: 8),
            Text(
              message,
              textAlign: TextAlign.center,
              style: TextStyle(
                color: Theme.of(context).colorScheme.onSurfaceVariant,
              ),
            ),
            if (action != null) ...[const SizedBox(height: 18), action!],
          ],
        ),
      ),
    );
  }
}
