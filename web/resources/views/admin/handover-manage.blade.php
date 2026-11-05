@extends('admin.layout')

@section('title', 'Manage Handover')

@section('content')
    @php
        $claim = $handover->claimRequest;
        $sighting = $handover->sighting;
        $post = $claim?->post ?? $sighting?->post;
        $owner = $claim?->claimant ?? $post?->author;
        $holder = $claim?->post?->author ?? $sighting?->user;
    @endphp

    <a class="button secondary" href="{{ route('admin.handovers.index') }}">← Back to handovers</a>
    <div class="page-heading">
        <div>
            <p class="eyebrow">HANDOVER #{{ $handover->id }}</p>
            <h1>{{ $post?->item_name ?? 'Campus item' }}</h1>
            <p class="muted">Owner: {{ trim(($owner?->first_name ?? '').' '.($owner?->last_name ?? '')) ?: 'Campus user' }} · Holder: {{ trim(($holder?->first_name ?? '').' '.($holder?->last_name ?? '')) ?: 'Campus user' }}</p>
        </div>
    </div>

    <section class="section card">
        <h2>{{ $handover->status === 'reschedule_requested' ? 'Review reschedule request' : 'Set handover place and schedule' }}</h2>
        <p>Owner approval: {{ $handover->owner_confirmed_at ? 'Approved' : 'Waiting' }} · {{ $claim ? 'Holder' : 'Finder' }} approval: {{ $handover->finder_confirmed_at ? 'Approved' : 'Waiting' }}</p>

        @if ($handover->status === 'reschedule_requested')
            <p><strong>Requested date:</strong> {{ $handover->requested_date?->format('M j, Y') ?? 'Not provided' }}</p>
            <p><strong>Reason:</strong> {{ $handover->reschedule_reason ?: 'No reason provided.' }}</p>
            <p><strong>Attempts:</strong> {{ $handover->reschedule_attempts }} / {{ $rescheduleLimit }}</p>
        @endif

        @if ($participantsApproved)
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
                    <input type="datetime-local" name="scheduled_at" min="{{ now()->format('Y-m-d\TH:i') }}" value="{{ $handover->requested_date ? $handover->requested_date->format('Y-m-d').'T09:00' : $handover->scheduled_at?->format('Y-m-d\TH:i') }}" required>
                </label>
                <button class="button" type="submit">
                    {{ $handover->status === 'reschedule_requested' ? 'Approve new schedule' : 'Set schedule and place' }}
                </button>
            </form>
        @else
            <p class="muted">Scheduling is available after both participants approve.</p>
        @endif

        @if ($handover->status === 'reschedule_requested')
            <form method="post" action="{{ route('admin.handovers.reschedule', $handover) }}">
                @csrf
                <input type="hidden" name="decision" value="reject">
                <label>Reason for keeping the current date
                    <textarea name="reason" rows="2" maxlength="1000"></textarea>
                </label>
                <button class="button secondary" type="submit">Decline reschedule</button>
            </form>
        @endif
    </section>
@endsection
