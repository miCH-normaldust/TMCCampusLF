@php($proofUrl = \App\Support\PublicMediaUrl::forPath($sighting->evidence_image))
<div class="proof-image">
    <button class="button secondary proof-view" type="button"
            data-proof-src="{{ $proofUrl }}"
            data-proof-title="{{ $sighting->post->item_name }}"
            data-proof-meta="Evidence from {{ trim($sighting->user->first_name.' '.$sighting->user->last_name) }} · Submitted {{ $sighting->created_at->format('M j, Y g:i A') }}">
        <span class="proof-view-icon" aria-hidden="true">▣</span> View image
    </button>
    @isset($description)
        <span class="proof-note">{{ $description }}</span>
    @endisset
</div>
