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
        <p>Receipt generated: {{ now()->format('M j, Y g:i A') }}</p>
        <div style="border: 2px dashed currentColor; margin: 28px auto; padding: 20px; max-width: 400px">
            <p class="eyebrow">UNIQUE 16-DIGIT VERIFICATION CODE</p>
            <strong style="font-size: 2rem; letter-spacing: .15em">{{ $code }}</strong>
        </div>
        <p>Present this receipt at the scheduled location. TMC staff will verify the code and confirm retrieval.</p>
        <button class="button" type="button" onclick="window.print()">Print receipt</button>
    </article>
    <style>@media print { .admin-sidebar, .admin-header, .button { display: none !important } body { background: white } }</style>
@endsection
