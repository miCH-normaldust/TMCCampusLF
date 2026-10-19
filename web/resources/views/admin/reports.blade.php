@extends('admin.layout')

@section('title', 'Reported posts')

@section('content')
    <h1>Reported posts</h1>
    <section class="cards">
        @forelse ($reports as $report)
            <article class="card">
                <span class="pill">{{ $report->created_at->format('M j, Y g:i A') }}</span>
                <h2>{{ $report->post->item_name }}</h2>
                <p>{{ $report->reason }}</p>
                <p class="muted">Reported by {{ $report->reporter->first_name }} · Posted by {{ $report->post->author->first_name }}</p>
                <form method="post" action="{{ route('admin.reports.decide', $report) }}">
                    @csrf
                    <label for="reason-{{ $report->id }}">Reason for action (required except dismiss)</label><br>
                    <textarea id="reason-{{ $report->id }}" name="reason" rows="2" maxlength="1000"></textarea><br><br>
                    <div class="cards">
                        <button class="button secondary" name="action" value="dismiss" type="submit">Dismiss</button>
                        <button class="button" name="action" value="hide" type="submit">Hide</button>
                        <button class="button danger" name="action" value="delete" type="submit" onclick="return confirm('Permanently delete this post and its images?')">Delete</button>
                        <button class="button secondary" name="action" value="warn" type="submit">Warn author</button>
                        <button class="button danger" name="action" value="suspend" type="submit" onclick="return confirm('Suspend this author account?')">Suspend author</button>
                    </div>
                </form>
            </article>
        @empty
            <article class="card">There are no reports awaiting review.</article>
        @endforelse
    </section>
    <div class="section">{{ $reports->links() }}</div>
@endsection
