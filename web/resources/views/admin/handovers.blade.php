@extends('admin.layout')

@section('title', 'Handovers')

@section('content')
    <div class="page-heading">
        <div>
            <p class="eyebrow">ADMIN-COORDINATED EXCHANGES</p>
            <h1>Handovers</h1>
            <p class="muted">Collect approval from both participants, set the date and place, then verify both participants’ codes to confirm retrieval.</p>
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
                                <button
                                    class="button"
                                    type="button"
                                    data-handover-complete="{{ route('admin.handovers.complete', $handover) }}"
                                    data-owner-receipt="{{ route('admin.handovers.receipt', ['handover' => $handover, 'participant' => 'owner']) }}"
                                    data-finder-receipt="{{ route('admin.handovers.receipt', ['handover' => $handover, 'participant' => 'finder']) }}"
                                    data-handover-id="{{ $handover->id }}"
                                    data-item-name="{{ $post?->item_name ?? 'Campus item' }}"
                                    data-owner-name="{{ trim(($owner?->first_name ?? '').' '.($owner?->last_name ?? '')) ?: 'Owner' }}"
                                    data-finder-name="{{ trim(($finder?->first_name ?? '').' '.($finder?->last_name ?? '')) ?: 'Other participant' }}"
                                >Confirm retrieval</button>
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

    <div class="image-modal handover-confirm-modal" id="handover-confirm-modal" role="dialog" aria-modal="true" aria-labelledby="handover-confirm-title" hidden>
        <section class="image-modal-panel handover-confirm-panel">
            <div class="image-modal-head">
                <div>
                    <h2 id="handover-confirm-title">Confirm item retrieval</h2>
                    <p class="image-modal-meta" id="handover-confirm-item"></p>
                </div>
                <button class="button secondary" type="button" id="handover-confirm-close">Close</button>
            </div>
            <div class="handover-confirm-body">
                <p>View each participant’s separate receipt, then enter both 16-digit verification codes to confirm retrieval.</p>
                <div class="handover-receipt-links">
                    <a class="button secondary" id="owner-receipt-link" href="#" target="_blank" rel="noopener">View owner receipt</a>
                    <a class="button secondary" id="finder-receipt-link" href="#" target="_blank" rel="noopener">View other participant receipt</a>
                </div>
                <form method="post" id="handover-confirm-form">
                    @csrf
                    <input type="hidden" name="handover_id" id="handover-confirm-id" value="{{ old('handover_id') }}">
                    <label id="owner-code-label">Owner’s 16-digit verification code
                        <input name="owner_code" inputmode="numeric" pattern="[0-9]{16}" minlength="16" maxlength="16" autocomplete="off" required>
                    </label>
                    @error('owner_code')<span class="field-error">{{ $message }}</span>@enderror
                    <label id="finder-code-label">Other participant’s 16-digit verification code
                        <input name="finder_code" inputmode="numeric" pattern="[0-9]{16}" minlength="16" maxlength="16" autocomplete="off" required>
                    </label>
                    @error('finder_code')<span class="field-error">{{ $message }}</span>@enderror
                    <button class="button" type="submit">Verify both codes and confirm retrieval</button>
                </form>
            </div>
        </section>
    </div>

    <script>
        (() => {
            const modal = document.getElementById('handover-confirm-modal');
            const form = document.getElementById('handover-confirm-form');
            const item = document.getElementById('handover-confirm-item');
            const ownerReceipt = document.getElementById('owner-receipt-link');
            const finderReceipt = document.getElementById('finder-receipt-link');
            const handoverId = document.getElementById('handover-confirm-id');
            const closeButton = document.getElementById('handover-confirm-close');
            if (!modal || !form || !item || !ownerReceipt || !finderReceipt || !handoverId || !closeButton) return;

            let lastFocused = null;
            const open = button => {
                lastFocused = button;
                form.action = button.dataset.handoverComplete;
                handoverId.value = button.dataset.handoverId;
                item.textContent = button.dataset.itemName;
                ownerReceipt.href = button.dataset.ownerReceipt;
                ownerReceipt.textContent = 'View owner receipt — ' + button.dataset.ownerName;
                finderReceipt.href = button.dataset.finderReceipt;
                finderReceipt.textContent = 'View other participant receipt — ' + button.dataset.finderName;
                form.elements.owner_code.value = '';
                form.elements.finder_code.value = '';
                modal.hidden = false;
                document.body.classList.add('image-modal-open');
                closeButton.focus();
            };
            const close = () => {
                modal.hidden = true;
                document.body.classList.remove('image-modal-open');
                if (lastFocused) lastFocused.focus();
            };

            document.querySelectorAll('[data-handover-complete]').forEach(button => {
                button.addEventListener('click', () => open(button));
            });
            closeButton.addEventListener('click', close);
            modal.addEventListener('click', event => {
                if (event.target === modal) close();
            });
            document.addEventListener('keydown', event => {
                if (event.key === 'Escape' && !modal.hidden) close();
            });

            const failedHandover = handoverId.value;
            if (failedHandover && (@json($errors->has('owner_code') || $errors->has('finder_code')))) {
                const trigger = [...document.querySelectorAll('[data-handover-complete]')]
                    .find(button => button.dataset.handoverId === failedHandover);
                if (trigger) open(trigger);
            }
        })();
    </script>
@endsection
