@extends('admin.layout')

@section('title', 'Dashboard')

@section('content')
    @php
        $activityValues = $dailyActivity->values()->all();
        $activityMax = max(1, max(array_map(fn ($day) => $day['lost'] + $day['found'], $activityValues)));
        $activityColors = ['lost' => '#9AC9E6', 'found' => '#3E5663'];
        $engagementColors = ['reports' => '#D2A84F', 'proofs' => '#C9E9FF', 'claims' => '#3E5663'];
        $chartPoints = static function (string $type) use ($activityValues, $activityMax): string {
            $lastIndex = max(1, count($activityValues) - 1);
            return collect($activityValues)->map(function ($day, $index) use ($type, $activityMax, $lastIndex): string {
                $x = (int) round($index * 1000 / $lastIndex);
                $y = (int) round(225 - ($day[$type] / $activityMax * 190));
                return "{$x},{$y}";
            })->implode(' ');
        };
        $engagementMax = max(1, max(array_merge(...array_values($engagement))));
        $notificationGroups = ['Evidence' => 0, 'Claims' => 0, 'Handovers' => 0, 'Other' => 0];
        foreach ($adminNotifications as $notification) {
            $type = strtolower($notification->type);
            $group = str_contains($type, 'sighting') || str_contains($type, 'evidence')
                ? 'Evidence'
                : (str_contains($type, 'claim')
                    ? 'Claims'
                    : (str_contains($type, 'handover') || str_contains($type, 'reschedule')
                        ? 'Handovers'
                        : 'Other'));
            $notificationGroups[$group]++;
        }
        $notificationTotal = array_sum($notificationGroups);
        $notificationColors = [
            'Evidence' => '#9AC9E6',
            'Claims' => '#3E5663',
            'Handovers' => '#D2A84F',
            'Other' => '#C9E9FF',
        ];
        $notificationGradient = 'conic-gradient(#E9DFCF 0 100%)';
        if ($notificationTotal > 0) {
            $position = 0;
            $stops = [];
            foreach ($notificationGroups as $group => $count) {
                $nextPosition = $position + ($count / $notificationTotal * 100);
                $stops[] = "{$notificationColors[$group]} {$position}% {$nextPosition}%";
                $position = $nextPosition;
            }
            $notificationGradient = 'conic-gradient('.implode(', ', $stops).')';
        }
    @endphp

    <div class="dashboard-welcome">
        <div>
            <p class="eyebrow">CAMPUS LOST &amp; FOUND</p>
            <h1>Dashboard</h1>
            <p class="muted">A clear view of campus activity and the items that need your attention.</p>
        </div>
        <span class="dashboard-date">{{ now()->format('l, M j, Y') }}</span>
    </div>

    <section class="dashboard-metric-grid" aria-label="CampusLF overview">
        <a class="dashboard-metric-card lost-metric" href="{{ route('admin.community-feed.index', ['type' => 'lost']) }}">
            <span class="dashboard-metric-icon" aria-hidden="true">⌕</span>
            <span><small>Lost posts</small><strong>{{ number_format($lostPosts) }}</strong></span>
            <span class="dashboard-metric-arrow" aria-hidden="true">→</span>
        </a>
        <a class="dashboard-metric-card found-metric" href="{{ route('admin.community-feed.index', ['type' => 'found']) }}">
            <span class="dashboard-metric-icon" aria-hidden="true">✦</span>
            <span><small>Found items</small><strong>{{ number_format($foundPosts) }}</strong></span>
            <span class="dashboard-metric-arrow" aria-hidden="true">→</span>
        </a>
        <a class="dashboard-metric-card users-metric" href="{{ route('admin.users.index') }}">
            <span class="dashboard-metric-icon" aria-hidden="true">♙</span>
            <span><small>Users</small><strong>{{ number_format($usersCount) }}</strong></span>
            <span class="dashboard-metric-arrow" aria-hidden="true">→</span>
        </a>
        <a class="dashboard-metric-card solved-metric" href="{{ route('admin.community-feed.index', ['status' => 'retrieved']) }}">
            <span class="dashboard-metric-icon" aria-hidden="true">✓</span>
            <span><small>Solved</small><strong>{{ number_format($solvedPosts) }}</strong></span>
            <span class="dashboard-metric-arrow" aria-hidden="true">→</span>
        </a>
        <a class="dashboard-metric-card report-metric" href="{{ route('admin.reports.index') }}">
            <span class="dashboard-metric-icon" aria-hidden="true">⚑</span>
            <span><small>Pending reports</small><strong>{{ number_format($pendingReports) }}</strong></span>
            <span class="dashboard-metric-arrow" aria-hidden="true">→</span>
        </a>
        <a class="dashboard-metric-card feedback-metric" href="{{ route('admin.feedback.index') }}">
            <span class="dashboard-metric-icon" aria-hidden="true">✉</span>
            <span><small>Feedback</small><strong>{{ number_format($pendingFeedback) }} <small>awaiting</small></strong></span>
            <span class="dashboard-metric-arrow" aria-hidden="true">→</span>
        </a>
    </section>

    <section class="dashboard-analytics-grid" aria-label="CampusLF analytics">
        <article class="dashboard-panel activity-chart-panel">
            <header class="dashboard-panel-heading">
                <div>
                    <p class="eyebrow">POST TRENDS</p>
                    <h2>CampusLF Activity: Last 30 Days</h2>
                </div>
                <span class="dashboard-chart-period">30 days</span>
            </header>
            <div class="dashboard-chart-legend">
                <span><i style="background:{{ $activityColors['lost'] }}"></i>Lost posts</span>
                <span><i style="background:{{ $activityColors['found'] }}"></i>Found posts</span>
            </div>
            <div class="activity-chart">
                <div class="activity-y-labels" aria-hidden="true">
                    <span>{{ $activityMax }}</span><span>{{ (int) round($activityMax * .75) }}</span>
                    <span>{{ (int) round($activityMax * .5) }}</span><span>{{ (int) round($activityMax * .25) }}</span><span>0</span>
                </div>
                <svg viewBox="0 0 1000 250" preserveAspectRatio="none" role="img" aria-label="Daily lost and found post counts over the last 30 days">
                    @foreach ([35, 82.5, 130, 177.5, 225] as $y)
                        <line x1="0" y1="{{ $y }}" x2="1000" y2="{{ $y }}" class="chart-grid-line"/>
                    @endforeach
                    <polyline points="{{ $chartPoints('lost') }}" class="chart-line" style="stroke:{{ $activityColors['lost'] }}"/>
                    <polyline points="{{ $chartPoints('found') }}" class="chart-line" style="stroke:{{ $activityColors['found'] }}"/>
                </svg>
            </div>
            <div class="activity-x-labels">
                @foreach ($dailyActivity->values() as $index => $day)
                    @if (in_array($index, [0, 7, 14, 21, 29], true))
                        <span>{{ $day['label'] }}</span>
                    @endif
                @endforeach
            </div>
        </article>

        <article class="dashboard-panel engagement-panel">
            <header class="dashboard-panel-heading">
                <div>
                    <p class="eyebrow">COMMUNITY PARTICIPATION</p>
                    <h2>User Engagement &amp; Reports</h2>
                </div>
                <span class="dashboard-chart-period">30 days</span>
            </header>
            <div class="dashboard-chart-legend">
                <span><i style="background:{{ $engagementColors['reports'] }}"></i>Reports</span>
                <span><i style="background:{{ $engagementColors['proofs'] }}"></i>Proofs</span>
                <span><i style="background:{{ $engagementColors['claims'] }}"></i>Claims</span>
            </div>
            <div class="engagement-chart" role="img" aria-label="Reports, proofs and claims submitted by user type over the last 30 days">
                @foreach (['student' => 'Students', 'staff' => 'Staff', 'instructor' => 'Instructors'] as $type => $label)
                    <div class="engagement-group">
                        <div class="engagement-bars">
                            @foreach (['reports' => 'Reports', 'proofs' => 'Proofs', 'claims' => 'Claims'] as $series => $seriesLabel)
                                @php($height = $engagement[$series][$type] > 0 ? max(4, $engagement[$series][$type] / $engagementMax * 100) : 2)
                                <span class="engagement-bar" style="height:{{ $height }}%;background:{{ $engagementColors[$series] }}" title="{{ $seriesLabel }}: {{ $engagement[$series][$type] }}"></span>
                            @endforeach
                        </div>
                        <small>{{ $label }}</small>
                    </div>
                @endforeach
            </div>
            <p class="engagement-chart-note">Submitted in the last 30 days</p>
        </article>
    </section>

    <section class="dashboard-quick-access" aria-labelledby="feed-shortcuts-heading">
        <header class="dashboard-section-heading">
            <div>
                <p class="eyebrow">QUICK ACCESS</p>
                <h2 id="feed-shortcuts-heading">Admin destinations</h2>
            </div>
            <a class="dashboard-text-link" href="{{ route('admin.community-feed.index') }}">Open Community Feed <span aria-hidden="true">→</span></a>
        </header>
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

    <section class="dashboard-bottom-grid">
        <section class="dashboard-panel notification-panel" aria-labelledby="notifications-heading">
            <header class="dashboard-panel-heading">
                <div><p class="eyebrow">ADMIN INBOX</p><h2 id="notifications-heading">New notifications</h2></div>
                <span class="pill">{{ $adminNotifications->count() }} unread</span>
            </header>
            @forelse ($adminNotifications as $notification)
                <article class="notification-row">
                    <span class="notification-dot" aria-hidden="true"></span>
                    <div class="notification-copy">
                        <strong>{{ $notification->data['title'] ?? 'CampusLF update' }}</strong>
                        <p>{{ $notification->data['body'] ?? 'There is a new item for administrator review.' }}</p>
                        <small>{{ $notification->created_at->diffForHumans() }}</small>
                    </div>
                    @if (($notification->data['feedback_id'] ?? null))
                        <a class="button secondary" href="{{ route('admin.feedback.index') }}">Review</a>
                    @elseif (($notification->data['sighting_id'] ?? null))
                        <a class="button secondary" href="{{ route('admin.sightings.index') }}">Review</a>
                    @elseif (($notification->data['retrieval_request_id'] ?? null))
                        <a class="button secondary" href="{{ route(($notification->data['post_type'] ?? 'lost') === 'found' ? 'admin.claims.index' : 'admin.sightings.index') }}">Review</a>
                    @endif
                    <form method="post" action="{{ route('admin.notifications.read', $notification->id) }}">
                        @csrf
                        <button class="icon-button" type="submit" aria-label="Mark notification as read" title="Mark as read">✓</button>
                    </form>
                </article>
            @empty
                <p class="dashboard-empty-note">You’re all caught up. New admin notifications will appear here.</p>
            @endforelse
        </section>

        <section class="dashboard-panel notification-types-panel" aria-label="Notification types">
            <header class="dashboard-panel-heading">
                <div><p class="eyebrow">INBOX SUMMARY</p><h2>Notification Types</h2></div>
            </header>
            <div class="notification-types-content">
                <div class="notification-donut" style="background:{{ $notificationGradient }}" role="img" aria-label="{{ $notificationTotal }} unread notifications by type">
                    <span>{{ $notificationTotal }}</span>
                </div>
                <ul class="notification-type-legend">
                    @foreach ($notificationGroups as $group => $count)
                        <li><i style="background:{{ $notificationColors[$group] }}"></i><span>{{ $group }}</span><strong>{{ $count }}</strong></li>
                    @endforeach
                </ul>
            </div>
        </section>
    </section>
@endsection
