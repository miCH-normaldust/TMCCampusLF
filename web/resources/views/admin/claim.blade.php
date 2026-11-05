@extends('admin.layout')

@section('title', 'Review claim')

@section('content')
    <a class="button secondary" href="{{ route('admin.dashboard') }}">Back to dashboard</a>
    <h1 class="section">Review claim: {{ $claim->post->item_name }}</h1>
    <p><a class="button secondary" href="{{ route('admin.community-feed.index', ['view' => 'posts', 'post_id' => $claim->post_id]) }}">View original post</a></p>
    <section class="cards">
        <article class="card">
            <h2>Finder's verification questions</h2>
            @foreach ($claim->post->verificationQuestions as $index => $question)
                <p><strong>{{ $index + 1 }}. {{ $question->question }}</strong><br>Answer: {{ $question->answer }}</p>
            @endforeach
        </article>
        <article class="card">
            <h2>Claimant's responses</h2>
            <p>{{ $claim->claimant->first_name }} {{ $claim->claimant->last_name }} · Attempt {{ $claim->attempt_no }}</p>
            @foreach ($claim->answers as $index => $answer)
                <p><strong>Response {{ $index + 1 }}:</strong><br>{{ $answer }}</p>
            @endforeach
            <h3>Photos supplied by the claimant</h3>
            @forelse ($claim->evidenceImages as $image)
                <p><a href="{{ \App\Support\PublicMediaUrl::forPath($image->path) }}" target="_blank" rel="noopener">View claimant photo {{ $loop->iteration }}</a></p>
            @empty
                <p class="muted">No claimant photos were attached.</p>
            @endforelse
        </article>
    </section>
    <section class="section card">
        <h2>Decision</h2>
        @if ($claim->author_decision_reason)
            <p><strong>Owner's reason:</strong> {{ $claim->author_decision_reason }}</p>
        @endif
        @if ($claim->author_decision === 'pending')
            <p class="muted">The item owner must decide first. Administrator review becomes available after the owner approves.</p>
        @elseif ($claim->status === 'pending')
            <p class="muted">The item owner approved this claim. Handover starts only after administrator approval.</p>
            <form method="post" action="{{ route('admin.claims.decide', $claim) }}">
                @csrf
                <p><label for="reason">Reason (required when rejecting)</label><br><textarea id="reason" name="reason" rows="3" maxlength="1000"></textarea></p>
                @error('decision')<p class="muted">{{ $message }}</p>@enderror
                @error('reason')<p class="muted">{{ $message }}</p>@enderror
                <button class="button" name="decision" value="approve" type="submit">Approve claim</button>
                <button class="button danger" name="decision" value="reject" type="submit" onclick="return confirm('Reject this claim?')">Reject claim</button>
            </form>
        @else
            <p>This claim is {{ $claim->status }}.</p>
            @if ($claim->review_reason)
                <p><strong>Administrator's reason:</strong> {{ $claim->review_reason }}</p>
            @endif
        @endif
    </section>
@endsection
