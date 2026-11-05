@extends('admin.layout')

@section('title', 'Handover Receipt')

@section('content')
    @php
        $claim = $handover->claimRequest;
        $sighting = $handover->sighting;
        $post = $claim?->post ?? $sighting?->post;
        $owner = $claim?->claimant ?? $post?->author;
        $holder = $claim?->post?->author ?? $sighting?->user;
        $type = $post?->type ?? 'item';
    @endphp
    <article class="card" style="max-width: 720px; margin: 24px auto; text-align: center">
        <p class="eyebrow">TMC CAMPUSLF · HANDOVER RECEIPT</p>
        <p>Transaction reference: <strong>HL-{{ str_pad((string) $handover->id, 8, '0', STR_PAD_LEFT) }}</strong></p>
        <p>Transaction type: {{ ucfirst($type) }} item handover</p>
        <h1>{{ $post?->item_name ?? 'Campus item' }}</h1>
        <p>Scheduled {{ $handover->scheduled_at?->format('l, F j, Y \a\t g:i A') }}</p>
        <p>Handover place: <strong>{{ $handover->location?->name ?? 'TMC campus' }}</strong></p>
        <p>Owner: {{ $owner?->first_name }} {{ $owner?->last_name }}</p>
        <p>Person handing over: {{ $holder?->first_name }} {{ $holder?->last_name }}</p>
        @isset($verificationCode)
            <h2>Verification code</h2>
            <p class="handover-receipt-code">{{ $verificationCode }}</p>
            <p>Present this code to TMC staff. Both participants must present their separate codes.</p>
        @else
            <p>Each participant receives a separate verification code in CampusLF Inbox &gt; Handovers. Both codes must be presented to TMC staff to confirm retrieval.</p>
        @endisset
        <p>Receipt generated: {{ now()->format('M j, Y g:i A') }}</p>
        <button class="button" type="button" onclick="window.print()">Print receipt</button>
    </article>
    <style>
        @media print {
            .side, .top, .button { display: none !important }
            body { background: white !important; color: #000 !important }
            .shell, .content, main { display: block !important; width: auto !important; margin: 0 !important; padding: 0 !important }
            article.card { max-width: none !important; border: 0 !important; box-shadow: none !important }
        }
    </style>
@endsection
