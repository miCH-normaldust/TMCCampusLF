@extends('admin.layout')

@section('title', 'Audit trail')

@section('content')
    <h1>Administrator audit trail</h1>
    <section class="card table-wrap"><table>
        <thead><tr><th>Administrator</th><th>Action</th><th>Target</th><th>Reason</th><th>Time</th><th>IP address</th></tr></thead>
        <tbody>
        @forelse ($logs as $log)
            <tr><td>{{ $log->first_name }} {{ $log->last_name }}<br>{{ $log->email }}</td><td>{{ $log->action }}</td><td>{{ $log->target_type }} #{{ $log->target_id }}</td><td>{{ $log->reason }}</td><td>{{ $log->created_at }}</td><td>{{ $log->ip_address }}</td></tr>
        @empty
            <tr><td colspan="6">No audit events have been recorded.</td></tr>
        @endforelse
        </tbody>
    </table></section>
    <div class="section">{{ $logs->links() }}</div>
@endsection
