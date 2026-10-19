@extends('admin.layout')

@section('title', 'Settings')

@section('content')
    <h1>System settings</h1>
    <section class="card">
        <h2>Registration, limits, and notices</h2>
        <form method="post" action="{{ route('admin.settings.update') }}">
            @csrf @method('PUT')
            <p class="muted">Registration and masterlist accounts must use a Gmail address ending in @gmail.com.</p>
            <div class="cards">
                @foreach ([
                    'posts_per_day' => 'Posts per day',
                    'claims_per_day' => 'Claims per day',
                    'claim_attempts' => 'Claim attempts per item',
                    'report_threshold' => 'Reports before auto-hide',
                    'archive_days' => 'Archive period (1-60 days)',
                    'handover_code_minutes' => 'Handover code validity (minutes)',
                    'comments_per_day' => 'Comments per day',
                    'reports_per_day' => 'Reports per day',
                ] as $key => $label)
                    <label>{{ $label }}<br><input name="{{ $key }}" value="{{ $settings[$key] ?? '' }}" required></label>
                @endforeach
            </div>
            <p><label>Blocked words (comma-separated)<br><textarea name="blocked_words" rows="3">{{ $settings['blocked_words'] ?? '' }}</textarea></label></p>
            <p><label>Disclaimer text<br><textarea name="disclaimer" rows="5" required>{{ $settings['disclaimer'] ?? '' }}</textarea></label></p>
            <button class="button" type="submit">Save settings</button>
        </form>
        @if ($errors->any())<p class="muted" role="alert">{{ $errors->first() }}</p>@endif
    </section>

    <section class="section cards">
        <article class="card">
            <h2>Categories</h2>
            <form method="post" action="{{ route('admin.catalog.store') }}">
                @csrf<input type="hidden" name="kind" value="category">
                <label>New category <input name="name" maxlength="100" required></label>
                <button class="button" type="submit">Add</button>
            </form>
            @foreach ($categories as $category)
                <form class="section" method="post" action="{{ route('admin.catalog.status', ['kind' => 'categories', 'id' => $category->id]) }}">
                    @csrf @method('PATCH')
                    {{ $category->name }} <span class="pill">{{ $category->is_active ? 'Active' : 'Inactive' }}</span>
                    <button class="button secondary" type="submit">{{ $category->is_active ? 'Deactivate' : 'Activate' }}</button>
                </form>
            @endforeach
        </article>
        <article class="card">
            <h2>TMC campus areas</h2>
            <form method="post" action="{{ route('admin.catalog.store') }}">
                @csrf<input type="hidden" name="kind" value="area">
                <label>New area <input name="name" maxlength="100" required></label>
                <button class="button" type="submit">Add</button>
            </form>
            @foreach ($locations as $location)
                <form class="section" method="post" action="{{ route('admin.catalog.status', ['kind' => 'locations', 'id' => $location->id]) }}">
                    @csrf @method('PATCH')
                    {{ $location->name }} <span class="pill">{{ $location->is_active ? 'Active' : 'Inactive' }}</span>
                    <button class="button secondary" type="submit">{{ $location->is_active ? 'Deactivate' : 'Activate' }}</button>
                </form>
            @endforeach
        </article>
    </section>

    <section class="section card">
        <h2>Safe meet-up locations</h2>
        <form method="post" action="{{ route('admin.meetups.store') }}">
            @csrf
            <label>Name <input name="name" maxlength="120" required></label>
            <label>Description <input name="description" maxlength="1000"></label>
            <label>Operating hours <input name="operating_hours" maxlength="200"></label>
            <button class="button" type="submit">Add location</button>
        </form>
        @foreach ($meetupLocations as $location)
            <form class="section" method="post" action="{{ route('admin.meetups.status', $location) }}">
                @csrf @method('PATCH')
                <strong>{{ $location->name }}</strong> · {{ $location->operating_hours }}
                <span class="pill">{{ $location->is_active ? 'Active' : 'Inactive' }}</span>
                <button class="button secondary" type="submit">{{ $location->is_active ? 'Deactivate' : 'Activate' }}</button>
            </form>
        @endforeach
    </section>
@endsection
