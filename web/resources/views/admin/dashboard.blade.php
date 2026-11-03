@extends('admin.layout')

@section('title', 'Home')

@section('content')
    <div class="page-heading">
        <div>
            <p class="eyebrow">TMC CAMPUS LOST &amp; FOUND</p>
            <h1>Admin Home</h1>
            <p class="muted">A quick overview of CampusLF activity and the sections you use most.</p>
        </div>
    </div>

    <section class="home-overview-grid" aria-label="CampusLF overview">
        <a class="home-overview-card report-overview" href="{{ route('admin.reports.index') }}">
            <span class="home-overview-icon" aria-hidden="true">⚑</span>
            <span><small>Pending reports</small><strong>{{ number_format($pendingReports) }}</strong></span>
            <span class="home-overview-arrow" aria-hidden="true">→</span>
        </a>
        <a class="home-overview-card claim-overview" href="{{ route('admin.claims.index') }}">
            <span class="home-overview-icon" aria-hidden="true">✓</span>
            <span><small>Found item claims</small><strong>{{ number_format($pendingClaims) }}</strong></span>
            <span class="home-overview-arrow" aria-hidden="true">→</span>
        </a>
        <a class="home-overview-card found-post-overview" href="{{ route('admin.community-feed.index', ['type' => 'found']) }}">
            <span class="home-overview-icon" aria-hidden="true">⌕</span>
            <span><small>Found item posts</small><strong>{{ number_format($foundPosts) }}</strong></span>
            <span class="home-overview-arrow" aria-hidden="true">→</span>
        </a>
        <a class="home-overview-card proof-overview" href="{{ route('admin.sightings.index') }}">
            <span class="home-overview-icon" aria-hidden="true">⌕</span>
            <span><small>Lost item proofs</small><strong>{{ number_format($pendingSightings) }}</strong></span>
            <span class="home-overview-arrow" aria-hidden="true">→</span>
        </a>
        <a class="home-overview-card handover-overview" href="{{ route('admin.handovers.index') }}">
            <span class="home-overview-icon" aria-hidden="true">⇄</span>
            <span><small>Handover confirmations</small><strong>{{ number_format($pendingHandovers) }}</strong></span>
            <span class="home-overview-arrow" aria-hidden="true">→</span>
        </a>
    </section>

    <section class="section" aria-labelledby="community-feed-shortcut-heading">
        <div class="section-heading">
            <div>
                <p class="eyebrow">BROWSE LISTINGS</p>
                <h2 id="community-feed-shortcut-heading">Community Feed</h2>
                <p class="muted">Search and review campus lost and found posts in a dedicated view.</p>
            </div>
            <a class="button" href="{{ route('admin.community-feed.index') }}">Open Community Feed <span aria-hidden="true">→</span></a>
        </div>
    </section>

    <section class="section" aria-labelledby="feed-shortcuts-heading">
        <div class="section-heading">
            <div>
                <p class="eyebrow">QUICK ACCESS</p>
                <h2 id="feed-shortcuts-heading">Admin destinations</h2>
                <p class="muted">Open users, reports, activity, and feedback.</p>
            </div>
        </div>
        <div class="shortcut-grid">
            @foreach ($shortcuts as $shortcut)
                <a class="shortcut-card" href="{{ route($shortcut['route']) }}">
                    <span class="shortcut-icon">{{ $shortcut['icon'] }}</span>
                    <span>
                        <strong>{{ $shortcut['label'] }}</strong>
                        <small>{{ $shortcut['pending'] !== null ? number_format($shortcut['pending']).' awaiting attention' : 'Open this admin section' }}</small>
                        <span class="shortcut-usage">{{ $shortcut['description'] }}</span>
                    </span>
                    <span class="shortcut-arrow">→</span>
                </a>
            @endforeach
        </div>
    </section>

    @if ($adminNotifications->isNotEmpty())
        <section class="section card notification-panel" aria-labelledby="notifications-heading">
            <div class="section-heading">
                <div><p class="eyebrow">ADMIN INBOX</p><h2 id="notifications-heading">New notifications</h2></div>
                <span class="pill">{{ $adminNotifications->count() }} unread</span>
            </div>
            @foreach ($adminNotifications as $notification)
                <article class="notification-row">
                    <span class="notification-dot" aria-hidden="true"></span>
                    <div class="notification-copy">
                        <strong>{{ $notification->data['title'] ?? 'CampusLF update' }}</strong>
                        <p>{{ $notification->data['body'] ?? 'There is a new item for administrator review.' }}</p>
                        <small>{{ $notification->created_at->diffForHumans() }}</small>
                    </div>
                    @if (($notification->data['feedback_id'] ?? null))
                        <a class="button secondary" href="{{ route('admin.feedback.index') }}">View feedback</a>
                    @elseif (($notification->data['sighting_id'] ?? null))
                        <a class="button secondary" href="{{ route('admin.sightings.index') }}">Review proof</a>
                    @elseif (($notification->data['retrieval_request_id'] ?? null))
                        @if (($notification->data['post_type'] ?? 'lost') === 'found')
                            <a class="button secondary" href="{{ route('admin.claims.index') }}#retrieval-requests">Review request</a>
                        @else
                            <a class="button secondary" href="{{ route('admin.sightings.index') }}#retrieval-requests">Review request</a>
                        @endif
                    @endif
                    <form method="post" action="{{ route('admin.notifications.read', $notification->id) }}">
                        @csrf
                        <button class="icon-button" type="submit" aria-label="Mark notification as read" title="Mark as read">✓</button>
                    </form>
                </article>
            @endforeach
        </section>
    @endif
@endsection
