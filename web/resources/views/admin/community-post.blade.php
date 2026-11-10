@extends('admin.layout')

@section('title', 'Post Management')

@section('content')
    <a class="button secondary" href="{{ route('admin.community-feed.index') }}">← Back to Community Feed</a>
    <section class="community-detail-heading section">
        <div>
            <p class="eyebrow">{{ strtoupper($post->type) }} POST #{{ $post->id }}</p>
            <h1>{{ $post->item_name }}</h1>
            <p>{{ $post->description }}</p>
            <p class="muted">Posted by {{ $post->author?->first_name }} {{ $post->author?->last_name }} · {{ $post->created_at->format('M j, Y g:i A') }} · {{ $post->location?->name ?? 'Campus' }}</p>
        </div>
        <span class="feed-status-pill status-{{ $post->status }}">{{ str_replace('_', ' ', ucfirst($post->status)) }}</span>
    </section>

    @if ($post->images->isNotEmpty())
        <div class="detail-photo-grid" aria-label="Post photos">
            @foreach ($post->images as $image)
                <img src="{{ \App\Support\PublicMediaUrl::forPath($image->path) }}" alt="{{ $image->alt_text }}">
            @endforeach
        </div>
    @endif

    <nav class="community-detail-tabs" aria-label="Post management sections">
        @if ($post->type === 'found')
            <a href="#claims">Found item claims ({{ $post->claimRequests->count() }})</a>
        @else
            <a href="#proofs">Lost item proofs ({{ $post->sightings->count() }})</a>
        @endif
        <a href="#handovers">Handovers ({{ $handovers->count() }})</a>
    </nav>

    @if ($post->type === 'found')
        <section id="claims" class="section" aria-labelledby="claims-heading">
            <div class="section-heading"><div><p class="eyebrow">CLAIM REVIEW</p><h2 id="claims-heading">Found item claims</h2></div></div>
            <div class="review-card-grid">
                @forelse ($post->claimRequests as $claim)
                    <article class="card review-card">
                        <div class="review-card-heading">
                            <div><h3>{{ $claim->claimant?->first_name }} {{ $claim->claimant?->last_name }}</h3><p class="muted">Attempt {{ $claim->attempt_no }} · Submitted {{ $claim->created_at->format('M j, Y g:i A') }}</p></div>
                            <span class="feed-status-pill status-{{ $claim->status }}">{{ ucfirst($claim->status) }}</span>
                        </div>
                        <div class="review-detail-grid">
                            <div><strong>Owner's action</strong><p>{{ ucfirst($claim->author_decision) }}</p></div>
                            <div><strong>Owner's reason</strong><p>{{ $claim->author_decision_reason ?: 'No reason provided.' }}</p></div>
                            <div><strong>Admin action</strong><p>{{ $claim->reviewed_at ? ucfirst($claim->status) : 'Awaiting administrator' }}</p></div>
                            <div><strong>Admin reason</strong><p>{{ $claim->review_reason ?: 'No reason provided.' }}</p></div>
                        </div>
                        @if ($claim->answers)
                            <div class="claim-answer-list">
                                @foreach ($claim->answers as $index => $answer)
                                    <p><strong>Response {{ $index + 1 }}:</strong> {{ $answer }}</p>
                                @endforeach
                            </div>
                        @endif
                        <div class="review-photo-grid" aria-label="Claim photos">
                            @forelse ($claim->evidenceImages as $image)
                                <a href="{{ \App\Support\PublicMediaUrl::forPath($image->path) }}" target="_blank" rel="noopener">
                                    <img src="{{ \App\Support\PublicMediaUrl::forPath($image->path) }}" alt="Claim evidence photo {{ $loop->iteration }}">
                                </a>
                            @empty
                                <p class="muted">No claim photos were attached.</p>
                            @endforelse
                        </div>
                        @if ($claim->status === 'pending' && $claim->author_decision === 'approved')
                            <a class="button" href="{{ route('admin.claims.show', $claim) }}">Review claim</a>
                        @elseif ($claim->reviewed_at)
                            <a class="button secondary" href="{{ route('admin.claims.show', $claim) }}">View admin decision</a>
                        @else
                            <p class="muted">Waiting for the item owner's decision before administrator review.</p>
                        @endif
                    </article>
                @empty
                    <div class="card community-empty"><strong>No claims for this post</strong><span>New found-item claims will appear here.</span></div>
                @endforelse
            </div>
        </section>
    @else
        <section id="proofs" class="section" aria-labelledby="proofs-heading">
            <div class="section-heading"><div><p class="eyebrow">PROOF REVIEW</p><h2 id="proofs-heading">Lost item proofs</h2></div></div>
            <div class="review-card-grid">
                @forelse ($post->sightings as $sighting)
                    <article class="card review-card">
                        <div class="review-card-heading">
                            <div><h3>{{ $sighting->user?->first_name }} {{ $sighting->user?->last_name }}</h3><p class="muted">Attempt {{ $sighting->attempt_number }} of {{ $sighting->attempt_total }} · Submitted {{ $sighting->created_at->format('M j, Y g:i A') }}</p></div>
                            <span class="feed-status-pill status-{{ $sighting->admin_decision }}">{{ $sighting->admin_decision === 'pending' ? 'Awaiting admin' : ucfirst($sighting->admin_decision) }}</span>
                        </div>
                        <p>{{ $sighting->evidence_text }}</p>
                        <div class="review-detail-grid">
                            <div><strong>Owner's action</strong><p>{{ ucfirst($sighting->author_decision) }}</p></div>
                            <div><strong>Owner's reason</strong><p>{{ $sighting->author_decision_reason ?: 'No reason provided.' }}</p></div>
                            <div><strong>Admin action</strong><p>{{ ucfirst($sighting->admin_decision) }}</p></div>
                            <div><strong>Admin reason</strong><p>{{ $sighting->admin_reason ?: 'No reason provided.' }}</p></div>
                        </div>
                        @if ($sighting->evidence_image)
                            <div class="review-photo-grid">
                                <a href="{{ \App\Support\PublicMediaUrl::forPath($sighting->evidence_image) }}" target="_blank" rel="noopener">
                                    <img src="{{ \App\Support\PublicMediaUrl::forPath($sighting->evidence_image) }}" alt="Proof photo from {{ $sighting->user?->first_name }} {{ $sighting->user?->last_name }}">
                                </a>
                            </div>
                        @endif
                        @if ($sighting->admin_decision === 'pending' && $sighting->author_decision === 'approved')
                            <form method="post" action="{{ route('admin.sightings.decide', $sighting) }}">
                                @csrf
                                <label>Administrator decision reason
                                    <textarea name="reason" rows="3" maxlength="1000" required></textarea>
                                </label>
                                <div class="review-action-buttons">
                                    <button class="button" name="decision" value="approve" type="submit">Approve proof</button>
                                    <button class="button danger" name="decision" value="reject" type="submit" onclick="return confirm('Reject this proof?')">Reject proof</button>
                                </div>
                            </form>
                        @endif
                    </article>
                @empty
                    <div class="card community-empty"><strong>No proofs for this post</strong><span>New lost-item proofs will appear here.</span></div>
                @endforelse
            </div>
        </section>
    @endif

    <section id="handovers" class="section" aria-labelledby="handovers-heading">
        <div class="section-heading"><div><p class="eyebrow">EXCHANGE COORDINATION</p><h2 id="handovers-heading">Handovers</h2></div></div>
        <div class="review-card-grid">
            @forelse ($handovers as $handover)
                @php
                    $claim = $handover->claimRequest;
                    $sighting = $handover->sighting;
                    $owner = $claim?->claimant ?? $post->author;
                    $participant = $claim?->post?->author ?? $sighting?->user;
                    $canManage = in_array($handover->status, ['awaiting_admin', 'awaiting_participants', 'awaiting_schedule', 'reschedule_requested'], true);
                @endphp
                <article class="card review-card">
                    <div class="review-card-heading"><div><h3>{{ $post->item_name }}</h3><p class="muted">Handover #{{ $handover->id }} · {{ $post->type === 'found' ? 'Claim' : 'Lost-item proof' }}</p></div><span class="feed-status-pill status-{{ $handover->status }}">{{ str_replace('_', ' ', ucfirst($handover->status)) }}</span></div>
                    <div class="review-detail-grid">
                        <div><strong>Owner / claimant</strong><p>{{ $owner?->first_name }} {{ $owner?->last_name }}</p></div>
                        <div><strong>Other participant</strong><p>{{ $participant?->first_name }} {{ $participant?->last_name }}</p></div>
                        <div><strong>Owner agreement</strong><p>{{ $handover->owner_confirmed_at?->format('M j, Y g:i A') ?? 'Awaiting participant' }}</p></div>
                        <div><strong>Other participant agreement</strong><p>{{ $handover->finder_confirmed_at?->format('M j, Y g:i A') ?? 'Awaiting participant' }}</p></div>
                        <div><strong>Schedule</strong><p>{{ $handover->scheduled_at?->format('M j, Y g:i A') ?? ($handover->requested_date ? $handover->requested_date->format('M j, Y').' requested' : 'Not scheduled') }}</p></div>
                        <div><strong>Place</strong><p>{{ $handover->location?->name ?? 'Not set' }}</p></div>
                    </div>
                    @if ($handover->status === 'reschedule_requested')
                        <p><strong>Reschedule reason:</strong> {{ $handover->reschedule_reason ?: 'No reason provided.' }}</p>
                    @endif
                    <div><strong>Admin action</strong>
                        @if ($canManage)
                            <p><a class="button" href="{{ route('admin.handovers.manage', $handover) }}">Manage handover</a></p>
                        @else
                            <p class="muted">{{ ucfirst(str_replace('_', ' ', $handover->status)) }} · no action required</p>
                        @endif
                    </div>
                </article>
            @empty
                <div class="card community-empty"><strong>No handovers for this post</strong><span>An approved claim or proof will create a handover here.</span></div>
            @endforelse
        </div>
    </section>
@endsection
