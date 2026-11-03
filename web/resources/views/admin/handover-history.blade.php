@extends('admin.layout')

@section('title', 'Handover History')

@section('content')
    <div class="page-heading handover-history-heading">
        <div><p class="eyebrow">COMPLETED RETURNS</p><h1>Handover History</h1><p class="muted">A record of item exchanges confirmed directly by both participants after the required approvals.</p></div>
        <a class="button secondary" href="{{ route('admin.handovers.index') }}">View in-progress handovers</a>
    </div>

    <div class="history-summary">
        <span class="history-summary-icon" aria-hidden="true">⇄</span>
        <div><strong>{{ number_format($handovers->total()) }} completed {{ \Illuminate\Support\Str::plural('handover', $handovers->total()) }}</strong><p>Every record links back to the original post and both participants’ confirmation times.</p></div>
    </div>

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
                    <span class="history-card-footer"><span>✓ Both participants confirmed</span><span>◷ Completed {{ $handover->updated_at->format('M j, Y · g:i A') }}</span></span>
                </span>
                <span class="history-card-arrow" aria-hidden="true">→</span>
            </a>
        @empty
            <div class="card history-empty"><span aria-hidden="true">⇄</span><h2>No completed handovers yet</h2><p>Once both participants confirm an approved exchange in the app, its record will appear here.</p><a class="button secondary" href="{{ route('admin.handovers.index') }}">Go to handovers</a></div>
        @endforelse
    </section>

    <div class="pagination">{{ $handovers->links() }}</div>
@endsection
