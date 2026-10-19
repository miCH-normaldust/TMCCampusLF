@extends('admin.layout')

@section('title', 'Users')

@section('content')
    <h1>Users</h1>
    <section class="cards">
        <article class="card">
            <h2>Add a user</h2>
            <form method="post" action="{{ route('admin.users.store') }}">
                @csrf
                <p><label>First name<br><input name="first_name" required maxlength="100"></label></p>
                <p><label>Last name<br><input name="last_name" required maxlength="100"></label></p>
                <p><label>TMC ID<br><input name="tmc_id_no" required maxlength="50"></label></p>
                <p><label>Gmail address<br><input type="email" name="email" placeholder="name@gmail.com" required></label></p>
                <p><label>User type<br><select name="user_type" id="user-type" required><option value="student">Student</option><option value="staff">Staff</option><option value="instructor">Instructor</option><option value="other">Others</option></select></label></p>
                <p id="user-type-other-row" hidden><label>Please Specify user type<br><input name="user_type_other" maxlength="100"></label></p>
                <p><label>Department / program<br><select name="department" required><option value="">Choose a department</option>@foreach (['CCS', 'CRIM', 'POLSCI', 'CAS', 'BSOA', 'BSED'] as $department)<option value="{{ $department }}">{{ $department }}</option>@endforeach</select></label></p>
                <p><label>Temporary password<br><input type="password" name="password" minlength="10" required></label></p>
                <p><label>Confirm password<br><input type="password" name="password_confirmation" required></label></p>
                <button class="button" type="submit">Create user</button>
            </form>
            <script>
                (() => {
                    const type = document.querySelector('#user-type');
                    const otherRow = document.querySelector('#user-type-other-row');
                    const otherInput = otherRow?.querySelector('input');
                    if (!type || !otherRow || !otherInput) return;
                    const updateOtherType = () => {
                        const isOther = type.value === 'other';
                        otherRow.hidden = !isOther;
                        otherInput.required = isOther;
                    };
                    type.addEventListener('change', updateOtherType);
                    updateOtherType();
                })();
            </script>
            @if ($errors->any())<p class="muted" role="alert">{{ $errors->first() }}</p>@endif
        </article>
        <article class="card">
            <h2>Import TMC masterlist</h2>
            <p class="muted">Gmail addresses only. CSV header order: tmc_id_no,full_name,email,user_type,user_type_other,department. Choose one of CCS, CRIM, POLSCI, CAS, BSOA, or BSED. Use user_type=other and specify the type in user_type_other for Others. A preview is shown before saving.</p>
            <form method="post" action="{{ route('admin.masterlist.import') }}" enctype="multipart/form-data">
                @csrf
                <label for="masterlist">Masterlist CSV</label><br>
                <input id="masterlist" type="file" name="masterlist" accept=".csv,text/csv" required>
                <button class="button" type="submit">Preview import</button>
            </form>
        </article>
    </section>

    <section class="section card">
        <form method="get" action="{{ route('admin.users.index') }}">
            <label for="search">Search</label>
            <input id="search" name="search" value="{{ request('search') }}" placeholder="Name, TMC ID, or email">
            <select name="status" aria-label="Filter by status">
                <option value="">All statuses</option>
                @foreach (['active', 'pending', 'suspended', 'deactivated'] as $status)
                    <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
                @endforeach
            </select>
            <button class="button secondary" type="submit">Filter</button>
        </form>
        <div class="table-wrap section"><table>
            <thead><tr><th>Name</th><th>TMC ID</th><th>Email</th><th>Type</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody>
            @forelse ($users as $user)
                <tr>
                    <td>{{ $user->first_name }} {{ $user->last_name }}</td>
                    <td>{{ $user->tmc_id_no }}</td><td>{{ $user->email }}</td>
                    <td>{{ $user->user_type === 'other' ? 'Other: '.$user->user_type_other : ucfirst($user->user_type ?? '—') }}</td><td><span class="pill">{{ ucfirst($user->status) }}</span></td>
                    <td>
                        <form method="post" action="{{ route('admin.users.status', $user) }}" onsubmit="return confirm('Update this account status?')">
                            @csrf @method('PATCH')
                            <input type="hidden" name="status" value="{{ $user->status === 'active' ? 'suspended' : 'active' }}">
                            @if ($user->status === 'active')<input type="hidden" name="reason" value="Account suspended by TMC administrator.">@endif
                            <button class="button {{ $user->status === 'active' ? 'danger' : 'secondary' }}" type="submit">{{ $user->status === 'active' ? 'Suspend' : 'Activate' }}</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6">No user accounts found.</td></tr>
            @endforelse
            </tbody>
        </table></div>
        <div class="section">{{ $users->links() }}</div>
    </section>
@endsection
