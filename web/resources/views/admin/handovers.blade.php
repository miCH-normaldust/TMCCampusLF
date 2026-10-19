@extends('admin.layout')

@section('title', 'Handovers')

@section('content')
    <div class="page-heading"><div><p class="eyebrow">RETURN CONFIRMATION</p><h1>Handovers</h1><p class="muted">Review returns for both found-item claims and lost-item sightings, including meet-ups, participant confirmations, and desk verification.</p></div></div>
    <section class="card handover-verify-panel">
        <h2>Verify an exchange at the TMC desk</h2>
        <p class="muted">After both participants confirm the exchange in the app, enter the current eight-digit code shown by either participant. Successful verification records the administrator; the participants' confirmations remain their own.</p>
        <form class="inline-form" method="post" action="{{ route('admin.handovers.verify') }}">
            @csrf
            <label for="handover-code">Handover code</label>
            <input id="handover-code" name="code" inputmode="numeric" pattern="[0-9]{8}" maxlength="8" autocomplete="off" required>
            <button class="button" type="submit">Verify exchange</button>
        </form>
        @error('code')<p class="form-error">{{ $message }}</p>@enderror
    </section>
    <section class="section card">
        <h2>Found-item and lost-item handover records</h2>
        <div class="table-wrap"><table>
            <thead><tr><th>Item</th><th>Type</th><th>Owner</th><th>Finder</th><th>Meet-up</th><th>Owner confirmed</th><th>Finder confirmed</th><th>Code expires</th><th>Desk check</th></tr></thead>
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
                    <td>{{ $handover->location?->name ?? 'Not selected' }}</td>
                    <td>{{ $handover->owner_confirmed_at?->format('M j, Y g:i A') ?? 'Waiting' }}</td>
                    <td>{{ $handover->finder_confirmed_at?->format('M j, Y g:i A') ?? 'Waiting' }}</td>
                    <td><span class="pill">{{ $handover->expires_at->format('M j, Y g:i A') }}</span></td>
                    <td>{{ $handover->verifiedBy ? 'Verified by '.$handover->verifiedBy->first_name.' '.$handover->verifiedBy->last_name : 'Not desk-verified' }}</td>
                </tr>
            @empty
                <tr><td colspan="9" class="empty-cell">No found-item or lost-item handovers have been generated.</td></tr>
            @endforelse
            </tbody>
        </table></div>
        <div class="pagination">{{ $handovers->links() }}</div>
    </section>
@endsection
