@extends('admin.layout')

@section('title', 'Users')

@section('content')
    @php
        $showUserForm = $errors->has('first_name')
            || $errors->has('last_name')
            || $errors->has('tmc_id_no')
            || $errors->has('email')
            || $errors->has('user_type')
            || $errors->has('user_type_other')
            || $errors->has('department')
            || $errors->has('password')
            || $errors->has('password_confirmation');
    @endphp

    <header class="users-page-heading">
        <div>
            <p class="eyebrow">MONITORING AND MAINTENANCE</p>
            <h1>Users</h1>
            <p class="users-page-description">Search, review, and manage CampusLF user accounts.</p>
        </div>
        <div class="users-page-actions">
            <button class="button" type="button" data-open-users-dialog="add-user-dialog"><span aria-hidden="true">＋</span> Add a user</button>
            <button class="button secondary" type="button" data-open-users-dialog="masterlist-dialog"><span aria-hidden="true">⇧</span> Masterlist</button>
        </div>
    </header>

    <section class="users-directory card" aria-labelledby="users-directory-heading">
        <header class="users-directory-heading">
            <div>
                <p class="eyebrow">ACCOUNT DIRECTORY</p>
                <h2 id="users-directory-heading">User list</h2>
                <p class="muted">Open a profile to review account activity and apply moderation actions.</p>
            </div>
            <span class="users-total-badge">{{ number_format($users->total()) }} <span>users</span></span>
        </header>

        <form class="user-filter-form" method="get" action="{{ route('admin.users.index') }}" data-auto-filter>
            <label class="user-search-field" for="search">
                <span>Search users</span>
                <input id="search" type="search" name="search" value="{{ request('search') }}" placeholder="Name, TMC ID, or email">
            </label>
            <label class="user-status-filter" for="user-status">
                <span>Account status</span>
                <select id="user-status" name="status" aria-label="Filter by status">
                    <option value="">All statuses</option>
                    @foreach (['active', 'pending', 'suspended', 'deactivated'] as $status)
                        <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </select>
            </label>
        </form>

        <div class="users-table-wrap">
            <table class="users-table">
                <thead>
                    <tr><th>Name</th><th>TMC ID</th><th>Email</th><th>Type</th><th>Status</th></tr>
                </thead>
                <tbody>
                    @forelse ($users as $user)
                        <tr>
                            <td class="user-name-cell">
                                <a class="user-profile-link" href="{{ route('admin.users.show', $user) }}">
                                    <span class="user-directory-avatar" aria-hidden="true">{{ strtoupper(substr($user->first_name, 0, 1).substr($user->last_name, 0, 1)) }}</span>
                                    <span>{{ $user->first_name }} {{ $user->last_name }}</span>
                                    <span class="user-profile-link-label">View profile</span>
                                </a>
                            </td>
                            <td>{{ $user->tmc_id_no }}</td>
                            <td>{{ $user->email }}</td>
                            <td>{{ $user->user_type === 'other' ? 'Other: '.$user->user_type_other : ucfirst($user->user_type ?? '—') }}</td>
                            <td><span class="user-status-pill status-{{ $user->status }}">{{ ucfirst($user->status) }}</span></td>
                        </tr>
                    @empty
                        <tr><td class="users-empty" colspan="5">No user accounts found. Try adjusting your search or status filter.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="users-pagination">{{ $users->links() }}</div>
    </section>

    <dialog id="add-user-dialog" class="users-dialog" @if ($showUserForm) data-auto-open @endif aria-labelledby="add-user-heading">
        <section class="users-reveal-panel">
            <header class="users-reveal-heading">
                <div>
                    <p class="eyebrow">NEW ACCOUNT</p>
                    <h2 id="add-user-heading">Register a user</h2>
                    <p class="muted">Details must match an official TMC masterlist record.</p>
                </div>
                <button class="users-dialog-close" type="button" data-close-users-dialog aria-label="Close registration form">×</button>
            </header>
            <form class="user-registration-form" method="post" action="{{ route('admin.users.store') }}">
                @csrf
                <label>First name<input name="first_name" value="{{ old('first_name') }}" required maxlength="100" autocomplete="given-name" autofocus></label>
                <label>Last name<input name="last_name" value="{{ old('last_name') }}" required maxlength="100" autocomplete="family-name"></label>
                <label>TMC ID<input name="tmc_id_no" value="{{ old('tmc_id_no') }}" required maxlength="50"></label>
                <label>Gmail address<input type="email" name="email" value="{{ old('email') }}" placeholder="name@gmail.com" required autocomplete="email"></label>
                <label>User type
                    <select name="user_type" id="user-type" required>
                        <option value="student" @selected(old('user_type', 'student') === 'student')>Student</option>
                        <option value="staff" @selected(old('user_type') === 'staff')>Staff</option>
                        <option value="instructor" @selected(old('user_type') === 'instructor')>Instructor</option>
                        <option value="other" @selected(old('user_type') === 'other')>Others</option>
                    </select>
                </label>
                <label id="user-type-other-row" @if (old('user_type') !== 'other') hidden @endif>Please specify user type<input name="user_type_other" value="{{ old('user_type_other') }}" maxlength="100"></label>
                <label>Department / program
                    <select name="department" required>
                        <option value="">Choose a department</option>
                        @foreach (['CCS', 'CRIM', 'POLSCI', 'CAS', 'BSOA', 'BSED'] as $department)
                            <option value="{{ $department }}" @selected(old('department') === $department)>{{ $department }}</option>
                        @endforeach
                    </select>
                </label>
                <label>Temporary password<input type="password" name="password" minlength="10" required autocomplete="new-password"></label>
                <label>Confirm password<input type="password" name="password_confirmation" required autocomplete="new-password"></label>
                <div class="user-form-submit"><button class="button" type="submit">Create user</button></div>
            </form>
        </section>
    </dialog>

    <dialog id="masterlist-dialog" class="users-dialog" @if ($errors->has('masterlist')) data-auto-open @endif aria-labelledby="masterlist-heading">
        <section class="users-reveal-panel">
            <header class="users-reveal-heading">
                <div>
                    <p class="eyebrow">OFFICIAL RECORDS</p>
                    <h2 id="masterlist-heading">Import masterlist</h2>
                    <p class="muted">Upload a CSV to add or update official TMC records.</p>
                </div>
                <button class="users-dialog-close" type="button" data-close-users-dialog aria-label="Close masterlist import">×</button>
            </header>
            <p class="users-masterlist-help">Use the headers <code>tmc_id_no,full_name,email,user_type,user_type_other,department</code>. Gmail addresses are required. Supported departments: CCS, CRIM, POLSCI, CAS, BSOA, and BSED.</p>
            <form class="masterlist-upload-form" method="post" action="{{ route('admin.masterlist.import') }}" enctype="multipart/form-data">
                @csrf
                <label for="masterlist">Masterlist CSV</label>
                <input id="masterlist" type="file" name="masterlist" accept=".csv,text/csv" required>
                <button class="button" type="submit">Preview import</button>
            </form>
        </section>
    </dialog>

    <script>
        (() => {
            document.querySelectorAll('[data-open-users-dialog]').forEach(button => {
                button.addEventListener('click', () => {
                    document.getElementById(button.dataset.openUsersDialog)?.showModal();
                });
            });
            document.querySelectorAll('.users-dialog').forEach(dialog => {
                dialog.querySelector('[data-close-users-dialog]')?.addEventListener('click', () => dialog.close());
                dialog.addEventListener('click', event => {
                    if (event.target === dialog) dialog.close();
                });
            });
            document.querySelector('.users-dialog[data-auto-open]')?.showModal();

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
@endsection
