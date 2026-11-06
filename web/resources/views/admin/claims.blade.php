@extends('admin.layout')

@section('title', 'Found Item Claims')

@section('content')
    <div class="page-heading"><div><p class="eyebrow">REVIEW QUEUE</p><h1>Found Item Claims</h1><p class="muted">Review a claimant’s private answers against the item’s verification questions.</p></div></div>
    @include('admin.partials.retrieval-requests', [
        'retrievalRequests' => $retrievalRequests,
        'heading' => 'Found-item mark-as-retrieved requests',
        'description' => 'Review requests from owners of found-item posts.',
    ])
    <section class="card">
        <form class="filter-form compact-filter" method="get" action="{{ route('admin.claims.index') }}">
            <label>Status<select name="status"><option value="">All claims</option>@foreach (['pending', 'approved', 'rejected'] as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>@endforeach</select></label>
            <button class="button" type="submit">Filter</button>
            <a class="button secondary" href="{{ route('admin.claims.index') }}">Clear</a>
        </form>
        <div class="table-wrap section"><table>
            <thead><tr><th>Found item</th><th>Item owner</th><th>Claims for this post</th></tr></thead>
            <tbody>
            @forelse ($claimPosts as $post)
                <tr class="claim-post-row" data-claim-group="{{ $post->id }}">
                    <td>{{ $post->item_name }}<br><a class="button secondary" href="{{ route('admin.community-feed.index', ['view' => 'posts', 'post_id' => $post->id]) }}">View original post</a></td>
                    <td>{{ $post->author?->first_name }} {{ $post->author?->last_name }}</td>
                    <td>
                        <div class="review-submission-list">
                            @foreach ($post->claimRequests as $claim)
                                <div class="review-submission" data-claim-id="{{ $claim->id }}">
                                    <div class="review-submission-details">
                                        <strong>{{ $claim->claimant->first_name }} {{ $claim->claimant->last_name }}</strong>
                                        <span class="muted">{{ $claim->claimant->tmc_id_no }}</span>
                                        <span>Attempt {{ $claim->attempt_no }}</span>
                                        <span class="pill">
                                            @if ($claim->status === 'pending' && $claim->author_decision === 'pending')
                                                Awaiting owner
                                            @elseif ($claim->status === 'pending' && $claim->author_decision === 'approved')
                                                Approved by owner · Awaiting admin
                                            @else
                                                {{ ucfirst($claim->status) }}
                                            @endif
                                        </span>
                                        @if ($claim->author_decision_reason)
                                            <span><strong>Owner's reason:</strong> {{ $claim->author_decision_reason }}</span>
                                        @endif
                                        <span class="muted">{{ $claim->created_at->format('M j, Y g:i A') }}</span>
                                    </div>
                                    <a class="button secondary" href="{{ route('admin.claims.show', $claim) }}">{{ $claim->status === 'pending' && $claim->author_decision === 'approved' ? 'Review claim' : 'View claim' }}</a>
                                </div>
                            @endforeach
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="3" class="empty-cell">No claims match this status.</td></tr>
            @endforelse
            </tbody>
        </table></div>
        <div class="pagination">{{ $claimPosts->links() }}</div>
    </section>
@endsection
