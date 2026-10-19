@extends('admin.layout')

@section('title', 'Found Item Claims')

@section('content')
    <div class="page-heading"><div><p class="eyebrow">REVIEW QUEUE</p><h1>Found Item Claims</h1><p class="muted">Review a claimant’s private answers against the item’s verification questions.</p></div></div>
    <section class="card">
        <form class="filter-form compact-filter" method="get" action="{{ route('admin.claims.index') }}">
            <label>Status<select name="status"><option value="">All claims</option>@foreach (['pending', 'approved', 'rejected'] as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>@endforeach</select></label>
            <button class="button" type="submit">Filter</button>
            <a class="button secondary" href="{{ route('admin.claims.index') }}">Clear</a>
        </form>
        <div class="table-wrap section"><table>
            <thead><tr><th>Found item</th><th>Item owner</th><th>Claimant</th><th>Attempt</th><th>Status</th><th>Submitted</th><th></th></tr></thead>
            <tbody>
            @forelse ($claims as $claim)
                <tr>
                    <td>{{ $claim->post->item_name }}</td>
                    <td>{{ $claim->post->author?->first_name }} {{ $claim->post->author?->last_name }}</td>
                    <td>{{ $claim->claimant->first_name }} {{ $claim->claimant->last_name }}<br><span class="muted">{{ $claim->claimant->tmc_id_no }}</span></td>
                    <td>{{ $claim->attempt_no }}</td>
                    <td><span class="pill">{{ ucfirst($claim->status) }}</span></td>
                    <td>{{ $claim->created_at->format('M j, Y g:i A') }}</td>
                    <td><a class="button secondary" href="{{ route('admin.claims.show', $claim) }}">{{ $claim->status === 'pending' ? 'Review claim' : 'View claim' }}</a></td>
                </tr>
            @empty
                <tr><td colspan="7" class="empty-cell">No claims match this status.</td></tr>
            @endforelse
            </tbody>
        </table></div>
        <div class="pagination">{{ $claims->links() }}</div>
    </section>
@endsection
