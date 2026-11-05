import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:image_picker/image_picker.dart';
import 'package:pdf/widgets.dart' as pw;
import 'package:printing/printing.dart';
import 'package:share_plus/share_plus.dart';

import '../core/api_client.dart';
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

class CampusHomeScreen extends StatefulWidget {
  const CampusHomeScreen({
    required this.api,
    required this.auth,
    required this.firstName,
    required this.isDarkMode,
    required this.onToggleTheme,
    required this.onSignedOut,
    super.key,
  });

  final ApiClient api;
  final AuthService auth;
  final String firstName;
  final bool isDarkMode;
  final VoidCallback onToggleTheme;
  final VoidCallback onSignedOut;

  @override
  State<CampusHomeScreen> createState() => _CampusHomeScreenState();
}

class _CampusHomeScreenState extends State<CampusHomeScreen> {
  int _tab = 0;
  String _type = '';
  String _search = '';
  String _status = '';
  String _sort = 'latest';
  int? _categoryId;
  int _communityMode = 0;
  Future<List<CampusPost>>? _posts;
  Future<List<Map<String, dynamic>>>? _people;
  List<Map<String, dynamic>> _categories = [];
  Timer? _peopleSearchTimer;
  final TextEditingController _homeSearchController = TextEditingController();
  final TextEditingController _peopleSearchController = TextEditingController();
  final Map<int, _ReactionState> _reactionOverrides = {};
  int _inboxRefreshKey = 0;
  int _profileRefreshKey = 0;
  int _postRefreshKey = 0;

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
        if (_type.isNotEmpty) 'type': _type,
        if (_status.isNotEmpty) 'status': _status,
        if (_categoryId != null) 'category_id': _categoryId,
        if (_search.trim().isNotEmpty) 'search': _search.trim(),
        'sort': _sort,
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
    setState(() => _posts = _fetchPosts());
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
      if (mounted) setState(() => _people = _fetchPeople());
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
    final reason = TextEditingController();
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: const Text('Request retrieved status'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Text(
              'TMC administrators will review your request before this post is marked as retrieved.',
            ),
            const SizedBox(height: 12),
            TextField(
              controller: reason,
              maxLines: 3,
              maxLength: 1000,
              decoration: const InputDecoration(labelText: 'Optional details'),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(dialogContext, false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(dialogContext, true),
            child: const Text('Send request'),
          ),
        ],
      ),
    );
    if (confirmed != true) {
      reason.dispose();
      return;
    }
    try {
      final response = await widget.api.dio.post<Map<String, dynamic>>(
        '/posts/${post.id}/retrieval-request',
        data: {'reason': reason.text.trim()},
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
    } finally {
      reason.dispose();
    }
  }

  @override
  void dispose() {
    _homeSearchController.dispose();
    _peopleSearchController.dispose();
    _peopleSearchTimer?.cancel();
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
        title: Row(
          children: [
            const CampusLfBrandMark(),
            const SizedBox(width: 10),
            Text(titles[_tab]),
          ],
        ),
        actions: [
          IconButton(
            tooltip: widget.isDarkMode
                ? 'Switch to light mode'
                : 'Switch to dark mode',
            onPressed: widget.onToggleTheme,
            icon: Icon(widget.isDarkMode ? Icons.light_mode : Icons.dark_mode),
          ),
        ],
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
      slivers: [
        SliverToBoxAdapter(
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16),
            child: SegmentedButton<int>(
              segments: const [
                ButtonSegment(
                  value: 0,
                  label: Text('Posts'),
                  icon: Icon(Icons.article_outlined),
                ),
                ButtonSegment(
                  value: 1,
                  label: Text('People'),
                  icon: Icon(Icons.people_outline),
                ),
              ],
              selected: {_communityMode},
              onSelectionChanged: (selection) => setState(() {
                _communityMode = selection.first;
              }),
            ),
          ),
        ),
        SliverToBoxAdapter(
          child: _communityMode == 0
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
                                      'Browse community posts',
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
                                      'Search, filter, and browse campus listings.',
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
                                    '${snapshot.data?.length ?? 0} posts',
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
                            onSubmitted: (_) => _applyFeedFilters(),
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
                              final columns = constraints.maxWidth >= 660
                                  ? 4
                                  : 2;
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
                                      initialValue: _type,
                                      isExpanded: true,
                                      decoration: const InputDecoration(
                                        labelText: 'Kind',
                                        isDense: true,
                                        contentPadding: EdgeInsets.symmetric(
                                          horizontal: 12,
                                          vertical: 8,
                                        ),
                                      ),
                                      items: const [
                                        DropdownMenuItem(
                                          value: '',
                                          child: Text('Lost and found'),
                                        ),
                                        DropdownMenuItem(
                                          value: 'lost',
                                          child: Text('Lost'),
                                        ),
                                        DropdownMenuItem(
                                          value: 'found',
                                          child: Text('Found'),
                                        ),
                                      ],
                                      onChanged: (value) =>
                                          setState(() => _type = value ?? ''),
                                    ),
                                  ),
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
                                      onChanged: (value) =>
                                          setState(() => _status = value ?? ''),
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
                                      onChanged: (value) =>
                                          setState(() => _categoryId = value),
                                    ),
                                  ),
                                  SizedBox(
                                    width: width,
                                    child: DropdownButtonFormField<String>(
                                      initialValue: _sort,
                                      isExpanded: true,
                                      decoration: const InputDecoration(
                                        labelText: 'Order',
                                        isDense: true,
                                        contentPadding: EdgeInsets.symmetric(
                                          horizontal: 12,
                                          vertical: 8,
                                        ),
                                      ),
                                      items: const [
                                        DropdownMenuItem(
                                          value: 'latest',
                                          child: Text('Newest first'),
                                        ),
                                        DropdownMenuItem(
                                          value: 'oldest',
                                          child: Text('Oldest first'),
                                        ),
                                      ],
                                      onChanged: (value) => setState(
                                        () => _sort = value ?? 'latest',
                                      ),
                                    ),
                                  ),
                                ],
                              );
                            },
                          ),
                          const SizedBox(height: 10),
                          Wrap(
                            spacing: 8,
                            children: [
                              FilledButton(
                                onPressed: _applyFeedFilters,
                                style: FilledButton.styleFrom(
                                  minimumSize: const Size(0, 36),
                                  padding: const EdgeInsets.symmetric(
                                    horizontal: 14,
                                    vertical: 6,
                                  ),
                                  tapTargetSize:
                                      MaterialTapTargetSize.shrinkWrap,
                                ),
                                child: const Text('Apply filters'),
                              ),
                              OutlinedButton(
                                onPressed: _clearFeedFilters,
                                style: OutlinedButton.styleFrom(
                                  minimumSize: const Size(0, 36),
                                  padding: const EdgeInsets.symmetric(
                                    horizontal: 14,
                                    vertical: 6,
                                  ),
                                  tapTargetSize:
                                      MaterialTapTargetSize.shrinkWrap,
                                ),
                                child: const Text('Clear'),
                              ),
                            ],
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
        if (_communityMode == 0) _feedPostsSliver() else _feedPeopleSliver(),
      ],
    );
  }

  void _applyFeedFilters() {
    FocusScope.of(context).unfocus();
    setState(() {
      _search = _homeSearchController.text.trim();
      _posts = _fetchPosts();
    });
  }

  void _clearFeedFilters() {
    _homeSearchController.clear();
    setState(() {
      _search = '';
      _type = '';
      _status = '';
      _categoryId = null;
      _sort = 'latest';
      _posts = _fetchPosts();
    });
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
              action: OutlinedButton(
                onPressed: _clearFeedFilters,
                child: const Text('Clear filters'),
              ),
            ),
          );
        }
        return SliverPadding(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 20),
          sliver: SliverList(
            delegate: SliverChildBuilderDelegate((context, index) {
              if (index.isOdd) return const SizedBox(height: 12);
              final post = _postWithReaction(posts[index ~/ 2]);
              return _PostCard(
                post: post,
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
            }, childCount: posts.length * 2 - 1),
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
                onPressed: () => setState(() => _people = _fetchPeople()),
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

class _PostCard extends StatelessWidget {
  const _PostCard({
    required this.post,
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
    final tagColor = found ? CampusTheme.found : CampusTheme.lost;
    final dark = Theme.of(context).brightness == Brightness.dark;
    final tagSurface = found
        ? (dark ? CampusTheme.foundSurfaceDark : CampusTheme.foundSurface)
        : (dark ? CampusTheme.lostSurfaceDark : CampusTheme.lostSurface);
    final neutralSurface = dark
        ? CampusTheme.otherSurfaceDark
        : CampusTheme.otherSurface;
    final primaryLabel = post.status == 'handover_pending'
        ? 'Handover'
        : found
        ? 'Claim'
        : 'I found it';
    return Card(
      clipBehavior: Clip.antiAlias,
      elevation: 2,
      shadowColor: const Color(0x1F0C416C),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(20),
        side: BorderSide(color: tagColor.withValues(alpha: .3)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(14, 10, 6, 10),
            child: Row(
              children: [
                Expanded(
                  child: InkWell(
                    onTap: onTapAuthor,
                    borderRadius: BorderRadius.circular(24),
                    child: Row(
                      children: [
                        CircleAvatar(
                          radius: 19,
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
                                    color: Theme.of(context)
                                        .colorScheme
                                        .primary,
                                    fontWeight: FontWeight.w700,
                                  ),
                                )
                              : null,
                        ),
                        const SizedBox(width: 9),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                '${post.authorFirstName} ${post.authorLastName}'
                                    .trim(),
                                style: const TextStyle(
                                  fontWeight: FontWeight.w700,
                                ),
                              ),
                              Text(
                                _timeAgo(post.createdAt),
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
          ),
          LayoutBuilder(
            builder: (context, constraints) {
              final wide = constraints.maxWidth >= 700;
              final hasImage =
                  post.images.isNotEmpty &&
                  post.images.first['url']!.isNotEmpty;
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
                        SizedBox(
                          width: constraints.maxWidth * .36,
                          child: _postImage(),
                        ),
                      Expanded(child: details),
                    ],
                  ),
                );
              }
              return Column(
                children: [
                  if (hasImage)
                    InkWell(
                      onTap: onTap,
                      child: AspectRatio(
                        aspectRatio: 1.48,
                        child: _postImage(),
                      ),
                    ),
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
                    (post.status == 'active' && !post.isMine))
                  FilledButton.tonal(
                    onPressed: onPrimaryAction,
                    style: FilledButton.styleFrom(
                      minimumSize: const Size(0, 38),
                      padding: const EdgeInsets.symmetric(horizontal: 13),
                      visualDensity: VisualDensity.compact,
                    ),
                    child: Text(primaryLabel),
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _postImage() {
    return InkWell(
      onTap: onTap,
      child: Image.network(
        post.images.first['url']!,
        fit: BoxFit.cover,
        semanticLabel: post.images.first['alt_text'],
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
    );
  }

  Widget _postDetails(
    BuildContext context,
    bool found,
    Color tagColor,
    Color tagSurface,
    Color neutralSurface,
  ) {
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
                color: CampusTheme.other,
                surface: neutralSurface,
              ),
              if (post.isMine && post.retrievalRequestStatus != null)
                _PostTag(
                  label:
                      'RETRIEVAL ${post.retrievalRequestStatus!.toUpperCase()}',
                  color: post.retrievalRequestStatus == 'pending'
                      ? const Color(0xFFB17B2C)
                      : CampusTheme.other,
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
  final reason = TextEditingController();
  try {
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
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('Send report'),
          ),
        ],
      ),
    );
    if (accepted != true || !context.mounted) return;
    if (reason.text.trim().isEmpty) {
      ScaffoldMessenger.maybeOf(context)?.showSnackBar(
        const SnackBar(content: Text('Enter a reason for the report.')),
      );
      return;
    }

    await api.dio.post<void>(
      '/posts/${post.id}/reports',
      data: {'reason': reason.text.trim()},
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
  } finally {
    reason.dispose();
  }
}

class _PostDetailSheet extends StatefulWidget {
  const _PostDetailSheet({
    required this.api,
    required this.post,
    this.onOpenProfile,
    this.onReactionChanged,
  });
  final ApiClient api;
  final CampusPost post;
  final void Function(String? reaction, int count)? onReactionChanged;
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
  String? _message;
  bool _loadingOwnClaim = false;
  bool _claimSubmitted = false;
  bool _claimCanEdit = false;
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

  Future<void> _loadOwnClaim() async {
    setState(() => _loadingOwnClaim = true);
    try {
      final response = await widget.api.dio.get<Map<String, dynamic>>(
        '/posts/${widget.post.id}/my-claim',
      );
      final claim = response.data?['data'];
      if (claim is Map<String, dynamic> && mounted) {
        final answers = claim['answers'];
        final evidenceImages = claim['evidence_images'];
        final attemptsUsed = int.tryParse('${claim['attempts_used']}');
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
          if (status == 'approved' && !_claimRejected) {
            _message = 'Your claim was approved and is awaiting the post owner’s decision.';
          } else if (_claimRejected) {
            if (_claimAttemptsUsed >= _claimAttemptLimit) {
              _message =
                  'You used all $_claimAttemptLimit claim attempts for this item.';
            } else if (_claimCanSubmitAnother) {
              _message =
                  'Your claim was not approved. You can submit another claim.';
            } else {
              _message = 'Your claim was not approved. You can retry 24 hours after your previous attempt.';
            }
          } else {
            _message = 'Your claim is under review. You cannot submit another claim until it is rejected.';
          }
        });
      } else if (mounted) {
        setState(() {
          _claimSubmitted = false;
          _claimId = null;
          _claimCanEdit = false;
          _editingClaim = false;
          _claimRejected = false;
          _claimCanSubmitAnother = false;
          _claimAttemptsUsed = 0;
          _ownClaimImages = [];
        });
      }
    } on DioException catch (error) {
      if (mounted) setState(() => _message = widget.api.errorMessage(error));
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
      _message = 'Preparing your claim...';
    });
    try {
      final fields = <String, dynamic>{
        'answers': _answers.map((answer) => answer.text).toList(),
      };
      if (_claimPhotos.isNotEmpty) {
        fields['evidence_images'] = await Future.wait(
          _claimPhotos.map(
            (photo) => MultipartFile.fromFile(photo.path, filename: photo.name),
          ),
        );
      }
      final response = await widget.api.dio.post<Map<String, dynamic>>(
        '/posts/${widget.post.id}/claims',
        data: FormData.fromMap(fields),
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
        await _loadOwnClaim();
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

  Future<void> _pickClaimPhotos() async {
    final photos = await ImagePicker().pickMultiImage(imageQuality: 85);
    if (!mounted || photos.isEmpty) return;
    if (photos.length > 5) {
      setState(() => _message = 'Choose no more than five photos.');
      return;
    }
    setState(() {
      _claimPhotos
        ..clear()
        ..addAll(photos);
      _message = null;
    });
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
      final fields = <String, dynamic>{
        '_method': 'PATCH',
        'answers': _answers.map((answer) => answer.text).toList(),
      };
      if (_claimPhotos.isNotEmpty) {
        fields['evidence_images'] = await Future.wait(
          _claimPhotos.map(
            (photo) => MultipartFile.fromFile(photo.path, filename: photo.name),
          ),
        );
      }
      final response = await widget.api.dio.post<Map<String, dynamic>>(
        '/posts/${widget.post.id}/claims/$claimId',
        data: FormData.fromMap(fields),
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
              if (post.images.isNotEmpty &&
                  post.images.first['url']!.isNotEmpty)
                ClipRRect(
                  borderRadius: BorderRadius.circular(12),
                  child: AspectRatio(
                    aspectRatio: 4 / 3,
                    child: Image.network(
                      post.images.first['url']!,
                      fit: BoxFit.cover,
                      semanticLabel: post.images.first['alt_text'],
                      errorBuilder: (context, _, _) => ColoredBox(
                        color: Theme.of(context)
                            .colorScheme
                            .surfaceContainerHighest,
                        child: Center(
                          child: Icon(Icons.image_not_supported_outlined),
                        ),
                      ),
                    ),
                  ),
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
                            imageUrl,
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
                  'Submit photos of the item and answer every ownership question. TMC administrators review your evidence before the post owner decides. When editing, selecting photos replaces the submitted photos.',
                ),
                const SizedBox(height: 12),
                if (_claimCanEdit && !_editingClaim)
                  OutlinedButton.icon(
                    onPressed: _busy || _loadingOwnClaim
                        ? null
                        : _beginClaimEdit,
                    icon: const Icon(Icons.edit_outlined),
                    label: const Text('Edit submitted claim'),
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
                if (!_editingClaim)
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
                            !_claimSubmitted
                                ? 'Submit claim'
                                : _claimCanSubmitAnother
                                ? 'Submit another claim'
                                : _claimRejected
                                ? 'Claim attempts used'
                                : 'Claim submitted',
                          ),
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
    final photo = await ImagePicker().pickImage(
      source: ImageSource.gallery,
      imageQuality: 85,
    );
    if (photo != null && mounted) setState(() => _photo = photo);
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
                  widget.initialImageUrl!,
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
      if (mounted) setState(() => _sightings = _load());
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
        setState(() => _sightings = _load());
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
                            Image.network(
                              sighting['evidence_image'] as String,
                              semanticLabel: 'Sighting evidence photo',
                              errorBuilder: (_, _, _) => const Text(
                                'Evidence photo could not be loaded.',
                              ),
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
        _categoryId = data['category_id'] as int?;
        _locationId = data['location_id'] as int?;
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
    final selectedCategory = _categories.where(
      (item) => item['id'] == _categoryId,
    );
    final category = selectedCategory.isEmpty
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
  }

  void _removePhoto(int index) {
    setState(() {
      _photos.removeAt(index);
      _altTextControllers.removeAt(index).dispose();
    });
  }

  Future<void> _submit() async {
    if (_busy || _editDataFailed || !_formKey.currentState!.validate()) return;
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
      if (widget.initialPost == null) {
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
        'category_id': _categoryId,
        'color': _color.text.trim(),
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
          initialValue: _categoryId,
          decoration: const InputDecoration(labelText: 'Category *'),
          items: _categories
              .map(
                (item) => DropdownMenuItem<int>(
                  value: item['id'] as int,
                  child: Text(item['name'] as String),
                ),
              )
              .toList(),
          onChanged: (value) => setState(() => _categoryId = value),
        ),
        const SizedBox(height: 12),
        TextFormField(
          controller: _color,
          decoration: const InputDecoration(labelText: 'Color *'),
          validator: _required,
        ),
        const SizedBox(height: 12),
        DropdownButtonFormField<int>(
          initialValue: _locationId,
          decoration: const InputDecoration(labelText: 'Campus area *'),
          items: _locations
              .map(
                (item) => DropdownMenuItem<int>(
                  value: item['id'] as int,
                  child: Text(item['name'] as String),
                ),
              )
              .toList(),
          onChanged: (value) => setState(() => _locationId = value),
        ),
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
    this.initialTab = 0,
    super.key,
  });
  final ApiClient api;
  final Future<void> Function(int) onOpenProfile;
  final int initialTab;

  @override
  State<InboxScreen> createState() => _InboxScreenState();
}

class _InboxScreenState extends State<InboxScreen> {
  int _tab = 0;
  late Future<List<Map<String, dynamic>>> _notifications;
  late Future<List<Map<String, dynamic>>> _conversations;
  late Future<List<Map<String, dynamic>>> _handovers;
  late Future<List<Map<String, dynamic>>> _authorClaims;
  late Future<List<Map<String, dynamic>>> _evidence;
  String _conversationFilter = 'all';

  @override
  void initState() {
    super.initState();
    _tab = widget.initialTab;
    _notifications = _load();
    _conversations = _loadConversations();
    _handovers = _loadHandovers();
    _authorClaims = _loadAuthorClaims();
    _evidence = _loadEvidence();
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
    final response = await widget.api.dio.get<Map<String, dynamic>>(
      '/handovers',
    );
    final data = response.data?['data'];
    if (data is! List) return const [];
    return data.whereType<Map<String, dynamic>>().toList();
  }

  Future<List<Map<String, dynamic>>> _loadAuthorClaims() async {
    final response = await widget.api.dio.get<Map<String, dynamic>>(
      '/claims/awaiting-post-author',
    );
    final data = response.data?['data'];
    if (data is! List) return const [];
    return data.whereType<Map<String, dynamic>>().toList();
  }

  Future<List<Map<String, dynamic>>> _loadEvidence() async {
    final response = await widget.api.dio.get<Map<String, dynamic>>(
      '/my-evidence',
    );
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
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(widget.api.errorMessage(error))));
      }
    }
  }

  Future<void> _openNotification(Map<String, dynamic> notification) async {
    final id = notification['id'] as String?;
    if (id != null && id.isNotEmpty) await _markRead(id);
    if (!mounted) return;
    final data = notification['data'] as Map<String, dynamic>? ?? const {};
    final conversationId = data['conversation_id'] as int?;
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

    final notificationType = notification['type'] as String? ?? '';
    if (notificationType == 'lost_sighting') {
      setState(() {
        _tab = 4;
        _evidence = _loadEvidence();
      });
      return;
    }
    if (notificationType == 'claim_owner_review') {
      setState(() {
        _tab = 3;
        _authorClaims = _loadAuthorClaims();
      });
      return;
    }

    final postId = data['post_id'] as int?;
    if (postId == null) return;
    try {
      final response = await widget.api.dio.get<Map<String, dynamic>>(
        '/posts/$postId',
      );
      final postData = response.data?['data'] is Map<String, dynamic>
          ? response.data!['data'] as Map<String, dynamic>
          : response.data;
      if (postData == null || !mounted) return;
      await showModalBottomSheet<void>(
        context: context,
        isScrollControlled: true,
        builder: (_) => _PostDetailSheet(
          api: widget.api,
          post: CampusPost.fromJson(postData),
          onOpenProfile: widget.onOpenProfile,
        ),
      );
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
    }
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
            tint: Color(0xFF8C5A92),
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
              onPressed: () =>
                  setState(() => _conversations = _loadConversations()),
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

  Future<void> _decideEvidence(int id, String decision) async {
    try {
      await widget.api.dio.patch<void>(
        '/sightings/$id',
        data: {'decision': decision},
      );
      if (mounted) {
        setState(() {
          _evidence = _loadEvidence();
          _handovers = _loadHandovers();
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

  Future<void> _messageAdminAboutEvidence(Map<String, dynamic> evidence) async {
    final message = TextEditingController(
      text:
          'Please review the evidence for ${evidence['item_name'] ?? 'my lost item'}. I believe it is valid.',
    );
    try {
      final body = await showDialog<String>(
        context: context,
        builder: (context) => AlertDialog(
          title: const Text('Message TMC administrators'),
          content: TextField(
            controller: message,
            maxLines: 4,
            maxLength: 2000,
            decoration: const InputDecoration(labelText: 'Message'),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(context),
              child: const Text('Cancel'),
            ),
            FilledButton(
              onPressed: () => Navigator.pop(context, message.text.trim()),
              child: const Text('Open chat'),
            ),
          ],
        ),
      );
      if (body == null || body.isEmpty || !mounted) return;
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
    } finally {
      message.dispose();
    }
  }

  Widget _evidenceList() {
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
              onPressed: () => setState(() => _evidence = _loadEvidence()),
              child: const Text('Try again'),
            ),
          );
        }
        final evidence = snapshot.data ?? const [];
        if (evidence.isEmpty) {
          return const _EmptyState(
            icon: Icons.photo_library_outlined,
            title: 'No evidence submitted',
            message: 'Evidence sent for your lost posts will appear here for review.',
          );
        }
        return RefreshIndicator(
          onRefresh: () async => setState(() => _evidence = _loadEvidence()),
          child: ListView.builder(
            padding: const EdgeInsets.all(14),
            itemCount: evidence.length,
            itemBuilder: (context, index) {
              final item = evidence[index];
              final id = item['id'] as int;
              final authorDecision =
                  item['author_decision'] as String? ?? 'pending';
              final adminDecision =
                  item['admin_decision'] as String? ?? 'pending';
              return Card(
                child: Padding(
                  padding: const EdgeInsets.all(14),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        item['item_name'] as String? ?? 'Lost item',
                        style: Theme.of(context).textTheme.titleMedium,
                      ),
                      Text(
                        'Evidence from ${item['finder_first_name'] ?? 'TMC member'} · Owner: $authorDecision · Admin: $adminDecision',
                      ),
                      if ((item['evidence_text'] as String? ?? '').isNotEmpty)
                        Padding(
                          padding: const EdgeInsets.only(top: 8),
                          child: Text(item['evidence_text'] as String),
                        ),
                      if (item['evidence_image'] is String) ...[
                        const SizedBox(height: 10),
                        ClipRRect(
                          borderRadius: BorderRadius.circular(12),
                          child: Image.network(
                            item['evidence_image'] as String,
                            errorBuilder: (_, _, _) => const Text(
                              'Evidence photo could not be loaded.',
                            ),
                          ),
                        ),
                      ],
                      if (adminDecision != 'approved' &&
                          adminDecision != 'rejected') ...[
                        const SizedBox(height: 10),
                        if (authorDecision == 'pending')
                          Wrap(
                            spacing: 8,
                            children: [
                              FilledButton.tonal(
                                onPressed: () => _decideEvidence(id, 'approve'),
                                child: const Text('Approve evidence'),
                              ),
                              OutlinedButton(
                                onPressed: () => _decideEvidence(id, 'reject'),
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
                            onPressed: () => _messageAdminAboutEvidence(item),
                            icon: const Icon(Icons.support_agent),
                            label: const Text('Nudge TMC admins'),
                          ),
                        ] else ...[
                          const Text(
                            'You declined this evidence. It will not be sent for administrator approval.',
                          ),
                        ],
                      ],
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

  Future<void> _decideAuthorClaim(int id, String decision) async {
    try {
      await widget.api.dio.patch<void>(
        '/claims/$id/post-author-decision',
        data: {'decision': decision},
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
                  ? 'You agreed to the claim. TMC administrators must approve before both participants can agree and schedule the handover.'
                  : 'Your decision was recorded.',
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

  Widget _authorClaimList() {
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
              onPressed: () =>
                  setState(() => _authorClaims = _loadAuthorClaims()),
              child: const Text('Try again'),
            ),
          );
        }
        final claims = snapshot.data ?? const [];
        if (claims.isEmpty) {
          return const _EmptyState(
            icon: Icons.fact_check_outlined,
            title: 'No claims need your decision',
            message: 'Claims approved by TMC administrators for your found posts will appear here.',
          );
        }
        return RefreshIndicator(
          onRefresh: () async =>
              setState(() => _authorClaims = _loadAuthorClaims()),
          child: ListView.separated(
            padding: const EdgeInsets.all(16),
            itemCount: claims.length,
            separatorBuilder: (_, _) => const SizedBox(height: 10),
            itemBuilder: (context, index) {
              final claim = claims[index];
              final id = claim['id'] as int;
              final claimant = [
                claim['claimant_first_name'] as String? ?? 'TMC',
                claim['claimant_last_name'] as String? ?? 'member',
              ].join(' ');
              return Card(
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        children: [
                          CircleAvatar(
                            backgroundColor: Theme.of(context)
                                .colorScheme
                                .surfaceContainerHighest,
                            child: Icon(
                              Icons.verified_outlined,
                              color: Theme.of(context).colorScheme.primary,
                            ),
                          ),
                          const SizedBox(width: 11),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  claim['item_name'] as String? ?? 'Found item',
                                  style: const TextStyle(
                                    fontWeight: FontWeight.w800,
                                  ),
                                ),
                                Text(
                                  '${claim['category'] ?? 'Other'} · Claimed by $claimant',
                                  style: const TextStyle(fontSize: 12),
                                ),
                              ],
                            ),
                          ),
                        ],
                      ),
                      const SizedBox(height: 12),
                      if (claim['evidence_images'] is List &&
                          (claim['evidence_images'] as List).isNotEmpty) ...[
                        const Text(
                          'Claimant ownership photos',
                          style: TextStyle(fontWeight: FontWeight.w700),
                        ),
                        const SizedBox(height: 8),
                        Wrap(
                          spacing: 8,
                          runSpacing: 8,
                          children: [
                            for (final imageUrl
                                in (claim['evidence_images'] as List)
                                    .whereType<String>())
                              ClipRRect(
                                borderRadius: BorderRadius.circular(8),
                                child: Image.network(
                                  imageUrl,
                                  width: 76,
                                  height: 76,
                                  fit: BoxFit.cover,
                                ),
                              ),
                          ],
                        ),
                        const SizedBox(height: 12),
                      ],
                      const Text(
                        'TMC administrators approved the ownership evidence. Do you agree to hand over this item to the claimant?',
                      ),
                      const SizedBox(height: 14),
                      Row(
                        children: [
                          Expanded(
                            child: FilledButton.icon(
                              onPressed: () =>
                                  _decideAuthorClaim(id, 'approve'),
                              icon: const Icon(Icons.handshake_outlined),
                              label: const Text('Approve handover'),
                            ),
                          ),
                          const SizedBox(width: 9),
                          OutlinedButton(
                            onPressed: () => _decideAuthorClaim(id, 'reject'),
                            child: const Text('Decline'),
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

  Future<void> _openHandover(Map<String, dynamic> handover) async {
    final completed = handover['completed'] == true;
    final confirmed = handover['my_confirmation_at'] != null;
    final status = handover['status'] as String? ?? 'awaiting_admin';
    final canApprove = _canApproveHandover(handover);
    final receiptCode = handover['receipt_code'] as String?;
    await showDialog<void>(
      context: context,
      builder: (dialogContext) => AlertDialog(
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
            if (receiptCode != null) ...[
              const SizedBox(height: 12),
              const Text('Handover receipt'),
              const Text('16-digit receipt code'),
              SelectableText(
                receiptCode,
                style: Theme.of(context).textTheme.titleLarge,
              ),
            ],
            if (status == 'scheduled' || status == 'reschedule_requested') ...[
              const SizedBox(height: 8),
              Text(
                'Reschedule requests: ${handover['reschedule_attempts'] ?? 0} / ${handover['reschedule_limit'] ?? 2}',
              ),
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
          if (status == 'scheduled')
            OutlinedButton(
              onPressed: () async {
                Navigator.pop(dialogContext);
                await _requestHandoverReschedule(handover);
              },
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
                      'Verification code: $receiptCode',
                ),
              ),
              icon: const Icon(Icons.ios_share),
              label: const Text('Share receipt'),
            ),
        ],
      ),
    );
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
      setState(() => _handovers = _loadHandovers());
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
              pw.Text('VERIFICATION CODE'),
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
                'Present this receipt at the scheduled location. TMC staff will verify the code and confirm retrieval.',
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
    final date = TextEditingController();
    final reason = TextEditingController();
    final request = await showDialog<Map<String, String>>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: const Text('Request a handover reschedule'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            TextField(
              controller: date,
              keyboardType: TextInputType.datetime,
              decoration: const InputDecoration(
                labelText: 'Requested date',
                hintText: 'YYYY-MM-DD',
              ),
            ),
            TextField(
              controller: reason,
              maxLines: 3,
              decoration: const InputDecoration(
                labelText: 'Message to TMC administrators',
              ),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(dialogContext),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(dialogContext, {
              'requested_date': date.text.trim(),
              'reason': reason.text.trim(),
            }),
            child: const Text('Send request'),
          ),
        ],
      ),
    );
    date.dispose();
    reason.dispose();
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
      setState(() => _handovers = _loadHandovers());
    } on DioException catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(
          context,
        ).showSnackBar(SnackBar(content: Text(widget.api.errorMessage(error))));
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
            message: 'Claims and lost-item evidence will appear here after administrator approval and the required participant agreement.',
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
                        '\nOwner: ${ownerConfirmed ? 'approved' : 'waiting'} · Other participant: ${finderConfirmed ? 'approved' : 'waiting'}'
                        '\n$statusLabel${handover['scheduled_at'] is String ? ' · ${handover['scheduled_at']}' : ''}',
                      ),
                      isThreeLine: true,
                      trailing: const Icon(Icons.chevron_right),
                      onTap: completed ? null : () => _openHandover(handover),
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
      _message.clear();
      setState(() => _messages = _loadMessages());
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
                    onPressed: () => setState(() => _posts = _loadPosts()),
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
              return Column(
                children: [
                  for (final post in displayPosts)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 12),
                      child: _PostCard(
                        post: post,
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
                      ),
                    ),
                ],
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
  String _postSort = 'latest';
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
          'sort': _postSort,
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

  Future<void> _editProfile() async {
    final profile = _profile ?? const <String, dynamic>{};
    final firstName = TextEditingController(
      text: profile['first_name'] as String? ?? '',
    );
    final lastName = TextEditingController(
      text: profile['last_name'] as String? ?? '',
    );
    final profileStatus = TextEditingController(
      text: profile['profile_status'] as String? ?? '',
    );
    final bio = TextEditingController(text: profile['bio'] as String? ?? '');
    final currentAddress = TextEditingController(
      text: profile['current_address'] as String? ?? '',
    );
    final permanentAddress = TextEditingController(
      text: profile['permanent_address'] as String? ?? '',
    );
    final contactNumber = TextEditingController(
      text: profile['contact_number'] as String? ?? '',
    );
    final contactEmail = TextEditingController(
      text: profile['contact_email'] as String? ?? '',
    );
    XFile? picture;
    var saving = false;
    var profileSaved = false;
    String? error;

    await showDialog<void>(
      context: context,
      builder: (dialogContext) => StatefulBuilder(
        builder: (context, setDialogState) => AlertDialog(
          title: const Text('Edit profile'),
          content: SizedBox(
            width: 460,
            child: SingleChildScrollView(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  if (error != null)
                    Align(
                      alignment: Alignment.centerLeft,
                      child: Text(
                        error!,
                        style: TextStyle(
                          color: Theme.of(context).colorScheme.error,
                        ),
                      ),
                    ),
                  Align(
                    alignment: Alignment.centerLeft,
                    child: Padding(
                      padding: EdgeInsets.only(bottom: 8),
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
                    controller: firstName,
                    decoration: const InputDecoration(
                      labelText: 'First name *',
                    ),
                  ),
                  TextField(
                    controller: lastName,
                    decoration: const InputDecoration(labelText: 'Last name *'),
                  ),
                  TextField(
                    controller: profileStatus,
                    maxLength: 100,
                    decoration: const InputDecoration(labelText: 'Status'),
                  ),
                  TextField(
                    controller: bio,
                    maxLines: 3,
                    maxLength: 500,
                    decoration: const InputDecoration(labelText: 'Bio'),
                  ),
                  TextField(
                    controller: currentAddress,
                    decoration: const InputDecoration(
                      labelText: 'Current address',
                    ),
                  ),
                  TextField(
                    controller: permanentAddress,
                    decoration: const InputDecoration(
                      labelText: 'Permanent address',
                    ),
                  ),
                  TextField(
                    controller: contactNumber,
                    keyboardType: TextInputType.phone,
                    decoration: const InputDecoration(
                      labelText: 'Mobile number',
                    ),
                  ),
                  TextField(
                    controller: contactEmail,
                    keyboardType: TextInputType.emailAddress,
                    decoration: const InputDecoration(
                      labelText: 'Contact email',
                    ),
                  ),
                  Align(
                    alignment: Alignment.centerLeft,
                    child: TextButton.icon(
                      onPressed: saving
                          ? null
                          : () async {
                              final selected = await ImagePicker().pickImage(
                                source: ImageSource.gallery,
                                imageQuality: 85,
                              );
                              if (selected != null && dialogContext.mounted) {
                                setDialogState(() => picture = selected);
                              }
                            },
                      icon: const Icon(Icons.add_a_photo_outlined),
                      label: Text(
                        picture?.name ?? 'Add or change profile picture',
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),
          actions: [
            TextButton(
              onPressed: saving ? null : () => Navigator.pop(dialogContext),
              child: const Text('Cancel'),
            ),
            FilledButton(
              onPressed: saving
                  ? null
                  : () async {
                      if (firstName.text.trim().isEmpty ||
                          lastName.text.trim().isEmpty) {
                        setDialogState(
                          () => error = 'First and last name are required.',
                        );
                        return;
                      }
                      setDialogState(() {
                        saving = true;
                        error = null;
                      });
                      try {
                        final fields = <String, dynamic>{
                          'first_name': firstName.text.trim(),
                          'last_name': lastName.text.trim(),
                          'profile_status': profileStatus.text.trim(),
                          'bio': bio.text.trim(),
                          'current_address': currentAddress.text.trim(),
                          'permanent_address': permanentAddress.text.trim(),
                          'contact_number': contactNumber.text.trim(),
                          'contact_email': contactEmail.text.trim(),
                        };
                        final response = picture == null
                            ? await widget.api.dio.put<Map<String, dynamic>>(
                                '/profile',
                                data: fields,
                              )
                            : await _uploadProfilePicture(fields, picture!);
                        if (!mounted || !dialogContext.mounted) return;
                        setState(() => _profile = response.data);
                        profileSaved = true;
                        Navigator.pop(dialogContext);
                      } on DioException catch (exception) {
                        if (dialogContext.mounted) {
                          setDialogState(
                            () => error = widget.api.errorMessage(exception),
                          );
                        }
                      } on Exception {
                        if (dialogContext.mounted) {
                          setDialogState(
                            () => error = 'Could not read the selected picture. Choose it again and retry.',
                          );
                        }
                      } finally {
                        if (!profileSaved && dialogContext.mounted) {
                          setDialogState(() => saving = false);
                        }
                      }
                    },
              child: saving
                  ? const SizedBox.square(
                      dimension: 18,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : const Text('Save'),
            ),
          ],
        ),
      ),
    );
    firstName.dispose();
    lastName.dispose();
    profileStatus.dispose();
    bio.dispose();
    currentAddress.dispose();
    permanentAddress.dispose();
    contactNumber.dispose();
    contactEmail.dispose();
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
          tint: Color(0xFFB17B2C),
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
        const SizedBox(height: 8),
        DropdownButtonFormField<String>(
          initialValue: _postSort,
          decoration: const InputDecoration(labelText: 'Sort posts'),
          items: const [
            DropdownMenuItem(value: 'latest', child: Text('Most recent')),
            DropdownMenuItem(value: 'oldest', child: Text('Oldest first')),
          ],
          onChanged: (value) {
            if (value == null) return;
            setState(() => _postSort = value);
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
          for (final post in [
            for (final post in _myPosts)
              _applyReaction(post, _reactionOverrides),
          ])
            _PostCard(
              post: post,
              onTapAuthor: () {},
              onOwnerEdit: () => unawaited(_editPost(post)),
              onOwnerDelete: () => unawaited(_deletePost(post)),
              onRequestRetrieved: () => unawaited(_requestRetrieved(post)),
              onReport: () => unawaited(_reportPost(context, widget.api, post)),
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
