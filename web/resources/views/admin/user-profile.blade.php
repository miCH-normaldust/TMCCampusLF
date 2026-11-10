@extends('admin.layout')

@section('title', 'User Profile')

@section('content')
    @php
        $scopeLabels = [
            'posting_lost' => 'Lost-item posting',
            'posting_found' => 'Found-item posting',
            'comments' => 'Comments',
            'reactions' => 'Reactions',
            'messaging' => 'Messaging',
            'other' => 'Other restriction',
        ];
    @endphp

    <header class="users-page-heading">
        <div>
            <p class="eyebrow"><a href="{{ route('admin.users.index') }}">USERS</a> / PROFILE</p>
            <h1>{{ $user->first_name }} {{ $user->last_name }}</h1>
            <p class="users-page-description">Review profile, activity, posts, and moderation history.</p>
        </div>
        <div class="user-profile-actions">
            <span class="user-status-pill status-{{ $user->status }}">{{ ucfirst($user->status) }}</span>
            <details class="user-moderation-menu">
                <summary aria-label="Moderation actions">•••</summary>
                <section class="user-moderation-panel">
                    <h2>Moderation action</h2>
                    <form method="post" action="{{ route('admin.users.moderate', $user) }}" data-user-moderation-form>
                        @csrf
                        <label>Action
                            <select name="action" data-moderation-action required>
                                <option value="warn">Warn user</option>
                                <option value="ban">Apply scoped ban</option>
                                <option value="suspend">Suspend user</option>
                                <option value="restore" @disabled($user->status === 'active')>Restore account</option>
                            </select>
                        </label>
                        <label>Reason
                            <textarea name="reason" rows="3" maxlength="1000" required placeholder="Explain the reason for this action"></textarea>
                        </label>
                        <fieldset data-ban-fields hidden>
                            <legend>Ban scope</legend>
                            <label><input type="checkbox" name="scopes[]" value="posting_lost"> Lost-item posting</label>
                            <label><input type="checkbox" name="scopes[]" value="posting_found"> Found-item posting</label>
                            <label><input type="checkbox" name="scopes[]" value="comments"> Comments (all posts)</label>
                            <label><input type="checkbox" name="scopes[]" value="reactions"> Reactions (all posts)</label>
                            <label><input type="checkbox" name="scopes[]" value="messaging"> Messaging</label>
                            <label><input type="checkbox" name="scopes[]" value="other"> Other restriction</label>
                            <div data-messaging-fields hidden>
                                <label>Messaging restriction
                                    <select name="messaging_target" data-messaging-target>
                                        <option value="all">All users and admin support</option>
                                        <option value="specific">One specific user</option>
                                    </select>
                                </label>
                                <label data-messaging-user-field hidden>Specific user
                                    <select name="messaging_user_id">
                                        <option value="">Choose a user</option>
                                        @foreach ($recipients as $recipient)
                                            <option value="{{ $recipient->id }}">{{ $recipient->first_name }} {{ $recipient->last_name }} · {{ $recipient->email }}</option>
                                        @endforeach
                                    </select>
                                </label>
                            </div>
                            <label data-other-fields hidden>Describe the other restriction
                                <textarea name="other_details" rows="2" maxlength="1000"></textarea>
                            </label>
                            <p>Lost/found posting, comments, reactions, and messaging restrictions are enforced by the system. “Other” is recorded for administrator review and is not automatically enforced.</p>
                        </fieldset>
                        <button class="button" type="submit">Apply action</button>
                    </form>
                </section>
            </details>
        </div>
    </header>

    <div class="user-profile-layout">
        <section class="card user-profile-summary">
            <div class="user-profile-avatar">
                @if ($user->profile_picture_path)
                    <img src="{{ \App\Support\PublicMediaUrl::forPath($user->profile_picture_path) }}" alt="">
                @else
                    {{ strtoupper(substr($user->first_name, 0, 1).substr($user->last_name, 0, 1)) }}
                @endif
            </div>
            <div>
                <p class="eyebrow">CAMPUSLF USER</p>
                <h2>{{ $user->first_name }} {{ $user->last_name }}</h2>
                <p>{{ $user->email }}</p>
                <p>{{ $user->tmc_id_no }} · {{ ucfirst($user->user_type ?? 'member') }} · {{ $user->department ?? 'No department' }}</p>
            </div>
            @if ($user->bio)
                <p class="user-profile-bio">{{ $user->bio }}</p>
            @endif
            <div class="user-profile-stats">
                <span><strong>{{ number_format($user->posts_count) }}</strong> posts</span>
                <span><strong>{{ number_format($user->comments_count) }}</strong> comments</span>
                <span><strong>{{ $restrictions->whereNull('lifted_at')->count() }}</strong> active restrictions</span>
            </div>
        </section>

        <section class="card user-profile-section">
            <header><div><p class="eyebrow">ACCOUNT DETAILS</p><h2>Profile information</h2></div></header>
            <dl class="user-profile-details">
                <div><dt>Contact email</dt><dd>{{ $user->contact_email ?: '—' }}</dd></div>
                <div><dt>Contact number</dt><dd>{{ $user->contact_number ?: '—' }}</dd></div>
                <div><dt>Current address</dt><dd>{{ $user->current_address ?: '—' }}</dd></div>
                <div><dt>Permanent address</dt><dd>{{ $user->permanent_address ?: '—' }}</dd></div>
                <div><dt>Joined</dt><dd>{{ $user->created_at?->format('M j, Y g:i A') ?? '—' }}</dd></div>
            </dl>
        </section>

        <section class="card user-profile-section">
            <header><div><p class="eyebrow">POST HISTORY</p><h2>My Posts</h2></div><span class="users-total-badge">{{ number_format($user->posts_count) }}</span></header>
            <div class="user-profile-posts">
                @forelse ($posts as $post)
                    <a class="user-profile-post" href="{{ route('admin.community-feed.show', $post) }}">
                        <span class="status-tag {{ $post->type }}">{{ ucfirst($post->type) }}</span>
                        <strong>{{ $post->item_name }}</strong>
                        <span>{{ str_replace('_', ' ', ucfirst($post->status)) }}</span>
                        <small>{{ $post->created_at->format('M j, Y g:i A') }}</small>
                    </a>
                @empty
                    <p class="muted">This user has not created any posts.</p>
                @endforelse
            </div>
        </section>

        <section class="card user-profile-section">
            <header><div><p class="eyebrow">MODERATION</p><h2>Restrictions and decisions</h2></div></header>
            <div class="user-profile-history">
                @forelse ($restrictions as $restriction)
                    <article>
                        <div>
                            <strong>{{ $scopeLabels[$restriction->scope] ?? ucfirst($restriction->scope) }}</strong>
                            <span class="{{ $restriction->lifted_at ? 'restriction-lifted' : 'restriction-active' }}">{{ $restriction->lifted_at ? 'Lifted' : 'Active' }}</span>
                            @if ($restriction->targetUser)
                                <p>Messaging with {{ $restriction->targetUser->first_name }} {{ $restriction->targetUser->last_name }}</p>
                            @endif
                            @if ($restriction->details)
                                <p>{{ $restriction->details }}</p>
                            @endif
                            <p>{{ $restriction->reason }}</p>
                            <small>{{ $restriction->created_at->format('M j, Y g:i A') }}</small>
                            @if ($restriction->lifted_at)
                                <p>Lift reason: {{ $restriction->lift_reason }}</p>
                            @endif
                        </div>
                        @if (! $restriction->lifted_at)
                            <form method="post" action="{{ route('admin.users.restrictions.lift', [$user, $restriction]) }}" class="user-lift-form">
                                @csrf
                                <label>Reason for lifting
                                    <input type="text" name="reason" maxlength="1000" required placeholder="Required reason">
                                </label>
                                <button class="button secondary" type="submit">Lift restriction</button>
                            </form>
                        @endif
                    </article>
                @empty
                    <p class="muted">No scoped restrictions have been applied.</p>
                @endforelse
                @foreach ($moderationActions as $moderationAction)
                    <article>
                        <div>
                            <strong>{{ str_replace('_', ' ', ucfirst($moderationAction->action)) }}</strong>
                            <p>{{ $moderationAction->reason }}</p>
                            <small>{{ $moderationAction->created_at }} · {{ trim(($moderationAction->admin_first_name ?? 'Admin').' '.($moderationAction->admin_last_name ?? '')) }}</small>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>

        <section class="card user-profile-section">
            <header><div><p class="eyebrow">ACCOUNT ACTIVITY</p><h2>Recent activity</h2></div></header>
            <div class="user-profile-activity">
                @forelse ($activity as $entry)
                    <article><strong>{{ str_replace('_', ' ', ucfirst($entry->event)) }}</strong><span>{{ $entry->created_at->format('M j, Y g:i A') }}</span></article>
                @empty
                    <p class="muted">No recent activity is available.</p>
                @endforelse
            </div>
        </section>
    </div>

    <script>
        (() => {
            const form = document.querySelector('[data-user-moderation-form]');
            if (!form) return;
            const action = form.querySelector('[data-moderation-action]');
            const banFields = form.querySelector('[data-ban-fields]');
            const messagingCheckbox = form.querySelector('input[value="messaging"]');
            const messagingFields = form.querySelector('[data-messaging-fields]');
            const messagingTarget = form.querySelector('[data-messaging-target]');
            const messagingUserField = form.querySelector('[data-messaging-user-field]');
            const messagingUser = messagingUserField.querySelector('select');
            const otherCheckbox = form.querySelector('input[value="other"]');
            const otherFields = form.querySelector('[data-other-fields]');
            const otherDetails = otherFields.querySelector('textarea');
            const updateFields = () => {
                const isBan = action.value === 'ban';
                banFields.hidden = !isBan;
                messagingFields.hidden = !isBan || !messagingCheckbox.checked;
                messagingUserField.hidden = messagingTarget.value !== 'specific' || messagingFields.hidden;
                messagingUser.required = !messagingUserField.hidden;
                otherFields.hidden = !isBan || !otherCheckbox.checked;
                otherDetails.required = !otherFields.hidden;
            };
            action.addEventListener('change', updateFields);
            messagingCheckbox.addEventListener('change', updateFields);
            messagingTarget.addEventListener('change', updateFields);
            otherCheckbox.addEventListener('change', updateFields);
            updateFields();
        })();
    </script>
@endsection
