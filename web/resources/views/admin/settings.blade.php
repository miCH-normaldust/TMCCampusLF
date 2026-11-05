@extends('admin.layout')

@section('title', 'Settings')

@section('content')
    <div class="settings-heading">
        <div><p class="eyebrow">CAMPUS OPERATIONS</p><h1>System settings</h1><p class="muted">Tune account, community, and retention rules for TMC CampusLF.</p></div>
        <span class="settings-heading-icon" aria-hidden="true">⚙</span>
    </div>
    <section class="card settings-card">
        <div class="settings-section-heading"><span class="settings-section-icon" aria-hidden="true">☷</span><div><h2>Registration, limits, and notices</h2><p class="muted">Set daily limits and the notices shown to CampusLF members.</p></div></div>
        <form method="post" action="{{ route('admin.settings.update') }}">
            @csrf @method('PUT')
            <p class="settings-note"><span aria-hidden="true">ⓘ</span> Registration and masterlist accounts must use a Gmail address ending in <strong>@gmail.com</strong>.</p>
            <div class="settings-limits-grid">
                @foreach ([
                    'posts_per_day' => 'Posts per day',
                    'claims_per_day' => 'Claims per day',
                    'claim_attempts' => 'Claim attempts per item',
                    'proof_attempts' => 'Proof submissions per item',
                    'handover_reschedule_attempts' => 'Handover reschedules per item',
                    'report_threshold' => 'Reports before auto-hide',
                    'archive_days' => 'Archive period (1-60 days)',
                    'comments_per_day' => 'Comments per day',
                    'reports_per_day' => 'Reports per day',
                ] as $key => $label)
                    <label class="settings-field">{{ $label }}<input type="number" min="1" name="{{ $key }}" value="{{ $settings[$key] ?? '' }}" required></label>
                @endforeach
            </div>
            <div class="settings-text-grid">
                <label class="settings-field">Blocked words <span class="settings-field-hint">Comma-separated terms that should be filtered.</span><textarea name="blocked_words" rows="4">{{ $settings['blocked_words'] ?? '' }}</textarea></label>
                <label class="settings-field">Disclaimer text <span class="settings-field-hint">Displayed in the app’s About and Help sections.</span><textarea name="disclaimer" rows="4" required>{{ $settings['disclaimer'] ?? '' }}</textarea></label>
            </div>
            <div class="settings-actions"><button class="button" type="submit">Save settings</button></div>
        </form>
    </section>

    <section class="section settings-catalog-grid">
        <article class="card settings-catalog-card">
            <div class="settings-section-heading"><span class="settings-section-icon gold" aria-hidden="true">◇</span><div><h2>Categories</h2><p class="muted">Organize the kinds of items members list.</p></div></div>
            <form class="settings-add-form" method="post" action="{{ route('admin.catalog.store') }}">
                @csrf<input type="hidden" name="kind" value="category">
                <label class="settings-field">New category<input name="name" maxlength="100" placeholder="e.g. Electronics" required></label>
                <button class="button" type="submit">Add category</button>
            </form>
            <div class="settings-catalog-list">
                @foreach ($categories as $category)
                    <form class="settings-catalog-row" method="post" action="{{ route('admin.catalog.status', ['kind' => 'categories', 'id' => $category->id]) }}">
                        @csrf @method('PATCH')
                        <span class="settings-catalog-name">{{ $category->name }}</span><span class="pill">{{ $category->is_active ? 'Active' : 'Inactive' }}</span>
                        <button class="button secondary" type="submit">{{ $category->is_active ? 'Deactivate' : 'Activate' }}</button>
                    </form>
                @endforeach
            </div>
        </article>
        <article class="card settings-catalog-card">
            <div class="settings-section-heading"><span class="settings-section-icon blue" aria-hidden="true">⌖</span><div><h2>TMC campus areas</h2><p class="muted">Manage the areas available on item listings.</p></div></div>
            <form class="settings-add-form" method="post" action="{{ route('admin.catalog.store') }}">
                @csrf<input type="hidden" name="kind" value="area">
                <label class="settings-field">New area<input name="name" maxlength="100" placeholder="e.g. Library" required></label>
                <button class="button" type="submit">Add area</button>
            </form>
            <div class="settings-catalog-list">
                @foreach ($locations as $location)
                    <form class="settings-catalog-row" method="post" action="{{ route('admin.catalog.status', ['kind' => 'locations', 'id' => $location->id]) }}">
                        @csrf @method('PATCH')
                        <span class="settings-catalog-name">{{ $location->name }}</span><span class="pill">{{ $location->is_active ? 'Active' : 'Inactive' }}</span>
                        <button class="button secondary" type="submit">{{ $location->is_active ? 'Deactivate' : 'Activate' }}</button>
                    </form>
                @endforeach
            </div>
        </article>
    </section>

    <section class="section card settings-catalog-card">
        <div class="settings-section-heading"><span class="settings-section-icon blue" aria-hidden="true">⌖</span><div><h2>Handover places</h2><p class="muted">Manage the safe places administrators can choose when scheduling an item handover.</p></div></div>
        <form class="settings-add-form" method="post" action="{{ route('admin.settings.handover-locations.store') }}">
            @csrf
            <label class="settings-field">Place name<input name="name" maxlength="120" placeholder="e.g. Main Office" required></label>
            <label class="settings-field">Description<input name="description" maxlength="2000" placeholder="Optional directions or details"></label>
            <label class="settings-field">Operating hours<input name="operating_hours" maxlength="200" placeholder="e.g. Weekdays, 8:00 AM - 5:00 PM"></label>
            <button class="button" type="submit">Add handover place</button>
        </form>
        <div class="settings-catalog-list">
            @foreach ($handoverLocations as $handoverLocation)
                <form class="settings-catalog-row" method="post" action="{{ route('admin.settings.handover-locations.status', $handoverLocation) }}">
                    @csrf @method('PATCH')
                    <span class="settings-catalog-name">
                        {{ $handoverLocation->name }}
                        @if ($handoverLocation->description)<span class="settings-field-hint">{{ $handoverLocation->description }}</span>@endif
                        @if ($handoverLocation->operating_hours)<span class="settings-field-hint">{{ $handoverLocation->operating_hours }}</span>@endif
                    </span>
                    <span class="pill">{{ $handoverLocation->is_active ? 'Active' : 'Inactive' }}</span>
                    <button class="button secondary" type="submit">{{ $handoverLocation->is_active ? 'Deactivate' : 'Activate' }}</button>
                </form>
            @endforeach
        </div>
    </section>
@endsection
