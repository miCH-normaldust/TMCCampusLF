@extends('admin.layout')

@section('title', 'Lost-item evidence')

@section('content')
    <h1>Lost-item evidence</h1>
    <section class="cards">
        @forelse ($sightings as $sighting)
            <article class="card">
                <h2>{{ $sighting->post->item_name }}</h2>
                <p>{{ $sighting->post->description }}</p>
                <p class="muted">Owner: {{ $sighting->post->author->first_name }} · Evidence from {{ $sighting->user->first_name }}</p>
                <p>{{ $sighting->evidence_text }}</p>
                @if ($sighting->evidence_image)
                    <img src="{{ asset('storage/'.$sighting->evidence_image) }}" alt="Evidence photo for {{ $sighting->post->item_name }}" style="max-width:100%;height:auto;border-radius:10px">
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
    <div class="section">{{ $sightings->links() }}</div>
@endsection
