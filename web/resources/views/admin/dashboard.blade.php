@extends('admin.layout')

@section('title', 'Dashboard')

@section('content')
    <div class="page-heading">
        <div>
            <p class="eyebrow">TMC CAMPUS LOST &amp; FOUND</p>
            <h1>Dashboard</h1>
            <p class="muted">Shortcuts and a quick view of items currently in circulation.</p>
        </div>
    </div>

    <section class="section" aria-labelledby="shortcuts-heading">
        <div class="section-heading">
            <div><p class="eyebrow">QUICK ACCESS</p><h2 id="shortcuts-heading">Dashboard shortcuts</h2></div>
        </div>
        <div class="shortcut-grid">
            <a class="shortcut-card" href="{{ route('admin.claims.index', ['status' => 'pending']) }}"><span class="shortcut-icon">✓</span><span><strong>Found Item Claims</strong><small>{{ $pendingClaims }} waiting for review</small></span><span class="shortcut-arrow">→</span></a>
            <a class="shortcut-card" href="{{ route('admin.sightings.index') }}"><span class="shortcut-icon">⌕</span><span><strong>Lost Item Proofs</strong><small>{{ $pendingSightings }} awaiting review</small></span><span class="shortcut-arrow">→</span></a>
            <a class="shortcut-card" href="{{ route('admin.reports.index') }}"><span class="shortcut-icon">⚑</span><span><strong>Reports</strong><small>{{ $pendingReports }} need attention</small></span><span class="shortcut-arrow">→</span></a>
            <a class="shortcut-card" href="{{ route('admin.handovers.index') }}"><span class="shortcut-icon">⇄</span><span><strong>Handovers</strong><small>{{ $pendingHandovers }} active codes</small></span><span class="shortcut-arrow">→</span></a>
        </div>
    </section>

    <section class="section card notification-panel" aria-labelledby="notifications-heading">
        <div class="section-heading">
            <div><p class="eyebrow">ADMIN INBOX</p><h2 id="notifications-heading">New notifications</h2></div>
            @if ($adminNotifications->isNotEmpty())<span class="pill">{{ $adminNotifications->count() }} unread</span>@endif
        </div>
        @forelse ($adminNotifications as $notification)
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
                @endif
                <form method="post" action="{{ route('admin.notifications.read', $notification->id) }}">
                    @csrf
                    <button class="icon-button" type="submit" aria-label="Mark notification as read" title="Mark as read">✓</button>
                </form>
            </article>
        @empty
            <div class="empty-state"><span aria-hidden="true">✓</span><strong>You’re all caught up.</strong><p>No new administrator notifications.</p></div>
        @endforelse
    </section>

    <section class="section card metrics-panel" aria-labelledby="metrics-heading">
        <div class="section-heading">
            <div><p class="eyebrow">LIVE INVENTORY</p><h2 id="metrics-heading">Lost vs. found items</h2><p class="muted">Share of active, claim-pending, and approved-for-meetup posts.</p></div>
            <span class="metric-total">{{ $totalActivePosts }}<small>items</small></span>
        </div>
        <div class="comparison-legend"><span><i class="legend-dot lost"></i>Lost · {{ $lostCount }}</span><span><i class="legend-dot found"></i>Found · {{ $foundCount }}</span></div>
        <div class="comparison-row">
            <div class="comparison-label"><strong>Lost</strong><span>{{ number_format($lostPercent, 1) }}%</span></div>
            <div class="progress-track" role="progressbar" aria-label="Lost items share" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $lostPercent }}"><span class="progress-fill lost-fill" style="width: {{ $lostPercent }}%"></span></div>
        </div>
        <div class="comparison-row">
            <div class="comparison-label"><strong>Found</strong><span>{{ number_format($foundPercent, 1) }}%</span></div>
            <div class="progress-track" role="progressbar" aria-label="Found items share" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $foundPercent }}"><span class="progress-fill found-fill" style="width: {{ $foundPercent }}%"></span></div>
        </div>
        <div class="metric-footnote">{{ $activePosts }} active posts · {{ $retrievedThisMonth }} items retrieved this month · {{ $newUsers }} new members in the last 30 days</div>
    </section>
@endsection
