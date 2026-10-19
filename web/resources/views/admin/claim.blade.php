@extends('admin.layout')

@section('title', 'Review claim')

@section('content')
    <a class="button secondary" href="{{ route('admin.dashboard') }}">Back to dashboard</a>
    <h1 class="section">Review claim: {{ $claim->post->item_name }}</h1>
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
        </article>
    </section>
    <section class="section card">
        <h2>Decision</h2>
        <form method="post" action="{{ route('admin.claims.decide', $claim) }}">
            @csrf
            <label for="location_id">Meet-up location (required for approval)</label><br>
            <select id="location_id" name="location_id">
                <option value="">Choose an active location</option>
                @foreach ($meetupLocations as $location)
                    <option value="{{ $location->id }}">{{ $location->name }} — {{ $location->operating_hours }}</option>
                @endforeach
            </select>
            <p><label for="reason">Reason (required when rejecting)</label><br><textarea id="reason" name="reason" rows="3" maxlength="1000"></textarea></p>
            @error('decision')<p class="muted">{{ $message }}</p>@enderror
            @error('location_id')<p class="muted">{{ $message }}</p>@enderror
            @error('reason')<p class="muted">{{ $message }}</p>@enderror
            <button class="button" name="decision" value="approve" type="submit">Approve claim</button>
            <button class="button danger" name="decision" value="reject" type="submit" onclick="return confirm('Reject this claim?')">Reject claim</button>
        </form>
    </section>
@endsection
