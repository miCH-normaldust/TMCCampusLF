@extends('admin.layout')

@section('title', 'Community Feed')

@section('content')
    <div class="page-heading"><div><p class="eyebrow">POST MODERATION</p><h1>Community Feed</h1><p class="muted">Browse lost and found posts, categories, authors, and current status.</p></div></div>
    <section class="card">
        <form class="filter-form" method="get" action="{{ route('admin.community-feed.index') }}">
            <label>Search<input name="search" value="{{ request('search') }}" placeholder="Item name or description"></label>
            <label>Type<select name="type"><option value="">Lost and found</option><option value="lost" @selected(request('type') === 'lost')>Lost</option><option value="found" @selected(request('type') === 'found')>Found</option></select></label>
            <label>Status<select name="status"><option value="">All statuses</option>@foreach (['active', 'claim_pending', 'approved_for_meetup', 'retrieved', 'hidden', 'archived'] as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ str_replace('_', ' ', ucfirst($status)) }}</option>@endforeach</select></label>
            <label>Category<select name="category_id"><option value="">All categories</option>@foreach ($categories as $category)<option value="{{ $category->id }}" @selected((string) request('category_id') === (string) $category->id)>{{ $category->name }}</option>@endforeach</select></label>
            <button class="button" type="submit">Apply filters</button>
            <a class="button secondary" href="{{ route('admin.community-feed.index') }}">Clear</a>
        </form>
        <div class="table-wrap section"><table>
            <thead><tr><th>Item</th><th>Type</th><th>Category</th><th>Area</th><th>Author</th><th>Status</th><th>Event date</th><th>Posted</th></tr></thead>
            <tbody>
            @forelse ($posts as $post)
                <tr>
                    <td><strong>{{ $post->item_name }}</strong>@if ($post->images->isNotEmpty())<br><span class="muted">{{ $post->images->count() }} photo(s)</span>@endif</td>
                    <td><span class="status-tag {{ $post->type }}">{{ ucfirst($post->type) }}</span></td>
                    <td>{{ $post->category?->name ?? '—' }}</td>
                    <td>{{ $post->location?->name ?? '—' }}</td>
                    <td>{{ $post->author?->first_name }} {{ $post->author?->last_name }}</td>
                    <td><span class="pill">{{ str_replace('_', ' ', ucfirst($post->status)) }}</span></td>
                    <td>{{ $post->event_date?->format('M j, Y') ?? '—' }}</td>
                    <td>{{ $post->created_at->format('M j, Y') }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="empty-cell">No posts match these filters.</td></tr>
            @endforelse
            </tbody>
        </table></div>
        <div class="pagination">{{ $posts->links() }}</div>
    </section>
@endsection
