@extends('admin.layout')

@section('title', 'Handovers')

@section('content')
    <div class="page-heading"><div><p class="eyebrow">PARTICIPANT CONFIRMATION</p><h1>Handovers</h1><p class="muted">Track direct exchanges after administrator approval and agreement from the involved users. Each participant confirms the exchange in the app.</p></div></div>
    <section class="section card">
        <div class="section-heading">
            <div><h2>Found-item and lost-item handover records</h2><p class="muted">Open any completed record in <a href="{{ route('admin.handovers.history') }}">Handover History</a> for its full post and exchange details.</p></div>
            <a class="button secondary" href="{{ route('admin.handovers.history') }}">View handover history</a>
        </div>
        <div class="table-wrap"><table>
            <thead><tr><th>Item</th><th>Type</th><th>Owner</th><th>Finder / handler</th><th>Owner confirmed</th><th>Finder confirmed</th><th>Last update</th></tr></thead>
            <tbody>
            @forelse ($handovers as $handover)
                @php($claim = $handover->claimRequest)
                @php($sighting = $handover->sighting)
                @php($post = $claim?->post ?? $sighting?->post)
                @php($owner = $claim?->claimant ?? $post?->author)
                @php($finder = $claim?->post?->author ?? $sighting?->user)
                <tr>
                    <td>{{ $post?->item_name ?? 'Deleted item' }}</td>
                    <td><span class="status-tag {{ $post?->type }}">{{ ucfirst($post?->type ?? 'unknown') }}</span></td>
                    <td>{{ $owner?->first_name }} {{ $owner?->last_name }}</td>
                    <td>{{ $finder?->first_name }} {{ $finder?->last_name }}</td>
                    <td>{{ $handover->owner_confirmed_at?->format('M j, Y g:i A') ?? 'Waiting' }}</td>
                    <td>{{ $handover->finder_confirmed_at?->format('M j, Y g:i A') ?? 'Waiting' }}</td>
                    <td>{{ $handover->updated_at->format('M j, Y g:i A') }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="empty-cell">No found-item or lost-item handovers are awaiting participant confirmation.</td></tr>
            @endforelse
            </tbody>
        </table></div>
        <div class="pagination">{{ $handovers->links() }}</div>
    </section>
@endsection
