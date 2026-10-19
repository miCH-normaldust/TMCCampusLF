@extends('admin.layout')

@section('title', 'Activity')

@section('content')
    <h1>Login and logout activity</h1>
    <section class="card">
        <form method="get" action="{{ route('admin.activity.index') }}">
            <label>From <input type="date" name="from" value="{{ request('from') }}"></label>
            <label>To <input type="date" name="to" value="{{ request('to') }}"></label>
            <button class="button secondary" type="submit">Filter</button>
        </form>
        <form class="section" method="get" action="{{ route('admin.activity.export') }}">
            <label>From <input type="date" name="from" value="{{ request('from') }}"></label>
            <label>To <input type="date" name="to" value="{{ request('to') }}"></label>
            <button class="button" type="submit">Export CSV</button>
        </form>
        <div class="table-wrap section"><table>
            <thead><tr><th>Account</th><th>Event</th><th>Time</th><th>IP address</th><th>Device</th></tr></thead>
            <tbody>
            @forelse ($activities as $activity)
                <tr><td>{{ $activity->first_name }} {{ $activity->last_name }}<br>{{ $activity->email }}</td><td>{{ $activity->event }}</td><td>{{ $activity->created_at }}</td><td>{{ $activity->ip_address }}</td><td>{{ $activity->device }}</td></tr>
            @empty
                <tr><td colspan="5">No activity records found.</td></tr>
            @endforelse
            </tbody>
        </table></div>
        {{ $activities->links() }}
    </section>
@endsection
