@extends('admin.layout')

@section('title', 'Community Feed')

@section('content')
    <section class="community-heading" style="--community-cover: url('{{ asset('images/tmc-campus-building.png') }}')">
        <div>
            <p class="eyebrow">TMC CAMPUS LOST &amp; FOUND</p>
            <h1>Community Feed</h1>
            <p>Recent campus posts and community members, together in one place.</p>
        </div>
        <span class="community-heading-icon brand-emblems" aria-hidden="true">
            <img class="tmc-brand-logo" src="{{ asset('images/tmc-logo.png') }}" alt="">
            <img class="campuslf-brand-logo" src="{{ asset('images/campuslf-logo.png') }}" alt="">
        </span>
    </section>

    <nav class="community-mode-tabs" aria-label="Community feed view">
        <a class="{{ $view === 'posts' ? 'selected' : '' }}" href="{{ route('admin.community-feed.index', ['view' => 'posts']) }}">Posts</a>
        <a class="{{ $view === 'people' ? 'selected' : '' }}" href="{{ route('admin.community-feed.index', ['view' => 'people']) }}">People</a>
    </nav>

    <section class="card community-browser">
        <div class="community-feed-heading">
            <div>
                <h2>{{ $view === 'people' ? 'Find people' : 'Browse community posts' }}</h2>
                <p>{{ $view === 'people' ? 'People are listed alphabetically by last name.' : 'Search, filter, and browse the latest campus listings.' }}</p>
            </div>
            <span class="feed-result-count">
                @if ($view === 'people')
                    {{ number_format($people->total()) }} {{ \Illuminate\Support\Str::plural('member', $people->total()) }}
                @else
                    {{ number_format($posts->total()) }} {{ \Illuminate\Support\Str::plural('post', $posts->total()) }}
                @endif
            </span>
        </div>

        <form id="community-feed-filters" class="filter-form feed-filter-form community-filter-form {{ $view === 'people' ? 'community-people-filter-form' : '' }}" method="get" action="{{ route('admin.community-feed.index') }}" data-auto-filter>
            <input type="hidden" name="view" value="{{ $view }}">
            <label class="feed-search-field">Search
                <span class="feed-search-input"><span aria-hidden="true">⌕</span><input type="search" name="search" value="{{ request('search') }}" placeholder="{{ $view === 'people' ? 'Name or email' : 'Item, description, or person' }}" aria-label="Search {{ $view }}"></span>
            </label>
            @if ($view === 'posts')
                <label>Kind
                    <select name="type">
                        <option value="">Lost and found</option>
                        <option value="lost" @selected(request('type') === 'lost')>Lost</option>
                        <option value="found" @selected(request('type') === 'found')>Found</option>
                    </select>
                </label>
                <label>Status
                    <select name="status">
                        <option value="">All statuses</option>
                        @foreach (['active', 'claim_pending', 'handover_pending', 'retrieved', 'hidden', 'archived'] as $status)
                            <option value="{{ $status }}" @selected(request('status') === $status)>{{ str_replace('_', ' ', ucfirst($status)) }}</option>
                        @endforeach
                    </select>
                </label>
                <label>Category
                    <select name="category_id">
                        <option value="">All categories</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}" @selected((string) request('category_id') === (string) $category->id)>{{ $category->name }}</option>
                        @endforeach
                    </select>
                </label>
            @endif
        </form>
        @if ($view === 'people')
            <div class="community-people-list">
                @forelse ($people as $person)
                    <article class="community-person-card">
                        <a class="community-profile-link" href="{{ route('admin.users.show', $person) }}" aria-label="View {{ $person->first_name }} {{ $person->last_name }} profile">
                            <span class="community-person-avatar">
                                @if ($person->profile_picture_path)
                                    <img src="{{ \App\Support\PublicMediaUrl::forPath($person->profile_picture_path) }}" alt="">
                                @else
                                    {{ strtoupper(substr($person->first_name, 0, 1).substr($person->last_name, 0, 1)) }}
                                @endif
                            </span>
                        </a>
                        <span class="community-person-copy">
                            <strong><a class="community-profile-name-link" href="{{ route('admin.users.show', $person) }}">{{ $person->last_name }}, {{ $person->first_name }}</a></strong>
                            <small>{{ $person->department ?: 'TMC community member' }} · {{ $person->email }}</small>
                        </span>
                        <span class="community-person-status status-{{ $person->status }}">{{ ucfirst($person->status) }}</span>
                    </article>
                @empty
                    <div class="community-empty"><strong>No people found</strong><span>Try a different name or email.</span></div>
                @endforelse
            </div>
            <div class="feed-table-footer"><span>Showing {{ $people->firstItem() ?? 0 }}–{{ $people->lastItem() ?? 0 }} of {{ number_format($people->total()) }} members</span><div class="pagination">{{ $people->links() }}</div></div>
        @else
            <div class="community-post-list">
                @forelse ($posts as $post)
                    <article class="community-post-card">
                        @if ($post->images->isNotEmpty())
                            <div class="community-post-slideshow" data-post-slideshow role="group" aria-label="{{ $post->images->count() }} post photos">
                                <div class="community-post-slides">
                                    @foreach ($post->images as $image)
                                        <div class="community-post-slide">
                                            <img src="{{ \App\Support\PublicMediaUrl::forPath($image->path) }}" alt="{{ $image->alt_text }}">
                                        </div>
                                    @endforeach
                                </div>
                                @if ($post->images->count() > 1)
                                    <button class="community-slide-control previous" type="button" aria-label="Previous photo">‹</button>
                                    <span class="community-slide-count" aria-live="polite">1 / {{ $post->images->count() }}</span>
                                    <button class="community-slide-control next" type="button" aria-label="Next photo">›</button>
                                @endif
                            </div>
                        @else
                            <div class="community-post-image community-post-placeholder {{ $post->type }}" aria-hidden="true">{{ $post->type === 'lost' ? '⌕' : '✦' }}</div>
                        @endif
                        <div class="community-post-content">
                            <header class="community-post-author">
                                @if ($post->author)
                                    <a class="community-profile-link" href="{{ route('admin.users.show', $post->author) }}" aria-label="View {{ $post->author->first_name }} {{ $post->author->last_name }} profile">
                                        <span class="community-person-avatar">
                                            @if ($post->author->profile_picture_path)
                                                <img src="{{ \App\Support\PublicMediaUrl::forPath($post->author->profile_picture_path) }}" alt="">
                                            @else
                                                {{ strtoupper(substr($post->author->first_name, 0, 1).substr($post->author->last_name, 0, 1)) }}
                                            @endif
                                        </span>
                                    </a>
                                    <span><strong><a class="community-profile-name-link" href="{{ route('admin.users.show', $post->author) }}">{{ $post->author->first_name }} {{ $post->author->last_name }}</a></strong><small>{{ $post->created_at->diffForHumans() }}</small></span>
                                @else
                                    <span class="community-person-avatar">?</span>
                                    <span><strong>Unknown user</strong><small>{{ $post->created_at->diffForHumans() }}</small></span>
                                @endif
                                <details class="community-post-menu">
                                    <summary aria-label="Post moderation options">•••</summary>
                                    <div class="community-post-menu-panel">
                                        <strong>Post #{{ $post->id }} · {{ ucfirst($post->type) }}</strong>
                                        <form method="post" action="{{ route('admin.community-feed.moderate', $post) }}">
                                            @csrf
                                            <label for="moderation-reason-{{ $post->id }}">Reason</label>
                                            <textarea id="moderation-reason-{{ $post->id }}" name="reason" rows="2" maxlength="500" required placeholder="Add a reason for this action"></textarea>
                                            <button type="submit" name="action" value="report">Report post</button>
                                            <button type="submit" name="action" value="warn">Warn author</button>
                                            <button class="danger" type="submit" name="action" value="delete" onclick="return confirm('Permanently delete this post and its images?')">Delete post</button>
                                        </form>
                                    </div>
                                </details>
                            </header>
                            <div class="community-post-tags">
                                <span class="status-tag {{ $post->type }}">{{ ucfirst($post->type) }}</span>
                                <span class="feed-status-pill status-{{ $post->status }}">{{ str_replace('_', ' ', ucfirst($post->status)) }}</span>
                                <span class="community-category">{{ $post->custom_category ?: ($post->category?->name ?? 'Uncategorized') }}</span>
                            </div>
                            <h3>{{ $post->item_name }}</h3>
                            <p class="community-post-description">{{ $post->description }}</p>
                            <div class="community-post-meta">
                                <span>⌖ {{ $post->custom_location ?: ($post->location?->name ?? 'Campus') }}</span>
                                <span>Event {{ $post->event_date?->format('M j, Y') ?? '—' }}</span>
                            </div>
                            <nav class="community-post-actions" aria-label="Post management">
                                @if ($post->type === 'found')
                                    <a class="review-pill" href="{{ route('admin.community-feed.show', $post) }}#claims">Found item claims · {{ $post->claim_requests_count }}</a>
                                @else
                                    <a class="review-pill" href="{{ route('admin.community-feed.show', $post) }}#proofs">Lost item proofs · {{ $post->sightings_count }}</a>
                                @endif
                                @if ($post->status === 'handover_pending' || $post->claim_requests_count > 0 || $post->sightings_count > 0)
                                    <a class="review-pill handover-pill" href="{{ route('admin.community-feed.show', $post) }}#handovers">Handovers</a>
                                @endif
                                <a class="post-detail-link" href="{{ route('admin.community-feed.show', $post) }}">Post details <span aria-hidden="true">→</span></a>
                            </nav>
                        </div>
                    </article>
                @empty
                    <div class="community-empty"><strong>No posts match these filters</strong><span>Try a different search or clear the filters.</span></div>
                @endforelse
            </div>
            <div class="feed-table-footer"><span>Showing {{ $posts->firstItem() ?? 0 }}–{{ $posts->lastItem() ?? 0 }} of {{ number_format($posts->total()) }} posts</span><div class="pagination">{{ $posts->links() }}</div></div>
        @endif
    </section>
    @if ($view !== 'people')
        <script>
            document.querySelectorAll('[data-post-slideshow]').forEach(slideshow => {
                const slides = slideshow.querySelector('.community-post-slides');
                const count = slides?.children.length ?? 0;
                const counter = slideshow.querySelector('.community-slide-count');
                let index = 0;
                const show = next => {
                    index = (next + count) % count;
                    slides.style.transform = `translateX(-${index * 100}%)`;
                    if (counter) counter.textContent = `${index + 1} / ${count}`;
                };
                slideshow.querySelector('.previous')?.addEventListener('click', () => show(index - 1));
                slideshow.querySelector('.next')?.addEventListener('click', () => show(index + 1));
            });
        </script>
    @endif
@endsection
