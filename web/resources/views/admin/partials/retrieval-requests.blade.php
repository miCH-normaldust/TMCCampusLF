<section class="card section" id="retrieval-requests">
    <div class="section-heading">
        <div>
            <p class="eyebrow">POST OWNER REQUESTS</p>
            <h2>{{ $heading }}</h2>
            <p class="muted">{{ $description }}</p>
        </div>
        <span class="pill">{{ $retrievalRequests->count() }} pending</span>
    </div>
    @forelse ($retrievalRequests as $retrievalRequest)
        <article class="card section">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">REQUEST #{{ $retrievalRequest->id }}</p>
                    <h3>{{ $retrievalRequest->post->item_name }}</h3>
                </div>
                <span class="status-tag {{ $retrievalRequest->post->type }}">{{ ucfirst($retrievalRequest->post->type) }} item</span>
            </div>
            <p class="muted">
                Requested by {{ $retrievalRequest->user->first_name }} {{ $retrievalRequest->user->last_name }}
                · {{ $retrievalRequest->user->email }}
                · {{ $retrievalRequest->created_at->format('M j, Y · g:i A') }}
            </p>
            <p>{{ $retrievalRequest->reason ?: 'No additional details were provided.' }}</p>
            <form method="post" action="{{ route('admin.retrieval-requests.decide', $retrievalRequest) }}">
                @csrf
                <label for="retrieval-reason-{{ $retrievalRequest->id }}">Decision note (optional)</label>
                <textarea id="retrieval-reason-{{ $retrievalRequest->id }}" name="reason" rows="2" maxlength="1000"></textarea>
                <div class="inline-form">
                    <button class="button" name="decision" value="approve" type="submit"
                        onclick="return confirm('Approve this request and mark the post as retrieved?')">
                        Approve and mark retrieved
                    </button>
                    <button class="button danger" name="decision" value="reject" type="submit"
                        onclick="return confirm('Reject this retrieval request?')">
                        Reject
                    </button>
                </div>
            </form>
        </article>
    @empty
        <p class="muted">No mark-as-retrieved requests are waiting for review.</p>
    @endforelse
</section>
