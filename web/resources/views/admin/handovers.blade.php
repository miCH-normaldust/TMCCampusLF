@extends('admin.layout')

@section('title', 'Handovers')

@section('content')
    <div class="page-heading">
        <div>
            <p class="eyebrow">ADMIN-COORDINATED EXCHANGES</p>
            <h1>Handovers</h1>
            <p class="muted">Collect approval from both participants, set the date and place, then confirm retrieval using the generated receipt code.</p>
        </div>
        <a class="button secondary" href="{{ route('admin.handovers.history') }}">View handover history</a>
    </div>

    <section class="section card">
        <div class="table-wrap">
            <table>
                <thead><tr><th>Item and participants</th><th>Status</th><th>Agreements</th><th>Schedule</th><th>Place</th><th>Admin action</th></tr></thead>
                <tbody>
                @forelse ($handovers as $handover)
                    @php
                        $claim = $handover->claimRequest;
                        $sighting = $handover->sighting;
                        $post = $claim?->post ?? $sighting?->post;
                        $owner = $claim?->claimant ?? $post?->author;
                        $finder = $claim?->post?->author ?? $sighting?->user;
                        $participantsApproved = $handover->owner_confirmed_at !== null
                            && $handover->finder_confirmed_at !== null
                            && in_array($handover->status, ['awaiting_schedule', 'reschedule_requested'], true);
                    @endphp
                    <tr>
                        <td>
                            <strong>{{ $post?->item_name ?? 'Deleted item' }}</strong><br>
                            <span class="muted">Owner: {{ $owner?->first_name }} {{ $owner?->last_name }}</span><br>
                            <span class="muted">Holder: {{ $finder?->first_name }} {{ $finder?->last_name }}</span>
                        </td>
                        <td>
                            @if (in_array($handover->status, ['awaiting_admin', 'awaiting_participants'], true))
                                Awaiting participant agreements
                            @elseif ($handover->status === 'awaiting_schedule')
                                Both participants agreed
                            @elseif ($handover->status === 'reschedule_requested')
                                Reschedule requested
                            @elseif ($handover->status === 'scheduled')
                                Scheduled
                            @else
                                {{ str_replace('_', ' ', ucfirst($handover->status)) }}
                            @endif
                        </td>
                        <td>
                            Owner: {{ $handover->owner_confirmed_at ? 'Agreed' : 'Waiting' }}<br>
                            {{ $claim ? 'Holder' : 'Finder' }}: {{ $handover->finder_confirmed_at ? 'Agreed' : 'Waiting' }}
                        </td>
                        <td>
                            @if ($handover->scheduled_at)
                                {{ $handover->scheduled_at->format('M j, Y g:i A') }}
                            @elseif ($handover->requested_date)
                                Requested: {{ $handover->requested_date->format('M j, Y') }}
                            @else
                                Not set
                            @endif
                        </td>
                        <td>{{ $handover->location?->name ?? 'Not set' }}</td>
                        <td class="handover-actions">
                            @if (in_array($handover->status, ['awaiting_admin', 'awaiting_participants', 'awaiting_schedule', 'reschedule_requested'], true))
                                @if ($participantsApproved)
                                    <a class="button" href="{{ route('admin.handovers.manage', $handover) }}">{{ $handover->status === 'reschedule_requested' ? 'Review date change' : 'Set place and schedule' }}</a>
                                @else
                                    <span class="muted">Waiting for both agreements</span>
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
                    <tr><td colspan="6" class="empty-cell">No active handovers need administrator attention.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="pagination">{{ $handovers->links() }}</div>
    </section>
@endsection
