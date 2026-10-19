@extends('admin.layout')

@section('title', 'Feedbacks')

@section('content')
    <div class="page-heading"><div><p class="eyebrow">MEMBER SUPPORT</p><h1>Feedbacks</h1><p class="muted">Read member suggestions and issue reports, then record a status and response.</p></div></div>
    <section class="card">
        <form class="filter-form compact-filter" method="get" action="{{ route('admin.feedback.index') }}">
            <label>Status<select name="status"><option value="">All feedback</option>@foreach (['open', 'reviewing', 'resolved', 'closed'] as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>@endforeach</select></label>
            <button class="button" type="submit">Filter</button>
            <a class="button secondary" href="{{ route('admin.feedback.index') }}">Clear</a>
        </form>
        <div class="feedback-list section">
            @forelse ($feedback as $entry)
                <article class="feedback-card">
                    <div class="feedback-header"><div><span class="status-tag">{{ ucfirst($entry->category) }}</span><h2>{{ $entry->first_name }} {{ $entry->last_name }}</h2><p class="muted">{{ $entry->email }} · {{ \Illuminate\Support\Carbon::parse($entry->created_at)->format('M j, Y g:i A') }}</p></div><span class="pill">{{ ucfirst($entry->status) }}</span></div>
                    <p class="feedback-message">{{ $entry->message }}</p>
                    @if ($entry->admin_response)<p class="feedback-response"><strong>Previous response:</strong> {{ $entry->admin_response }}</p>@endif
                    <form method="post" action="{{ route('admin.feedback.update', $entry->id) }}">
                        @csrf @method('PATCH')
                        <div class="filter-form">
                            <label>Status<select name="status" required>@foreach (['open', 'reviewing', 'resolved', 'closed'] as $status)<option value="{{ $status }}" @selected($entry->status === $status)>{{ ucfirst($status) }}</option>@endforeach</select></label>
                            <label class="response-field">Admin response<textarea name="admin_response" rows="2" maxlength="2000" placeholder="Optional response for the record">{{ $entry->admin_response }}</textarea></label>
                            <button class="button" type="submit">Save review</button>
                        </div>
                    </form>
                </article>
            @empty
                <div class="empty-state"><span aria-hidden="true">✉</span><strong>No feedback submitted yet.</strong><p>Feedback sent from the CampusLF mobile app will appear here.</p></div>
            @endforelse
        </div>
        <div class="pagination">{{ $feedback->links() }}</div>
    </section>
@endsection
