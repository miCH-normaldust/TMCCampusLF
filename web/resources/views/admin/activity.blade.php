@extends('admin.layout')

@section('title', 'Activity')

@section('content')
    <div class="page-heading"><div><p class="eyebrow">HISTORY AND AUDIT</p><h1>Activity</h1><p class="muted">Review user actions and the history of claims, proofs, and handovers.</p></div></div>
    <nav class="activity-categories" aria-label="Activity categories">
        @foreach (['claims' => 'Found item claims', 'proofs' => 'Lost item proofs', 'handovers' => 'Handovers', 'users' => 'User activities'] as $key => $label)
            <a class="{{ $category === $key ? 'selected' : '' }}" href="{{ route('admin.activity.index', array_merge(request()->only(['from', 'to']), ['category' => $key])) }}">{{ $label }}</a>
        @endforeach
    </nav>

    <section class="card">
        <form class="filter-form" method="get" action="{{ route('admin.activity.index') }}" data-auto-filter>
            <input type="hidden" name="category" value="{{ $category }}">
            <label>From <input type="date" name="from" value="{{ request('from') }}"></label>
            <label>To <input type="date" name="to" value="{{ request('to') }}"></label>
            <a class="button" href="{{ route('admin.activity.export', request()->only(['category', 'from', 'to'])) }}">Export CSV</a>
        </form>

        @if ($category === 'users')
            <div class="table-wrap section"><table>
                <thead><tr><th>Account</th><th>Event</th><th>Time</th><th>IP address</th><th>Device</th></tr></thead>
                <tbody>
                @forelse ($activities as $activity)
                    <tr><td>{{ $activity->first_name }} {{ $activity->last_name }}<br>{{ $activity->email }}</td><td>{{ $activity->event }}</td><td>{{ $activity->created_at }}</td><td>{{ $activity->ip_address }}</td><td>{{ $activity->device }}</td></tr>
                @empty
                    <tr><td colspan="5">No user activities found.</td></tr>
                @endforelse
                </tbody>
            </table></div>
        @elseif ($category === 'claims')
            <div class="table-wrap section"><table>
                <thead><tr><th>Found item</th><th>Claimant and attempt</th><th>Owner action and reason</th><th>Admin action and reason</th><th>Submitted</th></tr></thead>
                <tbody>
                @forelse ($activities as $activity)
                    <tr>
                        <td><a href="{{ route('admin.community-feed.show', $activity->post) }}">{{ $activity->post?->item_name ?? 'Deleted post' }}</a></td>
                        <td>{{ $activity->claimant?->first_name }} {{ $activity->claimant?->last_name }}<br>Attempt {{ $activity->attempt_no }}</td>
                        <td>{{ ucfirst($activity->author_decision) }}<br>{{ $activity->author_decision_reason ?: 'No reason provided.' }}</td>
                        <td>{{ $activity->reviewed_at ? ucfirst($activity->status) : 'Awaiting admin' }}<br>{{ $activity->review_reason ?: 'No reason provided.' }}</td>
                        <td>{{ $activity->created_at->format('M j, Y g:i A') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5">No claim history found.</td></tr>
                @endforelse
                </tbody>
            </table></div>
        @elseif ($category === 'proofs')
            <div class="table-wrap section"><table>
                <thead><tr><th>Lost item</th><th>Submitter and attempt</th><th>Owner action and reason</th><th>Admin action and reason</th><th>Submitted</th></tr></thead>
                <tbody>
                @forelse ($activities as $activity)
                    <tr>
                        <td><a href="{{ route('admin.community-feed.show', $activity->post) }}">{{ $activity->post?->item_name ?? 'Deleted post' }}</a></td>
                        <td>{{ $activity->user?->first_name }} {{ $activity->user?->last_name }}<br>{{ $activity->created_at->format('M j, Y g:i A') }}</td>
                        <td>{{ ucfirst($activity->author_decision) }}<br>{{ $activity->author_decision_reason ?: 'No reason provided.' }}</td>
                        <td>{{ ucfirst($activity->admin_decision) }}<br>{{ $activity->admin_reason ?: 'No reason provided.' }}</td>
                        <td>{{ $activity->created_at->format('M j, Y g:i A') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5">No lost-item proof history found.</td></tr>
                @endforelse
                </tbody>
            </table></div>
        @else
            <div class="table-wrap section"><table>
                <thead><tr><th>Item and status</th><th>Participants</th><th>Agreements</th><th>Schedule and place</th><th>Updated</th></tr></thead>
                <tbody>
                @forelse ($activities as $activity)
                    @php
                        $claim = $activity->claimRequest;
                        $sighting = $activity->sighting;
                        $post = $claim?->post ?? $sighting?->post;
                        $owner = $claim?->claimant ?? $post?->author;
                        $participant = $claim?->post?->author ?? $sighting?->user;
                    @endphp
                    <tr>
                        <td>
                            @if ($post)<a href="{{ route('admin.community-feed.show', $post) }}">{{ $post->item_name }}</a><br>@endif
                            {{ str_replace('_', ' ', ucfirst($activity->status)) }}
                        </td>
                        <td>Owner: {{ $owner?->first_name }} {{ $owner?->last_name }}<br>Other: {{ $participant?->first_name }} {{ $participant?->last_name }}</td>
                        <td>Owner: {{ $activity->owner_confirmed_at?->format('M j, Y g:i A') ?? 'Awaiting' }}<br>Other: {{ $activity->finder_confirmed_at?->format('M j, Y g:i A') ?? 'Awaiting' }}</td>
                        <td>{{ $activity->scheduled_at?->format('M j, Y g:i A') ?? 'Not scheduled' }}<br>{{ $activity->location?->name ?? 'Place not set' }}</td>
                        <td>{{ $activity->updated_at?->format('M j, Y g:i A') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5">No handover history found.</td></tr>
                @endforelse
                </tbody>
            </table></div>
        @endif
        <div class="pagination">{{ $activities->links() }}</div>
    </section>
@endsection
