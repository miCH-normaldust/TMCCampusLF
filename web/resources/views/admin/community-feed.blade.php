@extends('admin.layout')

@section('title', 'Community Feed')

@section('content')
    <section class="community-heading">
        <div>
            <p class="eyebrow">TMC CAMPUS LOST &amp; FOUND</p>
            <h1>Community Feed</h1>
            <p>Recent campus posts and community members, together in one place.</p>
        </div>
        <span class="community-heading-icon campuslf-brand-mark" aria-hidden="true">
            <span class="brand-lf">LF</span>
            <img src="{{ asset('images/tmc-logo.png') }}" alt="">
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

        <form class="filter-form feed-filter-form community-filter-form" method="get" action="{{ route('admin.community-feed.index') }}">
            <input type="hidden" name="view" value="{{ $view }}">
            <label class="feed-search-field">Search
                <span class="feed-search-input"><span aria-hidden="true">⌕</span><input name="search" value="{{ request('search') }}" placeholder="{{ $view === 'people' ? 'Name or email' : 'Item, description, or person' }}" aria-label="Search {{ $view }}"></span>
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
                <label>Order
                    <select name="sort">
                        <option value="latest" @selected(request('sort', 'latest') === 'latest')>Newest first</option>
                        <option value="oldest" @selected(request('sort') === 'oldest')>Oldest first</option>
                    </select>
                </label>
            @endif
            <div class="feed-filter-actions">
                <button class="button" type="submit">Apply filters</button>
                @if (request()->hasAny(['search', 'type', 'status', 'category_id', 'sort']))
                    <a class="button secondary" href="{{ route('admin.community-feed.index', ['view' => $view]) }}">Clear</a>
                @endif
            </div>
        </form>

        @if ($view === 'people')
            <div class="community-people-list">
                @forelse ($people as $person)
                    <article class="community-person-card">
                        <span class="community-person-avatar">{{ strtoupper(substr($person->first_name, 0, 1).substr($person->last_name, 0, 1)) }}</span>
                        <span class="community-person-copy">
                            <strong>{{ $person->last_name }}, {{ $person->first_name }}</strong>
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
                            <img class="community-post-image" src="{{ \App\Support\PublicMediaUrl::forPath($post->images->first()->path) }}" alt="{{ $post->images->first()->alt_text }}">
                        @else
                            <div class="community-post-image community-post-placeholder {{ $post->type }}" aria-hidden="true">{{ $post->type === 'lost' ? '⌕' : '✦' }}</div>
                        @endif
                        <div class="community-post-content">
                            <header class="community-post-author">
                                <span class="community-person-avatar">{{ strtoupper(substr($post->author?->first_name ?? '?', 0, 1).substr($post->author?->last_name ?? '', 0, 1)) }}</span>
                                <span><strong>{{ $post->author?->first_name }} {{ $post->author?->last_name }}</strong><small>{{ $post->created_at->diffForHumans() }}</small></span>
                                <details class="community-post-menu">
                                    <summary aria-label="Post menu">•••</summary>
                                    <div>Post #{{ $post->id }} · {{ ucfirst($post->type) }}</div>
                                </details>
                            </header>
                            <div class="community-post-tags">
                                <span class="status-tag {{ $post->type }}">{{ ucfirst($post->type) }}</span>
                                <span class="feed-status-pill status-{{ $post->status }}">{{ str_replace('_', ' ', ucfirst($post->status)) }}</span>
                                <span class="community-category">{{ $post->category?->name ?? 'Uncategorized' }}</span>
                            </div>
                            <h3>{{ $post->item_name }}</h3>
                            <p class="community-post-description">{{ $post->description }}</p>
                            <div class="community-post-meta">
                                <span>⌖ {{ $post->location?->name ?? 'Campus' }}</span>
                                <span>Event {{ $post->event_date?->format('M j, Y') ?? '—' }}</span>
                            </div>
                        </div>
                    </article>
                @empty
                    <div class="community-empty"><strong>No posts match these filters</strong><span>Try a different search or clear the filters.</span></div>
                @endforelse
            </div>
            <div class="feed-table-footer"><span>Showing {{ $posts->firstItem() ?? 0 }}–{{ $posts->lastItem() ?? 0 }} of {{ number_format($posts->total()) }} posts</span><div class="pagination">{{ $posts->links() }}</div></div>
        @endif
    </section>
@endsection
