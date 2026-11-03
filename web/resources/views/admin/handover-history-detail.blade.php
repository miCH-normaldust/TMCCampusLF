@extends('admin.layout')

@section('title', 'Handover Details')

@section('content')
    @php
        $claim = $handover->claimRequest;
        $sighting = $handover->sighting;
        $post = $claim?->post ?? $sighting?->post;
        $postAuthor = $post?->author;
        $otherParticipant = $claim?->claimant ?? $sighting?->user;
        $postAuthorRole = $post?->type === 'lost' ? 'Item owner / post author' : 'Finder / post author';
        $otherRole = $post?->type === 'lost' ? 'Finder / item handler' : 'Verified claimant';
    @endphp
    <a class="button secondary history-back-link" href="{{ route('admin.handovers.history') }}">← Back to history</a>
    <div class="page-heading history-detail-heading"><div><p class="eyebrow">COMPLETED EXCHANGE · #{{ $handover->id }}</p><h1>{{ $post?->item_name ?? 'Campus item' }}</h1><p class="muted">Handover record and original listing details.</p></div><span class="history-complete-badge"><span aria-hidden="true">✓</span> Both participants confirmed</span></div>

    <section class="history-detail-layout">
        <article class="card origin-post-card">
            <div class="origin-post-header"><div><p class="eyebrow">ORIGINAL CAMPUSLF POST</p><h2>Item listing</h2></div><span class="status-tag {{ $post?->type }}">{{ ucfirst($post?->type ?? 'item') }}</span></div>
            @if ($post?->images?->isNotEmpty())
                <div class="origin-post-images">
                    @foreach ($post->images as $image)
                        <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($image->path) }}" alt="{{ $image->alt_text ?: $post->item_name }}" loading="lazy">
                    @endforeach
                </div>
            @endif
            <h3>{{ $post?->item_name ?? 'Listing no longer available' }}</h3>
            @if ($post?->description)<p class="origin-post-description">{{ $post->description }}</p>@endif
            <div class="origin-post-meta">
                <span><small>Category</small>{{ $post?->category?->name ?? '—' }}</span>
                <span><small>Listed area</small>{{ $post?->location?->name ?? '—' }}</span>
                <span><small>Event date</small>{{ $post?->event_date?->format('M j, Y') ?? '—' }}</span>
                <span><small>Post status</small>{{ str_replace('_', ' ', ucfirst($post?->status ?? 'unavailable')) }}</span>
            </div>
        </article>

        <aside class="history-detail-aside">
            <section class="card exchange-participants">
                <p class="eyebrow">PEOPLE IN THE EXCHANGE</p>
                <div class="participant-row"><span class="participant-avatar">{{ strtoupper(substr($postAuthor?->first_name ?? 'U', 0, 1)) }}</span><span><small>{{ $postAuthorRole }}</small><strong>{{ trim(($postAuthor?->first_name ?? '').' '.($postAuthor?->last_name ?? '')) ?: 'Campus user' }}</strong></span></div>
                <div class="participant-connector" aria-hidden="true"><span>⇄</span></div>
                <div class="participant-row"><span class="participant-avatar alt">{{ strtoupper(substr($otherParticipant?->first_name ?? 'U', 0, 1)) }}</span><span><small>{{ $otherRole }}</small><strong>{{ trim(($otherParticipant?->first_name ?? '').' '.($otherParticipant?->last_name ?? '')) ?: 'Campus user' }}</strong></span></div>
            </section>

            <section class="card exchange-timeline">
                <p class="eyebrow">HANDOVER DETAILS</p>
                <div class="timeline-detail"><span class="timeline-icon">◷</span><span><small>Owner confirmation</small><strong>{{ $handover->owner_confirmed_at->format('l, F j, Y') }}</strong><small>{{ $handover->owner_confirmed_at->format('g:i A') }}</small></span></div>
                <div class="timeline-detail"><span class="timeline-icon">✓</span><span><small>Finder / handler confirmation</small><strong>{{ $handover->finder_confirmed_at->format('l, F j, Y') }}</strong><small>{{ $handover->finder_confirmed_at->format('g:i A') }}</small></span></div>
                <div class="verified-by-note">Both authorized participants confirmed the exchange in CampusLF.</div>
            </section>
        </aside>
    </section>
@endsection
