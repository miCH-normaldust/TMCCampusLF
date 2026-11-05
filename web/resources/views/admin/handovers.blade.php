@extends('admin.layout')

@section('title', 'Handovers')

@section('content')
    <div class="page-heading">
        <div>
            <p class="eyebrow">ADMIN-COORDINATED EXCHANGES</p>
            <h1>Handovers</h1>
            <p class="muted">Approve first, collect agreement from both participants, schedule the handover, and confirm retrieval using the receipt code.</p>
        </div>
        <a class="button secondary" href="{{ route('admin.handovers.history') }}">View handover history</a>
    </div>

    <section class="section card">
        <div class="table-wrap">
            <table>
                <thead><tr><th>Item and participants</th><th>Status</th><th>Agreements</th><th>Schedule / reschedule</th><th>Admin action</th></tr></thead>
                <tbody>
                @forelse ($handovers as $handover)
                    @php
                        $claim = $handover->claimRequest;
                        $sighting = $handover->sighting;
                        $post = $claim?->post ?? $sighting?->post;
                        $owner = $claim?->claimant ?? $post?->author;
                        $finder = $claim?->post?->author ?? $sighting?->user;
                    @endphp
                    <tr>
                        <td>
                            <strong>{{ $post?->item_name ?? 'Deleted item' }}</strong><br>
                            <span class="muted">Owner: {{ $owner?->first_name }} {{ $owner?->last_name }}</span><br>
                            <span class="muted">Holder: {{ $finder?->first_name }} {{ $finder?->last_name }}</span>
                        </td>
                        <td>{{ str_replace('_', ' ', ucfirst($handover->status)) }}</td>
                        <td>
                            TMC: {{ $handover->admin_approved_at ? 'Approved' : 'Waiting' }}<br>
                            Owner: {{ $handover->owner_confirmed_at ? 'Agreed' : 'Waiting' }}<br>
                            Other user: {{ $handover->finder_confirmed_at ? 'Agreed' : 'Waiting' }}
                        </td>
                        <td>
                            @if ($handover->scheduled_at)
                                {{ $handover->scheduled_at->format('M j, Y g:i A') }}<br>
                                {{ $handover->location?->name ?? 'Location unavailable' }}
                            @endif
                            @if ($handover->status === 'reschedule_requested')
                                <p><strong>Requested:</strong> {{ $handover->requested_date?->format('M j, Y') }}</p>
                                <p>{{ $handover->reschedule_reason }}</p>
                                <p>Attempts: {{ $handover->reschedule_attempts }} / {{ $rescheduleLimit }}</p>
                            @endif
                        </td>
                        <td class="handover-actions">
                            @if ($handover->status === 'awaiting_admin')
                                <form method="post" action="{{ route('admin.handovers.approve', $handover) }}">
                                    @csrf
                                    <button class="button" type="submit">Approve handover</button>
                                </form>
                            @elseif (in_array($handover->status, ['awaiting_schedule', 'reschedule_requested'], true))
                                <form method="post" action="{{ $handover->status === 'reschedule_requested' ? route('admin.handovers.reschedule', $handover) : route('admin.handovers.schedule', $handover) }}">
                                    @csrf
                                    @if ($handover->status === 'reschedule_requested')
                                        <input type="hidden" name="decision" value="approve">
                                    @endif
                                    <label>Handover place
                                        <select name="location_id" required>
                                            <option value="">Select a place</option>
                                            @foreach ($locations as $location)
                                                <option value="{{ $location->id }}" @selected($handover->location_id === $location->id)>{{ $location->name }}</option>
                                            @endforeach
                                        </select>
                                    </label>
                                    <label>Scheduled date and time
                                        <input type="datetime-local" name="scheduled_at" value="{{ $handover->requested_date ? $handover->requested_date->format('Y-m-d').'T09:00' : '' }}" required>
                                    </label>
                                    <button class="button" type="submit">{{ $handover->status === 'reschedule_requested' ? 'Approve new schedule' : 'Schedule and issue receipt' }}</button>
                                </form>
                                @if ($handover->status === 'reschedule_requested')
                                    <form method="post" action="{{ route('admin.handovers.reschedule', $handover) }}">
                                        @csrf
                                        <input type="hidden" name="decision" value="reject">
                                        <label>Reason for keeping the current date<textarea name="reason" rows="2" maxlength="1000"></textarea></label>
                                        <button class="button secondary" type="submit">Decline reschedule</button>
                                    </form>
                                @endif
                            @elseif ($handover->status === 'scheduled')
                                <a class="button secondary" href="{{ route('admin.handovers.receipt', $handover) }}" target="_blank" rel="noopener">Print receipt</a>
                                <form method="post" action="{{ route('admin.handovers.complete', $handover) }}">
                                    @csrf
                                    <label>Verify 16-digit receipt code
                                        <input name="code" inputmode="numeric" pattern="[0-9]{16}" minlength="16" maxlength="16" required>
                                    </label>
                                    <button class="button" type="submit">Confirm owner retrieved item</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="empty-cell">No active handovers need administrator attention.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="pagination">{{ $handovers->links() }}</div>
    </section>
@endsection
