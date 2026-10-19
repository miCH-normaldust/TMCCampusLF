@extends('admin.layout')

@section('title', 'Masterlist preview')

@section('content')
    <h1>Review masterlist import</h1>
    <p>{{ $count }} valid row(s) will be added or updated. The preview shows up to 20 records.</p>
    <section class="card table-wrap"><table>
        <thead><tr><th>TMC ID</th><th>Full name</th><th>Email</th><th>User type</th><th>Department</th></tr></thead>
        <tbody>
        @foreach ($rows as $row)
            <tr><td>{{ $row['tmc_id_no'] }}</td><td>{{ $row['full_name'] }}</td><td>{{ $row['email'] }}</td><td>{{ $row['user_type'] === 'other' ? 'Other: '.$row['user_type_other'] : ucfirst($row['user_type']) }}</td><td>{{ $row['department'] }}</td></tr>
        @endforeach
        </tbody>
    </table></section>
    <form class="section" method="post" action="{{ route('admin.masterlist.confirm') }}">
        @csrf
        <button class="button" type="submit">Confirm import of {{ $count }} records</button>
        <a class="button secondary" href="{{ route('admin.users.index') }}">Cancel</a>
    </form>
@endsection
