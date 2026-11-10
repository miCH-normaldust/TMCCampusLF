@extends('admin.layout')

@section('title', 'Handover History')

@section('content')
    <div class="page-heading handover-history-heading">
        <div><p class="eyebrow">HANDOVER OUTCOMES</p><h1>Handover History</h1><p class="muted">Completed and unsuccessful transactions, linked to their original campus posts.</p></div>
        <a class="button secondary" href="{{ route('admin.handovers.index') }}">View in-progress handovers</a>
    </div>

    <div class="history-summary">
        <span class="history-summary-icon" aria-hidden="true">⇄</span>
        <div><strong>{{ number_format($handovers->total()) }} {{ \Illuminate\Support\Str::plural('handover outcome', $handovers->total()) }}</strong><p>Every record links back to the original post and shows why the transaction ended.</p></div>
    </div>

    <section class="card">
        <form class="filter-form compact-filter" method="get" action="{{ route('admin.handovers.history') }}" data-auto-filter>
            <label>Status
                <select name="status">
                    <option value="" @selected($status === '')>All outcomes</option>
                    <option value="completed" @selected($status === 'completed')>Completed</option>
                    <option value="unsuccessful" @selected($status === 'unsuccessful')>Unsuccessful</option>
                </select>
            </label>
            <label>Type
                <select name="type">
                    <option value="" @selected($type === '')>All types</option>
                    <option value="lost" @selected($type === 'lost')>Lost</option>
                    <option value="found" @selected($type === 'found')>Found</option>
                </select>
            </label>
        </form>
    </section>

    <section class="handover-history-list" aria-label="Completed handovers">
        @forelse ($handovers as $handover)
            @php
                $claim = $handover->claimRequest;
                $sighting = $handover->sighting;
                $post = $claim?->post ?? $sighting?->post;
                $postAuthor = $post?->author;
                $otherParticipant = $claim?->claimant ?? $sighting?->user;
                $primaryName = trim(($postAuthor?->first_name ?? '').' '.($postAuthor?->last_name ?? '')) ?: 'Campus user';
                $otherName = trim(($otherParticipant?->first_name ?? '').' '.($otherParticipant?->last_name ?? '')) ?: 'Campus user';
            @endphp
            <a class="handover-history-card" href="{{ route('admin.handovers.history.show', $handover) }}">
                <span class="history-card-type {{ $post?->type }}" aria-hidden="true">{{ $post?->type === 'lost' ? '⌕' : '✦' }}</span>
                <span class="history-card-main">
                    <span class="history-card-topline"><strong>{{ $post?->item_name ?? 'Campus item' }}</strong><span class="status-tag {{ $post?->type }}">{{ ucfirst($post?->type ?? 'item') }}</span></span>
                    <span class="history-card-details">
                        <span><small>Post author</small>{{ $primaryName }}</span>
                        <span class="history-transfer" aria-label="handed over to">⇄</span>
                        <span><small>{{ $post?->type === 'lost' ? 'Finder / handler' : 'Verified claimant' }}</small>{{ $otherName }}</span>
                    </span>
                    <span class="history-card-footer"><span>{{ $handover->status === 'completed' ? '✓ Retrieved' : '× Unsuccessful' }}</span><span>◷ {{ $handover->status === 'completed' ? 'Completed' : 'Ended' }} {{ ($handover->completed_at ?? $handover->unsuccessful_at ?? $handover->updated_at)->format('M j, Y · g:i A') }}</span></span>
                </span>
                <span class="history-card-arrow" aria-hidden="true">→</span>
            </a>
        @empty
            <div class="card history-empty"><span aria-hidden="true">⇄</span><h2>No handover outcomes yet</h2><p>Completed and unsuccessful transactions will appear here.</p><a class="button secondary" href="{{ route('admin.handovers.index') }}">Go to handovers</a></div>
        @endforelse
    </section>

    <div class="pagination">{{ $handovers->links() }}</div>
@endsection
