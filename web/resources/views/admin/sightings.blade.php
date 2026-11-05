@extends('admin.layout')

@section('title', 'Lost-item evidence')

@section('content')
    <h1>Lost-item evidence</h1>
    @include('admin.partials.retrieval-requests', [
        'retrievalRequests' => $retrievalRequests,
        'heading' => 'Lost-item mark-as-retrieved requests',
        'description' => 'Review requests from owners who recovered their own lost item.',
    ])
    <section class="cards">
        <article class="card">
            <h2>Submitted evidence awaiting owner review</h2>
            <p class="muted">Owners review evidence first. Administrator decisions become available after an owner approves it.</p>
            @forelse ($awaitingOwnerReview as $sighting)
                <div class="proof-item">
                    <div class="proof-item-head">
                        <h3>{{ $sighting->post->item_name }}</h3>
                        <span class="muted">Submitted {{ $sighting->created_at->diffForHumans() }}</span>
                    </div>
                    <p class="muted">Owner: {{ $sighting->post->author->first_name }} · Evidence from {{ $sighting->user->first_name }}</p>
                    <p>{{ $sighting->evidence_text }}</p>
                    @if ($sighting->evidence_image)
                        @include('admin.partials.proof-image', [
                            'sighting' => $sighting,
                            'description' => 'Waiting for the post owner to review this photo.',
                        ])
                    @endif
                </div>
            @empty
                <p>No evidence is currently awaiting owner review.</p>
            @endforelse
        </article>
    </section>
    <section class="proof-list">
        @forelse ($sightings as $sighting)
            <article class="card proof-card">
                <div class="proof-item-head">
                    <h2>{{ $sighting->post->item_name }}</h2>
                    <span class="muted">Submitted {{ $sighting->created_at->diffForHumans() }}</span>
                </div>
                <p>{{ $sighting->post->description }}</p>
                <p class="muted">Owner: {{ $sighting->post->author->first_name }} · Evidence from {{ $sighting->user->first_name }}</p>
                <p class="proof-text">{{ $sighting->evidence_text }}</p>
                @if ($sighting->evidence_image)
                    @include('admin.partials.proof-image', [
                        'sighting' => $sighting,
                        'description' => 'Open the photo in full size before making a decision.',
                    ])
                @endif
                <form method="post" action="{{ route('admin.sightings.decide', $sighting) }}">
                    @csrf
                    <label for="sighting-reason-{{ $sighting->id }}">Decision reason (required)</label><br>
                    <textarea id="sighting-reason-{{ $sighting->id }}" name="reason" rows="3" maxlength="1000" required></textarea><br><br>
                    <button class="button" name="decision" value="approve" type="submit">Approve return</button>
                    <button class="button danger" name="decision" value="reject" type="submit" onclick="return confirm('Reject this evidence?')">Reject</button>
                </form>
            </article>
        @empty
            <article class="card">No lost-item evidence is waiting for review.</article>
        @endforelse
    </section>
    <div class="pagination">{{ $sightings->links() }}</div>

    <section class="section card">
        <h2>Previous lost-item evidence</h2>
        <p class="muted">Review past owner and administrator decisions, including rejected and approved submissions.</p>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Item</th><th>Submitted by</th><th>Owner decision</th><th>Administrator decision</th><th>Reason</th><th>Photo</th><th>Date</th></tr></thead>
                <tbody>
                @forelse ($proofHistory as $proof)
                    <tr>
                        <td>{{ $proof->post->item_name }}</td>
                        <td>{{ $proof->user->first_name }} {{ $proof->user->last_name }}</td>
                        <td>{{ ucfirst($proof->author_decision) }}</td>
                        <td>{{ ucfirst($proof->admin_decision) }}</td>
                        <td>{{ $proof->admin_reason ?? '—' }}</td>
                        <td>
                            @if ($proof->evidence_image)
                                @include('admin.partials.proof-image', ['sighting' => $proof])
                            @else
                                —
                            @endif
                        </td>
                        <td>{{ $proof->created_at->format('M j, Y g:i A') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="empty-cell">No previously reviewed evidence.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="pagination">{{ $proofHistory->links() }}</div>
    </section>

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
