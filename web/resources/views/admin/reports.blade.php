@extends('admin.layout')

@section('title', 'Reported posts')

@section('content')
    <div class="page-heading">
        <div>
            <p class="eyebrow">MODERATION</p>
            <h1>Reported posts</h1>
            <p class="muted">Review member reports and take action on the reported post.</p>
        </div>
    </div>

    <section class="card section">
        <form class="filter-form compact-filter" method="get" action="{{ route('admin.reports.index') }}" data-auto-filter>
            <label>Report history
                <select name="status">
                    <option value="pending" @selected($status === 'pending')>Pending review</option>
                    <option value="dismissed" @selected($status === 'dismissed')>Dismissed</option>
                    <option value="actioned" @selected($status === 'actioned')>Actioned</option>
                    <option value="all" @selected($status === 'all')>All reports</option>
                </select>
            </label>
        </form>
    </section>

    <section class="reports-list">
        @forelse ($reports as $report)
            @php($post = $report->post)
            <article class="card report-card">
                <div class="report-card-head">
                    <div>
                        <span class="pill">{{ $report->created_at->format('M j, Y g:i A') }}</span>
                        <span class="pill">{{ ucfirst($report->status) }}{{ $report->action ? ' · '.ucfirst($report->action) : '' }}</span>
                        <h2>{{ $post?->item_name ?? 'Post no longer available' }}</h2>
                        <p class="muted">
                            Reported by {{ $report->reporter?->first_name ?? 'Unknown member' }}
                            @if ($post && $report->status === 'pending')
                                · Posted by {{ $post->author?->first_name ?? 'Unknown member' }}
                            @endif
                        </p>
                    </div>
                    @if ($post)
                        <a class="button secondary" href="{{ route('admin.community-feed.index', ['search' => $post->item_name]) }}">View in feed</a>
                    @endif
                </div>

                <div class="report-reason">
                    <strong>Reason:</strong> {{ $report->reason }}
                </div>

                @if ($post)
                    <form method="post" action="{{ route('admin.reports.decide', $report) }}">
                        @csrf
                        <label for="reason-{{ $report->id }}">Reason for action (required except dismiss)</label>
                        <textarea id="reason-{{ $report->id }}" name="reason" rows="2" maxlength="1000"></textarea>
                        <div class="action-buttons">
                            <button class="button secondary" name="action" value="dismiss" type="submit">Dismiss</button>
                            <button class="button" name="action" value="hide" type="submit">Hide</button>
                            <button class="button danger" name="action" value="delete" type="submit" onclick="return confirm('Permanently delete this post and its images?')">Delete</button>
                            <button class="button secondary" name="action" value="warn" type="submit">Warn author</button>
                            <button class="button danger" name="action" value="suspend" type="submit" onclick="return confirm('Suspend this author account?')">Suspend author</button>
                        </div>
                    </form>
                @else
                    <p class="muted">
                        {{ $report->admin_reason ?? ($post ? 'This report has already been reviewed.' : 'The post was removed after moderation.') }}
                    </p>
                @endif
            </article>
        @empty
            <article class="card">
                <p class="muted">There are no reports awaiting review.</p>
            </article>
        @endforelse
    </section>

    <div class="pagination">{{ $reports->links() }}</div>
@endsection
