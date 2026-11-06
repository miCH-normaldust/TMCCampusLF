@extends('admin.layout')

@section('title', 'Lost-item evidence')

@section('content')
    <h1>Lost-item evidence</h1>
    @include('admin.partials.retrieval-requests', [
        'retrievalRequests' => $retrievalRequests,
        'heading' => 'Lost-item mark-as-retrieved requests',
        'description' => 'Review requests from owners who recovered their own lost item.',
    ])
    <h2>Lost-item proof submissions</h2>
    <p class="muted">All proofs for an origin post are grouped together. Owners review proofs before administrator decisions.</p>
    <section class="card">
        <form class="filter-form compact-filter" method="get" action="{{ route('admin.sightings.index') }}">
            <label>Status
                <select name="status">
                    <option value="" @selected($status === '')>All proofs</option>
                    <option value="pending" @selected($status === 'pending')>Pending</option>
                    <option value="approved" @selected($status === 'approved')>Approved</option>
                    <option value="rejected" @selected($status === 'rejected')>Rejected</option>
                </select>
            </label>
            <button class="button" type="submit">Filter</button>
            <a class="button secondary" href="{{ route('admin.sightings.index') }}">Clear</a>
        </form>
    </section>
    <section class="proof-list">
        @forelse ($proofPosts as $post)
            <article class="card proof-card proof-post-group" data-proof-group="{{ $post->id }}">
                <div class="proof-item-head">
                    <h2>{{ $post->item_name }}</h2>
                    <span class="muted">{{ $post->sightings->count() }} proof(s)</span>
                </div>
                <p>{{ $post->description }}</p>
                <p class="muted">Owner: {{ $post->author->first_name }} {{ $post->author->last_name }}</p>
                <p><a class="button secondary" href="{{ route('admin.community-feed.index', ['view' => 'posts', 'post_id' => $post->id]) }}">View original post</a></p>
                @foreach ($post->sightings as $sighting)
                    <div class="proof-item" data-sighting-id="{{ $sighting->id }}">
                        <div class="proof-item-head">
                            <h3>Evidence from {{ $sighting->user->first_name }} {{ $sighting->user->last_name }}</h3>
                            <span class="muted">Submitted {{ $sighting->created_at->diffForHumans() }}</span>
                        </div>
                        <p class="muted">
                            @if ($sighting->admin_decision === 'approved')
                                Approved by administrator
                            @elseif ($sighting->admin_decision === 'rejected' || $sighting->author_decision === 'rejected')
                                Rejected
                            @elseif ($sighting->author_decision === 'approved')
                                Owner approved · Awaiting administrator review
                            @else
                                Awaiting owner review
                            @endif
                        </p>
                        <p class="proof-text">{{ $sighting->evidence_text }}</p>
                        @if ($sighting->author_decision_reason)
                            <p><strong>Owner's decision reason:</strong> {{ $sighting->author_decision_reason }}</p>
                        @endif
                        @if ($sighting->evidence_image)
                            @include('admin.partials.proof-image', [
                                'sighting' => $sighting,
                                'description' => $sighting->author_decision === 'approved' && $sighting->admin_decision === 'pending'
                                    ? 'Open the photo in full size before making a decision.'
                                    : 'Open the evidence photo in full size.',
                            ])
                        @endif
                        @if ($sighting->admin_decision === 'pending' && $sighting->author_decision === 'approved')
                            <form method="post" action="{{ route('admin.sightings.decide', $sighting) }}">
                                @csrf
                                <label for="sighting-reason-{{ $sighting->id }}">Decision reason (required)</label><br>
                                <textarea id="sighting-reason-{{ $sighting->id }}" name="reason" rows="3" maxlength="1000" required></textarea><br><br>
                                <button class="button" name="decision" value="approve" type="submit">Approve return</button>
                                <button class="button danger" name="decision" value="reject" type="submit" onclick="return confirm('Reject this evidence?')">Reject</button>
                            </form>
                        @elseif ($sighting->admin_reason)
                            <p class="muted"><strong>Administrator's decision reason:</strong> {{ $sighting->admin_reason }}</p>
                        @endif
                    </div>
                @endforeach
            </article>
        @empty
            <article class="card">No lost-item proofs have been submitted.</article>
        @endforelse
    </section>
    <div class="pagination">{{ $proofPosts->links() }}</div>

    <div class="image-modal" id="proof-image-modal" role="dialog" aria-modal="true" aria-labelledby="proof-image-title" hidden>
        <div class="image-modal-panel">
            <div class="image-modal-head">
                <div>
                    <h2 id="proof-image-title">Evidence photo</h2>
                    <p class="image-modal-meta" id="proof-image-meta"></p>
                </div>
                <button class="button secondary" type="button" id="proof-image-close">Close</button>
            </div>
            <div class="image-modal-body">
                <img id="proof-image" src="" alt="Lost-item evidence photo">
            </div>
        </div>
    </div>

    <script>
        (() => {
            const modal = document.getElementById('proof-image-modal');
            if (!modal) return;
            const image = document.getElementById('proof-image');
            const title = document.getElementById('proof-image-title');
            const meta = document.getElementById('proof-image-meta');
            const closeButton = document.getElementById('proof-image-close');
            let lastFocused = null;

            const open = (button) => {
                if (!button.dataset.proofSrc) return;
                lastFocused = button;
                image.src = button.dataset.proofSrc;
                image.alt = 'Evidence photo for ' + (button.dataset.proofTitle || 'this item');
                title.textContent = button.dataset.proofTitle || 'Evidence photo';
                meta.textContent = button.dataset.proofMeta || '';
                modal.hidden = false;
                document.body.classList.add('image-modal-open');
                closeButton.focus();
            };

            const close = () => {
                modal.hidden = true;
                image.removeAttribute('src');
                document.body.classList.remove('image-modal-open');
                if (lastFocused) lastFocused.focus();
            };

            document.querySelectorAll('[data-proof-src]').forEach((button) => {
                button.addEventListener('click', () => open(button));
            });
            closeButton.addEventListener('click', close);
            modal.addEventListener('click', (event) => {
                if (event.target === modal) close();
            });
            document.addEventListener('keydown', (event) => {
                if (event.key === 'Escape' && !modal.hidden) close();
            });
        })();
    </script>
@endsection
